<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Rules\PortalPassword;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * Print a bcrypt hash for the bootstrap Super Admin.
 *
 * The password is read from a hidden prompt. It is not an argument, and it
 * is not written to the database or the log.
 */
final class HashPassword extends Command
{
    protected $signature = 'portal:hash-password';

    protected $description = 'Print a bcrypt hash for the bootstrap Super Admin. The password is not stored.';

    /**
     * Ask twice, then print only the hash.
     */
    public function handle(): int
    {
        $password = $this->secret('Password');
        $confirmation = $this->secret('Confirm password');

        if (! is_string($password) || ! is_string($confirmation) || ! hash_equals($password, $confirmation)) {
            $this->error('The passwords do not match.');

            return self::FAILURE;
        }

        if (! PortalPassword::allows($password)) {
            $this->error('Choose a different password.');

            return self::FAILURE;
        }

        $this->output->writeln($this->hashPassword($password));

        return self::SUCCESS;
    }

    /**
     * Bcrypt hash at this application's configured cost.
     */
    private function hashPassword(#[\SensitiveParameter] string $password): string
    {
        return Hash::make($password);
    }
}
