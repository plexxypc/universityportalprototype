<?php

declare(strict_types=1);

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Models\AcademicSession;
use App\Models\Invoice;
use App\Models\InvoiceAdjustment;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * A signed-in student. The account is Active and the password is already changed.
 */
function fees_page_student(string $first_name): Student
{
    $user = User::factory()->create();

    return Student::factory()->create([
        'user_id' => $user->id,
        'first_name' => $first_name,
    ]);
}

it('shows the student their invoices, outstanding balance, and a pay button that does not submit', function () {
    $student = fees_page_student('Chioma');
    $session = AcademicSession::factory()->create(['name' => '2026/2027']);
    $invoice = Invoice::factory()->create([
        'student_id' => $student->id,
        'session_id' => $session->id,
        'number' => 'INV-OWN-1',
        'total_kobo' => 150_000,
        'adjustments_kobo' => 20_000,
        'paid_kobo' => 30_000,
        'status' => InvoiceStatus::PartPaid,
    ]);
    InvoiceItem::factory()->create([
        'invoice_id' => $invoice->id,
        'description' => 'Tuition',
        'amount_kobo' => 150_000,
    ]);
    InvoiceAdjustment::factory()->create([
        'invoice_id' => $invoice->id,
        'amount_kobo' => 20_000,
        'reason' => 'Merit award',
    ]);
    Payment::factory()->create([
        'invoice_id' => $invoice->id,
        'student_id' => $student->id,
        'reference' => 'PAY-OWN-1',
        'amount_kobo' => 10_000,
        'status' => PaymentStatus::Pending,
        'expires_at' => now()->addMinutes(30),
    ]);

    $list = $this->actingAs($student->user)->get('/student/fees');
    $list->assertOk();
    $list_html = (string) $list->getContent();

    expect(str_contains($list_html, 'INV-OWN-1'))->toBeTrue()
        ->and(str_contains($list_html, '₦1,000.00'))->toBeTrue()
        ->and(str_contains($list_html, 'No invoices yet.'))->toBeFalse();

    $page = $this->actingAs($student->user)->get('/student/fees/'.$invoice->id);
    $page->assertOk();
    $html = (string) $page->getContent();

    expect(str_contains($html, 'Tuition'))->toBeTrue()
        ->and(str_contains($html, 'Merit award'))->toBeTrue()
        ->and(str_contains($html, 'PAY-OWN-1'))->toBeTrue()
        ->and(str_contains($html, '₦1,000.00'))->toBeTrue()
        ->and(str_contains($html, 'name="amount"'))->toBeTrue()
        ->and(preg_match('/<button[^>]*type="submit"[^>]*>\s*Pay\s*<\/button>/', $html))->toBe(1)
        ->and(str_contains($html, '/student/fees/'.$invoice->id.'/pay'))->toBeTrue()
        ->and(str_contains($html, 'wire:click'))->toBeFalse()
        ->and(InvoiceItem::query()->visibleTo($student->user)->count())->toBe(1)
        ->and(InvoiceAdjustment::query()->visibleTo($student->user)->count())->toBe(1);
});

it('gives another student the same 404 for a real invoice and a missing id', function () {
    $owner = fees_page_student('Chioma');
    $other = fees_page_student('Tunde');
    $invoice = Invoice::factory()->create([
        'student_id' => $owner->id,
        'number' => 'INV-OWN-2',
        'total_kobo' => 150_000,
        'adjustments_kobo' => 0,
        'paid_kobo' => 0,
        'status' => InvoiceStatus::Unpaid,
    ]);
    InvoiceItem::factory()->create([
        'invoice_id' => $invoice->id,
        'description' => 'Tuition',
    ]);

    $real = $this->actingAs($other->user)->get('/student/fees/'.$invoice->id);
    $missing = $this->actingAs($other->user)->get('/student/fees/999999');
    $empty = $this->actingAs($other->user)->get('/student/fees');

    $real->assertNotFound();
    $missing->assertNotFound();
    $empty->assertOk();

    expect($real->status())->toBe($missing->status())
        ->and($real->status())->toBe(404)
        ->and(str_contains((string) $real->getContent(), 'INV-OWN-2'))->toBeFalse()
        ->and(str_contains((string) $missing->getContent(), 'INV-OWN-2'))->toBeFalse()
        ->and(str_contains((string) $empty->getContent(), 'No invoices yet.'))->toBeTrue()
        ->and(str_contains((string) $empty->getContent(), 'INV-OWN-2'))->toBeFalse()
        ->and(InvoiceItem::query()->visibleTo($other->user)->count())->toBe(0);
});
