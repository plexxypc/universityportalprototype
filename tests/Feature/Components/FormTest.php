<?php

declare(strict_types=1);

use App\Support\FieldId;
use Illuminate\Support\Facades\Blade;

it('builds a stable field id', function () {
    expect(FieldId::for('preview_email'))->toBe('field-preview_email')
        ->and(FieldId::for('student.name'))->toBe('field-student-name')
        ->and(FieldId::help('preview_email'))->toBe('field-preview_email-help')
        ->and(FieldId::error('preview_email'))->toBe('field-preview_email-error');
});

it('renders button variants, sizes, loading and disabled', function () {
    $html = Blade::render(<<<'BLADE'
        <x-button>Primary</x-button>
        <x-button variant="secondary">Secondary</x-button>
        <x-button variant="ghost">Ghost</x-button>
        <x-button variant="destructive">Destructive</x-button>
        <x-button variant="link" href="/fees">Fees</x-button>
        <x-button size="small">Small</x-button>
        <x-button size="large">Large</x-button>
        <x-button disabled>Disabled</x-button>
        <x-button loading loading-label="Paying…">Pay now</x-button>
    BLADE);

    expect($html)->toContain('bg-primary-600')
        ->and($html)->toContain('border-border')
        ->and($html)->toContain('hover:bg-primary-50')
        ->and($html)->toContain('bg-destructive')
        ->and($html)->toContain('text-primary-600')
        ->and($html)->toContain('md:h-8')
        ->and($html)->toContain('h-12')
        ->and($html)->toContain('min-h-11')
        ->and($html)->toContain('disabled')
        ->and($html)->toContain('aria-busy="true"')
        ->and($html)->toContain('Paying…')
        ->and($html)->toContain('animate-spin')
        ->and($html)->not->toContain('Pay now');
});

it('links a label, help text and error to the input', function () {
    $html = Blade::render(<<<'BLADE'
        <x-field name="preview_email" label="Email address" required help="Use the school address." error="Enter an email address.">
            <x-input name="preview_email" type="email" inputmode="email" />
        </x-field>
    BLADE);

    expect($html)->toContain('for="field-preview_email"')
        ->and($html)->toContain('id="field-preview_email"')
        ->and($html)->toContain('Email address')
        ->and($html)->toContain('aria-required="true"')
        ->and($html)->toContain('aria-invalid="true"')
        ->and($html)->toContain('aria-describedby="field-preview_email-help field-preview_email-error"')
        ->and($html)->toContain('id="field-preview_email-help"')
        ->and($html)->toContain('id="field-preview_email-error"')
        ->and($html)->toContain('Enter an email address.')
        ->and($html)->toContain('h-11')
        ->and($html)->toContain('rounded-control')
        ->and($html)->toContain('focus:ring-primary');
});

it('renders a password show and hide toggle', function () {
    $html = Blade::render(<<<'BLADE'
        <x-field name="preview_password" label="Password" required>
            <x-password-input name="preview_password" />
        </x-field>
    BLADE);

    expect($html)->toContain('for="field-preview_password"')
        ->and($html)->toContain('type="password"')
        ->and($html)->toContain('aria-label="Show password"')
        ->and($html)->toContain('Hide password')
        ->and($html)->toContain('x-data')
        ->and($html)->toContain('aria-required="true"');
});

it('renders select, checkbox and textarea with visible labels', function () {
    $html = Blade::render(<<<'BLADE'
        <x-field name="preview_level" label="Level">
            <x-select name="preview_level">
                <option value="100">100</option>
            </x-select>
        </x-field>
        <x-checkbox name="preview_agree" label="I agree to the fee schedule" />
        <x-field name="preview_note" label="Note" help="Optional.">
            <x-textarea name="preview_note">Hello</x-textarea>
        </x-field>
    BLADE);

    expect($html)->toContain('for="field-preview_level"')
        ->and($html)->toContain('<select')
        ->and($html)->toContain('for="field-preview_agree"')
        ->and($html)->toContain('type="checkbox"')
        ->and($html)->toContain('I agree to the fee schedule')
        ->and($html)->toContain('for="field-preview_note"')
        ->and($html)->toContain('<textarea')
        ->and($html)->toContain('aria-describedby="field-preview_note-help"')
        ->and($html)->toContain('min-h-11');
});
