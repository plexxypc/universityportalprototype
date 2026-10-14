<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Student;

/**
 * A new student and the welcome-email outcome.
 *
 * The temporary password is not a property of this result.
 */
final readonly class CreatedStudent
{
    public function __construct(
        public Student $student,
        public CredentialDelivery $delivery,
    ) {}
}
