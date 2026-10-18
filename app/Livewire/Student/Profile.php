<?php

declare(strict_types=1);

namespace App\Livewire\Student;

use App\Models\User;
use App\Services\StudentService;
use App\Support\CurrentStudent;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * The signed-in student's profile.
 *
 * Editable values are phone, address, and guardian phone and email.
 * The component does not keep a student id.
 */
class Profile extends Component
{
    public string $phone = '';

    public string $address = '';

    /**
     * Guardian rows this student already has. Name and relationship are display only.
     *
     * Livewire can replace a row with a partial array, so the keys are not guaranteed.
     *
     * @var list<array<string, mixed>>
     */
    public array $guardians = [];

    /**
     * Fill the form from the signed-in student.
     */
    public function mount(): void
    {
        $student = CurrentStudent::require();
        $student->refresh();
        $student->load(['user', 'programme', 'guardians']);
        $user = $student->user;

        $this->phone = $user instanceof User ? (string) $user->phone : '';
        $this->address = (string) $student->address;
        $this->guardians = [];

        foreach ($student->guardians as $guardian) {
            $this->guardians[] = [
                'id' => (int) $guardian->getKey(),
                'name' => $guardian->name,
                'relationship' => $guardian->relationship,
                'phone' => (string) $guardian->phone,
                'email' => (string) $guardian->email,
            ];
        }
    }

    /**
     * Save the contact fields for the signed-in student.
     */
    public function save(StudentService $students): void
    {
        $student = CurrentStudent::require();
        $user = $student->user;

        if (! $user instanceof User) {
            CurrentStudent::require();

            return;
        }

        $students->updateOwnContact($user, [
            'phone' => $this->phone,
            'address' => $this->address,
            'guardians' => array_map(
                fn (array $row): array => [
                    'id' => $row['id'] ?? null,
                    'phone' => $row['phone'] ?? null,
                    'email' => $row['email'] ?? null,
                ],
                $this->guardians,
            ),
        ], request());

        session()->flash('toast', 'Your contact details were saved.');
        $this->mount();
    }

    /**
     * Show the profile. Locked fields are read from the student again.
     */
    public function render(): View
    {
        $student = CurrentStudent::require();
        $student->refresh();
        $student->load(['user', 'programme']);

        return view('livewire.student.profile', [
            'student' => $student,
            'full_name' => CurrentStudent::fullName($student),
        ]);
    }
}
