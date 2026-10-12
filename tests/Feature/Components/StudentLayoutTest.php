<?php

declare(strict_types=1);

use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders the student shell from institution config', function () {
    $this->withoutVite();
    config([
        'portal.institution.name' => 'Northwind Polytechnic',
        'portal.institution.code' => 'NWP',
        'portal.institution.logo' => 'images/logo-placeholder.svg',
    ]);

    $brand = app(SettingsService::class)->brand();
    $html = view('design-preview-student')->render();

    expect($html)->toContain('Northwind Polytechnic')
        ->and($html)->toContain('NWP')
        ->and($html)->toContain($brand['logo_url'])
        ->and($html)->not->toContain('University Portal')
        ->and($html)->toContain('Home')
        ->and($html)->toContain('Courses')
        ->and($html)->toContain('Fees')
        ->and($html)->toContain('Results')
        ->and($html)->toContain('More')
        ->and($html)->toContain('min-h-11')
        ->and($html)->toContain('md:hidden')
        ->and($html)->toContain('md:flex')
        ->and($html)->toContain('aria-label="Breadcrumb"')
        ->and($html)->toContain('aria-current="page"')
        ->and($html)->toContain('Pay now')
        ->and($html)->toContain('Notifications, 2 unread')
        ->and($html)->toContain('Ada Okonkwo');
});
