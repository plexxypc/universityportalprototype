<?php

declare(strict_types=1);

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Support\StatusTone;
use Illuminate\Support\Facades\Blade;

it('renders a status badge with text and its tone', function () {
    $html = Blade::render('<x-status-badge :status="$status" />', [
        'status' => PaymentStatus::Expired,
    ]);

    expect($html)->toContain('Expired')
        ->and($html)->toContain(StatusTone::classes(StatusTone::NEUTRAL))
        ->and($html)->not->toContain('>Paid<');

    $paid = Blade::render('<x-status-badge :status="$status" />', [
        'status' => InvoiceStatus::Paid,
    ]);

    expect($paid)->toContain('Paid')
        ->and($paid)->toContain(StatusTone::classes(StatusTone::SUCCESS));
});

it('renders a dialog with a focus trap, escape close, and focus return', function () {
    $html = Blade::render(<<<'BLADE'
        <x-dialog id="preview-dialog" title="Receipt">
            <x-slot:trigger>
                <button type="button" x-on:click="open = true">Open dialog</button>
            </x-slot:trigger>
            <p>Ready to view.</p>
        </x-dialog>
    BLADE);

    expect($html)->toContain('role="dialog"')
        ->and($html)->toContain('aria-modal="true"')
        ->and($html)->toContain('aria-labelledby="preview-dialog-title"')
        ->and($html)->toContain('id="preview-dialog-title"')
        ->and($html)->toContain('x-trap="open"')
        ->and($html)->toContain('keydown.escape')
        ->and($html)->not->toContain('noreturn')
        ->and($html)->toContain('Receipt');
});

it('renders a polite toast that dismisses after five seconds', function () {
    $stack = Blade::render('<x-toast-stack />');
    $toast = Blade::render('<x-toast message="Payment received." />');

    expect($stack)->toContain('aria-live="polite"')
        ->and($stack)->toContain('5000')
        ->and($stack)->toContain('Dismiss')
        ->and($stack)->toContain('x-text="toast.message"')
        ->and($toast)->toContain('Payment received.')
        ->and($toast)->toContain('role="status"');
});

it('renders keyboard tabs and a card', function () {
    $html = Blade::render(<<<'BLADE'
        <x-card title="Invoice">
            <x-slot:actions>
                <button type="button">Export</button>
            </x-slot:actions>
            <p>Body</p>
        </x-card>
        <x-tabs :tabs="['profile' => 'Profile', 'fees' => 'Fees']" label="Record sections">
            <div role="tabpanel" id="panel-profile" aria-labelledby="tab-profile">Profile</div>
            <div role="tabpanel" id="panel-fees" aria-labelledby="tab-fees">Fees</div>
        </x-tabs>
        <x-stat-card label="Outstanding fees" hint="Current session">125</x-stat-card>
    BLADE);

    expect($html)->toContain('role="tablist"')
        ->and($html)->toContain('role="tab"')
        ->and($html)->toContain('aria-selected="true"')
        ->and($html)->toContain('aria-controls="panel-profile"')
        ->and($html)->toContain('keydown.right')
        ->and($html)->toContain('keydown.left')
        ->and($html)->toContain('rounded-card')
        ->and($html)->toContain('Invoice')
        ->and($html)->toContain('Outstanding fees');
});
