<?php

declare(strict_types=1);

namespace App\Filament\Staff\Pages;

use App\Exceptions\ImportNotFoundException;
use App\Models\User;
use App\Policies\StudentPolicy;
use App\Services\ImportService;
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
     * Column index for each portal field. Empty until mapping is confirmed.
     *
     * @var array<string, int>
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
        $this->step = 'preview';
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
