<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\RegistrationStatus;
use App\Enums\Role;
use App\Models\CourseRegistration;
use App\Models\Semester;
use App\Models\Student;
use App\Models\User;
use App\Support\Rbac\Permissions;
use Barryvdh\DomPDF\PDF;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Registration slip for an approved registration.
 *
 * The student route has no id. A missing, hidden, or not-yet-approved
 * registration is the same 404. The PDF has no remote file and no logo image.
 */
final class RegistrationSlip
{
    public const int RATE_LIMIT = 10;

    /**
     * Rate-limit key for one account.
     */
    public static function rateKey(User $user): string
    {
        return 'registration-slip:'.$user->getKey();
    }

    /**
     * Slip for the signed-in student's active semester.
     */
    public function student(User $actor): Response
    {
        $this->limit($actor);
        $student = $actor->student;
        $registration = null;

        if ($student instanceof Student) {
            $semester_id = Semester::query()->active()->value('id');

            if (is_numeric($semester_id)) {
                $registration = CourseRegistration::query()
                    ->where('student_id', $student->getKey())
                    ->where('semester_id', (int) $semester_id)
                    ->first();
            }
        }

        return $this->download($registration);
    }

    /**
     * Slip for a staff account that may approve this registration.
     */
    public function staff(User $actor, int $registration_id): Response
    {
        $this->limit($actor);

        if (! $this->staffMayDownload($actor)) {
            abort(404);
        }

        $registration = CourseRegistration::query()
            ->visibleTo($actor)
            ->whereKey($registration_id)
            ->first();

        return $this->download($registration);
    }

    /**
     * PDF bytes for an approved registration.
     */
    public function bytes(CourseRegistration $registration): string
    {
        $registration->loadMissing(['items.course', 'student.programme', 'semester.academicSession']);
        $html = view('pdf.registration-slip', $this->viewData($registration))->render();

        if (str_contains($html, '<img') || str_contains($html, '<svg') || preg_match('/https?:/i', $html) === 1) {
            throw new \RuntimeException('slip_markup');
        }

        /** @var PDF $pdf */
        $pdf = app('dompdf.wrapper');
        $pdf->setOption('isRemoteEnabled', false);
        $pdf->setOption('isFontSubsettingEnabled', true);

        if ($pdf->getOptions()->getIsRemoteEnabled() !== false) {
            throw new \RuntimeException('slip_remote');
        }

        $pdf->loadHTML($html);

        return $pdf->output();
    }

    /**
     * Attachment response, or a 404 when the row is missing or not approved.
     */
    private function download(?CourseRegistration $registration): Response
    {
        if (! $registration instanceof CourseRegistration || $registration->status !== RegistrationStatus::Approved) {
            abort(404);
        }

        return response($this->bytes($registration), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="registration-slip.pdf"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * @return array{
     *     institution_name: string,
     *     student_name: string,
     *     matric_no: string,
     *     programme_name: string,
     *     session_name: string,
     *     semester_name: string,
     *     total_units: int,
     *     courses: list<array{code: string, title: string, credit_units: int}>
     * }
     */
    private function viewData(CourseRegistration $registration): array
    {
        $student = $registration->student;
        $semester = $registration->semester;
        $courses = [];

        foreach ($registration->items as $item) {
            $courses[] = [
                'code' => (string) $item->course->code,
                'title' => (string) $item->course->title,
                'credit_units' => (int) $item->credit_units,
            ];
        }

        return [
            'institution_name' => (string) app(SettingsService::class)->effective()['name'],
            'student_name' => $student instanceof Student ? trim($student->first_name.' '.$student->last_name) : '',
            'matric_no' => $student instanceof Student ? (string) $student->matric_no : '',
            'programme_name' => $student instanceof Student ? (string) ($student->programme->name ?? '') : '',
            'session_name' => (string) $semester->academicSession->name,
            'semester_name' => (string) $semester->name,
            'total_units' => (int) $registration->total_units,
            'courses' => $courses,
        ];
    }

    private function limit(User $user): void
    {
        $key = self::rateKey($user);

        if (RateLimiter::tooManyAttempts($key, self::RATE_LIMIT)) {
            abort(429);
        }

        RateLimiter::hit($key, 60);
    }

    /**
     * Lecturers and bursars do not receive a slip. Approval scope still applies.
     */
    private function staffMayDownload(User $user): bool
    {
        if ($user->hasRole(Role::Lecturer) || $user->hasRole(Role::Bursar)) {
            return false;
        }

        foreach ($user->roles() as $role) {
            if (Permissions::roleGrants($role, 'course_registration.approve')) {
                return true;
            }
        }

        return false;
    }
}
