<?php

declare(strict_types=1);

use App\Enums\InvoiceStatus;
use App\Enums\PaymentGateway;
use App\Exceptions\PaymentsUnavailableException;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Services\Payments\DemoGateway;
use App\Services\Payments\InterswitchGateway;
use App\Services\Payments\PaymentGatewayResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

/**
 * A student who owns one pending payment.
 *
 * @return array{student: Student, payment: Payment}
 */
function payment_config_owner(): array
{
    $user = User::factory()->create();
    $student = Student::factory()->create(['user_id' => $user->id]);
    $invoice = Invoice::factory()->create([
        'student_id' => $student->id,
        'total_kobo' => 500_000,
        'status' => InvoiceStatus::Unpaid,
    ]);
    $payment = Payment::factory()->create([
        'invoice_id' => $invoice->id,
        'student_id' => $student->id,
        'reference' => 'P20260000000001',
        'amount_kobo' => 100_000,
        'gateway' => PaymentGateway::Demo,
    ]);

    return [
        'student' => $student,
        'payment' => $payment,
    ];
}

it('boots and fails closed for an unknown provider', function (string $provider) {
    PaymentGatewayResolver::forgetWarning();
    $messages = payment_config_messages();
    config(['payments.provider' => $provider]);

    expect(fn () => app(PaymentGatewayResolver::class)->warnIfUnusable())->not->toThrow(Throwable::class)
        ->and(fn () => app(PaymentGatewayResolver::class)->resolve())->toThrow(PaymentsUnavailableException::class)
        ->and($messages->getArrayCopy())->toBe(['Payments are not configured.']);

    $this->get('/login')->assertOk();
    $this->get('/health')->assertStatus(503)->assertJsonPath('payments', 'misconfigured');
})->with(['sandbox', 'not-a-provider']);

it('refuses to boot remita and a non-local interswitch configuration that is missing a value', function () {
    config(['payments.provider' => 'remita']);

    expect(fn () => app(PaymentGatewayResolver::class)->enforceBoot())
        ->toThrow(RuntimeException::class, 'Payments are not configured.');

    $this->app['env'] = 'local';

    expect(fn () => app(PaymentGatewayResolver::class)->enforceBoot())
        ->toThrow(RuntimeException::class, 'Payments are not configured.');

    $this->app['env'] = 'testing';
    $secret = 'super-secret-value';
    config([
        'payments.provider' => 'interswitch',
        'payments.interswitch.mode' => 'TEST',
        'payments.interswitch.merchant_code' => '',
        'payments.interswitch.pay_item_id' => 'PITEM',
        'payments.interswitch.secret' => $secret,
    ]);
    $message = '';

    try {
        app(PaymentGatewayResolver::class)->enforceBoot();
    } catch (RuntimeException $exception) {
        $message = $exception->getMessage();
    }

    expect($message)->toBe('Payments are not configured.')
        ->and(str_contains($message, $secret))->toBeFalse();

    $this->app['env'] = 'local';
    app(PaymentGatewayResolver::class)->enforceBoot();

    expect(fn () => app(PaymentGatewayResolver::class)->resolve())->toThrow(PaymentsUnavailableException::class);

    $this->app['env'] = 'testing';
    config([
        'payments.provider' => 'interswitch',
        'payments.interswitch.mode' => 'TEST',
        'payments.interswitch.merchant_code' => 'MTEST',
        'payments.interswitch.pay_item_id' => 'PITEM',
    ]);
    app(PaymentGatewayResolver::class)->enforceBoot();

    expect(app(PaymentGatewayResolver::class)->resolve())->toBeInstanceOf(InterswitchGateway::class);
});

it('fails closed when an Interswitch value is missing and does not log that value', function () {
    PaymentGatewayResolver::forgetWarning();
    $messages = payment_config_messages();
    config([
        'payments.provider' => 'interswitch',
        'payments.interswitch.merchant_code' => '',
        'payments.interswitch.secret' => 'super-secret-value',
    ]);

    expect(fn () => app(PaymentGatewayResolver::class)->resolve())->toThrow(
        PaymentsUnavailableException::class,
        PaymentsUnavailableException::MESSAGE,
    );

    expect($messages->getArrayCopy())->toBe(['Payments are not configured.'])
        ->and(implode(' ', $messages->getArrayCopy()))->not->toContain('super-secret-value');
});

it('fails closed for the demo gateway in production without permission', function () {
    PaymentGatewayResolver::forgetWarning();
    $this->app['env'] = 'production';
    config([
        'payments.provider' => 'demo',
        'payments.allow_demo' => false,
    ]);

    expect(fn () => app(PaymentGatewayResolver::class)->resolve())->toThrow(PaymentsUnavailableException::class);
    $this->get('/health')->assertStatus(503)->assertJsonPath('payments', 'misconfigured');
});

it('allows the demo gateway in production when permission is set', function () {
    $this->app['env'] = 'production';
    config([
        'payments.provider' => 'demo',
        'payments.allow_demo' => true,
    ]);

    expect(app(PaymentGatewayResolver::class)->resolve())->toBeInstanceOf(DemoGateway::class)
        ->and(app(PaymentGatewayResolver::class)->isConfigured())->toBeTrue();
});

it('warns once and refuses to boot remita', function () {
    PaymentGatewayResolver::forgetWarning();
    $messages = payment_config_messages();
    config(['payments.provider' => 'remita']);
    $resolver = app(PaymentGatewayResolver::class);
    $resolver->warnIfUnusable();
    $resolver->warnIfUnusable();

    expect($messages->getArrayCopy())->toBe(['Payments are not configured.']);

    expect(fn () => app(PaymentGatewayResolver::class)->enforceBoot())
        ->toThrow(RuntimeException::class, 'Payments are not configured.');
});

/**
 * Messages written after this listener is attached.
 *
 * @return ArrayObject<int, string>
 */
function payment_config_messages(): ArrayObject
{
    /** @var ArrayObject<int, string> $messages */
    $messages = new ArrayObject;
    Log::listen(function (MessageLogged $event) use ($messages): void {
        $messages->append((string) $event->message);
    });

    return $messages;
}

it('shows the demo banner and rejects a forged outcome', function () {
    $world = payment_config_owner();
    $demo = app(DemoGateway::class);
    $page = $this->actingAs($world['student']->user)->get('/student/payments/demo/'.$world['payment']->reference);

    $page->assertOk();
    expect((string) $page->getContent())->toContain(DemoGateway::BANNER);

    $this->actingAs($world['student']->user)
        ->from('/student/payments/demo/'.$world['payment']->reference)
        ->post('/student/payments/demo/'.$world['payment']->reference, [
            'outcome' => 'success',
            'signature' => 'forged',
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('outcome');

    expect($demo->verify($world['payment']->reference)->result)->toBe('pending');

    $signature = $demo->signature($world['payment']->reference, (int) $world['payment']->amount_kobo, 'success');
    $this->actingAs($world['student']->user)
        ->post('/student/payments/demo/'.$world['payment']->reference, [
            'outcome' => 'success',
            'signature' => $signature,
        ])
        ->assertStatus(303)
        ->assertRedirectToRoute('payments.return', ['reference' => $world['payment']->reference]);

    $outcome = $demo->verify($world['payment']->reference);

    expect($outcome->result)->toBe('success')
        ->and($outcome->amount_kobo)->toBe(100_000)
        ->and($outcome->currency)->toBe('566');
});

it('hides another students demo payment with the same 404 as a missing reference', function () {
    $owner = payment_config_owner();
    $other_user = User::factory()->create();
    $other = Student::factory()->create(['user_id' => $other_user->id]);

    $real = $this->actingAs($other->user)->get('/student/payments/demo/'.$owner['payment']->reference);
    $missing = $this->actingAs($other->user)->get('/student/payments/demo/P20260000000999');

    $real->assertNotFound();
    $missing->assertNotFound();

    expect($real->status())->toBe($missing->status());
});
