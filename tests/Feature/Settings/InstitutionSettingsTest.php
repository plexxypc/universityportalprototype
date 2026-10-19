<?php

declare(strict_types=1);

use App\Enums\EmailTemplate;
use App\Enums\Role;
use App\Filament\Staff\Pages\InstitutionSettingsPage;
use App\Http\Controllers\InstitutionLogoController;
use App\Models\AuditLog;
use App\Models\InstitutionSetting;
use App\Models\RoleAssignment;
use App\Models\Student;
use App\Models\User;
use App\Services\RegistrationService;
use App\Services\SettingsService;
use App\Support\InstitutionLogo;
use App\Support\Mail\EmailTemplateRenderer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function settings_input(array $overrides = []): array
{
    return array_merge([
        'name' => 'Northwind Polytechnic',
        'code' => 'nwp',
        'address' => '1 Campus Road',
        'phone' => '+234 801 234 5678',
        'email' => 'registrar@example.test',
        'motto' => 'Learn',
        'matric_pattern' => '{DEPT}/{YEAR}/{SEQ4}',
        'min_units' => 15,
        'max_units' => 24,
        'approval_required' => true,
        'withhold_results_for_debt' => true,
        'attendance_threshold' => 75,
        'require_minimum_payment' => false,
    ], $overrides);
}

function settings_user(Role $role): User
{
    $user = User::factory()->create();

    if ($role === Role::FacultyAdmin) {
        RoleAssignment::factory()->facultyAdmin()->create([
            'user_id' => $user->id,
        ]);

        return $user;
    }

    RoleAssignment::factory()->create([
        'user_id' => $user->id,
        'role' => $role,
    ]);

    return $user;
}

function settings_request(): Request
{
    return Request::create('/staff/institution-settings', 'POST', server: [
        'REMOTE_ADDR' => '203.0.113.8',
    ]);
}

/**
 * Save a logo that must be refused, and leave no settings row behind.
 */
function settings_refuse_logo(UploadedFile $logo, string $message): void
{
    Storage::fake('local');
    config(['filesystems.default' => 'local']);

    try {
        app(SettingsService::class)->save(
            settings_user(Role::SuperAdmin),
            settings_input(),
            $logo,
            settings_request(),
        );
        expect(false)->toBeTrue();
    } catch (ValidationException $exception) {
        expect($exception->errors()['logo'][0] ?? null)->toBe($message);
    }

    expect(InstitutionSetting::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
}

/**
 * A forged Livewire save. Mounting as the caller is refused. A component
 * opened by a Super Admin is also refused when the caller posts save.
 */
function settings_forge_save(User $user): void
{
    Livewire::actingAs($user)
        ->test(InstitutionSettingsPage::class)
        ->assertForbidden();

    $admin = settings_user(Role::SuperAdmin);
    $page = Livewire::actingAs($admin)
        ->test(InstitutionSettingsPage::class)
        ->assertOk();

    Livewire::actingAs($user);

    try {
        $page->call('save');
    } catch (HttpException $exception) {
        if ($exception->getStatusCode() !== 403) {
            throw $exception;
        }

        return;
    } catch (AuthorizationException) {
        return;
    }

    $page->assertForbidden();
}

it('falls back to portal config when institution settings are empty', function () {
    config([
        'portal.institution.name' => 'Northwind Polytechnic',
        'portal.institution.code' => 'NWP',
        'portal.institution.matric_pattern' => '{DEPT}/{YEAR}/{SEQ4}',
        'portal.institution.withhold_results_for_debt' => true,
    ]);

    $effective = app(SettingsService::class)->effective();

    expect(InstitutionSetting::query()->exists())->toBeFalse()
        ->and($effective['name'])->toBe('Northwind Polytechnic')
        ->and($effective['code'])->toBe('NWP')
        ->and($effective['matric_pattern'])->toBe('{DEPT}/{YEAR}/{SEQ4}')
        ->and($effective['withhold_results_for_debt'])->toBeTrue();
});

it('keeps the effective withhold setting on the first save', function () {
    config([
        'portal.institution.name' => 'Northwind Polytechnic',
        'portal.institution.code' => 'NWP',
        'portal.institution.matric_pattern' => '{DEPT}/{YEAR}/{SEQ4}',
        'portal.institution.withhold_results_for_debt' => true,
        'portal.institution.min_units' => 15,
        'portal.institution.max_units' => 24,
        'portal.institution.approval_required' => true,
        'portal.institution.attendance_threshold' => 75,
    ]);

    $admin = settings_user(Role::SuperAdmin);

    Livewire::actingAs($admin)
        ->test(InstitutionSettingsPage::class)
        ->assertSet('data.name', 'Northwind Polytechnic')
        ->assertSet('data.matric_pattern', '{DEPT}/{YEAR}/{SEQ4}')
        ->assertSet('data.withhold_results_for_debt', true)
        ->call('save')
        ->assertHasNoErrors();

    $row = InstitutionSetting::query()->first();

    expect($row)->not->toBeNull()
        ->and($row->name)->toBe('Northwind Polytechnic')
        ->and($row->code)->toBe('NWP')
        ->and($row->matric_pattern)->toBe('{DEPT}/{YEAR}/{SEQ4}')
        ->and($row->withhold_results_for_debt)->toBeTrue();
});

it('refuses a registrar and a faculty admin on institution settings', function (Role $role) {
    $user = settings_user($role);

    $this->actingAs($user)->get('/staff/institution-settings')->assertForbidden();
    settings_forge_save($user);

    expect(InstitutionSetting::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', SettingsService::ACTION_UPDATED)->count())->toBe(0);
})->with([
    Role::Registrar,
    Role::FacultyAdmin,
]);

it('forgets the institution settings cache on save', function () {
    $admin = settings_user(Role::SuperAdmin);
    $service = app(SettingsService::class);
    $service->effective();

    expect(Cache::has(SettingsService::CACHE_KEY))->toBeTrue();

    $service->save($admin, settings_input(), null, settings_request());

    expect(Cache::has(SettingsService::CACHE_KEY))->toBeFalse();
});

it('writes a before and after audit row without secrets', function () {
    $admin = settings_user(Role::SuperAdmin);
    $service = app(SettingsService::class);
    $service->save($admin, settings_input(), null, settings_request());
    $service->save($admin, settings_input(['motto' => 'Serve']), null, settings_request());

    $row = AuditLog::query()->where('action', SettingsService::ACTION_UPDATED)->latest('id')->first();
    $encoded = json_encode($row?->before).json_encode($row?->after);

    expect($row)->not->toBeNull()
        ->and($row->ip)->toBe('203.0.113.8')
        ->and($row->before['motto'] ?? null)->toBe('Learn')
        ->and($row->after['motto'] ?? null)->toBe('Serve')
        ->and(str_contains(strtolower($encoded), 'password'))->toBeFalse()
        ->and(str_contains(strtolower($encoded), 'token'))->toBeFalse();
});

it('does not rewrite matric numbers when the pattern changes', function () {
    $admin = settings_user(Role::SuperAdmin);
    $student = Student::factory()->create([
        'matric_no' => 'CSC/2026/0001',
    ]);

    app(SettingsService::class)->save(
        $admin,
        settings_input(['matric_pattern' => '{YEAR}/{SEQ}']),
        null,
        settings_request(),
    );

    expect($student->refresh()->matric_no)->toBe('CSC/2026/0001');
});

it('warns that minimum payment before registration is not available yet', function () {
    Livewire::actingAs(settings_user(Role::SuperAdmin))
        ->test(InstitutionSettingsPage::class)
        ->assertSee(RegistrationService::MESSAGE_PAYMENT_UNAVAILABLE);
});

it('shows the saved name and code on the login page and in email', function () {
    $admin = settings_user(Role::SuperAdmin);

    app(SettingsService::class)->save(
        $admin,
        settings_input([
            'name' => 'Harbor College',
            'code' => 'HBC',
        ]),
        null,
        settings_request(),
    );

    $this->get('/login')
        ->assertOk()
        ->assertSee('Harbor College')
        ->assertSee('HBC');

    $rendered = app(EmailTemplateRenderer::class)->render(EmailTemplate::Admission, [
        'name' => 'Ada',
        'programme' => 'BSc Computer Science',
    ]);

    expect($rendered->html)->toContain('Harbor College')
        ->and($rendered->html)->not->toContain('/institution/logo')
        ->and($rendered->html)->not->toContain('<img');
});

it('serves the institution logo with a public cache and no path', function () {
    Storage::fake('local');
    config(['filesystems.default' => 'local']);

    $admin = settings_user(Role::SuperAdmin);
    $png = logo_png(8, 8);
    $upload = UploadedFile::fake()->createWithContent('logo.png', $png);

    app(SettingsService::class)->save($admin, settings_input(), $upload, settings_request());

    $route = Route::getRoutes()->getByName('institution.logo');

    expect($route)->not->toBeNull()
        ->and($route->uri())->toBe('institution/logo')
        ->and($route->parameterNames())->toBe([]);

    $this->get('/institution/logo/extra')->assertNotFound();

    $response = $this->get('/institution/logo?v=ignored&path=../.env');
    $etag = $response->headers->get('ETag');
    $cache = (string) $response->headers->get('Cache-Control');

    $response->assertOk();
    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Content-Type'))->toBe('image/png')
        ->and($cache)->toContain('public')
        ->and($cache)->toContain('max-age=31536000')
        ->and($cache)->not->toContain('no-store')
        ->and($response->getContent())->toBe($png)
        ->and(is_string($etag))->toBeTrue();

    $this->get('/institution/logo?v=ignored', ['If-None-Match' => (string) $etag])
        ->assertStatus(304)
        ->assertHeader('ETag', (string) $etag);
});

it('returns 304 when the stored logo etag matches', function () {
    Storage::fake('local');
    config(['filesystems.default' => 'local']);

    $admin = settings_user(Role::SuperAdmin);
    $png = logo_png(8, 8);

    app(SettingsService::class)->save(
        $admin,
        settings_input(),
        UploadedFile::fake()->createWithContent('logo.png', $png),
        settings_request(),
    );

    $etag = $this->get('/institution/logo')->headers->get('ETag');

    $matched = $this->get('/institution/logo', ['If-None-Match' => (string) $etag]);
    $cache = (string) $matched->headers->get('Cache-Control');

    $matched->assertStatus(304)->assertHeader('ETag', (string) $etag);

    foreach (explode(', ', InstitutionLogoController::CACHE_CONTROL) as $part) {
        expect($cache)->toContain($part);
    }

    expect($cache)->not->toContain('no-store');
});

it('changes the logo url after a new upload', function () {
    Storage::fake('local');
    config(['filesystems.default' => 'local']);

    $admin = settings_user(Role::SuperAdmin);
    $service = app(SettingsService::class);

    $service->save(
        $admin,
        settings_input(),
        UploadedFile::fake()->createWithContent('logo.png', logo_png(8, 8)),
        settings_request(),
    );
    $first = $service->brand()['logo_url'];

    $service->save(
        $admin,
        settings_input(),
        UploadedFile::fake()->createWithContent('logo.png', logo_png(16, 16)),
        settings_request(),
    );
    $second = $service->brand()['logo_url'];

    expect($first)->toContain('/institution/logo')
        ->and($first)->toContain('v=')
        ->and($second)->toContain('/institution/logo')
        ->and($second)->toContain('v=')
        ->and($first)->not->toBe($second);
});

it('falls back to the placeholder when the logo file is missing', function () {
    $default = public_path('images/logo-default.png');
    $backup = is_file($default) ? file_get_contents($default) : null;

    if (is_file($default)) {
        unlink($default);
    }

    try {
        Cache::flush();
        $url = app(SettingsService::class)->brand()['logo_url'];

        expect($url)->toContain('images/logo-placeholder.svg');

        $response = $this->get('/institution/logo?path=../.env');
        $cache = (string) $response->headers->get('Cache-Control');

        $response->assertRedirect($url);
        expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
            ->and($cache)->toContain('no-store')
            ->and($cache)->not->toContain('max-age=31536000')
            ->and($cache)->not->toContain('public');
    } finally {
        if (is_string($backup)) {
            file_put_contents($default, $backup);
        }
    }
});

it('uses logo-default.png when that file exists and no logo is stored', function () {
    $default = public_path('images/logo-default.png');
    $backup = is_file($default) ? file_get_contents($default) : null;
    file_put_contents($default, logo_png(4, 4));

    try {
        Cache::flush();
        $url = app(SettingsService::class)->brand()['logo_url'];

        expect($url)->toContain('images/logo-default.png')
            ->and($url)->toContain('v=')
            ->and($url)->not->toContain('/institution/logo');
    } finally {
        if (is_string($backup)) {
            file_put_contents($default, $backup);
        } else {
            unlink($default);
        }
    }
});

it('refuses an svg logo upload', function () {
    settings_refuse_logo(
        UploadedFile::fake()->createWithContent(
            'logo.svg',
            '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"></svg>',
        ),
        InstitutionLogo::MESSAGE_TYPE,
    );
});

it('refuses a renamed svg logo', function () {
    settings_refuse_logo(
        UploadedFile::fake()->createWithContent(
            'logo.png',
            '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"></svg>',
        ),
        InstitutionLogo::MESSAGE_TYPE,
    );
});

it('refuses a logo whose content type does not match', function () {
    settings_refuse_logo(
        UploadedFile::fake()->createWithContent('logo.png', logo_jpeg(8, 8)),
        InstitutionLogo::MESSAGE_MISMATCH,
    );
});

it('refuses an oversize logo upload', function () {
    settings_refuse_logo(
        UploadedFile::fake()->create('logo.png', 1025),
        InstitutionLogo::MESSAGE_SIZE,
    );
});

it('refuses a corrupt logo upload', function () {
    settings_refuse_logo(
        UploadedFile::fake()->createWithContent('logo.png', substr(logo_png(8, 8), 0, 24)),
        InstitutionLogo::MESSAGE_READ,
    );
});

it('refuses a logo upload larger than 2000 by 2000 pixels', function () {
    settings_refuse_logo(
        UploadedFile::fake()->createWithContent('logo.png', logo_png(2001, 1)),
        InstitutionLogo::MESSAGE_DIMENSIONS,
    );
});
