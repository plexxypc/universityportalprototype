<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Filament\Staff\Pages\InvoicesPage;
use App\Models\AcademicSession;
use App\Models\FeeCategory;
use App\Models\FeeStructure;
use App\Models\Invoice;
use App\Models\InvoiceAdjustment;
use App\Models\Programme;
use App\Models\RoleAssignment;
use App\Models\Student;
use App\Models\User;
use App\Services\InvoiceService;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function invoices_screen_user(Role $role): User
{
    $user = User::factory()->create();
    $assignment = RoleAssignment::factory();

    if ($role === Role::FacultyAdmin) {
        $assignment = $assignment->facultyAdmin();
    }

    $assignment->create([
        'user_id' => $user->id,
        'role' => $role,
    ]);

    return $user;
}

/**
 * One student who can be invoiced at level 100.
 *
 * @return array{programme: Programme, session: AcademicSession, student: Student}
 */
function invoices_screen_world(): array
{
    $programme = Programme::factory()->create();
    $session = AcademicSession::factory()->create(['name' => '2026/2027']);
    $category = FeeCategory::factory()->create(['name' => 'Tuition', 'code' => 'tuition']);
    FeeStructure::factory()->create([
        'fee_category_id' => $category->id,
        'programme_id' => $programme->id,
        'level' => 100,
        'session_id' => $session->id,
        'amount_kobo' => 1_000_000,
    ]);
    $student = Student::factory()->create([
        'programme_id' => $programme->id,
        'entry_session_id' => $session->id,
        'level' => 100,
    ]);

    return [
        'programme' => $programme,
        'session' => $session,
        'student' => $student,
    ];
}

it('lets the bursar preview and create the next invoices', function () {
    $world = invoices_screen_world();
    $bursar = invoices_screen_user(Role::Bursar);

    $page = Livewire::actingAs($bursar)
        ->test(InvoicesPage::class)
        ->assertSee('A later price change does not change an invoice that was already issued.')
        ->set('programme_id', (string) $world['programme']->id)
        ->set('level', '100')
        ->set('session_id', (string) $world['session']->id);

    $page->call('previewInvoices');
    Notification::assertNotified('Will create 1. Already invoiced 0. No fee structure 0.');

    $page->call('confirmInvoices');
    Notification::assertNotified('Created 1. Already invoiced 0. No fee structure 0. That was the last group.');

    expect(Invoice::query()->where('student_id', $world['student']->id)->count())->toBe(1)
        ->and((int) $page->get('after_student_id'))->toBe((int) $world['student']->id);
});

it('lets the registrar preview and refuses confirm, and hides the page from other staff', function () {
    $world = invoices_screen_world();
    $registrar = invoices_screen_user(Role::Registrar);

    Livewire::actingAs($registrar)
        ->test(InvoicesPage::class)
        ->set('programme_id', (string) $world['programme']->id)
        ->set('level', '100')
        ->set('session_id', (string) $world['session']->id)
        ->call('previewInvoices');

    Notification::assertNotified('Will create 1. Already invoiced 0. No fee structure 0.');

    Livewire::actingAs($registrar)
        ->test(InvoicesPage::class)
        ->call('confirmInvoices')
        ->assertForbidden();

    expect(Invoice::query()->count())->toBe(0);

    Livewire::actingAs(invoices_screen_user(Role::FacultyAdmin))
        ->test(InvoicesPage::class)
        ->assertForbidden();

    Livewire::actingAs(invoices_screen_user(Role::Lecturer))
        ->test(InvoicesPage::class)
        ->assertForbidden();
});

it('adds an adjustment for the bursar and removes it only for the super admin', function () {
    $world = invoices_screen_world();
    $bursar = invoices_screen_user(Role::Bursar);
    $super_admin = invoices_screen_user(Role::SuperAdmin);

    Livewire::actingAs($bursar)
        ->test(InvoicesPage::class)
        ->set('programme_id', (string) $world['programme']->id)
        ->set('level', '100')
        ->set('session_id', (string) $world['session']->id)
        ->call('confirmInvoices');

    $invoice = Invoice::query()->where('student_id', $world['student']->id)->firstOrFail();

    Livewire::actingAs($bursar)
        ->test(InvoicesPage::class)
        ->call('addInvoiceAdjustment', (int) $invoice->id, 'Discount', '10.00', 'Early payment');

    Notification::assertNotified('Adjustment saved');
    $adjustment = InvoiceAdjustment::query()->where('invoice_id', $invoice->id)->firstOrFail();

    Livewire::actingAs($bursar)
        ->test(InvoicesPage::class)
        ->call('removeInvoiceAdjustment', (int) $invoice->id, (int) $adjustment->id, 'Not allowed')
        ->assertForbidden();

    expect(InvoiceAdjustment::query()->whereKey($adjustment->id)->exists())->toBeTrue();

    Livewire::actingAs($super_admin)
        ->test(InvoicesPage::class)
        ->call('removeInvoiceAdjustment', (int) $invoice->id, (int) $adjustment->id, 'Entered twice');

    Notification::assertNotified('Adjustment removed');

    expect(InvoiceAdjustment::query()->whereKey($adjustment->id)->exists())->toBeFalse();
});

it('uses the same not-found message for a missing invoice and another missing id', function () {
    $bursar = invoices_screen_user(Role::Bursar);
    $page = Livewire::actingAs($bursar)->test(InvoicesPage::class);

    $page->call('cancelInvoice', 999999, 'Missing row');
    Notification::assertNotified(InvoiceService::MESSAGE_MISSING);

    $page->call('cancelInvoice', 888888, 'Also missing');
    Notification::assertNotified(InvoiceService::MESSAGE_MISSING);

    $page->call('addInvoiceAdjustment', 999999, 'Waiver', '10.00', 'Missing invoice');
    Notification::assertNotified(InvoiceService::MESSAGE_MISSING);
});
