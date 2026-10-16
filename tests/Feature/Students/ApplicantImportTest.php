<?php

declare(strict_types=1);

use App\Enums\ImportTarget;
use App\Enums\Role;
use App\Filament\Staff\Pages\ImportApplicantsPage;
use App\Filament\Staff\Pages\ImportStudentsPage;
use App\Models\AcademicSession;
use App\Models\Applicant;
use App\Models\Programme;
use App\Models\Student;
use App\Models\User;
use App\Services\ApplicantService;
use App\Services\ImportService;
use App\Support\Import\StagedImportStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake('local');
    AcademicSession::factory()->current()->create(['name' => '2025/2026']);
    Programme::factory()->create(['code' => 'CSC', 'name' => 'Computer Science', 'duration_years' => 4]);
});

/**
 * @param  list<string>  $rows
 */
function applicant_import_token(User $actor, array $rows, ImportTarget $target = ImportTarget::Applicant): string
{
    $header = 'first_name,last_name,email,phone,gender,date_of_birth,programme_code,level,matric_number,guardian_name,guardian_phone';
    $token = app(ImportService::class)->stageUpload($actor, UploadedFile::fake()->createWithContent(
        'applicants.csv',
        $header."\n".implode("\n", $rows)."\n",
    ), $target);
    app(StagedImportStore::class)->saveMap((int) $actor->id, $token, [
        'first_name' => 0,
        'last_name' => 1,
        'email' => 2,
        'phone' => 3,
        'gender' => 4,
        'date_of_birth' => 5,
        'programme_code' => 6,
        'level' => 7,
        'matric_number' => 8,
        'guardian_name' => 9,
        'guardian_phone' => 10,
    ]);

    return $token;
}

function applicant_import_commit(User $actor, string $token): array
{
    return app(ImportService::class)->commitChunk($actor, $token, 'skip', 'now', Request::create('/staff/applicants/import', 'POST'));
}

it('imports applicants through the staged file and does not create users', function () {
    $registrar = import_wizard_actor();
    $token = applicant_import_token($registrar, [
        'Ada,Lovelace,ada.import@example.test,08012345678,Female,03/02/2001,CSC,100,CSC/2025/0001,Bad Guardian,not-a-phone',
        'Grace,Hopper,grace.import@example.test,08012345678,Female,03/02/2001,CSC,100,,',
    ]);

    $result = applicant_import_commit($registrar, $token);

    $ada = Applicant::query()->where('email', 'ada.import@example.test')->first();

    expect($result['created_count'])->toBe(2)
        ->and($ada)->not->toBeNull()
        ->and($ada?->phone)->toBe('+2348012345678')
        ->and($ada?->source->value)->toBe('csv')
        ->and(User::query()->where('email', 'ada.import@example.test')->exists())->toBeFalse()
        ->and(Student::query()->count())->toBe(0);
});

it('fails a second in-file copy and an email that already belongs to a user', function () {
    $registrar = import_wizard_actor();
    User::factory()->create(['email' => 'taken.import@example.test']);
    $token = applicant_import_token($registrar, [
        'Ada,Lovelace,ada.copy@example.test,08012345678,Female,03/02/2001,CSC,100,,',
        'Ann,Lovelace,ada.copy@example.test,08012345678,Female,03/02/2001,CSC,100,,',
        'Ben,Taken,taken.import@example.test,08012345678,Male,03/02/2001,CSC,100,,',
    ]);

    applicant_import_commit($registrar, $token);

    expect(Applicant::query()->where('email', 'ada.copy@example.test')->count())->toBe(1)
        ->and(Applicant::query()->where('email', 'taken.import@example.test')->exists())->toBeFalse()
        ->and(User::query()->where('email', 'ada.copy@example.test')->exists())->toBeFalse();
});

it('does not let a tampered livewire target turn an applicant file into students', function () {
    $registrar = import_wizard_actor(Role::Registrar);
    $csv = "first_name,last_name,email,programme_code,level\nAda,Lovelace,live.ada@example.test,CSC,100\n";

    Livewire::actingAs($registrar)->test(ImportApplicantsPage::class)
        ->set('target', 'Student')
        ->set('upload', UploadedFile::fake()->createWithContent('people.csv', $csv))
        ->call('stageUpload')
        ->call('openMap')
        ->call('confirmMap')
        ->set('target', 'Student')
        ->set('send_mode', 'now')
        ->call('commitChunk');

    expect(Applicant::query()->where('email', 'live.ada@example.test')->exists())->toBeTrue()
        ->and(Student::query()->count())->toBe(0)
        ->and(User::query()->where('email', 'live.ada@example.test')->exists())->toBeFalse();
});

it('does not let a tampered livewire target turn a student file into applicants', function () {
    $registrar = import_wizard_actor(Role::Registrar);
    $csv = "first_name,last_name,email,programme_code,level\nGrace,Hopper,live.grace@example.test,CSC,100\n";

    Livewire::actingAs($registrar)->test(ImportStudentsPage::class)
        ->set('target', 'Applicant')
        ->set('upload', UploadedFile::fake()->createWithContent('people.csv', $csv))
        ->call('stageUpload')
        ->call('openMap')
        ->call('confirmMap')
        ->set('target', 'Applicant')
        ->set('send_mode', 'later')
        ->call('commitChunk');

    expect(Student::query()->whereHas('user', fn ($query) => $query->where('email', 'live.grace@example.test'))->exists())->toBeTrue()
        ->and(Applicant::query()->count())->toBe(0);
});

it('refuses an applicant import update that would change the email', function () {
    $registrar = import_wizard_actor();
    $programme = Programme::query()->where('code', 'CSC')->firstOrFail();
    $existing = app(ApplicantService::class)->create($registrar, [
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'email' => 'keep.ada@example.test',
        'phone' => '08012345678',
        'programme_id' => $programme->id,
        'level' => 100,
    ], Request::create('/'));

    expect(fn () => app(ApplicantService::class)->update($registrar, (int) $existing->id, [
        'first_name' => 'Ada',
        'last_name' => 'Byron',
        'email' => 'changed.ada@example.test',
        'programme_id' => $programme->id,
        'level' => 100,
    ], Request::create('/')))->toThrow(ValidationException::class);

    expect($existing->refresh()->email)->toBe('keep.ada@example.test')
        ->and($existing->last_name)->toBe('Lovelace');
});
