<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Filament\Staff\Pages\FeeCategoriesPage;
use App\Filament\Staff\Pages\FeeStructuresPage;
use App\Models\AcademicSession;
use App\Models\FeeCategory;
use App\Models\FeeStructure;
use App\Models\InvoiceItem;
use App\Models\Programme;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Services\FeeService;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function fee_user(Role $role): User
{
    $user = User::factory()->create();

    RoleAssignment::factory()->create([
        'user_id' => $user->id,
        'role' => $role,
    ]);

    return $user;
}

function fee_request(): Request
{
    return Request::create('/staff/fee-categories', 'POST', server: [
        'REMOTE_ADDR' => '203.0.113.40',
    ]);
}

it('refuses a duplicate category code and deactivates a category that is in use', function () {
    $bursar = fee_user(Role::Bursar);
    $service = app(FeeService::class);
    $category = $service->createCategory($bursar, 'Tuition', 'tuition', null, 1, fee_request());
    $programme = Programme::factory()->create();
    $session = AcademicSession::factory()->create();

    expect(fn () => $service->createCategory($bursar, 'Other', 'tuition', null, 2, fee_request()))
        ->toThrow(ValidationException::class, 'That category code is already in use.');

    $service->createStructure($bursar, (int) $category->getKey(), (int) $programme->getKey(), 100, (int) $session->getKey(), '150000.50', fee_request());

    $notice = $service->deleteCategory($bursar, (int) $category->getKey(), fee_request());

    expect($notice)->toBe(FeeService::MESSAGE_IN_USE)
        ->and($category->refresh()->is_active)->toBeFalse()
        ->and(FeeCategory::query()->count())->toBe(1);

    $unused = $service->createCategory($bursar, 'ICT levy', 'ict', null, 2, fee_request());
    $service->deleteCategory($bursar, (int) $unused->getKey(), fee_request());

    expect(FeeCategory::query()->whereKey($unused->getKey())->exists())->toBeFalse();
});

it('stores the naira amount as kobo and leaves issued invoices unchanged', function () {
    $bursar = fee_user(Role::Bursar);
    $service = app(FeeService::class);
    $category = $service->createCategory($bursar, 'Tuition', 'tuition', null, 1, fee_request());
    $programme = Programme::factory()->create();
    $session = AcademicSession::factory()->create();
    $structure = $service->createStructure(
        $bursar,
        (int) $category->getKey(),
        (int) $programme->getKey(),
        200,
        (int) $session->getKey(),
        '150000.50',
        fee_request(),
    );
    $item = InvoiceItem::factory()->create([
        'fee_category_id' => $category->id,
        'amount_kobo' => 5_000_000,
        'description' => 'Tuition',
    ]);

    expect($structure->amount_kobo)->toBe(15_000_050)
        ->and(Money::format((int) $structure->amount_kobo))->toBe('₦150,000.50');

    expect(fn () => $service->createStructure($bursar, (int) $category->getKey(), (int) $programme->getKey(), 300, (int) $session->getKey(), '0', fee_request()))
        ->toThrow(ValidationException::class, 'Enter at least 0.01.');

    $service->updateStructure(
        $bursar,
        (int) $structure->getKey(),
        (int) $category->getKey(),
        (int) $programme->getKey(),
        200,
        (int) $session->getKey(),
        '2000',
        fee_request(),
    );

    expect($item->refresh()->amount_kobo)->toBe(5_000_000)
        ->and($item->description)->toBe('Tuition')
        ->and($structure->refresh()->amount_kobo)->toBe(200_000);
});

it('deactivates a category that is already on an invoice', function () {
    $bursar = fee_user(Role::Bursar);
    $service = app(FeeService::class);
    $category = $service->createCategory($bursar, 'Library', 'library', null, 1, fee_request());
    InvoiceItem::factory()->create(['fee_category_id' => $category->id]);

    expect($service->deleteCategory($bursar, (int) $category->getKey(), fee_request()))
        ->toBe(FeeService::MESSAGE_IN_USE)
        ->and($category->refresh()->is_active)->toBeFalse();
});

it('lets the bursar manage fees and lets the registrar only view', function () {
    $bursar = fee_user(Role::Bursar);
    $registrar = fee_user(Role::Registrar);

    Livewire::actingAs($bursar)
        ->test(FeeStructuresPage::class)
        ->assertSee('Changing a price does not change invoices that were already issued.');

    Livewire::actingAs($registrar)
        ->test(FeeCategoriesPage::class)
        ->call('saveCategory', 0, ['name' => 'Tuition', 'code' => 'tuition', 'description' => '', 'sort' => 1])
        ->assertForbidden();

    expect(fn () => app(FeeService::class)->createCategory($registrar, 'Tuition', 'tuition', null, 1, fee_request()))
        ->toThrow(AuthorizationException::class);

    expect(FeeCategory::query()->count())->toBe(0);
});

it('refuses a second price for the same programme, level, session, and category', function () {
    $bursar = fee_user(Role::Bursar);
    $service = app(FeeService::class);
    $category = FeeCategory::factory()->create();
    $programme = Programme::factory()->create();
    $session = AcademicSession::factory()->create();
    $service->createStructure($bursar, (int) $category->getKey(), (int) $programme->getKey(), 100, (int) $session->getKey(), '10', fee_request());

    expect(fn () => $service->createStructure($bursar, (int) $category->getKey(), (int) $programme->getKey(), 100, (int) $session->getKey(), '20', fee_request()))
        ->toThrow(ValidationException::class, 'That programme, level, session, and category already has a price.');

    expect(FeeStructure::query()->count())->toBe(1);
});
