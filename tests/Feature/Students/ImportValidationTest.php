<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Exceptions\ImportNotFoundException;
use App\Filament\Staff\Pages\ImportStudentsPage;
use App\Models\AcademicSession;
use App\Models\Programme;
use App\Models\Student;
use App\Models\User;
use App\Services\ImportService;
use App\Services\StudentService;
use App\Support\Import\StagedImportStore;
use App\Support\Import\StudentRowValidator;
use App\Support\Phone;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake('local');
    AcademicSession::factory()->current()->create(['name' => '2025/2026']);
    Programme::factory()->create(['code' => 'CSC', 'duration_years' => 4]);
});

/**
 * @param  list<string>  $rows
 */
function import_validation_token(User $actor, array $rows): string
{
    $header = 'first_name,last_name,email,phone,gender,date_of_birth,programme_code,level,matric_number,guardian_name,guardian_phone,guardian_email,guardian_relationship';
    $token = app(ImportService::class)->stageUpload($actor, UploadedFile::fake()->createWithContent(
        'students.csv',
        $header."\n".implode("\n", $rows)."\n",
    ));
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
        'guardian_email' => 11,
        'guardian_relationship' => 12,
    ]);

    return $token;
}

function import_validation_row(string $email = 'ada@example.test', string $overrides = ''): string
{
    $cells = [
        'Ada',
        'Lovelace',
        $email,
        '08012345678',
        'Female',
        '03/02/2001',
        'CSC',
        '100',
        '',
        '',
        '',
        '',
        '',
    ];

    if ($overrides !== '') {
        return $overrides;
    }

    return implode(',', $cells);
}

it('reports a missing email, an unknown programme, a bad date, and the first in-file copy', function () {
    $registrar = import_wizard_actor();
    $token = import_validation_token($registrar, [
        'Ada,Lovelace,,08012345678,Female,03/02/2001,NOPE,100,,,,,',
        'Grace,Hopper,ada@example.test,08012345678,Female,31/02/2001,CSC,100,,,,,',
        'Grace,Hopper,ada@example.test,12,Female,2001-02-03,CSC,900,,,,,',
    ]);

    $report = app(ImportService::class)->validateSheet($registrar, $token, 'skip');
    $fields = [];

    foreach ($report['errors'] as $error) {
        $fields[$error['row']][$error['field']] = $error['message'];
    }

    expect($fields[1]['email'])->toBe(StudentService::MESSAGE_EMAIL)
        ->and($fields[1]['programme_code'])->toBe(StudentService::MESSAGE_PROGRAMME)
        ->and($fields[2]['date_of_birth'])->toBe(StudentService::MESSAGE_DATE)
        ->and($fields[3]['email'])->toBe('This value is already used on row 2.')
        ->and($fields[3]['phone'])->toBe(Phone::MESSAGE)
        ->and($fields[3]['level'])->toBe('This level is not valid for a 4-year programme.')
        ->and($report['ready_count'])->toBe(0);
});

it('accepts a day-first date, a local phone, and a guardian checked by the form rules', function () {
    $registrar = import_wizard_actor();
    $token = import_validation_token($registrar, [
        import_validation_row('ada@example.test', 'Ada,Lovelace,ada@example.test,08012345678,female,03/02/2001,CSC,100,,Ada Parent,08098765432,parent@example.test,Parent'),
    ]);

    $report = app(ImportService::class)->validateSheet($registrar, $token, 'skip');

    expect($report['error_count'])->toBe(0)
        ->and($report['ready_count'])->toBe(1)
        ->and(app(StudentService::class)->guardiansForImport([[
            'name' => 'Ada Parent',
            'phone' => '08098765432',
            'email' => 'parent@example.test',
            'relationship' => 'Parent',
        ]]))->toBe([[
            'name' => 'Ada Parent',
            'phone' => '+2348098765432',
            'email' => 'parent@example.test',
            'relationship' => 'Parent',
        ]]);

    expect(fn () => app(StudentService::class)->guardiansForImport([[
        'name' => str_repeat('A', 256),
        'phone' => '08098765432',
        'email' => 'parent@example.test',
        'relationship' => 'Parent',
    ]]))->toThrow(ValidationException::class, StudentService::MESSAGE_NAME_LENGTH);
});

it('refuses an email change in update mode and a matric owned by someone else', function () {
    $registrar = import_wizard_actor();
    $ada = User::factory()->create(['email' => 'ada@example.test']);
    Student::factory()->create(['user_id' => $ada->id, 'matric_no' => 'CSC/2026/0001']);
    $grace = User::factory()->create(['email' => 'grace@example.test']);
    Student::factory()->create(['user_id' => $grace->id, 'matric_no' => 'CSC/2026/0002']);
    User::factory()->create(['email' => 'staff@example.test']);

    $token = import_validation_token($registrar, [
        'Ada,Lovelace,new@example.test,08012345678,Female,2001-02-03,CSC,100,CSC/2026/0001,,,,',
        'Grace,Hopper,ada@example.test,08012345678,Female,2001-02-03,CSC,100,CSC/2026/0002,,,,',
        'Alan,Turing,staff@example.test,08012345678,Male,2001-02-03,CSC,100,,,,,',
    ]);

    $update = app(ImportService::class)->validateSheet($registrar, $token, 'update');
    $skip = app(ImportService::class)->validateSheet($registrar, $token, 'skip');
    $email_error = collect($update['errors'])->firstWhere('field', 'email');
    $matric_error = collect($update['errors'])->firstWhere('field', 'matric_number');

    expect(is_array($email_error) ? $email_error['message'] : null)->toBe(StudentRowValidator::MESSAGE_EMAIL_CHANGE)
        ->and(is_array($matric_error) ? $matric_error['message'] : null)->toBe(StudentRowValidator::MESSAGE_MATRIC_OWNER)
        ->and(collect($update['errors'])->pluck('message'))->toContain(StudentService::MESSAGE_EMAIL_TAKEN)
        ->and($update['duplicate_count'])->toBe(0)
        ->and($skip['duplicate_count'])->toBe(1)
        ->and(collect($skip['errors'])->pluck('message'))->not->toContain(StudentRowValidator::MESSAGE_EMAIL_CHANGE)
        ->and(Student::query()->count())->toBe(2);
});

it('shows the first 50 errors and ignores a tampered livewire column map', function () {
    $registrar = import_wizard_actor();
    $rows = [];

    for ($index = 0; $index < 51; $index++) {
        $rows[] = 'Ada,Lovelace,,08012345678,Female,2001-02-03,CSC,100,,,,,';
    }

    $token = import_validation_token($registrar, $rows);
    $report = app(ImportService::class)->validateSheet($registrar, $token, 'skip');

    expect($report['error_count'])->toBe(51)
        ->and($report['errors'])->toHaveCount(50)
        ->and($report['remaining_error_count'])->toBe(1);

    $valid = import_validation_token($registrar, [
        'Ada,Lovelace,ada-one@example.test,08012345678,Female,2001-02-03,CSC,100,,,,,',
        'Grace,Hopper,ada-two@example.test,08012345678,Female,2001-02-03,CSC,100,,,,,',
    ]);

    $page = Livewire::actingAs($registrar)
        ->test(ImportStudentsPage::class)
        ->set('token', $valid)
        ->set('step', 'validate')
        ->set('column_map.email', 0)
        ->assertSee('2 of 2 rows can be imported.')
        ->assertDontSee(StudentService::MESSAGE_EMAIL);

    $state = json_encode($page->instance()->all());

    expect($state)->toBeString()->and($state)->not->toContain('Lovelace');
});

it('returns not found for another users sheet and refuses a role without student records', function () {
    $owner = import_wizard_actor();
    $other = import_wizard_actor();
    $token = import_validation_token($owner, [import_validation_row()]);

    expect(fn () => app(ImportService::class)->validateSheet($other, $token, 'skip'))
        ->toThrow(ImportNotFoundException::class);

    expect(fn () => app(ImportService::class)->validateSheet(import_wizard_actor(Role::FacultyAdmin), $token, 'skip'))
        ->toThrow(AuthorizationException::class);
});
