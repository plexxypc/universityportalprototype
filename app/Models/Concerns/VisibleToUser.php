<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\User;

/**
 * A model whose rows can be limited to what one account may see.
 */
interface VisibleToUser
{
    /**
     * Whether this saved row is inside the user's visibleTo() query.
     */
    public function isVisibleTo(User $user): bool;
}
