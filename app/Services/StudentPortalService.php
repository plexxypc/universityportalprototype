<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Enums\RegistrationStatus;
use App\Models\AcademicSession;
use App\Models\CourseRegistration;
use App\Models\Invoice;
use App\Models\Notification;
use App\Models\Semester;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

/**
 * Read models for the student dashboard.
 *
 * The page does not query. Registration and fees are the two reads against
 * modules that are not built yet. Notifications go through NotificationService.
 */
final class StudentPortalService
{
    public const string AMOUNT_MESSAGE = 'Invoice amounts are not usable.';

    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * Programme, level, and entry session for this student.
     *
     * @return array{programme: string, level: int, entry_session: string}
     */
    public function summary(Student $student): array
    {
        $student->loadMissing(['programme', 'entrySession']);

        return [
            'programme' => (string) $student->programme?->name,
            'level' => (int) $student->level,
            'entry_session' => (string) $student->entrySession?->name,
        ];
    }

    /**
     * Registration for this student in the active semester.
     *
     * @return array{state: string, semester_name: ?string, status: ?RegistrationStatus}
     */
    public function registrationCard(Student $student): array
    {
        $semester = Semester::query()->active()->first();

        if (! $semester instanceof Semester) {
            return [
                'state' => 'no_semester',
                'semester_name' => null,
                'status' => null,
            ];
        }

        $registration = CourseRegistration::query()
            ->where('student_id', $student->getKey())
            ->where('semester_id', $semester->getKey())
            ->first();

        if (! $registration instanceof CourseRegistration) {
            return [
                'state' => 'none',
                'semester_name' => $semester->name,
                'status' => null,
            ];
        }

        return [
            'state' => 'found',
            'semester_name' => $semester->name,
            'status' => $registration->status,
        ];
    }

    /**
     * The non-cancelled invoice for this student and the current session.
     *
     * The three stored integers are checked before they are subtracted.
     *
     * @return array{state: string, session_name: ?string, status: ?InvoiceStatus, due_kobo: ?int, number: ?string}
     */
    public function feesCard(Student $student): array
    {
        $session = AcademicSession::query()->current()->first();

        if (! $session instanceof AcademicSession) {
            return [
                'state' => 'no_session',
                'session_name' => null,
                'status' => null,
                'due_kobo' => null,
                'number' => null,
            ];
        }

        $invoice = Invoice::query()
            ->where('student_id', $student->getKey())
            ->where('session_id', $session->getKey())
            ->where('status', '!=', InvoiceStatus::Cancelled->value)
            ->orderByDesc('id')
            ->first();

        if (! $invoice instanceof Invoice) {
            return [
                'state' => 'none',
                'session_name' => $session->name,
                'status' => null,
                'due_kobo' => null,
                'number' => null,
            ];
        }

        return [
            'state' => 'found',
            'session_name' => $session->name,
            'status' => $invoice->status,
            'due_kobo' => self::dueKobo(
                self::koboAmount($invoice->total_kobo),
                self::koboAmount($invoice->adjustments_kobo),
                self::koboAmount($invoice->paid_kobo),
            ),
            'number' => $invoice->number,
        ];
    }

    /**
     * The newest notifications for this account.
     *
     * @return Collection<int, Notification>
     */
    public function latestNotifications(User $user): Collection
    {
        return $this->notifications->latestFor($user, 3);
    }

    /**
     * Amount still due, in kobo.
     *
     * Subtraction runs only after each integer is non-negative and the
     * adjustments and payments fit inside the total.
     */
    public static function dueKobo(int $total_kobo, int $adjustments_kobo, int $paid_kobo): int
    {
        if ($total_kobo < 0 || $adjustments_kobo < 0 || $paid_kobo < 0) {
            throw new InvalidArgumentException(self::AMOUNT_MESSAGE);
        }

        if ($adjustments_kobo > $total_kobo) {
            throw new InvalidArgumentException(self::AMOUNT_MESSAGE);
        }

        $remaining = $total_kobo - $adjustments_kobo;

        if ($paid_kobo > $remaining) {
            throw new InvalidArgumentException(self::AMOUNT_MESSAGE);
        }

        return $remaining - $paid_kobo;
    }

    /**
     * A stored kobo column as a non-negative integer.
     */
    private static function koboAmount(mixed $value): int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }

        if (is_string($value) && preg_match('/\A0\z|\A[1-9]\d*\z/', $value) === 1) {
            $parsed = (int) $value;

            if ($parsed >= 0 && (string) $parsed === $value) {
                return $parsed;
            }
        }

        throw new InvalidArgumentException(self::AMOUNT_MESSAGE);
    }
}
