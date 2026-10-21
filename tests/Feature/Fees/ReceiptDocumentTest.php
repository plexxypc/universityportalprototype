<?php

declare(strict_types=1);

use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Models\Payment;
use App\Models\Receipt;
use App\Models\RoleAssignment;
use App\Models\Student;
use App\Models\User;
use App\Services\ReceiptDocument;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(SettingsService::class)->forgetCache();
});

/**
 * A staff account with one role and no faculty or department scope.
 */
function receipt_role(Role $role): User
{
    $user = User::factory()->create();
    RoleAssignment::factory()->create([
        'user_id' => $user->id,
        'role' => $role,
        'faculty_id' => null,
        'department_id' => null,
    ]);

    return $user;
}

/**
 * @return array{user: User, payment: Payment, receipt: Receipt}
 */
function receipt_world(): array
{
    $receipt = Receipt::factory()->create();
    $receipt->load('payment.student.user');
    $payment = $receipt->payment;
    $user = $payment->student->user;

    return [
        'user' => $user,
        'payment' => $payment,
        'receipt' => $receipt,
    ];
}

it('shows a receipt to the owner and to staff with view scope, and the same 404 otherwise', function () {
    $world = receipt_world();
    $receipt = $world['receipt'];
    $bursar = receipt_role(Role::Bursar);
    $lecturer = receipt_role(Role::Lecturer);
    $other = User::factory()->create();
    Student::factory()->create(['user_id' => $other->id]);
    $failed = Receipt::factory()->create();
    $failed->load('payment.student.user');
    $failed->payment->status = PaymentStatus::Failed;
    $failed->payment->save();
    $failed_user = $failed->payment->student->user;

    $student_page = $this->actingAs($world['user'])->get('/student/fees/receipts/'.$receipt->id);
    $student_pdf = $this->actingAs($world['user'])->get('/student/fees/receipts/'.$receipt->id.'/pdf');
    $staff_page = $this->actingAs($bursar)->get('/staff/downloads/receipts/'.$receipt->id);
    $staff_pdf = $this->actingAs($bursar)->get('/staff/downloads/receipts/'.$receipt->id.'/pdf');
    $missing = $this->actingAs($world['user'])->get('/student/fees/receipts/999999');
    $forged = $this->actingAs($other)->get('/student/fees/receipts/'.$receipt->id);
    $not_successful = $this->actingAs($failed_user)->get('/student/fees/receipts/'.$failed->id);
    $lecturer_page = $this->actingAs($lecturer)->get('/staff/downloads/receipts/'.$receipt->id);
    $html = view('pdf.receipt', [
        'institution_name' => 'University Portal',
        'number' => 'RCT-2026-000001',
        'status' => 'Successful',
        'amount' => 'NGN 1.00',
        'student_name' => 'Chioma Adeyemi',
        'matric_no' => 'CSC/2026/0001',
    ])->render();

    expect($student_page->status())->toBe(200)
        ->and($student_pdf->status())->toBe(200)
        ->and($staff_page->status())->toBe(200)
        ->and($staff_pdf->status())->toBe(200)
        ->and(str_contains((string) $student_page->getContent(), 'Successful'))->toBeTrue()
        ->and(str_starts_with((string) $student_pdf->getContent(), '%PDF'))->toBeTrue()
        ->and((string) $student_pdf->headers->get('Content-Disposition'))->toContain('attachment')
        ->and($student_pdf->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($student_pdf->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and((string) $student_pdf->headers->get('Cache-Control'))->toContain('no-store')
        ->and($student_page->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and((string) $student_page->headers->get('Cache-Control'))->toContain('no-store')
        ->and($missing->status())->toBe(404)
        ->and($forged->status())->toBe(404)
        ->and($not_successful->status())->toBe(404)
        ->and($lecturer_page->status())->toBe(404)
        ->and($forged->getContent())->toBe($missing->getContent())
        ->and($not_successful->getContent())->toBe($missing->getContent())
        ->and($lecturer_page->getContent())->toBe($this->actingAs($lecturer)->get('/staff/downloads/receipts/999999')->getContent())
        ->and(str_contains((string) $forged->getContent(), (string) $receipt->number))->toBeFalse()
        ->and(str_contains($html, '<img'))->toBeFalse()
        ->and(str_contains($html, '<svg'))->toBeFalse()
        ->and(preg_match('/https?:/i', $html))->not->toBe(1);

    $bytes = app(ReceiptDocument::class)->bytes($receipt);

    expect(str_starts_with($bytes, '%PDF'))->toBeTrue()
        ->and(strlen($bytes))->toBeLessThan(80_000);
});

it('shows Reversed on a reversed payment receipt', function () {
    $world = receipt_world();
    $world['payment']->status = PaymentStatus::Reversed;
    $world['payment']->reversed_reason = 'The bank reversed this payment.';
    $world['payment']->reversed_by = $world['user']->id;
    $world['payment']->reversed_at = now();
    $world['payment']->save();

    $page = $this->actingAs($world['user'])->get('/student/fees/receipts/'.$world['receipt']->id);
    $pdf = $this->actingAs($world['user'])->get('/student/fees/receipts/'.$world['receipt']->id.'/pdf');
    $staff = $this->actingAs(receipt_role(Role::Bursar))->get('/staff/downloads/receipts/'.$world['receipt']->id);

    expect($page->status())->toBe(200)
        ->and(str_contains((string) $page->getContent(), 'Reversed'))->toBeTrue()
        ->and(str_contains((string) $staff->getContent(), 'Reversed'))->toBeTrue()
        ->and($pdf->status())->toBe(200)
        ->and(str_starts_with((string) $pdf->getContent(), '%PDF'))->toBeTrue();
});

it('limits receipt downloads', function () {
    $world = receipt_world();
    RateLimiter::clear(ReceiptDocument::rateKey($world['user']));

    for ($attempt = 0; $attempt < ReceiptDocument::RATE_LIMIT; $attempt++) {
        $this->actingAs($world['user'])->get('/student/fees/receipts/'.$world['receipt']->id)->assertOk();
    }

    $this->actingAs($world['user'])->get('/student/fees/receipts/'.$world['receipt']->id.'/pdf')->assertStatus(429);
});
