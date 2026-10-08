<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\StaffPanelTheme;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Staff Filament panel served at /staff.
 *
 * Login stays on Filament's default page until authentication is replaced in Phase 4.
 * Navigation groups follow the PRD module names and stay empty until later pages
 * exist. Filament hides a group that has no items. Role filtering arrives in Phase 4.
 */
class StaffPanelProvider extends PanelProvider
{
    /**
     * Configure the staff panel.
     */
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('staff')
            ->path('staff')
            ->login()
            ->viteTheme('resources/css/filament/staff/theme.css')
            ->font('Inter', provider: LocalFontProvider::class)
            ->brandName(fn (): string => (string) config('portal.institution.name'))
            ->brandLogo(fn (): string => asset((string) config('portal.institution.logo')))
            ->brandLogoHeight('2rem')
            ->darkMode(false)
            ->sidebarWidth('240px')
            ->sidebarCollapsibleOnDesktop()
            ->colors([
                'primary' => StaffPanelTheme::primaryPalette(),
            ])
            ->navigationGroups(array_map(
                fn (string $label): NavigationGroup => NavigationGroup::make($label),
                StaffPanelTheme::navigationGroupLabels(),
            ))
            ->discoverResources(in: app_path('Filament/Staff/Resources'), for: 'App\Filament\Staff\Resources')
            ->discoverPages(in: app_path('Filament/Staff/Pages'), for: 'App\Filament\Staff\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Staff/Widgets'), for: 'App\Filament\Staff\Widgets')
            ->widgets([
                AccountWidget::class,
                FilamentInfoWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
