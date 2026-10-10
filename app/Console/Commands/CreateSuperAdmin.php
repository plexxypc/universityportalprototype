<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Rules\PortalPassword;
use App\Services\SuperAdminBootstrapResult;
use App\Services\SuperAdminService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * Create the bootstrap Super Admin, or refresh that account while it still
 * must change its password.
 *
 * The password is never an argument or an option. Interactive use asks with
 * a hidden prompt. The container passes a bcrypt hash in the environment.
 */
final class CreateSuperAdmin extends Command
{
    protected $signature = 'create-super-admin';

    protected $description = 'Create the bootstrap Super Admin from a hidden password or a bcrypt hash.';

    /**
     * Reminder printed whenever the bootstrap variables are still set and a Super Admin exists.
     */
    public const string REMINDER = 'Bootstrap variables are still set; remove BOOTSTRAP_SUPER_ADMIN_EMAIL and BOOTSTRAP_SUPER_ADMIN_PASSWORD_HASH.';

    /**
     * Run the interactive prompt or the environment bootstrap.
     */
    public function handle(SuperAdminService $admins): int
    {
        if (! $this->input->isInteractive()) {
            return $this->runFromEnvironment($admins);
        }

        return $this->runInteractive($admins);
    }

    /**
     * Read the email and the hash from the environment. Do not print either.
     */
    private function runFromEnvironment(SuperAdminService $admins): int
    {
        $email = $this->environmentValue('BOOTSTRAP_SUPER_ADMIN_EMAIL');
        $password_hash = $this->environmentValue('BOOTSTRAP_SUPER_ADMIN_PASSWORD_HASH');

        if ($email === null || $password_hash === null) {
            $this->error('BOOTSTRAP_SUPER_ADMIN_EMAIL and BOOTSTRAP_SUPER_ADMIN_PASSWORD_HASH are required. Nothing was created.');

            return self::FAILURE;
        }

        return $this->report(
            $admins->apply($email, 'Super Admin', $password_hash),
            $admins,
        );
    }

    /**
     * Ask for the account, then store only the hash.
     */
    private function runInteractive(SuperAdminService $admins): int
    {
        if ($admins->superAdminExists()) {
            $this->info('A Super Admin already exists. Nothing was created.');
            $this->remind($admins);

            return self::SUCCESS;
        }

        $name = $this->ask('Name');
        $email = $this->ask('Email');
        $password = $this->secret('Password');
        $confirmation = $this->secret('Confirm password');

        if (! is_string($name) || ! is_string($email) || ! is_string($password) || ! is_string($confirmation)) {
            $this->error('The account details were rejected. Nothing was created.');

            return self::FAILURE;
        }

        if (! hash_equals($password, $confirmation)) {
            $this->error('The passwords do not match.');

            return self::FAILURE;
        }

        if (! PortalPassword::allows($password, $email)) {
            $this->error('Choose a different password.');

            return self::FAILURE;
        }

        return $this->report(
            $admins->apply($email, $name, $this->hashPassword($password)),
            $admins,
        );
    }

    /**
     * Say what happened. The password and the hash stay out of the text.
     */
    private function report(SuperAdminBootstrapResult $result, SuperAdminService $admins): int
    {
        $message = match ($result) {
            SuperAdminBootstrapResult::Created => 'Super Admin created.',
            SuperAdminBootstrapResult::Rearmed => 'The bootstrap Super Admin temporary password was refreshed.',
            SuperAdminBootstrapResult::Unchanged => 'A Super Admin already exists. Nothing was created.',
            SuperAdminBootstrapResult::EmailTaken => 'A user with that email already exists. Nothing was created.',
            SuperAdminBootstrapResult::InvalidHash => 'The password hash was rejected. Nothing was created.',
            SuperAdminBootstrapResult::Rejected => 'The account details were rejected. Nothing was created.',
            SuperAdminBootstrapResult::Failed => 'Super Admin bootstrap could not finish. Nothing was created.',
        };

        if ($result === SuperAdminBootstrapResult::Created || $result === SuperAdminBootstrapResult::Rearmed) {
            $this->info($message);
        } else {
            $this->error($message);
        }

        $this->remind($admins);

        return match ($result) {
            SuperAdminBootstrapResult::Created,
            SuperAdminBootstrapResult::Rearmed,
            SuperAdminBootstrapResult::Unchanged => self::SUCCESS,
            default => self::FAILURE,
        };
    }

    /**
     * Warn once when the bootstrap variables are still present and a Super Admin exists.
     */
    private function remind(SuperAdminService $admins): void
    {
        if ($this->environmentValue('BOOTSTRAP_SUPER_ADMIN_EMAIL') === null) {
            return;
        }

        if ($this->environmentValue('BOOTSTRAP_SUPER_ADMIN_PASSWORD_HASH') === null) {
            return;
        }

        if (! $admins->superAdminExists()) {
            return;
        }

        $this->line(self::REMINDER);
    }

    /**
     * One environment value, with surrounding space removed.
     */
    private function environmentValue(string $name): ?string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * Bcrypt hash at this application's configured cost.
     */
    private function hashPassword(#[\SensitiveParameter] string $password): string
    {
        return Hash::make($password);
    }
}
