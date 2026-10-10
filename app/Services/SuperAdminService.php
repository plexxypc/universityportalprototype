<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Support\Rbac\RoleScopeValidator;
use Illuminate\Database\QueryException;
use Illuminate\Hashing\BcryptHasher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Throwable;

/**
 * Create or re-arm the bootstrap Super Admin.
 *
 * The password arrives as a bcrypt hash. This service never logs it.
 */
final class SuperAdminService
{
    public function __construct(private readonly RoleScopeValidator $scopes) {}

    /**
     * Whether any Super Admin role assignment exists, including a deactivated user.
     */
    public function superAdminExists(): bool
    {
        return RoleAssignment::query()
            ->where('role', Role::SuperAdmin)
            ->exists();
    }

    /**
     * Create the first Super Admin, or refresh the bootstrap account's hash and expiry.
     *
     * A second Super Admin is not created. A duplicate email is refused.
     * While the same email still must change its password, the hash and the
     * 24-hour expiry are refreshed. After that change, nothing is written.
     */
    public function apply(
        string $email,
        string $name,
        #[\SensitiveParameter] string $password_hash,
    ): SuperAdminBootstrapResult {
        $email = mb_strtolower(trim($email));
        $name = trim($name);
        $password_hash = trim($password_hash);

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || $name === '' || strlen($name) > 255) {
            return SuperAdminBootstrapResult::Rejected;
        }

        $user = User::query()
            ->whereRaw('lower(email) = ?', [$email])
            ->first();

        if ($this->superAdminExists()) {
            if (! $user instanceof User || ! $this->canRearm($user)) {
                return SuperAdminBootstrapResult::Unchanged;
            }

            if (! $this->hashIsAcceptable($password_hash)) {
                return SuperAdminBootstrapResult::InvalidHash;
            }

            return $this->write(function () use ($user, $password_hash): void {
                $this->rearm($user, $password_hash);
            }, SuperAdminBootstrapResult::Rearmed);
        }

        if ($user instanceof User) {
            return SuperAdminBootstrapResult::EmailTaken;
        }

        if (! $this->hashIsAcceptable($password_hash)) {
            return SuperAdminBootstrapResult::InvalidHash;
        }

        return $this->write(function () use ($email, $name, $password_hash): void {
            $this->create($email, $name, $password_hash);
        }, SuperAdminBootstrapResult::Created);
    }

    /**
     * The same email, with the Super Admin role, still has to change the password.
     */
    private function canRearm(User $user): bool
    {
        return $user->must_change_password
            && $user->hasRole(Role::SuperAdmin);
    }

    /**
     * Store a new account with an empty-scope Super Admin assignment.
     */
    private function create(string $email, string $name, #[\SensitiveParameter] string $password_hash): void
    {
        if ($this->scopes->errors(Role::SuperAdmin, null, null) !== []) {
            throw new \RuntimeException('Super Admin scope was rejected.');
        }

        DB::transaction(function () use ($email, $name, $password_hash): void {
            $user = User::query()->create([
                'name' => $name,
                'email' => $email,
                'password' => $password_hash,
                'status' => UserStatus::Active,
                'must_change_password' => true,
                'temp_password_expires_at' => now()->addHours(24),
            ]);

            RoleAssignment::query()->create([
                'user_id' => $user->id,
                'role' => Role::SuperAdmin,
                'faculty_id' => null,
                'department_id' => null,
            ]);
        });
    }

    /**
     * Refresh the hash and the 24-hour expiry. Status is left as it is.
     */
    private function rearm(User $user, #[\SensitiveParameter] string $password_hash): void
    {
        $user->forceFill([
            'password' => $password_hash,
            'temp_password_expires_at' => now()->addHours(24),
        ])->save();
    }

    /**
     * Run a write and hide database details from the caller.
     */
    private function write(callable $callback, SuperAdminBootstrapResult $success): SuperAdminBootstrapResult
    {
        try {
            $callback();
        } catch (QueryException $exception) {
            $state = $exception->errorInfo[0] ?? null;

            if ($state === '23000' || $state === 23000) {
                return SuperAdminBootstrapResult::EmailTaken;
            }

            return SuperAdminBootstrapResult::Failed;
        } catch (Throwable) {
            return SuperAdminBootstrapResult::Failed;
        }

        return $success;
    }

    /**
     * A bcrypt hash made with this application's cost, or weaker.
     */
    private function hashIsAcceptable(#[\SensitiveParameter] string $password_hash): bool
    {
        if ($password_hash === '' || preg_match('/\s/', $password_hash) === 1) {
            return false;
        }

        if (! Hash::isHashed($password_hash)) {
            return false;
        }

        $driver = Hash::driver();

        if (! $driver instanceof BcryptHasher) {
            return false;
        }

        return $driver->verifyConfiguration($password_hash);
    }
}
