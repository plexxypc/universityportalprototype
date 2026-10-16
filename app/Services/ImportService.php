<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EmailTemplate;
use App\Enums\ImportBatchStatus;
use App\Enums\ImportSource;
use App\Enums\ImportTarget;
use App\Exceptions\ImportFetchException;
use App\Exceptions\ImportNotFoundException;
use App\Exceptions\ImportReadException;
use App\Models\ImportBatch;
use App\Models\Student;
use App\Models\User;
use App\Policies\StudentPolicy;
use App\Support\Audit;
use App\Support\Import\ApplicantRowValidator;
use App\Support\Import\ColumnMap;
use App\Support\Import\FailedRowCsv;
use App\Support\Import\GoogleSheetClient;
use App\Support\Import\SpreadsheetReader;
use App\Support\Import\StagedImport;
use App\Support\Import\StagedImportStore;
use App\Support\Import\StudentRowValidator;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Stages a spreadsheet and reads it back for the actor who owns it.
 *
 * The target is stored on the staged file when the upload is accepted.
 * Commit reads that target. A later change on the page cannot switch an
 * applicant file into student accounts.
 *
 * Parsed rows stay in the private staged file. Callers pass a token.
 */
final class ImportService
{
    public const string MESSAGE_RATE = 'Wait a few minutes before importing another file.';

    public const string MESSAGE_FILE = 'Choose a CSV or Excel file.';

    public const string MESSAGE_SHEET = 'Paste a Google Sheets link.';

    public const int SOURCE_ATTEMPTS = 10;

    public const int SOURCE_DECAY_SECONDS = 600;

    public const int CHUNK_ROWS = 25;

    public const string MESSAGE_BUSY = 'This import is already running in another tab.';

    public const string MESSAGE_LOG_MAILER = 'Credential emails cannot be sent with the log mailer. Choose send later.';

    public const string MESSAGE_SEND = 'Choose send now or send later.';

    public const string ACTION_COMPLETED = 'imports.completed';

    public function __construct(
        private readonly SpreadsheetReader $reader,
        private readonly StagedImportStore $store,
        private readonly StudentRowValidator $rows,
        private readonly ApplicantRowValidator $applicant_rows,
        private readonly StudentService $students,
        private readonly ApplicantService $applicants,
        private readonly MailService $mail,
        private readonly Audit $audit,
        private readonly FailedRowCsv $failures,
    ) {}

    /**
     * Parse an upload and stage it for this actor.
     */
    public function stageUpload(User $actor, UploadedFile $file, ImportTarget $target = ImportTarget::Student): string
    {
        $this->authorise($actor);
        $this->limitSources($actor);
        $extension = strtolower($file->getClientOriginalExtension());

        if (! in_array($extension, ['csv', 'xlsx'], true)) {
            throw ValidationException::withMessages(['upload' => ImportReadException::MESSAGE_TYPE]);
        }

        $directory = storage_path('app/private/imports/tmp');

        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw ValidationException::withMessages(['upload' => ImportReadException::MESSAGE_READ]);
        }

        $temporary = $directory.'/'.bin2hex(random_bytes(16)).'.'.$extension;

        try {
            $contents = $file->getContent();
            if (file_put_contents($temporary, $contents) === false) {
                throw ValidationException::withMessages(['upload' => ImportReadException::MESSAGE_READ]);
            }

            $sheet = $this->reader->read($temporary);
        } catch (ImportReadException $exception) {
            throw ValidationException::withMessages(['upload' => $exception->getMessage()]);
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }

        return $this->store->put(
            (int) $actor->id,
            $extension,
            $this->originalName($file->getClientOriginalName(), $extension),
            $sheet,
            $target->value,
        );
    }

    /**
     * Download a public Google Sheet and stage its CSV.
     */
    public function stageSheet(User $actor, string $link, ImportTarget $target = ImportTarget::Student): string
    {
        $this->authorise($actor);
        $this->limitSources($actor);

        if (trim($link) === '') {
            throw ValidationException::withMessages(['sheet_url' => self::MESSAGE_SHEET]);
        }

        try {
            $csv = $this->google()->fetch($link);
            $sheet = $this->reader->readCsv($csv);
        } catch (ImportFetchException|ImportReadException $exception) {
            throw ValidationException::withMessages(['sheet_url' => $exception->getMessage()]);
        }

        return $this->store->put((int) $actor->id, 'google_sheet', 'Google Sheet', $sheet, $target->value);
    }

    /**
     * Headers and the first rows. The full sheet stays in the staged file.
     *
     * @return array{headers: list<string>, rows: list<list<string>>, total_rows: int, source: string, original_name: string}
     */
    public function preview(User $actor, string $token): array
    {
        $this->authorise($actor);
        $staged = $this->owned($actor, $token);

        return [
            'headers' => $staged->headers,
            'rows' => array_slice($staged->rows, 0, StagedImportStore::PREVIEW_ROWS),
            'total_rows' => count($staged->rows),
            'source' => $staged->source,
            'original_name' => $staged->original_name,
        ];
    }

    /**
     * Suggested column indexes for this actor's staged headers.
     *
     * @return array<string, int>
     */
    public function suggestMap(User $actor, string $token): array
    {
        $this->authorise($actor);

        return ColumnMap::suggest($this->owned($actor, $token)->headers);
    }

    /**
     * Store a confirmed column map on the staged file.
     *
     * The saved map is the one later chunks read. A tampered Livewire map
     * is checked here and is not written when it fails.
     *
     * @param  array<mixed, mixed>  $column_map
     * @return array<string, int>
     */
    public function confirmMap(User $actor, string $token, array $column_map): array
    {
        $this->authorise($actor);
        $staged = $this->owned($actor, $token);
        $confirmed = ColumnMap::confirmed($staged->headers, $column_map);
        $this->store->saveMap((int) $actor->id, $token, $confirmed);

        return $confirmed;
    }

    /**
     * Check every staged row. The column map is read from the staged file.
     *
     * @return array{
     *     errors: list<array{row: int, field: string, message: string}>,
     *     error_count: int,
     *     remaining_error_count: int,
     *     duplicates: list<array{row: int, field: string, message: string}>,
     *     duplicate_count: int,
     *     ready_count: int,
     *     total_rows: int
     * }
     */
    public function validateSheet(User $actor, string $token, string $duplicate_mode): array
    {
        $this->authorise($actor);

        if (! in_array($duplicate_mode, ['skip', 'update'], true)) {
            throw ValidationException::withMessages(['duplicate_mode' => StudentRowValidator::MESSAGE_MODE]);
        }

        $staged = $this->owned($actor, $token);

        if ($staged->target === ImportTarget::Applicant->value) {
            return $this->applicant_rows->assess($staged, $duplicate_mode);
        }

        return $this->rows->assess($staged, $duplicate_mode);
    }

    /**
     * Import the next 25 rows from the staged file.
     *
     * The actor must own the batch and be allowed to manage student records
     * on every chunk. The offset is the batch's processed_rows.
     *
     * @return array{
     *     finished: bool,
     *     processed_rows: int,
     *     total_rows: int,
     *     created_count: int,
     *     updated_count: int,
     *     skipped_count: int,
     *     failed_count: int,
     *     batch_id: int,
     *     credentials_needed: int
     * }
     */
    public function commitChunk(User $actor, string $token, string $duplicate_mode, string $send_mode, Request $request): array
    {
        $this->authorise($actor);

        if (! in_array($duplicate_mode, ['skip', 'update'], true)) {
            throw ValidationException::withMessages(['duplicate_mode' => StudentRowValidator::MESSAGE_MODE]);
        }

        if (! in_array($send_mode, ['now', 'later'], true)) {
            throw ValidationException::withMessages(['send_mode' => self::MESSAGE_SEND]);
        }

        $staged = $this->owned($actor, $token);
        $lock = Cache::lock('import-commit:'.$token, 300);

        if (! $lock->get()) {
            throw ValidationException::withMessages(['import' => self::MESSAGE_BUSY]);
        }

        try {
            if ($staged->target !== ImportTarget::Applicant->value
                && $send_mode === 'now'
                && $this->mail->refusesSecretTemplate(EmailTemplate::Credentials->value)) {
                throw ValidationException::withMessages(['send_mode' => self::MESSAGE_LOG_MAILER]);
            }

            $batch = $this->batch($actor, $staged);
            $actions = $staged->target === ImportTarget::Applicant->value
                ? $this->applicant_rows->actions($staged, $duplicate_mode)
                : $this->rows->actions($staged, $duplicate_mode);
            $slice = array_slice($actions, (int) $batch->processed_rows, self::CHUNK_ROWS);

            foreach ($slice as $action) {
                $this->apply($actor, $batch, $staged->token, $action, $send_mode, $request);
                $batch->processed_rows = (int) $batch->processed_rows + 1;
                $batch->save();
            }

            $finished = (int) $batch->processed_rows >= count($actions);

            if ($finished) {
                $this->finish($actor, $batch, $staged->token, $request);
            }

            return $this->progress($batch, $finished);
        } finally {
            $lock->release();
        }
    }

    /**
     * Counts for a batch that is still staged. Finished batches are read from the batch row.
     *
     * @return array{
     *     finished: bool,
     *     processed_rows: int,
     *     total_rows: int,
     *     created_count: int,
     *     updated_count: int,
     *     skipped_count: int,
     *     failed_count: int,
     *     batch_id: int,
     *     credentials_needed: int
     * }
     */
    public function status(User $actor, string $token): array
    {
        $this->authorise($actor);
        $staged = $this->owned($actor, $token);

        if ($staged->import_batch_id === null) {
            return [
                'finished' => false,
                'processed_rows' => 0,
                'total_rows' => count($staged->rows),
                'created_count' => 0,
                'updated_count' => 0,
                'skipped_count' => 0,
                'failed_count' => 0,
                'batch_id' => 0,
                'credentials_needed' => 0,
            ];
        }

        $batch = $this->batch($actor, $staged);

        return $this->progress($batch, $batch->status === ImportBatchStatus::Completed);
    }

    /**
     * @param  array{
     *     row: int,
     *     action: string,
     *     student_id: ?int,
     *     match_field: string,
     *     errors: list<array{row: int, field: string, message: string}>,
     *     input: array<string, mixed>,
     *     guardian: ?array{name: string, phone: string, email: string, relationship: string},
     *     cells: array<string, string>
     * }  $action
     */
    private function apply(User $actor, ImportBatch $batch, string $token, array $action, string $send_mode, Request $request): void
    {
        if ($action['action'] === 'fail') {
            foreach ($action['errors'] as $error) {
                $this->failures->append($token, $error['row'], $error['field'], $error['message'], $action['cells']);
            }
            $batch->failed_count = (int) $batch->failed_count + 1;

            return;
        }

        if ($action['action'] === 'skip') {
            $batch->skipped_count = (int) $batch->skipped_count + 1;

            return;
        }

        if ($batch->target === ImportTarget::Applicant) {
            $this->applyApplicant($actor, $batch, $token, $action, $request);

            return;
        }

        $previous = (string) config('queue.default');

        if ($action['action'] === 'create' && $send_mode === 'now') {
            config(['queue.default' => 'database']);
        }

        try {
            DB::transaction(function () use ($actor, $batch, $action, $send_mode, $request): void {
                if ($action['action'] === 'update' && $action['student_id'] !== null) {
                    $this->students->update($actor, $action['student_id'], $action['input'], $request);

                    if ($action['guardian'] !== null) {
                        $this->students->saveGuardians($actor, $action['student_id'], [$action['guardian']], $request);
                    }

                    return;
                }

                $created = $this->students->create(
                    $actor,
                    $action['input'],
                    $request,
                    $send_mode === 'now',
                    (int) $batch->id,
                );

                if ($action['guardian'] !== null) {
                    $this->students->saveGuardians($actor, (int) $created->student->getKey(), [$action['guardian']], $request);
                }
            });
        } catch (ValidationException $exception) {
            $field = array_key_first($exception->errors()) ?? 'row';
            $message = $exception->errors()[$field][0] ?? StudentService::MESSAGE_MISSING;
            $this->failures->append($token, $action['row'], is_string($field) ? $field : 'row', is_string($message) ? $message : StudentService::MESSAGE_MISSING, $action['cells']);
            $batch->failed_count = (int) $batch->failed_count + 1;

            return;
        } finally {
            config(['queue.default' => $previous]);
        }

        if ($action['action'] === 'update') {
            $batch->updated_count = (int) $batch->updated_count + 1;

            return;
        }

        $batch->created_count = (int) $batch->created_count + 1;
    }

    /**
     * Create or update one applicant. Credentials are not sent.
     *
     * @param  array{
     *     row: int,
     *     action: string,
     *     applicant_id?: ?int,
     *     errors: list<array{row: int, field: string, message: string}>,
     *     input: array<string, mixed>,
     *     cells: array<string, string>
     * }  $action
     */
    private function applyApplicant(User $actor, ImportBatch $batch, string $token, array $action, Request $request): void
    {
        $input = $action['input'];
        $input['source'] = $batch->source->value;

        try {
            DB::transaction(function () use ($actor, $batch, $action, $input, $request): void {
                if ($action['action'] === 'update' && ($action['applicant_id'] ?? null) !== null) {
                    $this->applicants->update($actor, (int) $action['applicant_id'], $input, $request);

                    return;
                }

                $this->applicants->create($actor, $input, $request, (int) $batch->id);
            });
        } catch (ValidationException $exception) {
            $field = array_key_first($exception->errors()) ?? 'row';
            $message = $exception->errors()[$field][0] ?? ApplicantService::MESSAGE_MISSING;
            $this->failures->append($token, $action['row'], is_string($field) ? $field : 'row', is_string($message) ? $message : ApplicantService::MESSAGE_MISSING, $action['cells']);
            $batch->failed_count = (int) $batch->failed_count + 1;

            return;
        }

        if ($action['action'] === 'update') {
            $batch->updated_count = (int) $batch->updated_count + 1;

            return;
        }

        $batch->created_count = (int) $batch->created_count + 1;
    }

    private function batch(User $actor, StagedImport $staged): ImportBatch
    {
        if ($staged->import_batch_id === null) {
            $batch = new ImportBatch;
            $batch->user_id = (int) $actor->id;
            $batch->source = $this->source($staged->source);
            $batch->target = ImportTarget::tryFrom($staged->target) ?? ImportTarget::Student;
            $batch->status = ImportBatchStatus::Processing;
            $batch->total_rows = count($staged->rows);
            $batch->original_name = $staged->original_name;
            $batch->save();
            $this->store->saveBatchId((int) $actor->id, $staged->token, (int) $batch->id);

            return $batch->refresh();
        }

        $batch = ImportBatch::query()->find($staged->import_batch_id);

        if (! $batch instanceof ImportBatch || (int) $batch->user_id !== (int) $actor->id) {
            throw ImportNotFoundException::missing();
        }

        return $batch;
    }

    private function finish(User $actor, ImportBatch $batch, string $token, Request $request): void
    {
        if ($batch->status === ImportBatchStatus::Completed) {
            return;
        }

        DB::transaction(function () use ($actor, $batch, $token, $request): void {
            $batch->status = ImportBatchStatus::Completed;
            $batch->completed_at = now();

            if ($this->failures->exists($token)) {
                $batch->failure_report_path = $this->failures->path($token);
            }

            $batch->save();
            $this->audit->record(
                (int) $actor->id,
                self::ACTION_COMPLETED,
                'import_batches',
                (int) $batch->id,
                null,
                [
                    'source' => $batch->source->value,
                    'target' => $batch->target->value,
                    'created_count' => (int) $batch->created_count,
                    'updated_count' => (int) $batch->updated_count,
                    'skipped_count' => (int) $batch->skipped_count,
                    'failed_count' => (int) $batch->failed_count,
                ],
                $this->audit->ipFromRequest($request),
            );
        });

        $this->store->delete((int) $actor->id, $token);
    }

    /**
     * @return array{
     *     finished: bool,
     *     processed_rows: int,
     *     total_rows: int,
     *     created_count: int,
     *     updated_count: int,
     *     skipped_count: int,
     *     failed_count: int,
     *     batch_id: int,
     *     credentials_needed: int
     * }
     */
    private function progress(ImportBatch $batch, bool $finished): array
    {
        return [
            'finished' => $finished,
            'processed_rows' => (int) $batch->processed_rows,
            'total_rows' => (int) $batch->total_rows,
            'created_count' => (int) $batch->created_count,
            'updated_count' => (int) $batch->updated_count,
            'skipped_count' => (int) $batch->skipped_count,
            'failed_count' => (int) $batch->failed_count,
            'batch_id' => (int) $batch->id,
            'credentials_needed' => $finished && $batch->target === ImportTarget::Student
                ? Student::query()->where('import_batch_id', $batch->id)->needsCredentials()->count()
                : 0,
        ];
    }

    private function source(string $source): ImportSource
    {
        return match ($source) {
            'xlsx' => ImportSource::Xlsx,
            'google_sheet' => ImportSource::GoogleSheet,
            default => ImportSource::Csv,
        };
    }

    /**
     * Delete staged files older than the cutoff.
     */
    public function pruneStaged(CarbonInterface $cutoff): int
    {
        return $this->store->prune($cutoff);
    }

    /**
     * The actor's staged sheet, or the same not-found exception for every miss.
     */
    public function owned(User $actor, string $token): StagedImport
    {
        $this->authorise($actor);

        return $this->store->read((int) $actor->id, $token);
    }

    /**
     * Refuse a caller who cannot manage student records.
     */
    private function authorise(User $actor): void
    {
        if (! app(StudentPolicy::class)->create($actor)) {
            throw new AuthorizationException;
        }
    }

    /**
     * Ten source uploads or fetches in ten minutes for one actor.
     */
    private function limitSources(User $actor): void
    {
        $key = 'import-source:'.$actor->id;

        if (RateLimiter::tooManyAttempts($key, self::SOURCE_ATTEMPTS)) {
            throw ValidationException::withMessages(['upload' => self::MESSAGE_RATE]);
        }

        RateLimiter::hit($key, self::SOURCE_DECAY_SECONDS);
    }

    /**
     * The bound client in tests, otherwise the real pinned client.
     */
    private function google(): GoogleSheetClient
    {
        if (app()->bound(GoogleSheetClient::class)) {
            return app(GoogleSheetClient::class);
        }

        return GoogleSheetClient::make();
    }

    private function originalName(string $name, string $extension): string
    {
        $base = basename(str_replace('\\', '/', $name));
        $cleaned = preg_replace('/[^\w.\- ]+/u', '', $base);
        $cleaned = is_string($cleaned) ? trim($cleaned) : '';

        if ($cleaned === '') {
            return 'students.'.$extension;
        }

        return mb_substr($cleaned, 0, 180);
    }
}
