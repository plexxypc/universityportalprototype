<?php

declare(strict_types=1);

use App\Enums\EmailTemplate;
use App\Models\AcademicSession;
use App\Models\Applicant;
use App\Models\AuditLog;
use App\Models\EmailOutbox;
use App\Models\Programme;
use App\Models\Student;
use App\Models\User;
use App\Services\ApplicantService;
use App\Services\CredentialService;
use App\Services\ImportService;
use App\Support\Import\StagedImportStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake('local');
    AcademicSession::factory()->current()->create(['name' => '2025/2026']);
    Programme::factory()->create(['code' => 'CSC', 'duration_years' => 4]);
});

/**
 * Remember each credentials password as the row is stored.
 *
 * The sync mailer may clear the secret after send. The list is not printed.
 *
 * @param  list<string>  $passwords
 */
function journey_capture_passwords(array &$passwords): void
{
    EmailOutbox::created(function (EmailOutbox $row) use (&$passwords): void {
        if ($row->template !== EmailTemplate::Credentials->value) {
            return;
        }

        $secrets = $row->secrets;
        $password = is_array($secrets) ? ($secrets['temporary_password'] ?? '') : '';

        if (is_string($password) && $password !== '') {
            $passwords[] = $password;
        }
    });
}

/**
 * Stage one student row and commit it with the welcome email.
 */
function journey_import(User $actor): Student
{
    $header = 'first_name,last_name,email,phone,gender,date_of_birth,programme_code,level,matric_number,guardian_name,guardian_phone,guardian_email,guardian_relationship';
    $token = app(ImportService::class)->stageUpload($actor, UploadedFile::fake()->createWithContent(
        'students.csv',
        $header."\nAda,Lovelace,ada.journey@example.test,08012345678,Female,03/02/2001,CSC,100,,,,,\n",
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
    app(ImportService::class)->commitChunk($actor, $token, 'skip', 'now', Request::create('/staff/students/import', 'POST'));
    $student = Student::query()->with('user')->first();

    if (! $student instanceof Student) {
        throw new RuntimeException('imported student missing');
    }

    return $student;
}

it('walks journey 1 from import through a forced password change and a resend after expiry', function () {
    $actor = import_wizard_actor();
    $passwords = [];
    journey_capture_passwords($passwords);
    $student = journey_import($actor);
    $account = $student->user;
    $credentials = EmailOutbox::query()->where('template', EmailTemplate::Credentials->value)->first();
    $has_secret = isset($passwords[0]) && $passwords[0] !== '';

    expect($account)->not->toBeNull()
        ->and($credentials)->not->toBeNull()
        ->and($has_secret)->toBeTrue()
        ->and($student->matric_no)->not->toBe('');

    if (! $account instanceof User || ! $has_secret) {
        return;
    }

    $signed_in = $this->post('/login', [
        'identifier' => $student->matric_no,
        'password' => $passwords[0],
    ]);
    $signed_location = (string) $signed_in->headers->get('Location');
    $home_while_forced = (string) $this->get('/student')->headers->get('Location');

    expect(str_ends_with($signed_location, '/student'))->toBeTrue()
        ->and(auth()->check())->toBeTrue()
        ->and(str_ends_with($home_while_forced, '/change-password'))->toBeTrue();

    $changed = $this->post(route('password.update'), [
        'current_password' => $passwords[0],
        'password' => 'River-lamp-29',
        'password_confirmation' => 'River-lamp-29',
    ]);
    $changed_location = (string) $changed->headers->get('Location');

    expect($changed->status())->toBe(302)
        ->and(str_ends_with($changed_location, '/student'))->toBeTrue();

    $this->get('/student')->assertOk()->assertSee('Student portal');

    app(CredentialService::class)->resend($actor, $account->fresh() ?? $account, $student->matric_no, Request::create('/staff/students', 'POST'), true);
    $account->refresh();
    $account->forceFill(['temp_password_expires_at' => now()->subMinute()])->save();
    auth()->logout();
    $replacement = $passwords[1] ?? '';
    $blocked = $this->post('/login', [
        'identifier' => $student->matric_no,
        'password' => $replacement,
    ]);
    $blocked_location = (string) $blocked->headers->get('Location');

    expect($replacement !== '')->toBeTrue()
        ->and(str_ends_with($blocked_location, '/student'))->toBeFalse()
        ->and(auth()->check())->toBeFalse();

    DB::table('cache')->where('key', 'like', '%credentials-resend%')->delete();
    app(CredentialService::class)->resend($actor, $account->fresh() ?? $account, $student->matric_no, Request::create('/staff/students', 'POST'), true);
    $renewed = $passwords[2] ?? '';
    $allowed = $this->post('/login', [
        'identifier' => $student->matric_no,
        'password' => $renewed,
    ]);
    $allowed_location = (string) $allowed->headers->get('Location');

    expect($renewed !== '')->toBeTrue()
        ->and(str_ends_with($allowed_location, '/student'))->toBeTrue()
        ->and(auth()->check())->toBeTrue();
});

it('keeps a converted applicant password out of the admission mail, the audits, and the log', function () {
    $actor = import_wizard_actor();
    $programme = Programme::query()->where('code', 'CSC')->first();
    $passwords = [];
    journey_capture_passwords($passwords);
    $logged = '';
    Log::listen(function (MessageLogged $event) use (&$logged): void {
        $encoded = json_encode([$event->message, $event->context]);

        if (is_string($encoded)) {
            $logged .= $encoded;
        }
    });

    if (! $programme instanceof Programme) {
        throw new RuntimeException('programme missing');
    }

    $service = app(ApplicantService::class);
    $applicant = $service->create($actor, [
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'other_names' => 'Mary',
        'email' => 'ada.convert.sweep@example.test',
        'phone' => '08012345678',
        'gender' => 'Female',
        'date_of_birth' => '2004-05-01',
        'state_of_origin' => 'Lagos',
        'address' => '12 Garden Close',
        'programme_id' => $programme->id,
        'level' => 100,
        'entry_session_id' => null,
    ], Request::create('/staff/applicants', 'POST', server: ['REMOTE_ADDR' => '203.0.113.20']));
    $service->convert($actor, (int) $applicant->id, Request::create('/staff/applicants', 'POST', server: ['REMOTE_ADDR' => '203.0.113.20']));

    $plain = $passwords[0] ?? '';
    $admission = EmailOutbox::query()->where('template', EmailTemplate::Admission->value)->first();
    $audit_text = '';

    foreach (AuditLog::query()->get(['before', 'after']) as $row) {
        $audit_text .= json_encode($row->before).json_encode($row->after);
    }

    $jobs = '';

    foreach (DB::table('jobs')->get() as $job) {
        $jobs .= (string) $job->payload;
    }

    $admission_text = (string) $admission?->body_html.(string) $admission?->body_text;
    $haystack = $admission_text.$audit_text.$logged.$jobs;
    $leaked = $plain !== '' && str_contains($haystack, $plain);

    expect($plain !== '')->toBeTrue()
        ->and($leaked)->toBeFalse()
        ->and($admission?->secrets)->toBeNull()
        ->and($applicant->refresh()->status->value)->toBe('Converted')
        ->and(Applicant::query()->count())->toBe(1);
});
