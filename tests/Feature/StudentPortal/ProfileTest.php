<?php

declare(strict_types=1);

use App\Enums\UserStatus;
use App\Livewire\Student\Profile;
use App\Models\AcademicSession;
use App\Models\AuditLog;
use App\Models\Guardian;
use App\Models\Programme;
use App\Models\Student;
use App\Models\User;
use App\Services\StudentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * A student account with a student row.
 */
function profile_student(string $matric, string $first, string $last): Student
{
    $user = User::factory()->create([
        'status' => UserStatus::Active,
        'must_change_password' => false,
        'phone' => '+2348011111111',
    ]);
    $programme = Programme::factory()->create([
        'name' => 'Computer Science '.$matric,
        'code' => 'CS'.substr($matric, -4),
    ]);
    $session = AcademicSession::factory()->create([
        'name' => '2019/2020-'.$matric,
    ]);

    return Student::factory()->create([
        'user_id' => $user->id,
        'programme_id' => $programme->id,
        'entry_session_id' => $session->id,
        'first_name' => $first,
        'last_name' => $last,
        'other_names' => null,
        'matric_no' => $matric,
        'level' => 200,
        'address' => 'Old address '.$matric,
    ]);
}

test('a student can change phone address and guardian contact', function () {
    $student = profile_student('CSC/2026/0201', 'Chioma', 'Adeyemi');
    $guardian = Guardian::factory()->for($student)->create([
        'name' => 'Ada Adeyemi',
        'relationship' => 'Mother',
        'phone' => '+2348099999999',
        'email' => 'ada@example.test',
    ]);
    $email = $student->user->email;
    $programme_id = $student->programme_id;
    $level = $student->level;

    Livewire::actingAs($student->user)
        ->test(Profile::class)
        ->set('phone', '08031234567')
        ->set('address', '12 Campus Road')
        ->set('guardians.0.phone', '07012345678')
        ->set('guardians.0.email', 'guardian@example.test')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('+2348031234567')
        ->assertSee('Chioma Adeyemi')
        ->assertSee($email)
        ->assertSee('CSC/2026/0201')
        ->assertDontSeeHtml('wire:model="full_name"');

    $student->refresh();
    $student->user->refresh();
    $guardian->refresh();

    expect($student->user->phone)->toBe('+2348031234567')
        ->and($student->address)->toBe('12 Campus Road')
        ->and($guardian->phone)->toBe('+2347012345678')
        ->and($guardian->email)->toBe('guardian@example.test')
        ->and($guardian->name)->toBe('Ada Adeyemi')
        ->and($guardian->relationship)->toBe('Mother')
        ->and($student->user->email)->toBe($email)
        ->and($student->first_name)->toBe('Chioma')
        ->and($student->matric_no)->toBe('CSC/2026/0201')
        ->and($student->programme_id)->toBe($programme_id)
        ->and($student->level)->toBe($level);

    $audit = AuditLog::query()->where('action', StudentService::ACTION_CONTACT)->first();
    $encoded = json_encode($audit?->toArray());

    expect($audit)->not->toBeNull()
        ->and($audit->before['changed'])->toBe(['phone', 'address', 'guardian_phone', 'guardian_email'])
        ->and($audit->after['changed'])->toBe(['phone', 'address', 'guardian_phone', 'guardian_email'])
        ->and($encoded)->not->toContain('+2348031234567')
        ->and($encoded)->not->toContain('12 Campus Road')
        ->and($encoded)->not->toContain('guardian@example.test');
});

test('a forged livewire field cannot change a locked value', function () {
    $student = profile_student('CSC/2026/0202', 'Ada', 'Okonkwo');

    expect(fn () => Livewire::actingAs($student->user)
        ->test(Profile::class)
        ->set('matric_no', 'HACK/0000/0001'))
        ->toThrow(Exception::class);

    $student->refresh();

    expect($student->matric_no)->toBe('CSC/2026/0202');
});

test('the eleventh contact save in a minute is refused', function () {
    $student = profile_student('CSC/2026/0203', 'Bola', 'Ade');
    RateLimiter::clear('student-contact-save:'.$student->user_id);
    $component = Livewire::actingAs($student->user)->test(Profile::class);

    foreach (range(1, StudentService::CONTACT_SAVES_PER_MINUTE) as $attempt) {
        $component->set('address', 'Address '.$attempt)->call('save')->assertHasNoErrors();
    }

    $component->set('address', 'Address too many')->call('save')
        ->assertHasErrors(['phone' => StudentService::MESSAGE_CONTACT_RATE]);

    expect($student->fresh()->address)->toBe('Address '.StudentService::CONTACT_SAVES_PER_MINUTE);
});

test('another students guardian id is a plain 404 and is left unchanged', function () {
    $owner = profile_student('CSC/2026/0204', 'Chioma', 'Adeyemi');
    $other = profile_student('CSC/2026/0205', 'Tunde', 'Bello');
    $foreign = Guardian::factory()->for($other)->create([
        'phone' => '+2348088888888',
        'email' => 'tunde-guardian@example.test',
    ]);

    Livewire::actingAs($owner->user)
        ->test(Profile::class)
        ->set('guardians', [[
            'id' => $foreign->getKey(),
            'phone' => '08031234567',
            'email' => 'stolen@example.test',
        ]])
        ->call('save')
        ->assertNotFound();

    $foreign->refresh();

    expect($foreign->phone)->toBe('+2348088888888')
        ->and($foreign->email)->toBe('tunde-guardian@example.test');
});

test('student b does not see student a contact details', function () {
    $first = profile_student('CSC/2026/0206', 'Chioma', 'Adeyemi');
    $first->user->forceFill(['phone' => '+2348031234567'])->save();
    $first->forceFill(['address' => 'Secret house'])->save();
    $second = profile_student('CSC/2026/0207', 'Tunde', 'Bello');

    $this->actingAs($second->user)
        ->get(route('student.profile'))
        ->assertOk()
        ->assertSee('Tunde Bello')
        ->assertDontSee('Chioma Adeyemi')
        ->assertDontSee('+2348031234567')
        ->assertDontSee('Secret house')
        ->assertDontSee('CSC/2026/0206');
});

test('a suspended student cannot open the profile', function () {
    $student = profile_student('CSC/2026/0208', 'Ngozi', 'Eze');
    $student->user->forceFill(['status' => UserStatus::Suspended])->save();

    $this->actingAs($student->user)
        ->get(route('student.profile'))
        ->assertRedirect(route('login'));
});
