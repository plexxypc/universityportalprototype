<?php

declare(strict_types=1);

use App\Enums\ApplicantStatus;
use App\Enums\AttendanceStatus;
use App\Enums\EmailStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\RegistrationStatus;
use App\Enums\ResultStatus;
use App\Enums\StudentStatus;
use App\Support\StatusTone;

it('maps every status enum to the design tone', function (UnitEnum $status, string $tone) {
    expect(StatusTone::for($status))->toBe($tone)
        ->and(StatusTone::classes($tone))->not->toBe('');
})->with([
    [InvoiceStatus::Unpaid, StatusTone::WARNING],
    [InvoiceStatus::PartPaid, StatusTone::INFO],
    [InvoiceStatus::Paid, StatusTone::SUCCESS],
    [InvoiceStatus::Cancelled, StatusTone::NEUTRAL],
    [PaymentStatus::Pending, StatusTone::WARNING],
    [PaymentStatus::Successful, StatusTone::SUCCESS],
    [PaymentStatus::Failed, StatusTone::DANGER],
    [PaymentStatus::Cancelled, StatusTone::NEUTRAL],
    [PaymentStatus::Expired, StatusTone::NEUTRAL],
    [PaymentStatus::Reversed, StatusTone::DANGER],
    [RegistrationStatus::Draft, StatusTone::NEUTRAL],
    [RegistrationStatus::Submitted, StatusTone::INFO],
    [RegistrationStatus::Approved, StatusTone::SUCCESS],
    [RegistrationStatus::Rejected, StatusTone::DANGER],
    [ResultStatus::Draft, StatusTone::NEUTRAL],
    [ResultStatus::Submitted, StatusTone::INFO],
    [ResultStatus::Approved, StatusTone::WARNING],
    [ResultStatus::Published, StatusTone::SUCCESS],
    [StudentStatus::Active, StatusTone::SUCCESS],
    [StudentStatus::Suspended, StatusTone::DANGER],
    [StudentStatus::Deferred, StatusTone::WARNING],
    [StudentStatus::Graduated, StatusTone::INFO],
    [StudentStatus::Withdrawn, StatusTone::NEUTRAL],
    [ApplicantStatus::Applied, StatusTone::NEUTRAL],
    [ApplicantStatus::UnderReview, StatusTone::INFO],
    [ApplicantStatus::Admitted, StatusTone::SUCCESS],
    [ApplicantStatus::Rejected, StatusTone::DANGER],
    [ApplicantStatus::Converted, StatusTone::SUCCESS],
    [EmailStatus::Queued, StatusTone::WARNING],
    [EmailStatus::Sent, StatusTone::SUCCESS],
    [EmailStatus::Failed, StatusTone::DANGER],
    [AttendanceStatus::Present, StatusTone::SUCCESS],
    [AttendanceStatus::Absent, StatusTone::DANGER],
    [AttendanceStatus::Late, StatusTone::WARNING],
    [AttendanceStatus::Excused, StatusTone::INFO],
]);
