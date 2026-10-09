<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Concerns\VisibleToUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Shared policy. Each subclass names the permission keys.
 *
 * view and viewAny call Gate::allows on those keys, so the permission map
 * and Gate::before both apply. A single record must also be inside
 * visibleTo(). Super Admin still passes Gate::before for every ability
 * except course_registration.submit and payments.make. Those two denials
 * are the gate keys. This class does not add create or make methods that
 * would stand in for them.
 */
abstract class PortalPolicy
{
    /**
     * Permission key required to list or open a row.
     */
    abstract protected function viewAbility(): string;

    /**
     * Permission key required to change a row. Null when the model is view-only.
     */
    protected function manageAbility(): ?string
    {
        return null;
    }

    /**
     * Whether the account may list this model.
     */
    public function viewAny(User $user): bool
    {
        return $this->allows($user, $this->viewAbility());
    }

    /**
     * Whether the account may open this row.
     */
    public function view(User $user, Model $record): bool
    {
        return $this->allows($user, $this->viewAbility())
            && $this->rowIsVisible($user, $record);
    }

    /**
     * Whether the account may download this row. Same rule as view.
     */
    public function download(User $user, Model $record): bool
    {
        return $this->view($user, $record);
    }

    /**
     * Whether the account may create a row.
     */
    public function create(User $user): bool
    {
        $ability = $this->manageAbility();

        return $ability !== null && $this->allows($user, $ability);
    }

    /**
     * Whether the account may update this row.
     */
    public function update(User $user, Model $record): bool
    {
        $ability = $this->manageAbility();

        return $ability !== null
            && $this->allows($user, $ability)
            && $this->rowIsVisible($user, $record);
    }

    /**
     * Whether the account may delete this row.
     */
    public function delete(User $user, Model $record): bool
    {
        return $this->update($user, $record);
    }

    /**
     * Ask the gate, which reads the permission map.
     */
    protected function allows(User $user, string $ability): bool
    {
        return Gate::forUser($user)->allows($ability);
    }

    /**
     * Whether visibleTo() contains this row.
     */
    protected function rowIsVisible(User $user, Model $record): bool
    {
        if (! $record instanceof VisibleToUser) {
            return false;
        }

        return $record->isVisibleTo($user);
    }
}
