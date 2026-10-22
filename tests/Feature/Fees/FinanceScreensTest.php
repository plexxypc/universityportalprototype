<?php

declare(strict_types=1);

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\RegistrationStatus;
use App\Enums\Role;
use App\Filament\Staff\Pages\FinanceDashboardPage;
use App\Filament\Staff\Pages\ReconciliationPage;
use App\Filament\Staff\Pages\TransactionPage;
use App\Filament\Staff\Pages\TransactionsPage;
use App\Http\Controllers\FinanceExportController;
use App\Models\AcademicSession;
use App\Models\AuditLog;
use App\Models\CourseRegistration;
use App\Models\EmailOutbox;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Programme;
use App\Models\Receipt;
use App\Models\RoleAssignment;
use App\Models\Semester;
use App\Models\Student;
use App\Models\User;
use App\Services\FinanceReport;
use App\Services\Payments\DemoGateway;
use App\Services\PaymentService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * @return array{user: User, student: Student, invoice: Invoice}
 */
function finance_student(int $total_kobo = 100_000): array
{
    $session = AcademicSession::query()->where('is_current', true)->first()
        ?? AcademicSession::factory()->current()->create();
    $programme = Programme::factory()->create();
    $user = User::factory()->create(['name' => 'Ada Finance']);
    $student = Student::factory()->create([
        'user_id' => $user->id,
        'programme_id' => $programme->id,
        'entry_session_id' => $session->id,
        'level' => 100,
    ]);
    $invoice = Invoice::factory()->create([
        'student_id' => $student->id,
        'session_id' => $session->id,
        'total_kobo' => $total_kobo,
        'paid_kobo' => 0,
        'adjustments_kobo' => 0,
        'status' => InvoiceStatus::Unpaid,
    ]);
    InvoiceItem::factory()->create([
        'invoice_id' => $invoice->id,
        'amount_kobo' => $total_kobo,
        'description' => 'Tuition',
    ]);

    return ['user' => $user, 'student' => $student, 'invoice' => $invoice];
}

function finance_staff(Role $role): User
{
    $user = User::factory()->create();
    $assignment = RoleAssignment::factory();

    if ($role === Role::FacultyAdmin) {
        $assignment = $assignment->facultyAdmin();
    }

    if ($role === Role::DepartmentOfficer) {
        $assignment = $assignment->departmentOfficer();
    }

    $assignment->create([
        'user_id' => $user->id,
        'role' => $role,
    ]);

    return $user;
}

it('lists a transaction for the bursar and hides finance screens from other roles', function () {
    $world = finance_student();
    $payment = Payment::factory()->create([
        'invoice_id' => $world['invoice']->id,
        'student_id' => $world['student']->id,
        'reference' => 'P20260000000108',
        'amount_kobo' => 100_000,
    ]);
    $bursar = finance_staff(Role::Bursar);

    Livewire::actingAs($bursar)
        ->test(TransactionsPage::class)
        ->assertSee('P20260000000108')
        ->assertSee(Money::format(100_000));

    Livewire::actingAs($bursar)
        ->test(TransactionPage::class, ['payment' => $payment->id])
        ->assertSee('P20260000000108')
        ->assertSee(Money::format(100_000));

    foreach ([Role::FacultyAdmin, Role::DepartmentOfficer, Role::Lecturer] as $role) {
        Livewire::actingAs(finance_staff($role))
            ->test(TransactionsPage::class)
            ->assertNotFound();
    }

    $student = User::factory()->create();
    Student::factory()->create(['user_id' => $student->id]);

    Livewire::actingAs($student)->test(TransactionsPage::class)->assertNotFound();

    foreach ([Role::FacultyAdmin, Role::DepartmentOfficer, Role::Lecturer] as $role) {
        Livewire::actingAs(finance_staff($role))
            ->test(TransactionPage::class, ['payment' => $payment->id])
            ->call('reverifyPayment')
            ->assertNotFound();
    }

    Livewire::actingAs($student)
        ->test(TransactionPage::class, ['payment' => $payment->id])
        ->call('reverifyPayment')
        ->assertNotFound();

    Livewire::actingAs(finance_staff(Role::Registrar))
        ->test(TransactionPage::class, ['payment' => $payment->id])
        ->call('reverifyPayment')
        ->assertForbidden();
});

it('re-verifies a pending payment for a manager and leaves a finished payment unchanged', function () {
    $this->freezeTime();
    $world = finance_student();
    $pending = Payment::factory()->create([
        'invoice_id' => $world['invoice']->id,
        'student_id' => $world['student']->id,
        'reference' => 'P20260000000109',
        'amount_kobo' => 100_000,
        'status' => PaymentStatus::Pending,
    ]);
    app(DemoGateway::class)->remember($pending->reference, 100_000, 'success');
    $bursar = finance_staff(Role::Bursar);

    Livewire::actingAs($bursar)
        ->test(TransactionPage::class, ['payment' => $pending->id])
        ->call('reverifyPayment');

    expect($pending->fresh()?->status)->toBe(PaymentStatus::Successful)
        ->and(AuditLog::query()->where('action', PaymentService::ACTION_REVERIFIED)->count())->toBe(1);

    Livewire::actingAs($bursar)
        ->test(TransactionPage::class, ['payment' => $pending->id])
        ->call('reverifyPayment');

    expect(AuditLog::query()->where('action', PaymentService::ACTION_REVERIFIED)->count())->toBe(2)
        ->and($pending->fresh()?->status)->toBe(PaymentStatus::Successful);

    $again = Payment::factory()->create([
        'invoice_id' => $world['invoice']->id,
        'student_id' => $world['student']->id,
        'reference' => 'P20260000000110',
        'amount_kobo' => 1,
        'status' => PaymentStatus::Pending,
        'expires_at' => now()->addHour(),
    ]);
    app(PaymentService::class)->reverify($bursar, (int) $again->id, request());
    $second = app(PaymentService::class)->reverify($bursar, (int) $again->id, request());

    expect($second)->toBe(PaymentService::REVERIFY_RECENT);
    $this->travelBack();
});

it('pays through the demo gateway and leaves one receipt and one confirmation', function () {
    $world = finance_student();
    $this->actingAs($world['user'])
        ->post('/student/fees/'.$world['invoice']->id.'/pay', ['amount' => '1000.00'])
        ->assertRedirect();
    $payment = Payment::query()->where('invoice_id', $world['invoice']->id)->firstOrFail();
    $signature = app(DemoGateway::class)->signature($payment->reference, (int) $payment->amount_kobo, 'success');

    $this->actingAs($world['user'])
        ->post('/student/payments/demo/'.$payment->reference, [
            'outcome' => 'success',
            'signature' => $signature,
        ])
        ->assertRedirect();

    $this->followingRedirects()
        ->actingAs($world['user'])
        ->get(route('payments.return', ['reference' => $payment->reference]))
        ->assertOk();

    expect($payment->fresh()?->status)->toBe(PaymentStatus::Successful)
        ->and($payment->fresh()?->receipt()->exists())->toBeTrue()
        ->and(EmailOutbox::query()->where('template', 'payment_confirmed')->count())->toBe(1);
});

it('shows the bursar the student finance summary and hides it from a faculty admin', function () {
    $world = finance_student();
    $session = AcademicSession::query()->where('is_current', true)->firstOrFail();
    $semester = Semester::factory()->create(['session_id' => $session->id, 'is_active' => true]);
    CourseRegistration::factory()->create([
        'student_id' => $world['student']->id,
        'semester_id' => $semester->id,
        'status' => RegistrationStatus::Submitted,
    ]);
    $payment = Payment::factory()->successful()->create([
        'invoice_id' => $world['invoice']->id,
        'student_id' => $world['student']->id,
        'amount_kobo' => 100_000,
    ]);
    $world['invoice']->paid_kobo = 100_000;
    $world['invoice']->status = InvoiceStatus::Paid;
    $world['invoice']->save();
    Receipt::factory()->create(['payment_id' => $payment->id]);
    $bursar = finance_staff(Role::Bursar);
    $programme = $world['student']->programme()->with('department')->firstOrFail();
    $faculty_admin = User::factory()->create();
    RoleAssignment::factory()->create([
        'user_id' => $faculty_admin->id,
        'role' => Role::FacultyAdmin,
        'faculty_id' => $programme->department->faculty_id,
        'department_id' => null,
    ]);

    $record = $this->actingAs($bursar)->get('/staff/students/'.$world['student']->id);
    $record->assertOk();
    expect($record->getContent())
        ->toContain('Registration Submitted')
        ->toContain('Outstanding')
        ->toContain('Last payment Successful');

    $this->actingAs($bursar)
        ->get(TransactionPage::getUrl(['payment' => $payment->id]))
        ->assertOk()
        ->assertSee('Re-verify')
        ->assertSee(PaymentService::REVERSAL_NOTICE);

    $this->actingAs($bursar)
        ->get(route('staff.downloads.receipt.pdf', ['receipt' => $payment->receipt->id]))
        ->assertOk();

    $faculty = $this->actingAs($faculty_admin)->get('/staff/students/'.$world['student']->id);
    $faculty->assertOk();
    expect($faculty->getContent())
        ->toContain('Registration Submitted')
        ->not->toContain('Outstanding')
        ->not->toContain('Last payment');
});

it('reports a paid amount that does not match successful payments and a clean ledger as empty', function () {
    Http::preventStrayRequests();
    $clean = finance_student();
    $broken = finance_student(500_000);
    Payment::factory()->successful()->create([
        'invoice_id' => $broken['invoice']->id,
        'student_id' => $broken['student']->id,
        'amount_kobo' => 500_000,
    ]);
    Receipt::factory()->create(['payment_id' => Payment::query()->where('invoice_id', $broken['invoice']->id)->value('id')]);
    $bursar = finance_staff(Role::Bursar);

    $page = Livewire::actingAs($bursar)->test(ReconciliationPage::class)->call('loadSections');

    expect($page->get('integrity_count'))->toBe(1)
        ->and($page->get('integrity_state'))->toBe('ready')
        ->and(Http::recorded())->toHaveCount(0);

    $broken['invoice']->paid_kobo = 500_000;
    $broken['invoice']->status = InvoiceStatus::Paid;
    $broken['invoice']->save();

    $clean_page = Livewire::actingAs($bursar)->test(ReconciliationPage::class)->call('loadSections');

    expect($clean_page->get('integrity_count'))->toBe(0)
        ->and($clean_page->get('integrity_state'))->toBe('empty')
        ->and($clean_page->get('stuck_state'))->toBe('empty');
});

it('computes the dashboard from a hand-worked fixture', function () {
    $world = finance_student(200_000);
    $session_id = (int) $world['invoice']->session_id;
    Payment::factory()->successful()->create([
        'invoice_id' => $world['invoice']->id,
        'student_id' => $world['student']->id,
        'amount_kobo' => 100_000,
        'reference' => 'P20260000000200',
    ]);
    Receipt::factory()->create(['payment_id' => Payment::query()->where('reference', 'P20260000000200')->value('id')]);
    $reversed = Payment::factory()->create([
        'invoice_id' => $world['invoice']->id,
        'student_id' => $world['student']->id,
        'amount_kobo' => 20_000,
        'status' => PaymentStatus::Reversed,
        'reference' => 'P20260000000202',
        'reversed_reason' => 'Duplicate',
        'reversed_by' => finance_staff(Role::Bursar)->id,
        'reversed_at' => now(),
    ]);
    Receipt::factory()->create(['payment_id' => $reversed->id]);
    Payment::factory()->create([
        'invoice_id' => $world['invoice']->id,
        'student_id' => $world['student']->id,
        'amount_kobo' => 50_000,
        'status' => PaymentStatus::Pending,
        'reference' => 'P20260000000201',
    ]);
    $world['invoice']->paid_kobo = 100_000;
    $world['invoice']->status = InvoiceStatus::PartPaid;
    $world['invoice']->save();
    Invoice::factory()->create([
        'student_id' => $world['student']->id,
        'session_id' => $session_id,
        'total_kobo' => 50_000,
        'paid_kobo' => 0,
        'status' => InvoiceStatus::Cancelled,
        'cancelled_reason' => 'Withdrawn',
        'cancelled_by' => finance_staff(Role::Bursar)->id,
        'cancelled_at' => now(),
    ]);
    $bursar = finance_staff(Role::Bursar);
    $report = app(FinanceReport::class);

    expect($report->collectionRate(100_000, 100_000))->toBe('50.0%');

    Livewire::actingAs($bursar)
        ->test(FinanceDashboardPage::class)
        ->call('loadDashboard')
        ->assertSee(Money::format(100_000))
        ->assertSee('50.0%')
        ->assertSee(FinanceReport::PENDING_NOTE)
        ->assertSee('P20260000000200');
});

it('neutralises a formula in an export and audits the row count', function () {
    $world = finance_student();
    $world['user']->name = '=cmd|calc';
    $world['user']->save();
    $world['student']->matric_no = '=1+1';
    $world['student']->save();
    Payment::factory()->create([
        'invoice_id' => $world['invoice']->id,
        'student_id' => $world['student']->id,
        'reference' => 'P20260000000300',
        'amount_kobo' => 100_000,
        'provider_reference' => '5612330000000865',
    ]);
    $bursar = finance_staff(Role::Bursar);

    $response = $this->actingAs($bursar)->get(route('staff.downloads.finance.transactions'));
    $body = $response->streamedContent();

    expect($response->headers->get('content-type'))->toContain('text/csv')
        ->and($body)->toContain("'=cmd|calc")
        ->and($body)->toContain("'=1+1")
        ->and($body)->not->toContain('5612330000000865');

    $audit = AuditLog::query()->where('action', FinanceExportController::ACTION_EXPORTED)->first();

    expect($audit)->not->toBeNull()
        ->and($audit?->after['report'] ?? null)->toBe('transactions')
        ->and($audit?->after['row_count'] ?? null)->toBe(1)
        ->and(json_encode($audit?->after))->not->toContain('=cmd');
});
