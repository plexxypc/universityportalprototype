<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\AcademicSession;
use App\Models\Applicant;
use App\Models\AuditLog;
use App\Models\Counter;
use App\Models\FeeCategory;
use App\Models\FeeStructure;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Programme;
use App\Models\RoleAssignment;
use App\Models\Student;
use App\Models\User;
use App\Services\ApplicantService;
use App\Services\InvoiceService;
use App\Services\StudentService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

/**
 * @return array{actor: User, programme: Programme, session: AcademicSession, tuition: FeeCategory, ict: FeeCategory}
 */
function invoice_world(): array
{
    $actor = User::factory()->create();
    RoleAssignment::factory()->create([
        'user_id' => $actor->id,
        'role' => Role::Bursar,
    ]);
    $programme = Programme::factory()->create();
    $session = AcademicSession::factory()->current()->create(['name' => '2026/2027']);
    $tuition = FeeCategory::factory()->create(['name' => 'Tuition', 'code' => 'tuition', 'sort' => 1]);
    $ict = FeeCategory::factory()->create(['name' => 'ICT levy', 'code' => 'ict', 'sort' => 2]);
    $inactive = FeeCategory::factory()->inactive()->create(['name' => 'Old levy', 'code' => 'old', 'sort' => 0]);

    foreach ([$tuition, $ict, $inactive] as $category) {
        FeeStructure::factory()->create([
            'fee_category_id' => $category->id,
            'programme_id' => $programme->id,
            'level' => 100,
            'session_id' => $session->id,
            'amount_kobo' => $category->code === 'tuition' ? 1_500_000 : 250_000,
        ]);
    }

    return [
        'actor' => $actor,
        'programme' => $programme,
        'session' => $session,
        'tuition' => $tuition,
        'ict' => $ict,
    ];
}

function invoice_request(): Request
{
    return Request::create('/staff/invoices', 'POST', server: [
        'REMOTE_ADDR' => '203.0.113.50',
    ]);
}

function invoice_student(Programme $programme, AcademicSession $session, int $level = 100): Student
{
    return Student::factory()->create([
        'programme_id' => $programme->id,
        'entry_session_id' => $session->id,
        'level' => $level,
        'first_name' => 'Zainab',
    ]);
}

function invoice_role(Role $role): User
{
    $user = User::factory()->create();
    RoleAssignment::factory()->create([
        'user_id' => $user->id,
        'role' => $role,
    ]);

    return $user;
}

it('copies fee structure amounts onto invoice items', function () {
    $world = invoice_world();
    $student = invoice_student($world['programme'], $world['session']);
    $chunk = app(InvoiceService::class)->confirmChunk(
        $world['actor'],
        (int) $world['programme']->id,
        100,
        (int) $world['session']->id,
        0,
        invoice_request(),
    );
    $invoice = Invoice::query()->where('student_id', $student->id)->first();
    $items = InvoiceItem::query()->where('invoice_id', $invoice?->id)->orderBy('sort')->get();

    expect($chunk->created)->toBe(1)
        ->and($invoice)->not->toBeNull()
        ->and((int) $invoice->total_kobo)->toBe(1_750_000)
        ->and($items)->toHaveCount(2)
        ->and($items[0]->description)->toBe('Tuition')
        ->and((int) $items[0]->amount_kobo)->toBe(1_500_000)
        ->and($items[1]->description)->toBe('ICT levy')
        ->and((int) $items[1]->amount_kobo)->toBe(250_000)
        ->and($items->sum('amount_kobo'))->toBe(1_750_000);
});

it('lists a student with no fee structure and creates no invoice', function () {
    $world = invoice_world();
    $student = invoice_student($world['programme'], $world['session'], 200);
    $preview = app(InvoiceService::class)->preview(
        $world['actor'],
        (int) $world['programme']->id,
        200,
        (int) $world['session']->id,
    );
    $chunk = app(InvoiceService::class)->confirmChunk(
        $world['actor'],
        (int) $world['programme']->id,
        200,
        (int) $world['session']->id,
        0,
        invoice_request(),
    );

    expect($preview->no_fee_structure)->toBe([(int) $student->id])
        ->and($preview->will_create)->toBe([])
        ->and($chunk->created)->toBe(0)
        ->and($chunk->no_fee_structure)->toBe(1)
        ->and(Invoice::query()->where('student_id', $student->id)->exists())->toBeFalse();
});

it('previews will create, already invoiced, and no fee structure', function () {
    $world = invoice_world();
    $ready = invoice_student($world['programme'], $world['session']);
    $done = invoice_student($world['programme'], $world['session']);
    $missing = invoice_student($world['programme'], $world['session'], 200);
    $service = app(InvoiceService::class);
    $service->confirmChunk(
        $world['actor'],
        (int) $world['programme']->id,
        100,
        (int) $world['session']->id,
        (int) $ready->id,
        invoice_request(),
    );

    $priced = $service->preview($world['actor'], (int) $world['programme']->id, 100, (int) $world['session']->id);
    $unpriced = $service->preview($world['actor'], (int) $world['programme']->id, 200, (int) $world['session']->id);

    expect($priced->will_create)->toBe([(int) $ready->id])
        ->and($priced->already_invoiced)->toBe([(int) $done->id])
        ->and($priced->no_fee_structure)->toBe([])
        ->and($unpriced->no_fee_structure)->toBe([(int) $missing->id]);
});

it('rejects a second non-cancelled invoice for the same session', function () {
    $world = invoice_world();
    $student = invoice_student($world['programme'], $world['session']);
    $service = app(InvoiceService::class);
    $service->confirmChunk($world['actor'], (int) $world['programme']->id, 100, (int) $world['session']->id, 0, invoice_request());
    $again = $service->confirmChunk($world['actor'], (int) $world['programme']->id, 100, (int) $world['session']->id, 0, invoice_request());

    expect($again->created)->toBe(0)
        ->and($again->already_invoiced)->toBe(1)
        ->and(Invoice::query()->where('student_id', $student->id)->where('status', '!=', 'Cancelled')->count())->toBe(1);
});

it('leaves an issued invoice unchanged when the fee structure changes', function () {
    $world = invoice_world();
    $student = invoice_student($world['programme'], $world['session']);
    app(InvoiceService::class)->confirmChunk(
        $world['actor'],
        (int) $world['programme']->id,
        100,
        (int) $world['session']->id,
        0,
        invoice_request(),
    );
    FeeStructure::query()->where('fee_category_id', $world['tuition']->id)->update(['amount_kobo' => 9_999]);
    $item = InvoiceItem::query()->where('description', 'Tuition')->first();

    expect($item)->not->toBeNull()
        ->and((int) $item->amount_kobo)->toBe(1_500_000)
        ->and((int) Invoice::query()->where('student_id', $student->id)->value('total_kobo'))->toBe(1_750_000);
});

it('allocates the invoice number inside the creating transaction', function () {
    $world = invoice_world();
    invoice_student($world['programme'], $world['session']);
    app(InvoiceService::class)->confirmChunk(
        $world['actor'],
        (int) $world['programme']->id,
        100,
        (int) $world['session']->id,
        0,
        invoice_request(),
    );
    $year = now()->format('Y');
    $number = (string) Invoice::query()->value('number');
    $audit = AuditLog::query()->where('action', InvoiceService::ACTION_GENERATED)->first();

    expect($number)->toBe('INV-'.$year.'-000001')
        ->and((int) Counter::query()->where('key', 'invoice:'.$year)->value('value'))->toBe(1)
        ->and($audit?->after['total_kobo'] ?? null)->toBe(1_750_000)
        ->and(json_encode($audit?->after))->not->toContain('Zainab');
});

it('takes the matric counter before the invoice counter', function () {
    $world = invoice_world();
    $actor = invoice_role(Role::Registrar);
    $keys = [];
    DB::listen(function ($query) use (&$keys): void {
        if (! str_contains(strtolower($query->sql), 'for update') || ! str_contains($query->sql, 'counters')) {
            return;
        }

        $key = $query->bindings[0] ?? null;

        if (is_string($key)) {
            $keys[] = $key;
        }
    });

    $created = app(StudentService::class)->create($actor, [
        'first_name' => 'Zainab',
        'last_name' => 'Bello',
        'email' => 'zainab.bello@example.com',
        'phone' => '08012345678',
        'gender' => 'Female',
        'date_of_birth' => '2004-05-01',
        'state_of_origin' => 'Lagos',
        'address' => '12 Garden Close',
        'programme_id' => $world['programme']->id,
        'level' => 100,
        'entry_session_id' => $world['session']->id,
    ], invoice_request());

    $matric = null;
    $invoice = null;

    foreach ($keys as $index => $key) {
        if (str_starts_with($key, 'matric:') && $matric === null) {
            $matric = $index;
        }

        if (str_starts_with($key, 'invoice:') && $invoice === null) {
            $invoice = $index;
        }
    }

    expect($matric)->toBeInt()
        ->and($invoice)->toBeInt()
        ->and($matric)->toBeLessThan($invoice)
        ->and(Invoice::query()->where('student_id', $created->student->id)->count())->toBe(1);
});

it('generates an invoice when a student is created and when an applicant is converted', function () {
    $world = invoice_world();
    $actor = invoice_role(Role::Registrar);
    $created = app(StudentService::class)->create($actor, [
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'email' => 'ada.created@example.com',
        'gender' => 'Female',
        'date_of_birth' => '2004-05-01',
        'state_of_origin' => 'Lagos',
        'programme_id' => $world['programme']->id,
        'level' => 100,
        'entry_session_id' => $world['session']->id,
    ], invoice_request());
    $applicant = Applicant::factory()->create([
        'programme_id' => $world['programme']->id,
        'level' => 100,
        'entry_session_id' => $world['session']->id,
        'email' => 'ada.applicant@example.com',
        'state_of_origin' => 'Lagos',
        'date_of_birth' => '2004-05-01',
    ]);
    $converted = app(ApplicantService::class)->convert($actor, (int) $applicant->id, invoice_request());

    expect(Invoice::query()->where('student_id', $created->student->id)->count())->toBe(1)
        ->and(Invoice::query()->where('student_id', $converted->student->id)->count())->toBe(1);
});

it('creates the next 25 invoices and does not duplicate them on a second run', function () {
    $world = invoice_world();
    $service = app(InvoiceService::class);

    foreach (range(1, 26) as $ignored) {
        invoice_student($world['programme'], $world['session']);
    }

    $first = $service->confirmChunk(
        $world['actor'],
        (int) $world['programme']->id,
        100,
        (int) $world['session']->id,
        0,
        invoice_request(),
    );
    $second = $service->confirmChunk(
        $world['actor'],
        (int) $world['programme']->id,
        100,
        (int) $world['session']->id,
        $first->last_student_id,
        invoice_request(),
    );
    $repeat = $service->confirmChunk(
        $world['actor'],
        (int) $world['programme']->id,
        100,
        (int) $world['session']->id,
        0,
        invoice_request(),
    );

    expect($first->created)->toBe(25)
        ->and($first->done)->toBeFalse()
        ->and($second->created)->toBe(1)
        ->and($second->done)->toBeTrue()
        ->and($repeat->created)->toBe(0)
        ->and(Invoice::query()->count())->toBe(26);
});

it('cancels an unpaid invoice with no pending payment', function () {
    $world = invoice_world();
    $student = invoice_student($world['programme'], $world['session']);
    $service = app(InvoiceService::class);
    $service->confirmChunk($world['actor'], (int) $world['programme']->id, 100, (int) $world['session']->id, 0, invoice_request());
    $invoice = Invoice::query()->where('student_id', $student->id)->firstOrFail();
    $cancelled = $service->cancel($world['actor'], (int) $invoice->id, 'Wrong session', invoice_request());
    $audit = AuditLog::query()->where('action', InvoiceService::ACTION_CANCELLED)->first();

    expect($cancelled->status->value)->toBe('Cancelled')
        ->and($cancelled->cancelled_reason)->toBe('Wrong session')
        ->and($audit?->after['reason'] ?? null)->toBe('Wrong session')
        ->and(json_encode($audit?->after))->not->toContain('Zainab');
});

it('refuses to cancel when money has been paid or a payment is pending', function () {
    $world = invoice_world();
    $student = invoice_student($world['programme'], $world['session']);
    $service = app(InvoiceService::class);
    $service->confirmChunk($world['actor'], (int) $world['programme']->id, 100, (int) $world['session']->id, 0, invoice_request());
    $invoice = Invoice::query()->where('student_id', $student->id)->firstOrFail();
    Payment::factory()->create([
        'invoice_id' => $invoice->id,
        'student_id' => $student->id,
        'amount_kobo' => 100,
        'expires_at' => now()->addMinutes(30),
    ]);

    expect(fn () => $service->cancel($world['actor'], (int) $invoice->id, 'Stop', invoice_request()))
        ->toThrow(ValidationException::class, InvoiceService::MESSAGE_PENDING);

    $paid = Invoice::factory()->paid()->create();

    expect(fn () => $service->cancel($world['actor'], (int) $paid->id, 'Stop', invoice_request()))
        ->toThrow(ValidationException::class, InvoiceService::MESSAGE_PAID);
});

it('regenerates a cancelled invoice with a new number', function () {
    $world = invoice_world();
    $student = invoice_student($world['programme'], $world['session']);
    $service = app(InvoiceService::class);
    $service->confirmChunk($world['actor'], (int) $world['programme']->id, 100, (int) $world['session']->id, 0, invoice_request());
    $original = Invoice::query()->where('student_id', $student->id)->firstOrFail();
    $replacement = $service->regenerate($world['actor'], (int) $original->id, 'Issued too early', invoice_request());
    $original->refresh();

    expect($original->status->value)->toBe('Cancelled')
        ->and($replacement->number)->not->toBe($original->number)
        ->and((int) $replacement->total_kobo)->toBe(1_750_000)
        ->and(Invoice::query()->where('student_id', $student->id)->where('status', '!=', 'Cancelled')->count())->toBe(1);
});

it('refuses invoice changes from a registrar', function () {
    $world = invoice_world();
    $registrar = invoice_role(Role::Registrar);
    $service = app(InvoiceService::class);
    $preview = $service->preview($registrar, (int) $world['programme']->id, 100, (int) $world['session']->id);

    expect($preview->will_create)->toBe([]);

    expect(fn () => $service->confirmChunk(
        $registrar,
        (int) $world['programme']->id,
        100,
        (int) $world['session']->id,
        0,
        invoice_request(),
    ))->toThrow(AuthorizationException::class, InvoiceService::MESSAGE_MANAGE);
});

it('rejects a cancelled invoice that has no reason', function () {
    $invoice = Invoice::factory()->create();

    expect(fn () => DB::table('invoices')->where('id', $invoice->id)->update([
        'status' => 'Cancelled',
    ]))->toThrow(QueryException::class);
});
