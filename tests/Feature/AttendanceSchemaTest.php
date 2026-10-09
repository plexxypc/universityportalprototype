<?php

declare(strict_types=1);

use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\ExamTimetable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('rejects a second attendance mark for the same session and student', function () {
    $record = AttendanceRecord::factory()->create();

    expect(fn () => AttendanceRecord::factory()->create([
        'attendance_session_id' => $record->attendance_session_id,
        'student_id' => $record->student_id,
    ]))->toThrow(QueryException::class);
});

it('allows the same student to be marked in another session', function () {
    $record = AttendanceRecord::factory()->create();
    $session = AttendanceSession::factory()->create();

    AttendanceRecord::factory()->create([
        'attendance_session_id' => $session->id,
        'student_id' => $record->student_id,
    ]);

    expect(DB::table('attendance_records')->count())->toBe(2);
});

it('rejects a blank attendance topic', function () {
    expect(fn () => AttendanceSession::factory()->create(['topic' => '   ']))
        ->toThrow(QueryException::class);
});

it('rejects an attendance status outside the four marks', function () {
    $record = AttendanceRecord::factory()->create();

    expect(fn () => DB::table('attendance_records')->where('id', $record->id)->update([
        'status' => 'Missing',
    ]))->toThrow(QueryException::class);
});

it('rejects an exam that does not end after it starts', function (string $start_time, string $end_time) {
    expect(fn () => ExamTimetable::factory()->create([
        'start_time' => $start_time,
        'end_time' => $end_time,
    ]))->toThrow(QueryException::class);
})->with([
    'end before start' => ['10:00:00', '09:00:00'],
    'end equal to start' => ['10:00:00', '10:00:00'],
]);

it('stores an exam whose end is after its start', function () {
    $exam = ExamTimetable::factory()->create([
        'start_time' => '09:00:00',
        'end_time' => '12:00:00',
    ]);

    expect($exam->start_time)->toBe('09:00:00')
        ->and($exam->end_time)->toBe('12:00:00');
});

it('rejects a blank exam venue', function () {
    expect(fn () => ExamTimetable::factory()->create(['venue' => '   ']))
        ->toThrow(QueryException::class);
});

it('blocks deleting a session that still has marks', function () {
    $record = AttendanceRecord::factory()->create();

    expect(fn () => DB::table('attendance_sessions')->where('id', $record->attendance_session_id)->delete())
        ->toThrow(QueryException::class);
});

it('blocks deleting a student who has an attendance mark', function () {
    $record = AttendanceRecord::factory()->create();

    expect(fn () => DB::table('students')->where('id', $record->student_id)->delete())
        ->toThrow(QueryException::class);
});

it('restricts every attendance and exam foreign key', function () {
    $rows = DB::select(
        'select CONSTRAINT_NAME as constraint_name, DELETE_RULE as delete_rule
         from information_schema.referential_constraints
         where CONSTRAINT_SCHEMA = ? and TABLE_NAME in (?, ?, ?)',
        [
            DB::getDatabaseName(),
            'attendance_sessions',
            'attendance_records',
            'exam_timetable',
        ],
    );

    $delete_rules = collect($rows)->mapWithKeys(
        fn (object $row): array => [$row->constraint_name => $row->delete_rule],
    );

    expect($delete_rules)->toHaveCount(7)
        ->and($delete_rules->every(fn (string $rule): bool => $rule === 'RESTRICT'))->toBeTrue();
});

it('has the attendance and exam tables', function () {
    expect(Schema::hasTable('attendance_sessions'))->toBeTrue()
        ->and(Schema::hasTable('attendance_records'))->toBeTrue()
        ->and(Schema::hasTable('exam_timetable'))->toBeTrue()
        ->and(Schema::hasColumns('attendance_sessions', [
            'course_id',
            'semester_id',
            'session_date',
            'topic',
            'created_by',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('attendance_records', [
            'attendance_session_id',
            'student_id',
            'status',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('exam_timetable', [
            'course_id',
            'semester_id',
            'exam_date',
            'start_time',
            'end_time',
            'venue',
            'notes',
            'published_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumn('exam_timetable', 'starts_at'))->toBeFalse()
        ->and(Schema::hasColumn('exam_timetable', 'ends_at'))->toBeFalse();
});
