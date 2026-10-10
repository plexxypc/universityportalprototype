<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Hash;

/**
 * The password rules from ADR-028.
 *
 * A candidate is 10 to 72 bytes, contains a letter and a digit, does not
 * contain the email or matric number, and is not the current password.
 * The common-password check lowercases the candidate first.
 */
final class PortalPassword implements ValidationRule
{
    public function __construct(private readonly User $user) {}

    /**
     * Reject a password that breaks any of the rules.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! $this->accepts($value)) {
            $fail('Choose a different password.');
        }
    }

    /**
     * Whether the password meets the length, character, email, and common-password rules.
     *
     * The matric number and the current password are checked separately.
     */
    public static function allows(string $password, string $email = ''): bool
    {
        $length = strlen($password);

        if ($length < 10 || $length > 72) {
            return false;
        }

        if (preg_match('/\p{L}/u', $password) !== 1 || preg_match('/\d/', $password) !== 1) {
            return false;
        }

        $normalised_email = mb_strtolower(trim($email));

        if ($normalised_email !== '' && str_contains(mb_strtolower($password), $normalised_email)) {
            return false;
        }

        return ! self::isCommon($password);
    }

    /**
     * Whether this password may be stored for the signed-in user.
     */
    private function accepts(string $password): bool
    {
        if (! self::allows($password, $this->user->email) || $this->containsMatric($password)) {
            return false;
        }

        return ! Hash::check($password, (string) $this->user->getAuthPassword());
    }

    /**
     * Whether the password contains the matric number.
     */
    private function containsMatric(string $password): bool
    {
        $this->user->loadMissing('student');
        $matric = $this->user->student?->matric_no;

        return is_string($matric)
            && $matric !== ''
            && str_contains(mb_strtolower($password), mb_strtolower($matric));
    }

    /**
     * Whether the lowercased password is on the local common-password list.
     */
    private static function isCommon(string $password): bool
    {
        $candidate = mb_strtolower($password);

        foreach (self::commonPasswords() as $common) {
            if ($candidate === $common) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lowercased entries from the common-password file.
     *
     * @return list<string>
     */
    private static function commonPasswords(): array
    {
        $path = resource_path('auth/common-passwords.txt');
        $contents = is_file($path) ? file_get_contents($path) : false;

        if ($contents === false) {
            throw new \RuntimeException('The common password list is missing.');
        }

        $passwords = [];

        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $line = mb_strtolower(trim($line));

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $passwords[] = $line;
        }

        return $passwords;
    }
}
