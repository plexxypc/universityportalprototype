<?php

declare(strict_types=1);

use App\Enums\EmailStatus;
use App\Enums\EmailTemplate;
use App\Enums\Role;
use App\Exceptions\ImportNotFoundException;
use App\Filament\Staff\Pages\ImportStudentsPage;
use App\Filament\Staff\Pages\StudentsPage;
use App\Models\AcademicSession;
use App\Models\AuditLog;
use App\Models\EmailOutbox;
use App\Models\Guardian;
use App\Models\ImportBatch;
use App\Models\Programme;
use App\Models\Student;
use App\Models\User;
use App\Services\ImportService;
use App\Support\Import\FailedRowCsv;
use App\Support\Import\StagedImportStore;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
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
function import_commit_token(User $actor, array $rows): string
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

function import_commit(User $actor, string $token, string $duplicate_mode = 'skip', string $send_mode = 'later'): array
{
    return app(ImportService::class)->commitChunk($actor, $token, $duplicate_mode, $send_mode, Request::create('/'));
}

it('creates a student from the staged file and leaves mail queued', function () {
    $registrar = import_wizard_actor();
    $token = import_commit_token($registrar, [
        'Ada,Lovelace,ada@example.test,08012345678,Female,03/02/2001,CSC,100,,Ada Parent,08011111111,parent@example.test,Parent',
    ]);

    $queued = import_commit($registrar, $token, 'skip', 'now');
    $student = Student::query()->first();
    $outbox = EmailOutbox::query()->first();

    expect($queued['finished'])->toBeTrue()
        ->and($queued['created_count'])->toBe(1)
        ->and($student)->not->toBeNull()
        ->and($student?->user?->phone)->toBe('+2348012345678')
        ->and($student?->user?->must_change_password)->toBeTrue()
        ->and($student?->date_of_birth?->toDateString())->toBe('2001-02-03')
        ->and($student?->import_batch_id)->toBe($queued['batch_id'])
        ->and(Guardian::query()->first()?->phone)->toBe('+2348011111111')
        ->and($outbox?->status)->toBe(EmailStatus::Queued)
        ->and($outbox?->template)->toBe(EmailTemplate::Credentials->value)
        ->and(Storage::disk('local')->exists(StagedImportStore::DIRECTORY.'/'.$token.'.json'))->toBeFalse();

    $later_actor = import_wizard_actor();
    $later = import_commit_token($later_actor, [
        'Grace,Hopper,grace@example.test,08012345678,Female,2001-02-03,CSC,100,,,,,',
    ]);
    $sent_later = import_commit($later_actor, $later, 'skip', 'later');
    $grace = User::query()->where('email', 'grace@example.test')->first();

    expect($grace?->must_change_password)->toBeTrue()
        ->and($grace?->temp_password_expires_at)->not->toBeNull()
        ->and(EmailOutbox::query()->where('user_id', $grace?->id)->exists())->toBeFalse()
        ->and($sent_later['credentials_needed'])->toBe(1);

    Livewire::withQueryParams([
        'needs_credentials' => '1',
        'import_batch' => (string) $sent_later['batch_id'],
    ])->actingAs($later_actor)
        ->test(StudentsPage::class)
        ->assertCanSeeTableRecords([Student::query()->where('import_batch_id', $sent_later['batch_id'])->first()])
        ->assertCanNotSeeTableRecords([$student]);
});

it('refuses the log mailer before the first insert', function () {
    $this->app['env'] = 'production';
    config(['mail.default' => 'log']);
    $registrar = import_wizard_actor();
    $token = import_commit_token($registrar, [
        'Ada,Lovelace,ada@example.test,08012345678,Female,2001-02-03,CSC,100,,,,,',
    ]);

    expect(fn () => import_commit($registrar, $token, 'skip', 'now'))
        ->toThrow(ValidationException::class, ImportService::MESSAGE_LOG_MAILER);
    expect(Student::query()->count())->toBe(0)
        ->and(ImportBatch::query()->count())->toBe(0);

    $later = import_commit($registrar, $token, 'skip', 'later');

    expect($later['created_count'])->toBe(1)
        ->and(EmailOutbox::query()->count())->toBe(0);
});

it('skips or updates a database duplicate and names an in-file copy', function () {
    $registrar = import_wizard_actor();
    $existing_user = User::factory()->create(['email' => 'ada@example.test']);
    $existing = Student::factory()->create([
        'user_id' => $existing_user->id,
        'matric_no' => 'CSC/2026/0001',
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
    ]);
    $password = $existing_user->password;

    $skip = import_commit_token($registrar, [
        'Ada,Byron,ada@example.test,08012345678,Female,2001-02-03,CSC,100,,,,,',
    ]);
    $skipped = import_commit($registrar, $skip, 'skip', 'later');

    expect($skipped['skipped_count'])->toBe(1)
        ->and($existing->refresh()->last_name)->toBe('Lovelace');

    $update = import_commit_token($registrar, [
        'Ada,Byron,ada@example.test,08012345678,Female,2001-02-03,CSC,100,,Ada Parent,08022222222,parent@example.test,Parent',
    ]);
    $updated = import_commit($registrar, $update, 'update', 'later');

    expect($updated['updated_count'])->toBe(1)
        ->and($existing->refresh()->last_name)->toBe('Byron')
        ->and($existing->matric_no)->toBe('CSC/2026/0001')
        ->and($existing->user?->email)->toBe('ada@example.test')
        ->and($existing->user?->password)->toBe($password)
        ->and(Guardian::query()->where('student_id', $existing->id)->first()?->phone)->toBe('+2348022222222')
        ->and(EmailOutbox::query()->where('template', EmailTemplate::Credentials->value)->count())->toBe(0);

    $copies = import_commit_token($registrar, [
        'Ada,Lovelace,copy@example.test,08012345678,Female,2001-02-03,CSC,100,,,,,',
        '=Ada,Hopper,copy@example.test,08012345678,Female,2001-02-03,CSC,100,,,,,',
    ]);
    $copied = import_commit($registrar, $copies, 'skip', 'later');
    $csv = Storage::disk('local')->get(FailedRowCsv::DIRECTORY.'/'.$copies.'.csv');

    expect($copied['created_count'])->toBe(1)
        ->and($copied['failed_count'])->toBe(1)
        ->and(Student::query()->whereHas('user', fn ($query) => $query->where('email', 'copy@example.test'))->count())->toBe(1)
        ->and($csv)->toContain('already used on row 1')
        ->and($csv)->toContain("'=Ada");
});

it('resumes from processed rows and will not run two commits at once', function () {
    $registrar = import_wizard_actor();
    $rows = [];

    for ($index = 0; $index < 26; $index++) {
        $rows[] = "Ada,Lovelace,ada{$index}@example.test,08012345678,Female,2001-02-03,CSC,100,,,,,";
    }

    $token = import_commit_token($registrar, $rows);
    $lock = Cache::lock('import-commit:'.$token, 30);
    expect($lock->get())->toBeTrue();
    expect(fn () => import_commit($registrar, $token))->toThrow(ValidationException::class, ImportService::MESSAGE_BUSY);
    expect(Student::query()->count())->toBe(0);
    $lock->release();

    $first = import_commit($registrar, $token);
    $second = import_commit($registrar, $token);

    expect($first['processed_rows'])->toBe(25)
        ->and($first['finished'])->toBeFalse()
        ->and($second['processed_rows'])->toBe(26)
        ->and($second['finished'])->toBeTrue()
        ->and($second['created_count'])->toBe(26)
        ->and(Student::query()->count())->toBe(26);

    $batch = ImportBatch::query()->find($second['batch_id']);
    $audit = AuditLog::query()->where('action', ImportService::ACTION_COMPLETED)->first();
    $after = json_encode($audit?->after);

    expect($batch?->processed_rows)->toBe(26)
        ->and($after)->toBeString()
        ->and($after)->not->toContain('ada0@example.test')
        ->and($after)->toContain('"created_count":26');
});

it('returns the same not found result for a missing, forged, or foreign token', function () {
    $owner = import_wizard_actor();
    $other = import_wizard_actor();
    $token = import_commit_token($owner, [
        'Ada,Lovelace,ada@example.test,08012345678,Female,2001-02-03,CSC,100,,,,,',
    ]);

    expect(fn () => import_commit($other, $token))->toThrow(ImportNotFoundException::class, ImportNotFoundException::MESSAGE);
    expect(fn () => import_commit($owner, str_repeat('ab', 32)))->toThrow(ImportNotFoundException::class, ImportNotFoundException::MESSAGE);
    expect(fn () => import_commit(import_wizard_actor(Role::FacultyAdmin), $token))->toThrow(AuthorizationException::class);

    Livewire::actingAs($other)
        ->test(ImportStudentsPage::class)
        ->set('token', $token)
        ->call('commitChunk')
        ->assertNotFound();

    expect(Student::query()->count())->toBe(0);
});
