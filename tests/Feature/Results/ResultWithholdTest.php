<?php

declare(strict_types=1);

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Models\AcademicSession;
use App\Models\CourseRegistration;
use App\Models\CourseRegistrationItem;
use App\Models\InstitutionSetting;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Result;
use App\Models\Semester;
use App\Services\ResultService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ResultFixture;

uses(RefreshDatabase::class);

it('hides a withheld session and the CGPA from the student', function () {
    $fixture = ResultFixture::make();
    $published = Result::factory()->published()->create([
        'student_id' => $fixture->student->id,
        'course_id' => $fixture->course->id,
        'semester_id' => $fixture->semester->id,
        'grading_scheme_id' => $fixture->scheme->id,
        'scheme_version' => $fixture->scheme->version,
        'total' => '70.00',
        'grade' => 'A',
        'points' => '5.00',
        'entered_by' => $fixture->lecturer->id,
    ]);
    $other_session = AcademicSession::factory()->create(['name' => '2025/2026']);
    $other_semester = Semester::factory()->create([
        'session_id' => $other_session->id,
        'name' => 'First',
        'semester_no' => 1,
        'is_active' => false,
    ]);
    $registration = CourseRegistration::factory()->approved()->create([
        'student_id' => $fixture->student->id,
        'semester_id' => $other_semester->id,
        'total_units' => 3,
    ]);
    CourseRegistrationItem::factory()->create([
        'course_registration_id' => $registration->id,
        'course_id' => $fixture->course->id,
        'credit_units' => 3,
    ]);
    $clear = Result::factory()->published()->create([
        'student_id' => $fixture->student->id,
        'course_id' => $fixture->course->id,
        'semester_id' => $other_semester->id,
        'grading_scheme_id' => $fixture->scheme->id,
        'scheme_version' => $fixture->scheme->version,
        'total' => '70.00',
        'grade' => 'A',
        'points' => '5.00',
        'entered_by' => $fixture->lecturer->id,
    ]);
    $invoice = Invoice::factory()->create([
        'student_id' => $fixture->student->id,
        'session_id' => $fixture->session->id,
        'total_kobo' => 100_000,
        'paid_kobo' => 0,
        'adjustments_kobo' => 0,
        'status' => InvoiceStatus::Unpaid,
    ]);
    Payment::factory()->create([
        'invoice_id' => $invoice->id,
        'student_id' => $fixture->student->id,
        'amount_kobo' => 100_000,
        'status' => PaymentStatus::Pending,
    ]);
    $service = app(ResultService::class);
    app(SettingsService::class)->forgetCache();
    $open = $service->statement($fixture->studentUser, $fixture->student);

    expect($open['cgpa'])->toBe('5.00')
        ->and($open['cumulative_message'])->toBeNull();

    InstitutionSetting::factory()->create(['withhold_results_for_debt' => true]);
    app(SettingsService::class)->forgetCache();
    $student = $service->statement($fixture->studentUser, $fixture->student);
    $staff = $service->statement($fixture->examOfficer, $fixture->student);
    $hidden = collect($student['semesters'])->firstWhere('semester_id', $fixture->semester->id);
    $shown = collect($student['semesters'])->firstWhere('semester_id', $other_semester->id);
    $student_ids = Result::query()->visibleTo($fixture->studentUser)->pluck('id');
    $staff_ids = Result::query()->visibleTo($fixture->examOfficer)->pluck('id');

    expect($student['cgpa'])->toBeNull()
        ->and($student['classification'])->toBeNull()
        ->and($student['units_attempted'])->toBeNull()
        ->and($student['units_earned'])->toBeNull()
        ->and($student['cumulative_message'])->toBe(ResultService::MESSAGE_CGPA_WITHHELD)
        ->and($hidden['withheld'] ?? null)->toBeTrue()
        ->and($hidden['courses'] ?? null)->toBe([])
        ->and($hidden['message'] ?? null)->toBe(ResultService::MESSAGE_FEES)
        ->and($hidden['fees_link'] ?? null)->toBe(ResultService::FEES_LINK)
        ->and($shown['gpa'] ?? null)->toBe('5.00')
        ->and($shown['courses'] ?? [])->not->toBeEmpty()
        ->and($staff['cgpa'])->toBe('5.00')
        ->and($staff['classification'])->not->toBeNull()
        ->and($student_ids)->not->toContain($published->id)
        ->and($student_ids)->toContain($clear->id)
        ->and($staff_ids)->toContain($published->id)
        ->and($staff_ids)->toContain($clear->id);

    $invoice->paid_kobo = 100_000;
    $invoice->status = InvoiceStatus::Paid;
    $invoice->save();
    $settled = $service->statement($fixture->studentUser, $fixture->student);

    expect($settled['cgpa'])->toBe('5.00')
        ->and($settled['cumulative_message'])->toBeNull()
        ->and(Result::query()->visibleTo($fixture->studentUser)->pluck('id'))->toContain($published->id);
});
