<?php

declare(strict_types=1);

use App\Models\CourseRegistration;
use App\Models\CourseRegistrationItem;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('rejects a second registration for the same student and semester', function () {
    $registration = CourseRegistration::factory()->create();

    expect(fn () => CourseRegistration::factory()->create([
        'student_id' => $registration->student_id,
        'semester_id' => $registration->semester_id,
    ]))->toThrow(QueryException::class);
});

it('allows the same student to register in another semester', function () {
    $registration = CourseRegistration::factory()->create();
    $semester = Semester::factory()->create();

    CourseRegistration::factory()->create([
        'student_id' => $registration->student_id,
        'semester_id' => $semester->id,
    ]);

    expect(DB::table('course_registrations')->count())->toBe(2);
});

it('rejects a duplicate course on one registration', function () {
    $item = CourseRegistrationItem::factory()->create();

    expect(fn () => CourseRegistrationItem::factory()->create([
        'course_registration_id' => $item->course_registration_id,
        'course_id' => $item->course_id,
    ]))->toThrow(QueryException::class);
});

it('rejects a negative registration total', function () {
    $registration = CourseRegistration::factory()->create();

    expect(fn () => DB::table('course_registrations')->where('id', $registration->id)->update([
        'total_units' => -1,
    ]))->toThrow(QueryException::class);
});

it('rejects an item with no credit units', function () {
    expect(fn () => CourseRegistrationItem::factory()->create(['credit_units' => 0]))
        ->toThrow(QueryException::class);
});

it('blocks deleting a student who has a registration', function () {
    $registration = CourseRegistration::factory()->create();

    expect(fn () => DB::table('students')->where('id', $registration->student_id)->delete())
        ->toThrow(QueryException::class);
});

it('blocks deleting a course that is on a registration item', function () {
    $item = CourseRegistrationItem::factory()->create();

    expect(fn () => DB::table('courses')->where('id', $item->course_id)->delete())
        ->toThrow(QueryException::class);
});

it('blocks deleting a registration that still has items', function () {
    $item = CourseRegistrationItem::factory()->create();

    expect(fn () => DB::table('course_registrations')->where('id', $item->course_registration_id)->delete())
        ->toThrow(QueryException::class);
});

it('blocks deleting a user who decided a registration', function () {
    $user = User::factory()->create();
    CourseRegistration::factory()->create(['decided_by' => $user->id]);

    expect(fn () => DB::table('users')->where('id', $user->id)->delete())
        ->toThrow(QueryException::class);
});

it('restricts every registration foreign key', function () {
    $rows = DB::select(
        'select CONSTRAINT_NAME as constraint_name, DELETE_RULE as delete_rule
         from information_schema.referential_constraints
         where CONSTRAINT_SCHEMA = ? and TABLE_NAME in (?, ?)',
        [
            DB::getDatabaseName(),
            'course_registrations',
            'course_registration_items',
        ],
    );

    $delete_rules = collect($rows)->mapWithKeys(
        fn (object $row): array => [$row->constraint_name => $row->delete_rule],
    );

    expect($delete_rules)->toHaveCount(5)
        ->and($delete_rules->every(fn (string $rule): bool => $rule === 'RESTRICT'))->toBeTrue();
});

it('has the registration tables', function () {
    expect(Schema::hasTable('course_registrations'))->toBeTrue()
        ->and(Schema::hasTable('course_registration_items'))->toBeTrue()
        ->and(Schema::hasColumns('course_registrations', [
            'student_id',
            'semester_id',
            'status',
            'total_units',
            'submitted_at',
            'decided_at',
            'decided_by',
            'rejection_reason',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('course_registration_items', [
            'course_registration_id',
            'course_id',
            'credit_units',
        ]))->toBeTrue();
});
