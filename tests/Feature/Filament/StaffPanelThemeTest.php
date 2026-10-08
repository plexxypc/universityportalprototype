<?php

declare(strict_types=1);

use App\Filament\StaffPanelTheme;
use Filament\FontProviders\LocalFontProvider;

it('themes the staff panel from the design tokens and institution config', function () {
    config([
        'portal.institution.name' => 'Northwind Polytechnic',
        'portal.institution.logo' => 'images/logo-placeholder.svg',
    ]);

    $panel = filament()->getPanel('staff');
    $primary = $panel->getColors()['primary'];
    $labels = array_map(
        fn ($group): ?string => $group->getLabel(),
        $panel->getNavigationGroups(),
    );

    expect($panel->getFontFamily())->toBe('Inter')
        ->and($panel->getFontProvider())->toBe(LocalFontProvider::class)
        ->and((string) $panel->getFontHtml())->not->toContain('fonts.bunny.net')
        ->and($panel->getFontUrl())->toBeNull()
        ->and($panel->hasDarkMode())->toBeFalse()
        ->and($panel->getSidebarWidth())->toBe('240px')
        ->and($panel->isSidebarCollapsibleOnDesktop())->toBeTrue()
        ->and($panel->getViteTheme())->toBe('resources/css/filament/staff/theme.css')
        ->and($primary[50])->toBe('#EEF2FF')
        ->and($primary[500])->toBe('#6366F1')
        ->and($primary[600])->toBe('#4F46E5')
        ->and($primary[700])->toBe('#4338CA')
        ->and($panel->getBrandName())->toBe('Northwind Polytechnic')
        ->and((string) $panel->getBrandLogo())->toContain('images/logo-placeholder.svg')
        ->and($labels)->toBe(StaffPanelTheme::navigationGroupLabels())
        ->and($panel->getResources())->toBe([])
        ->and(is_file(public_path('js/filament/support/support.js')))->toBeTrue();
});
