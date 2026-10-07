<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;

it('renders skeleton variants with a loading status', function () {
    $page = Blade::render('<x-skeleton variant="page" />');
    $card = Blade::render('<x-skeleton variant="card" />');
    $row = Blade::render('<x-skeleton variant="row" />');

    expect($page)->toContain('Loading')
        ->and($page)->toContain('aria-hidden="true"')
        ->and($page)->toContain('animate-pulse')
        ->and($card)->toContain('rounded-card')
        ->and($row)->toContain('h-12');
});

it('renders an empty state with an optional primary action', function () {
    $html = Blade::render(<<<'BLADE'
        <x-empty-state message="No invoices yet">
            <x-slot:action>
                <x-button>Import students</x-button>
            </x-slot:action>
        </x-empty-state>
    BLADE);

    expect($html)->toContain('No invoices yet')
        ->and($html)->toContain('Import students')
        ->and($html)->toContain('bg-primary-600');
});

it('renders a friendly error state without a raw error', function () {
    $html = Blade::render(<<<'BLADE'
        <x-error-state
            message="We could not verify this payment yet."
            hint="Your account has not been charged twice. Try again in a minute."
            retry-label="Try again"
        />
    BLADE);

    expect($html)->toContain('role="alert"')
        ->and($html)->toContain('We could not verify this payment yet.')
        ->and($html)->toContain('Your account has not been charged twice.')
        ->and($html)->toContain('Try again')
        ->and($html)->not->toContain('SQLSTATE')
        ->and($html)->not->toContain('Exception');
});

it('states the consequence in a confirm dialog and cancels on escape', function () {
    $html = Blade::render(<<<'BLADE'
        <x-confirm-dialog
            id="preview-deactivate"
            title="Deactivate student"
            consequence="Ada will not be able to sign in or register courses."
            confirm-label="Deactivate"
        />
    BLADE);

    expect($html)->toContain('role="dialog"')
        ->and($html)->toContain('aria-modal="true"')
        ->and($html)->toContain('aria-describedby="preview-deactivate-consequence"')
        ->and($html)->toContain('Ada will not be able to sign in or register courses.')
        ->and($html)->toContain('Deactivate')
        ->and($html)->toContain('bg-destructive')
        ->and($html)->toContain('x-trap="open"')
        ->and($html)->toContain('keydown.escape.window="open = false"')
        ->and($html)->toContain('class="absolute inset-0 bg-text/40"')
        ->and($html)->not->toContain('noreturn');
});
