<?php

declare(strict_types=1);

use App\Enums\ApplicantStatus;
use App\Enums\DocumentOwner;
use App\Enums\Gender;
use App\Enums\ImportTarget;
use App\Models\Applicant;
use App\Models\Document;
use App\Models\Guardian;
use App\Models\ImportBatch;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('rejects a duplicate matric number', function () {
    Student::factory()->create(['matric_no' => 'CSC/2026/0001']);

    expect(fn () => Student::factory()->create(['matric_no' => 'CSC/2026/0001']))
        ->toThrow(QueryException::class);
});

it('rejects a duplicate applicant email', function () {
    Applicant::factory()->create(['email' => 'ada@example.test']);

    expect(fn () => Applicant::factory()->create(['email' => 'ada@example.test']))
        ->toThrow(QueryException::class);
});

it('allows an applicant email that already belongs to a user', function () {
    $user = User::factory()->create(['email' => 'ada@example.test']);
    $applicant = Applicant::factory()->create(['email' => 'ada@example.test']);

    expect($applicant->email)->toBe($user->email);
});

it('rejects a student level outside 100 to 600', function () {
    expect(fn () => Student::factory()->create(['level' => 150]))
        ->toThrow(QueryException::class);

    expect(fn () => Student::factory()->create(['level' => 700]))
        ->toThrow(QueryException::class);
});

it('rejects an applicant status outside the design labels', function () {
    $applicant = Applicant::factory()->create();

    expect(fn () => DB::table('applicants')->insert([
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'email' => 'ada-status@example.test',
        'programme_id' => $applicant->programme_id,
        'level' => 100,
        'status' => 'Pending',
        'source' => 'manual',
    ]))->toThrow(QueryException::class);
});

it('stores the under review applicant status', function () {
    $applicant = Applicant::factory()->create([
        'status' => ApplicantStatus::UnderReview,
    ]);

    expect(DB::table('applicants')->where('id', $applicant->id)->value('status'))
        ->toBe('Under review');
});

it('stores other as a gender and rejects an unknown gender', function () {
    $student = Student::factory()->create(['gender' => Gender::Other]);

    expect(DB::table('students')->where('id', $student->id)->value('gender'))->toBe('Other');

    expect(fn () => DB::table('students')->insert([
        'user_id' => User::factory()->create()->id,
        'matric_no' => 'CSC/2026/0099',
        'programme_id' => $student->programme_id,
        'level' => 100,
        'entry_session_id' => $student->entry_session_id,
        'status' => 'Active',
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'gender' => 'Unknown',
    ]))->toThrow(QueryException::class);
});

it('allows many students with no applicant and rejects a second conversion', function () {
    Student::factory()->create(['applicant_id' => null]);
    Student::factory()->create(['applicant_id' => null]);
    $applicant = Applicant::factory()->create();
    Student::factory()->create(['applicant_id' => $applicant->id]);

    expect(DB::table('students')->whereNull('applicant_id')->count())->toBe(2);

    expect(fn () => Student::factory()->create(['applicant_id' => $applicant->id]))
        ->toThrow(QueryException::class);
});

it('accepts a staff import target and rejects a manual batch source', function () {
    $batch = ImportBatch::factory()->create(['target' => ImportTarget::Staff]);

    expect(DB::table('import_batches')->where('id', $batch->id)->value('target'))->toBe('Staff');

    expect(fn () => DB::table('import_batches')->insert([
        'user_id' => User::factory()->create()->id,
        'source' => 'manual',
        'target' => 'Student',
        'status' => 'Processing',
    ]))->toThrow(QueryException::class);
});

it('rejects processed rows above the total', function () {
    expect(fn () => ImportBatch::factory()->create([
        'total_rows' => 1,
        'processed_rows' => 2,
    ]))->toThrow(QueryException::class);
});

it('blocks deleting a user who has a student', function () {
    $student = Student::factory()->create();

    expect(fn () => DB::table('users')->where('id', $student->user_id)->delete())
        ->toThrow(QueryException::class);
});

it('blocks deleting an applicant who has been converted', function () {
    $applicant = Applicant::factory()->create();
    Student::factory()->create(['applicant_id' => $applicant->id]);

    expect(fn () => DB::table('applicants')->where('id', $applicant->id)->delete())
        ->toThrow(QueryException::class);
});

it('deletes guardians when the student is deleted', function () {
    $guardian = Guardian::factory()->create();

    DB::table('students')->where('id', $guardian->student_id)->delete();

    expect(DB::table('guardians')->where('id', $guardian->id)->exists())->toBeFalse()
        ->and(DB::table('students')->where('id', $guardian->student_id)->exists())->toBeFalse();
});

it('leaves a document in place when its student is deleted', function () {
    $student = Student::factory()->create();
    $document = Document::factory()->create([
        'owner_type' => DocumentOwner::Student,
        'owner_id' => $student->id,
    ]);

    DB::table('students')->where('id', $student->id)->delete();

    expect(DB::table('documents')->where('id', $document->id)->exists())->toBeTrue();
});

it('cascades only the guardian foreign key', function () {
    $rows = DB::select(
        'select CONSTRAINT_NAME as constraint_name, DELETE_RULE as delete_rule
         from information_schema.referential_constraints
         where CONSTRAINT_SCHEMA = ? and TABLE_NAME in (?, ?, ?, ?)',
        [
            DB::getDatabaseName(),
            'import_batches',
            'applicants',
            'students',
            'guardians',
        ],
    );

    $delete_rules = collect($rows)->mapWithKeys(
        fn (object $row): array => [$row->constraint_name => $row->delete_rule],
    );

    expect($delete_rules)->toHaveCount(10)
        ->and($delete_rules->get('guardians_student_id_foreign'))->toBe('CASCADE')
        ->and($delete_rules->except('guardians_student_id_foreign')->every(
            fn (string $rule): bool => $rule === 'RESTRICT',
        ))->toBeTrue();

    $document_keys = DB::select(
        'select CONSTRAINT_NAME as constraint_name
         from information_schema.referential_constraints
         where CONSTRAINT_SCHEMA = ? and TABLE_NAME = ?',
        [DB::getDatabaseName(), 'documents'],
    );

    expect($document_keys)->toHaveCount(0);
});

it('has the people tables', function () {
    expect(Schema::hasTable('import_batches'))->toBeTrue()
        ->and(Schema::hasTable('applicants'))->toBeTrue()
        ->and(Schema::hasTable('students'))->toBeTrue()
        ->and(Schema::hasTable('guardians'))->toBeTrue()
        ->and(Schema::hasTable('documents'))->toBeTrue()
        ->and(Schema::hasColumns('import_batches', [
            'total_rows',
            'processed_rows',
            'original_name',
            'failure_report_path',
            'completed_at',
        ]))->toBeTrue();
});
