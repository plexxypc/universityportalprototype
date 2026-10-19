<?php

declare(strict_types=1);

use App\Livewire\Student\CourseRegistration as RegistrationPage;
use App\Models\CourseRegistration;
use App\Services\RegistrationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-19 10:00:00', 'Africa/Lagos'));
});

afterEach(function () {
    Carbon::setTestNow();
});

it('keeps only the elective ids as public state', function () {
    $names = [];

    foreach ((new ReflectionClass(RegistrationPage::class))->getProperties() as $property) {
        if ($property->getDeclaringClass()->getName() === RegistrationPage::class) {
            $names[] = $property->getName();
        }
    }

    expect($names)->toBe(['elective_ids'])
        ->and((new ReflectionProperty(RegistrationPage::class, 'elective_ids'))->isPublic())->toBeTrue();
});

it('submits the ticked electives and stays idempotent', function () {
    $world = registration_rules_world();
    registration_rules_limits(2, 24);
    $student = $world['student'];
    $user = $student->user;

    $page = $this->actingAs($user)->get('/student/course-registration');

    $page->assertOk();
    expect($page->getContent())->toContain('wire:loading.attr="disabled"')
        ->and($page->getContent())->toContain('wire:target="submitRegistration"')
        ->and($page->getContent())->toContain('CSC101')
        ->and($page->getContent())->toContain('disabled');

    Livewire::actingAs($user)->test(RegistrationPage::class)
        ->set('elective_ids', [$world['elective']->id])
        ->call('submitRegistration')
        ->assertHasNoErrors()
        ->assertSee('Registration submitted.')
        ->call('submitRegistration')
        ->assertHasNoErrors();

    expect(CourseRegistration::query()->count())->toBe(1)
        ->and(CourseRegistration::query()->first()?->total_units)->toBe(4);
});

it('shows the service sentence when a course is not eligible', function () {
    $world = registration_rules_world();
    registration_rules_limits(2, 24);

    Livewire::actingAs($world['student']->user)->test(RegistrationPage::class)
        ->set('elective_ids', [999999])
        ->call('submitRegistration')
        ->assertSee(RegistrationService::MESSAGE_NOT_ELIGIBLE)
        ->assertDontSee('SQLSTATE');

    expect(CourseRegistration::query()->count())->toBe(0);
});

it('hides an unexpected failure behind a plain sentence', function () {
    $world = registration_rules_world();
    registration_rules_limits(2, 24);
    $component = Livewire::actingAs($world['student']->user)->test(RegistrationPage::class)->instance();
    $run = new ReflectionMethod(RegistrationPage::class, 'run');

    try {
        $run->invoke($component, app(RegistrationService::class), function (): void {
            throw new RuntimeException('secret-sqlstate');
        }, 'Saved.');
        $caught = false;
    } catch (ValidationException $exception) {
        $caught = true;
        $message = $exception->errors()['registration'][0] ?? '';

        expect($message)->toBe('That registration could not be saved.')
            ->and(str_contains($message, 'secret-sqlstate'))->toBeFalse();
    }

    expect($caught)->toBeTrue();
});
