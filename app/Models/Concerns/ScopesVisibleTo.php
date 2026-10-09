<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\User;
use App\Support\Rbac\Visibility;
use App\Support\Rbac\VisibilityProfile;
use Illuminate\Database\Eloquent\Builder;

/**
 * Adds visibleTo() using the shared visibility query.
 *
 * The scope adds where, whereIn, and whereExists constraints. It does not
 * load a relation per row.
 */
trait ScopesVisibleTo
{
    /**
     * Matrix rows and the SQL shape for this model.
     */
    abstract protected static function visibilityProfile(): VisibilityProfile;

    /**
     * Limit the query to rows this account may see.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return Visibility::apply($query, $user, static::visibilityProfile());
    }

    /**
     * Whether this saved row is inside visibleTo() for the account.
     */
    public function isVisibleTo(User $user): bool
    {
        $query = $this->newQuery()->whereKey($this->getKey());

        return $this->scopeVisibleTo($query, $user)->exists();
    }
}
