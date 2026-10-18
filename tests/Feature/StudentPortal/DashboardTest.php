<?php

declare(strict_types=1);

use App\Enums\InvoiceStatus;
use App\Enums\RegistrationStatus;
use App\Enums\UserStatus;
use App\Livewire\Student\Dashboard;
use App\Models\AcademicSession;
use App\Models\CourseRegistration;
use App\Models\Invoice;
use App\Models\Notification;
use App\Models\Programme;
use App\Models\Semester;
use App\Models\Student;
use App\Models\User;
use App\Services\StudentPortalService;
use App\Support\CurrentStudent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;
use Livewire\Attributes\Lazy;
use Livewire\Features\SupportLazyLoading\SupportLazyLoading;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;

uses(RefreshDatabase::class);

/**
 * A fictional student with a stable name and matric number.
 */
function portal_student(string $matric_no = 'CSC/2026/0142', string $first_name = 'Chioma'): Student
{
    $user = User::factory()->create([
        'status' => UserStatus::Active,
        'must_change_password' => false,
    ]);
    $programme = Programme::factory()->create([
        'name' => 'Computer Science '.$matric_no,
        'code' => 'CS'.substr($matric_no, -4),
    ]);
    $session = AcademicSession::factory()->create([
        'name' => '2019/2020-'.$matric_no,
    ]);

    return Student::factory()->create([
        'user_id' => $user->id,
        'programme_id' => $programme->id,
        'entry_session_id' => $session->id,
        'first_name' => $first_name,
        'last_name' => 'Adeyemi',
        'other_names' => null,
        'matric_no' => $matric_no,
        'level' => 200,
    ]);
}

/**
 * Post the dashboard's lazy resume using the snapshot from the first response.
 */
function portal_lazy_update(string $html): TestResponse
{
    $matched = preg_match('/wire:snapshot="([^"]*)"/', $html, $snapshot);
    $lazy = preg_match('/__lazyLoad\(\'([^\']+)\'\)/', html_entity_decode($html, ENT_QUOTES), $encoded);

    expect($matched)->toBe(1)
        ->and($lazy)->toBe(1);

    return test()
        ->withHeader('X-Livewire', 'true')
        ->withHeader('X-CSRF-TOKEN', csrf_token())
        ->postJson(EndpointResolver::updatePath(), [
            'components' => [
                [
                    'snapshot' => html_entity_decode($snapshot[1], ENT_QUOTES),
                    'updates' => [],
                    'calls' => [
                        ['method' => '__lazyLoad', 'params' => [$encoded[1]]],
                    ],
                ],
            ],
        ]);
}

it('shows the signed-in student and empty cards', function () {
    Livewire::withoutLazyLoading();
    $student = portal_student();

    $page = $this->actingAs($student->user)->get('/student');
    $html = (string) $page->getContent();

    $page->assertOk();

    expect(str_contains($html, 'Hello, Chioma'))->toBeTrue()
        ->and(str_contains($html, 'Chioma Adeyemi'))->toBeTrue()
        ->and(str_contains($html, 'CSC/2026/0142'))->toBeTrue()
        ->and(str_contains($html, 'Computer Science CSC/2026/0142'))->toBeTrue()
        ->and(str_contains($html, '200'))->toBeTrue()
        ->and(str_contains($html, 'Ada Okonkwo'))->toBeFalse()
        ->and(str_contains($html, 'CSC/2026/001'))->toBeFalse()
        ->and(str_contains($html, 'There is no active semester.'))->toBeTrue()
        ->and(str_contains($html, 'There is no current session.'))->toBeTrue()
        ->and(str_contains($html, 'You have no notifications.'))->toBeTrue()
        ->and(str_contains($html, 'Soon'))->toBeTrue()
        ->and(str_contains($html, 'grid-cols-5'))->toBeTrue()
        ->and(str_contains($html, 'aria-disabled="true"'))->toBeTrue();

    preg_match_all('/<a\b[^>]*>[\s\S]*?<\/a>/', $html, $anchors);

    foreach ($anchors[0] as $anchor) {
        expect(str_contains($anchor, '>Courses<'))->toBeFalse()
            ->and(str_contains($anchor, '>Fees<'))->toBeFalse()
            ->and(str_contains($anchor, '>Results<'))->toBeFalse();
    }
});

it('shows this student only and checks the fee integers', function () {
    Livewire::withoutLazyLoading();
    $student = portal_student();
    $other = portal_student('MTH/2024/0099', 'Tunde');
    $session = AcademicSession::factory()->current()->create(['name' => '2026/2027']);
    $semester = Semester::factory()->active()->create([
        'session_id' => $session->id,
        'name' => 'First',
    ]);

    CourseRegistration::factory()->create([
        'student_id' => $student->id,
        'semester_id' => $semester->id,
        'status' => RegistrationStatus::Submitted,
    ]);
    Invoice::factory()->cancelled()->create([
        'student_id' => $student->id,
        'session_id' => $session->id,
        'number' => 'INV-CANCELLED-A',
    ]);
    Invoice::factory()->create([
        'student_id' => $student->id,
        'session_id' => $session->id,
        'number' => 'INV-LIVE-A',
        'total_kobo' => 150_000,
        'adjustments_kobo' => 20_000,
        'paid_kobo' => 30_000,
        'status' => InvoiceStatus::PartPaid,
    ]);
    Invoice::factory()->create([
        'student_id' => $other->id,
        'session_id' => $session->id,
        'number' => 'INV-LIVE-B',
        'total_kobo' => 999_999,
        'adjustments_kobo' => 0,
        'paid_kobo' => 0,
        'status' => InvoiceStatus::Unpaid,
    ]);
    Notification::factory()->create([
        'user_id' => $student->user_id,
        'data' => ['title' => 'Library closes early', 'message' => 'At six'],
    ]);
    Notification::factory()->create([
        'user_id' => $other->user_id,
        'data' => ['title' => 'Secret notice for B', 'message' => 'Hidden'],
    ]);

    $card = app(StudentPortalService::class)->registrationCard($student->fresh() ?? $student);
    $html = (string) $this->actingAs($student->user)->get('/student')->assertOk()->getContent();

    expect($card['state'])->toBe('found')
        ->and(str_contains($html, 'Submitted'))->toBeTrue()
        ->and(str_contains($html, 'INV-LIVE-A'))->toBeTrue()
        ->and(str_contains($html, '1,000.00'))->toBeTrue()
        ->and(str_contains($html, 'Library closes early'))->toBeTrue()
        ->and(str_contains($html, 'INV-CANCELLED-A'))->toBeFalse()
        ->and(str_contains($html, 'INV-LIVE-B'))->toBeFalse()
        ->and(str_contains($html, 'Secret notice for B'))->toBeFalse()
        ->and(str_contains($html, 'Tunde'))->toBeFalse()
        ->and(StudentPortalService::dueKobo(150_000, 20_000, 30_000))->toBe(100_000)
        ->and(fn () => StudentPortalService::dueKobo(100, 40, 70))->toThrow(InvalidArgumentException::class)
        ->and(fn () => StudentPortalService::dueKobo(-1, 0, 0))->toThrow(InvalidArgumentException::class);
});

it('refuses a lazy dashboard request after sign-out or suspension', function () {
    $lazy = new ReflectionProperty(SupportLazyLoading::class, 'disableWhileTesting');
    $lazy->setValue(null, false);

    $student = portal_student();
    $this->actingAs($student->user);

    $page = $this->get('/student')->assertOk();
    $html = (string) $page->getContent();

    expect(str_contains($html, 'id="student-dashboard-loading"'))->toBeTrue()
        ->and(str_contains($html, 'id="student-dashboard"'))->toBeFalse()
        ->and(str_contains($html, 'Chioma'))->toBeTrue();

    auth()->logout();

    $signed_out = portal_lazy_update($html);
    expect($signed_out->status())->not->toBe(200)
        ->and(str_contains((string) $signed_out->getContent(), 'Hello, Chioma'))->toBeFalse();

    $this->actingAs($student->user);
    $again = (string) $this->get('/student')->assertOk()->getContent();
    $student->user->forceFill(['status' => UserStatus::Suspended])->save();

    $suspended = portal_lazy_update($again);
    $suspended->assertRedirect(route('login'));
    expect(str_contains((string) $suspended->getContent(), 'Hello, Chioma'))->toBeFalse();
    $this->assertGuest();
});

it('signs out when the helper finds no student row', function () {
    $student = portal_student();
    $this->actingAs($student->user);
    $student->delete();
    $student->user->unsetRelation('student');

    $session = $this->app['session']->driver();
    $session->start();
    $request = Request::create('/student', 'GET');
    $request->setLaravelSession($session);
    $request->setUserResolver(fn (): User => $student->user);
    $this->app->instance('request', $request);

    try {
        CurrentStudent::require();
        expect(false)->toBeTrue();
    } catch (HttpResponseException $exception) {
        expect($exception->getResponse()->isRedirect(route('login')))->toBeTrue();
    }

    $this->assertGuest();
    $this->get('/student')->assertRedirect(route('login'));
});

it('keeps the student id off the dashboard component', function () {
    $reflection = new ReflectionClass(Dashboard::class);
    $names = [];

    foreach ($reflection->getProperties() as $property) {
        if ($property->getDeclaringClass()->getName() === Dashboard::class && $property->isPublic()) {
            $names[] = $property->getName();
        }
    }

    expect($names)->toBe([])
        ->and($reflection->getAttributes(Lazy::class))->not->toBe([]);
});
