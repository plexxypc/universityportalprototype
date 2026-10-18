<?php

declare(strict_types=1);

namespace App\Livewire\Student;

use App\Services\StudentPortalService;
use App\Support\CurrentStudent;
use App\Support\StudentNavigation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Exceptions\HttpResponseException;
use Livewire\Attributes\Lazy;
use Livewire\Component;
use Throwable;

/**
 * Student home cards.
 *
 * The component is lazy. It reads the student again on its own request
 * and does not keep a student id.
 */
#[Lazy]
class Dashboard extends Component
{
    /**
     * Skeletons for each card while this component loads.
     */
    public function placeholder(): View
    {
        return view('livewire.student.dashboard-placeholder');
    }

    /**
     * Load the cards for the signed-in student.
     */
    public function render(StudentPortalService $portal): View
    {
        $student = CurrentStudent::require();
        $student->loadMissing('user');
        $user = $student->user;

        return view('livewire.student.dashboard', [
            'first_name' => $student->first_name,
            'summary' => $this->read(fn (): array => $portal->summary($student)),
            'registration' => $this->read(fn (): array => $portal->registrationCard($student)),
            'fees' => $this->read(fn (): array => $portal->feesCard($student)),
            'notifications' => $user === null
                ? ['ok' => false, 'value' => null]
                : $this->read(fn () => $portal->latestNotifications($user)),
            'actions' => StudentNavigation::pages(),
        ]);
    }

    /**
     * One card's value, or a failed flag when the read throws.
     *
     * A sign-out redirect is not a card error.
     *
     * @return array{ok: bool, value: mixed}
     */
    private function read(callable $callback): array
    {
        try {
            return [
                'ok' => true,
                'value' => $callback(),
            ];
        } catch (HttpResponseException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            return [
                'ok' => false,
                'value' => null,
            ];
        }
    }
}
