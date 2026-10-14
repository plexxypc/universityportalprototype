<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Exceptions\ImportNotFoundException;
use App\Filament\Staff\Pages\ImportStudentsPage;
use App\Services\ImportService;
use App\Support\Import\GoogleSheetClient;
use App\Support\Import\HostResolver;
use App\Support\Import\PinnedHttp;
use App\Support\Import\PinnedHttpResponse;
use App\Support\Import\StagedImportStore;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake('local');
});

function import_wizard_csv(): UploadedFile
{
    return UploadedFile::fake()->createWithContent(
        'students.csv',
        "first_name,last_name\nAda,Lovelace\n",
    );
}

function import_wizard_sheet_client(): void
{
    $resolver = new class implements HostResolver
    {
        public function addresses(string $host): array
        {
            return ['8.8.8.8'];
        }
    };
    $http = new class implements PinnedHttp
    {
        public function get(string $url, string $pinned_ip, int $connect_timeout, int $timeout, int $max_bytes): PinnedHttpResponse
        {
            return new PinnedHttpResponse(200, "first_name,last_name\nGrace,Hopper\n", null, 'text/csv', false);
        }
    };

    app()->instance(GoogleSheetClient::class, new GoogleSheetClient($resolver, $http));
}

it('previews a csv for a registrar and keeps rows out of livewire state', function () {
    $registrar = import_wizard_actor();

    $page = Livewire::actingAs($registrar)
        ->test(ImportStudentsPage::class)
        ->set('upload', import_wizard_csv())
        ->call('stageUpload')
        ->assertSet('step', 'preview')
        ->assertSee('Ada')
        ->assertSee('Lovelace');

    $state = json_encode($page->instance()->all());

    expect($state)->toBeString()
        ->and($state)->not->toContain('Lovelace')
        ->and($state)->not->toContain('Ada')
        ->and(array_keys($page->instance()->all()))->not->toContain('rows');

    $token = $page->get('token');
    expect($token)->toBeString()->not->toBe('');
    expect(Storage::disk('local')->exists(StagedImportStore::DIRECTORY.'/'.$token.'.json'))->toBeTrue();
    expect(str_starts_with(Storage::disk('local')->path(StagedImportStore::DIRECTORY), public_path()))->toBeFalse();
});

it('previews an excel file and a google sheet', function () {
    $registrar = import_wizard_actor();
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setCellValue('A1', 'first_name');
    $sheet->setCellValue('A2', 'Grace');
    $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
    (new Xlsx($spreadsheet))->save($path);
    $spreadsheet->disconnectWorksheets();
    $upload = UploadedFile::fake()->createWithContent('students.xlsx', (string) file_get_contents($path));
    unlink($path);

    Livewire::actingAs($registrar)
        ->test(ImportStudentsPage::class)
        ->set('upload', $upload)
        ->call('stageUpload')
        ->assertSee('Grace');

    import_wizard_sheet_client();

    Livewire::actingAs($registrar)
        ->test(ImportStudentsPage::class)
        ->set('sheet_url', 'https://docs.google.com/spreadsheets/d/abcdefghijklmnopqrstuv/edit')
        ->call('stageSheet')
        ->assertSee('Hopper');
});

it('replaces an older staged file from the same actor', function () {
    $registrar = import_wizard_actor();
    $service = app(ImportService::class);
    $first = $service->stageUpload($registrar, import_wizard_csv());
    $second = $service->stageUpload($registrar, UploadedFile::fake()->createWithContent(
        'other.csv',
        "first_name\nGrace\n",
    ));

    expect(Storage::disk('local')->exists(StagedImportStore::DIRECTORY.'/'.$first.'.json'))->toBeFalse()
        ->and(Storage::disk('local')->exists(StagedImportStore::DIRECTORY.'/'.$second.'.json'))->toBeTrue()
        ->and($service->preview($registrar, $second)['rows'])->toBe([['Grace']]);
});

it('returns the same not-found result for a missing, forged, or foreign token', function () {
    $owner = import_wizard_actor();
    $other = import_wizard_actor();
    $token = app(ImportService::class)->stageUpload($owner, import_wizard_csv());

    expect(fn () => app(ImportService::class)->preview($other, $token))
        ->toThrow(ImportNotFoundException::class, ImportNotFoundException::MESSAGE);
    expect(fn () => app(ImportService::class)->preview($owner, str_repeat('ab', 32)))
        ->toThrow(ImportNotFoundException::class, ImportNotFoundException::MESSAGE);

    Livewire::actingAs($other)
        ->test(ImportStudentsPage::class)
        ->set('token', $token)
        ->set('step', 'preview')
        ->assertNotFound();

    Livewire::actingAs($owner)
        ->test(ImportStudentsPage::class)
        ->set('token', str_repeat('a', 64))
        ->set('step', 'preview')
        ->assertNotFound();
});

it('refuses a tampered rows property', function () {
    $registrar = import_wizard_actor();
    $page = Livewire::actingAs($registrar)->test(ImportStudentsPage::class);

    expect(fn () => $page->set('rows', [['Ada', 'Lovelace']]))->toThrow(Exception::class);
    expect(array_keys($page->instance()->all()))->not->toContain('rows');
});

it('refuses scoped roles on the wizard', function (Role $role) {
    $actor = import_wizard_actor($role);

    Livewire::actingAs($actor)
        ->test(ImportStudentsPage::class)
        ->assertForbidden();

    expect(fn () => app(ImportService::class)->stageUpload($actor, import_wizard_csv()))
        ->toThrow(AuthorizationException::class);
})->with([
    Role::FacultyAdmin,
    Role::DepartmentOfficer,
    Role::Lecturer,
    Role::Bursar,
]);

it('limits source uploads to 10 in 10 minutes', function () {
    $registrar = import_wizard_actor();
    $service = app(ImportService::class);
    RateLimiter::clear('import-source:'.$registrar->id);

    for ($attempt = 0; $attempt < ImportService::SOURCE_ATTEMPTS; $attempt++) {
        $service->stageUpload($registrar, import_wizard_csv());
    }

    expect(fn () => $service->stageUpload($registrar, import_wizard_csv()))
        ->toThrow(ValidationException::class, ImportService::MESSAGE_RATE);
});

it('deletes staged files older than 24 hours and keeps a new one', function () {
    $registrar = import_wizard_actor();
    $token = app(ImportService::class)->stageUpload($registrar, import_wizard_csv());
    $path = StagedImportStore::DIRECTORY.'/'.$token.'.json';
    $payload = json_decode(Storage::disk('local')->get($path) ?: '', true, 512, JSON_THROW_ON_ERROR);
    $payload['created_at'] = now()->subHours(25)->toIso8601String();
    Storage::disk('local')->put($path, json_encode($payload, JSON_THROW_ON_ERROR));

    $fresh = app(ImportService::class)->stageUpload($registrar, UploadedFile::fake()->createWithContent(
        'fresh.csv',
        "first_name\nGrace\n",
    ));
    Storage::disk('local')->put($path, json_encode($payload, JSON_THROW_ON_ERROR));

    Artisan::call('imports:prune-staged');

    expect(Storage::disk('local')->exists($path))->toBeFalse()
        ->and(Storage::disk('local')->exists(StagedImportStore::DIRECTORY.'/'.$fresh.'.json'))->toBeTrue();

    $scheduled = collect(app(Schedule::class)->events())
        ->contains(fn (object $event): bool => str_contains((string) ($event->command ?? ''), 'imports:prune-staged'));

    expect($scheduled)->toBeTrue();
});
