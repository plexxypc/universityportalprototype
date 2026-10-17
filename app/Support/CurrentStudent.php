<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Student;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * The signed-in student's row.
 *
 * Every student page reads the student from the authenticated user through
 * this helper. A route, query, or Livewire property is never the source.
 */
final class CurrentStudent
{
    public const string MISSING_RECORD = 'Your student record is not available. You have been signed out.';

    /**
     * The authenticated user's student row, when one exists.
     */
    public static function find(): ?Student
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return null;
        }

        $user->loadMissing('student');
        $student = $user->student;

        return $student instanceof Student ? $student : null;
    }

    /**
     * The authenticated user's student row.
     *
     * A missing row signs the user out and redirects to login. The response
     * is a redirect, not an error page.
     */
    public static function require(): Student
    {
        $student = self::find();

        if ($student instanceof Student) {
            return $student;
        }

        $request = request();
        app(AuthService::class)->logout($request);

        throw new HttpResponseException(
            redirect()->route('login')->with('status', self::MISSING_RECORD),
        );
    }

    /**
     * Name and matric for the student chrome.
     *
     * A signed-in student always wins. The design-preview fixture is used
     * only for that guest preview, never for a real student.
     *
     * @return array{first_name: string, full_name: string, matric_no: string, home: string}|null
     */
    public static function layoutIdentity(): ?array
    {
        $student = self::find();

        if ($student instanceof Student) {
            return [
                'first_name' => $student->first_name,
                'full_name' => self::fullName($student),
                'matric_no' => $student->matric_no,
                'home' => route('student.home'),
            ];
        }

        if (! self::previewRequested()) {
            return null;
        }

        return [
            'first_name' => 'Ada',
            'full_name' => 'Ada Okonkwo',
            'matric_no' => 'CSC/2026/001',
            'home' => url('/design-preview/student'),
        ];
    }

    /**
     * Full name from the three stored parts.
     */
    public static function fullName(Student $student): string
    {
        $parts = [$student->first_name];

        if (is_string($student->other_names) && $student->other_names !== '') {
            $parts[] = $student->other_names;
        }

        $parts[] = $student->last_name;

        return implode(' ', $parts);
    }

    /**
     * Whether this render is the local design preview.
     */
    private static function previewRequested(): bool
    {
        if (request()->routeIs('design-preview.student')) {
            return true;
        }

        return request()->attributes->get('design_preview_student') === true;
    }
}
