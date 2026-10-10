<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Result;
use App\Models\User;

/**
 * Results.
 *
 * Visibility is narrower than the results_view cell. A student sees only
 * their own Published rows. Draft rows are visible only to Super Admin and
 * to a lecturer assigned to that course. Faculty Admin, Department Officer,
 * Registrar, and Exam Officer see Submitted, Approved, and Published rows
 * inside their scope.
 */
final class ResultPolicy extends PortalPolicy
{
    /**
     * Permission key required to list or open a result.
     */
    protected function viewAbility(): string
    {
        return 'results.view';
    }

    /**
     * Whether the account may enter this result.
     */
    public function enter(User $user, Result $result): bool
    {
        return $this->allows($user, 'results.enter')
            && $this->rowIsVisible($user, $result);
    }

    /**
     * Whether the account may approve this result.
     */
    public function approve(User $user, Result $result): bool
    {
        return $this->allows($user, 'results.approve')
            && $this->rowIsVisible($user, $result);
    }

    /**
     * Whether the account may publish this result.
     */
    public function publish(User $user, Result $result): bool
    {
        return $this->allows($user, 'results.publish')
            && $this->rowIsVisible($user, $result);
    }
}
