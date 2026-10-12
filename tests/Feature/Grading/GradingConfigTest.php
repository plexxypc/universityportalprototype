<?php

declare(strict_types=1);

use App\Enums\ResultStatus;
use App\Enums\Role;
use App\Filament\Staff\Pages\GradingConfigurationPage;
use App\Models\AcademicStandingThreshold;
use App\Models\AuditLog;
use App\Models\GradingScheme;
use App\Models\Result;
use App\Models\ResultScore;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Services\GradingConfigService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * An active staff account with one role.
 */
function grading_user(Role $role): User
{
    $user = User::factory()->create();

    RoleAssignment::factory()->create([
        'user_id' => $user->id,
        'role' => $role,
    ]);

    return $user;
}

/**
 * A request whose address is the audit ip.
 */
function grading_request(): Request
{
    return Request::create('/staff/grading-configuration', 'POST', server: [
        'REMOTE_ADDR' => '203.0.113.20',
    ]);
}

it('stores a standing row only when a threshold is set', function () {
    $scheme = GradingScheme::factory()->create();

    AcademicStandingThreshold::query()->create([
        'scheme_id' => $scheme->id,
        'warning_cgpa' => null,
        'probation_cgpa' => null,
    ]);

    expect(AcademicStandingThreshold::query()->count())->toBe(1);

    expect(fn () => AcademicStandingThreshold::factory()->create([
        'scheme_id' => GradingScheme::factory(),
        'warning_cgpa' => '1.00',
        'probation_cgpa' => '1.00',
    ]))->toThrow(QueryException::class);
});

it('does not insert a scheme from the migration', function () {
    expect(GradingScheme::query()->count())->toBe(0);
});

it('loads the default scheme only from the button when none exists', function () {
    $admin = grading_user(Role::SuperAdmin);

    Livewire::actingAs($admin)
        ->test(GradingConfigurationPage::class)
        ->assertActionVisible('loadDefault')
        ->call('loadDefault')
        ->assertNotified('Default scheme loaded')
        ->assertActionHidden('loadDefault');

    $scheme = GradingScheme::query()->where('is_active', true)->first();

    expect($scheme)->not->toBeNull()
        ->and((string) $scheme?->pass_mark)->toBe('40.00')
        ->and($scheme?->assessmentComponents()->count())->toBe(2)
        ->and($scheme?->standing()->exists())->toBeFalse();

    expect(fn () => app(GradingConfigService::class)->loadDefault($admin, grading_request()))
        ->toThrow(ValidationException::class, 'A grading scheme already exists.');
});

it('refuses component totals that are not 100', function () {
    $admin = grading_user(Role::SuperAdmin);
    $service = app(GradingConfigService::class);
    $input = $service->defaultInput();
    $input['components'][0]['max_score'] = '40.00';
    $input['components'][1]['max_score'] = '40.00';

    expect(fn () => $service->saveVersion($admin, $input, grading_request()))
        ->toThrow(ValidationException::class, 'Component maximums must add up to 100.00.');
});

it('refuses grade bands that leave a gap', function () {
    $admin = grading_user(Role::SuperAdmin);
    $service = app(GradingConfigService::class);
    $input = $service->defaultInput();
    $input['bands'][1]['min_score'] = '60.01';

    expect(fn () => $service->saveVersion($admin, $input, grading_request()))
        ->toThrow(ValidationException::class, 'Grade bands must run from 0.00 to 100.00 without a gap.');
});

it('refuses a pass mark that does not sit one hundredth above the fail band', function () {
    $admin = grading_user(Role::SuperAdmin);
    $service = app(GradingConfigService::class);
    $input = $service->defaultInput();
    $input['pass_mark'] = '50.00';

    expect(fn () => $service->saveVersion($admin, $input, grading_request()))
        ->toThrow(ValidationException::class, 'The pass mark must be one hundredth above the fail band.');
});

it('requires classification bands to end at the highest grade point', function () {
    $admin = grading_user(Role::SuperAdmin);
    $service = app(GradingConfigService::class);
    $input = $service->defaultInput();
    $input['bands'][0]['points'] = '4.00';

    expect(fn () => $service->saveVersion($admin, $input, grading_request()))
        ->toThrow(ValidationException::class, 'Classification bands must run from 0.00 to 4.00 without a gap.');
});

it('keeps exactly one active scheme after a version save and after a failed one', function () {
    $admin = grading_user(Role::SuperAdmin);
    $service = app(GradingConfigService::class);
    $original = $service->loadDefault($admin, grading_request());
    $revised = $service->defaultInput();
    $revised['name'] = 'Nigerian 5-point revised';
    $next = $service->saveVersion($admin, $revised, grading_request());
    $failed = $service->defaultInput();
    $failed['components'][0]['max_score'] = '10.00';

    expect(fn () => $service->saveVersion($admin, $failed, grading_request()))
        ->toThrow(ValidationException::class);

    expect(GradingScheme::query()->where('is_active', true)->count())->toBe(1)
        ->and($next->refresh()->is_active)->toBeTrue()
        ->and($original->refresh()->is_active)->toBeFalse()
        ->and(GradingScheme::query()->count())->toBe(2);
});

it('refuses a tampered id on the grading screen', function () {
    $admin = grading_user(Role::SuperAdmin);
    $scheme = app(GradingConfigService::class)->loadDefault($admin, grading_request());

    Livewire::actingAs($admin)
        ->test(GradingConfigurationPage::class)
        ->call('save', 999999);

    expect(GradingScheme::query()->whereKey(999999)->exists())->toBeFalse()
        ->and($scheme->refresh()->name)->toBe('Nigerian 5-point')
        ->and($scheme->is_active)->toBeFalse()
        ->and(GradingScheme::query()->where('is_active', true)->count())->toBe(1)
        ->and(GradingScheme::query()->count())->toBe(2);
});

it('allows only a super admin to save the grading scheme', function () {
    $admin = grading_user(Role::SuperAdmin);
    $input = app(GradingConfigService::class)->defaultInput();

    Livewire::actingAs($admin)
        ->test(GradingConfigurationPage::class)
        ->fillForm($input)
        ->call('save')
        ->assertNotified('Grading scheme saved');

    expect(GradingScheme::query()->where('is_active', true)->count())->toBe(1);

    $refused = [
        Role::Registrar,
        Role::ExamOfficer,
        Role::Bursar,
        Role::FacultyAdmin,
        Role::DepartmentOfficer,
        Role::Lecturer,
    ];

    foreach ($refused as $role) {
        $actor = grading_user($role);
        $can_open = in_array($role, [Role::Registrar, Role::ExamOfficer], true);

        if ($can_open) {
            Livewire::actingAs($actor)
                ->test(GradingConfigurationPage::class)
                ->call('save')
                ->assertForbidden();
        } else {
            Livewire::actingAs($actor)
                ->test(GradingConfigurationPage::class)
                ->assertForbidden();
        }

        $page = Livewire::actingAs($admin)
            ->test(GradingConfigurationPage::class);

        Livewire::actingAs($actor);

        $page->call('save')->assertForbidden();

        expect(GradingScheme::query()->count())->toBe(1);
    }
});

it('refuses classification bands that leave a gap', function () {
    $admin = grading_user(Role::SuperAdmin);
    $service = app(GradingConfigService::class);
    $input = $service->defaultInput();
    $input['classifications'][0]['min_cgpa'] = '0.01';

    expect(fn () => $service->saveVersion($admin, $input, grading_request()))
        ->toThrow(ValidationException::class, 'Classification bands must run from 0.00 to 5.00 without a gap.');
});

it('refuses standing when both thresholds are blank', function () {
    $admin = grading_user(Role::SuperAdmin);
    $service = app(GradingConfigService::class);
    $input = $service->defaultInput();
    $input['track_standing'] = true;

    expect(fn () => $service->saveVersion($admin, $input, grading_request()))
        ->toThrow(ValidationException::class, 'Set a warning CGPA, a probation CGPA, or leave standing off.');

    expect(GradingScheme::query()->count())->toBe(0);
});

it('versions the scheme and remaps unpublished scores in one transaction', function () {
    $admin = grading_user(Role::SuperAdmin);
    $service = app(GradingConfigService::class);
    $original = $service->loadDefault($admin, grading_request());
    $exam = $original->assessmentComponents()->where('name', 'Exam')->firstOrFail();

    $draft = Result::factory()->create([
        'grading_scheme_id' => $original->id,
        'scheme_version' => $original->version,
        'status' => ResultStatus::Draft,
    ]);
    $draft_score = ResultScore::factory()->create([
        'result_id' => $draft->id,
        'component_id' => $exam->id,
        'score' => '10.00',
    ]);
    $published = Result::factory()->published()->create([
        'grading_scheme_id' => $original->id,
        'scheme_version' => $original->version,
    ]);
    $published_score = ResultScore::factory()->create([
        'result_id' => $published->id,
        'component_id' => $exam->id,
        'score' => '40.00',
    ]);

    $input = $service->defaultInput();
    $input['name'] = 'Nigerian 5-point revised';
    $input['track_standing'] = true;
    $input['warning_cgpa'] = '1.50';
    $input['probation_cgpa'] = '1.00';
    Cache::put(GradingConfigService::CACHE_KEY, $original->id);

    $next = $service->saveVersion($admin, $input, grading_request());

    expect($original->refresh()->is_active)->toBeFalse()
        ->and($next->is_active)->toBeTrue()
        ->and($next->version)->toBe(2)
        ->and($draft->refresh()->grading_scheme_id)->toBe($next->id)
        ->and($draft->scheme_version)->toBe(2)
        ->and($published->refresh()->grading_scheme_id)->toBe($original->id)
        ->and($published_score->refresh()->component_id)->toBe($exam->id)
        ->and($draft_score->refresh()->component_id)->not->toBe($exam->id)
        ->and((string) $next->standing?->warning_cgpa)->toBe('1.50')
        ->and((string) $next->standing?->probation_cgpa)->toBe('1.00')
        ->and(Cache::get(GradingConfigService::CACHE_KEY))->toBeNull();

    $moved = $next->assessmentComponents()->where('name', 'Exam')->first();

    expect($draft_score->component_id)->toBe($moved?->id);

    $row = AuditLog::query()->where('action', GradingConfigService::VERSIONED)->latest('id')->first();

    expect($row)->not->toBeNull()
        ->and($row?->ip)->toBe('203.0.113.20')
        ->and($row?->before['is_active'] ?? null)->toBeTrue()
        ->and($row?->after['is_active'] ?? null)->toBeTrue();
});

it('rolls back when a removed component still has an unpublished score', function () {
    $admin = grading_user(Role::SuperAdmin);
    $service = app(GradingConfigService::class);
    $original = $service->loadDefault($admin, grading_request());
    $exam = $original->assessmentComponents()->where('name', 'Exam')->firstOrFail();
    $draft = Result::factory()->create([
        'grading_scheme_id' => $original->id,
        'scheme_version' => $original->version,
    ]);
    $score = ResultScore::factory()->create([
        'result_id' => $draft->id,
        'component_id' => $exam->id,
    ]);
    $input = $service->defaultInput();
    $input['components'] = [
        ['name' => 'Continuous Assessment', 'max_score' => '100.00'],
    ];

    expect(fn () => $service->saveVersion($admin, $input, grading_request()))
        ->toThrow(ValidationException::class, 'Exam still has an unpublished score, so it cannot be removed.');

    expect(GradingScheme::query()->count())->toBe(1)
        ->and($original->refresh()->is_active)->toBeTrue()
        ->and($score->refresh()->component_id)->toBe($exam->id)
        ->and($draft->refresh()->grading_scheme_id)->toBe($original->id);
});

it('lets the registrar view the grading screen and refuses a save', function () {
    $registrar = grading_user(Role::Registrar);

    Livewire::actingAs($registrar)
        ->test(GradingConfigurationPage::class)
        ->assertActionHidden('loadDefault')
        ->call('save')
        ->assertForbidden();

    $faculty_admin = User::factory()->create();
    RoleAssignment::factory()->facultyAdmin()->create([
        'user_id' => $faculty_admin->id,
    ]);

    Livewire::actingAs($faculty_admin)
        ->test(GradingConfigurationPage::class)
        ->assertForbidden();
});
