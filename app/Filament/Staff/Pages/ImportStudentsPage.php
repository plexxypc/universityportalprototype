<?php

declare(strict_types=1);

namespace App\Filament\Staff\Pages;

use App\Exceptions\ImportNotFoundException;
use App\Models\ImportBatch;
use App\Models\Student;
use App\Models\User;
use App\Policies\StudentPolicy;
use App\Services\ImportService;
use App\Support\Import\ColumnMap;
use App\Support\Import\StudentRowValidator;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use UnitEnum;

/**
 * Student import wizard. Rows stay in the private staged file.
 *
 * Livewire keeps the token, the column map, and the two commit options.
 */
class ImportStudentsPage extends Page
{
    use WithFileUploads;

    protected static ?string $slug = 'students/import';

    protected static ?string $navigationLabel = 'Import students';

    protected static string|UnitEnum|null $navigationGroup = 'Student information management';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.staff.import-students';

    public string $step = 'source';

    public string $token = '';

    public string $sheet_url = '';

    /**
     * Column index for each portal field. Unmapped fields are null.
     *
     * @var array<string, int|string|null>
     */
    public array $column_map = [];

    public string $duplicate_mode = 'skip';

    public string $send_mode = 'now';

    public ?TemporaryUploadedFile $upload = null;

    /**
     * Super Admin and Registrar. Other staff roles are refused.
     */
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(StudentPolicy::class)->create($user);
    }

    public function getTitle(): string
    {
        return 'Import students';
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    /**
     * Stage a CSV or Excel file and show the first rows.
     */
    public function stageUpload(): void
    {
        $actor = $this->actor();

        if (! $this->upload instanceof TemporaryUploadedFile) {
            throw ValidationException::withMessages(['upload' => ImportService::MESSAGE_FILE]);
        }

        try {
            $this->token = app(ImportService::class)->stageUpload($actor, $this->upload);
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $exception) {
            $this->token = '';
            throw $exception;
        }

        $this->upload = null;
        $this->column_map = [];
        $this->step = 'preview';
    }

    /**
     * Stage a public Google Sheet and show the first rows.
     */
    public function stageSheet(): void
    {
        $actor = $this->actor();

        try {
            $this->token = app(ImportService::class)->stageSheet($actor, $this->sheet_url);
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $exception) {
            $this->token = '';
            throw $exception;
        }

        $this->sheet_url = '';
        $this->column_map = [];
        $this->step = 'preview';
    }

    /**
     * Fill the column map from the staged headers and open the mapping step.
     */
    public function openMap(): void
    {
        try {
            $suggested = app(ImportService::class)->suggestMap($this->actor(), $this->token);
        } catch (ImportNotFoundException) {
            abort(404);
        } catch (AuthorizationException) {
            abort(403);
        }

        $map = [];

        foreach (ColumnMap::fields() as $field) {
            $map[$field['key']] = $suggested[$field['key']] ?? null;
        }

        $this->column_map = $map;
        $this->step = 'map';
    }

    /**
     * Check the column map and store it on the staged file.
     */
    public function confirmMap(): void
    {
        try {
            $saved = app(ImportService::class)->confirmMap($this->actor(), $this->token, $this->column_map);
        } catch (ImportNotFoundException) {
            abort(404);
        } catch (AuthorizationException) {
            abort(403);
        }

        $this->column_map = $saved;
        $this->step = 'validate';
    }

    /**
     * Row errors from the staged file. This return value is not stored.
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
    public function validationReport(): array
    {
        $empty = [
            'errors' => [],
            'error_count' => 0,
            'remaining_error_count' => 0,
            'duplicates' => [],
            'duplicate_count' => 0,
            'ready_count' => 0,
            'total_rows' => 0,
        ];

        if ($this->token === '' || $this->step !== 'validate') {
            return $empty;
        }

        try {
            return app(ImportService::class)->validateSheet($this->actor(), $this->token, $this->duplicate_mode);
        } catch (ImportNotFoundException) {
            abort(404);
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->first();
            $empty['errors'] = [[
                'row' => 0,
                'field' => 'column_map',
                'message' => is_string($message) ? $message : StudentRowValidator::MESSAGE_MAP,
            ]];
            $empty['error_count'] = 1;

            return $empty;
        }
    }

    /**
     * Import the next chunk. Rows stay in the staged file.
     */
    public function commitChunk(): void
    {
        try {
            $result = app(ImportService::class)->commitChunk(
                $this->actor(),
                $this->token,
                $this->duplicate_mode,
                $this->send_mode,
                request(),
            );
        } catch (ImportNotFoundException) {
            abort(404);
        } catch (AuthorizationException) {
            abort(403);
        }

        if ($result['finished']) {
            session(['student-import-batch:'.$this->actor()->id => $result['batch_id']]);
            $this->step = 'report';

            return;
        }

        $this->step = 'import';
    }

    /**
     * Progress for a batch that is still staged.
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
    public function importProgress(): array
    {
        try {
            return app(ImportService::class)->status($this->actor(), $this->token);
        } catch (ImportNotFoundException) {
            abort(404);
        } catch (AuthorizationException) {
            abort(403);
        }
    }

    /**
     * The finished batch for this actor. Counts only.
     *
     * @return array{created_count: int, updated_count: int, skipped_count: int, failed_count: int, credentials_needed: int, students_url: string}
     */
    public function commitReport(): array
    {
        $empty = [
            'created_count' => 0,
            'updated_count' => 0,
            'skipped_count' => 0,
            'failed_count' => 0,
            'credentials_needed' => 0,
            'students_url' => '',
        ];
        $batch_id = session('student-import-batch:'.$this->actor()->id);

        if (! is_int($batch_id) && ! (is_string($batch_id) && ctype_digit($batch_id))) {
            return $empty;
        }

        $batch = ImportBatch::query()
            ->whereKey((int) $batch_id)
            ->where('user_id', $this->actor()->id)
            ->first();

        if (! $batch instanceof ImportBatch) {
            return $empty;
        }

        $needed = Student::query()->where('import_batch_id', $batch->id)->needsCredentials()->count();

        return [
            'created_count' => (int) $batch->created_count,
            'updated_count' => (int) $batch->updated_count,
            'skipped_count' => (int) $batch->skipped_count,
            'failed_count' => (int) $batch->failed_count,
            'credentials_needed' => $needed,
            'students_url' => StudentsPage::getUrl().'?needs_credentials=1&import_batch='.$batch->id,
        ];
    }

    /**
     * Portal fields shown on the mapping step.
     *
     * @return list<array{key: string, label: string, required: bool}>
     */
    public function mappingFields(): array
    {
        return ColumnMap::fields();
    }

    /**
     * First rows from the staged file. This return value is not stored.
     *
     * @return array{headers: list<string>, rows: list<list<string>>, total_rows: int, source: string, original_name: string}
     */
    public function previewTable(): array
    {
        if ($this->token === '' || $this->step === 'source') {
            return [
                'headers' => [],
                'rows' => [],
                'total_rows' => 0,
                'source' => '',
                'original_name' => '',
            ];
        }

        try {
            return app(ImportService::class)->preview($this->actor(), $this->token);
        } catch (ImportNotFoundException) {
            abort(404);
        } catch (AuthorizationException) {
            abort(403);
        }
    }

    /**
     * The signed-in manager, or a 403.
     */
    private function actor(): User
    {
        $actor = auth()->user();

        if (! $actor instanceof User || ! app(StudentPolicy::class)->create($actor)) {
            abort(403);
        }

        return $actor;
    }
}
