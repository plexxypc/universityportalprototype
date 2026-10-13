<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EmailStatus;
use App\Enums\EmailTemplate;
use App\Enums\UserStatus;
use App\Models\EmailOutbox;
use App\Models\Student;
use App\Models\User;
use App\Policies\StudentPolicy;
use App\Support\Audit;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Issues a temporary password and the one-time welcome email.
 *
 * The plaintext password is passed only into MailService. It is not
 * returned, logged, or written to the audit row.
 */
class CredentialService
{
    public const string ACTION_ISSUED = 'credentials.issued';

    public const string ACTION_RESENT = 'credentials.resent';

    public const string MESSAGE_INACTIVE = 'Credentials can be sent only for an active account.';

    public const string MESSAGE_COOLDOWN = 'Wait a minute before sending credentials again.';

    public const string MESSAGE_REPLACE = 'This student has already set their own password. Resending replaces it.';

    public const int PASSWORD_LENGTH = 12;

    public const int EXPIRY_DAYS = 7;

    public const int COOLDOWN_SECONDS = 60;

    /**
     * Letters and digits that are not easily confused with each other.
     */
    private const string ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';

    private const string COOLDOWN_PREFIX = 'credentials-resend:';

    public function __construct(
        private readonly MailService $mail,
        private readonly Audit $audit,
        private readonly AuthService $auth,
    ) {}

    /**
     * Set a new temporary password and queue the welcome email.
     */
    public function issue(User $actor, User $account, string $login_id, Request $request): CredentialDelivery
    {
        $outbox_id = DB::transaction(function () use ($actor, $account, $login_id, $request): int {
            $locked = $this->lockedAccount($account);
            $this->authorise($actor, $locked);
            $this->assertActive($locked);

            $password = $this->temporaryPassword();
            $expires = now()->addDays(self::EXPIRY_DAYS);
            $this->storePassword($locked, $password, $expires);
            $row = $this->queueWelcome($locked, $login_id, $password, $expires);
            $this->audit->record(
                $actor->id,
                self::ACTION_ISSUED,
                'users',
                (int) $locked->getKey(),
                null,
                $this->credentialSnapshot($locked),
                $this->audit->ipFromRequest($request),
            );

            return (int) $row->id;
        });

        return $this->outcomeForId($outbox_id);
    }

    /**
     * Replace the password and supersede an unsent welcome email.
     *
     * The cool-down is checked while the user row is locked, so two clicks
     * cannot both issue a password. A student who already chose a password
     * requires replace_chosen_password.
     */
    public function resend(
        User $actor,
        User $account,
        string $login_id,
        Request $request,
        bool $replace_chosen_password = false,
    ): CredentialDelivery {
        $outbox_id = DB::transaction(function () use ($actor, $account, $login_id, $request, $replace_chosen_password): int {
            $locked = $this->lockedAccount($account);
            $this->authorise($actor, $locked);
            $this->assertActive($locked);

            if (! $locked->must_change_password && ! $replace_chosen_password) {
                throw ValidationException::withMessages(['credentials' => self::MESSAGE_REPLACE]);
            }

            $cache = Cache::store('database');
            $key = self::COOLDOWN_PREFIX.$locked->id;

            if ($cache->get($key) !== null) {
                throw ValidationException::withMessages(['credentials' => self::MESSAGE_COOLDOWN]);
            }

            $before = $this->credentialSnapshot($locked);
            $password = $this->temporaryPassword();
            $expires = now()->addDays(self::EXPIRY_DAYS);
            $this->storePassword($locked, $password, $expires);
            $row = $this->queueWelcome($locked, $login_id, $password, $expires);
            $cache->put($key, 1, self::COOLDOWN_SECONDS);
            $this->auth->revokeSessions($locked);
            $this->audit->record(
                $actor->id,
                self::ACTION_RESENT,
                'users',
                (int) $locked->getKey(),
                $before,
                $this->credentialSnapshot($locked),
                $this->audit->ipFromRequest($request),
            );

            return (int) $row->id;
        });

        return $this->outcomeForId($outbox_id);
    }

    /**
     * The account row locked for this credential change.
     */
    private function lockedAccount(User $account): User
    {
        $locked = User::query()->whereKey($account->id)->lockForUpdate()->first();

        if (! $locked instanceof User) {
            throw new AuthorizationException;
        }

        return $locked;
    }

    /**
     * Only a manager of this student may issue or resend credentials.
     */
    private function authorise(User $actor, User $account): void
    {
        $student = Student::query()->where('user_id', $account->id)->first();

        if (! $student instanceof Student || ! app(StudentPolicy::class)->update($actor, $student)) {
            throw new AuthorizationException;
        }
    }

    /**
     * Refuse an account that cannot sign in.
     */
    private function assertActive(User $account): void
    {
        if ($account->status !== UserStatus::Active) {
            throw ValidationException::withMessages(['credentials' => self::MESSAGE_INACTIVE]);
        }
    }

    /**
     * Store the hash, the forced-change flag, and the expiry together.
     */
    private function storePassword(User $account, #[\SensitiveParameter] string $password, CarbonInterface $expires): void
    {
        $account->forceFill([
            'password' => $password,
            'must_change_password' => true,
            'temp_password_expires_at' => $expires,
        ])->save();
    }

    /**
     * Queue the credentials template. MailService keeps the password encrypted.
     */
    private function queueWelcome(
        User $account,
        string $login_id,
        #[\SensitiveParameter] string $password,
        CarbonInterface $expires,
    ): EmailOutbox {
        return $this->mail->send(EmailTemplate::Credentials->value, $account->email, [
            'name' => $account->name,
            'login_id' => $login_id,
            'temporary_password' => $password,
            'expires_at' => $expires->timezone('Africa/Lagos')->format('d M Y, H:i'),
        ], (int) $account->id);
    }

    /**
     * Twelve characters from the unambiguous alphabet, with a letter and a digit.
     */
    private function temporaryPassword(): string
    {
        $last = strlen(self::ALPHABET) - 1;

        do {
            $password = '';

            for ($index = 0; $index < self::PASSWORD_LENGTH; $index++) {
                $password .= self::ALPHABET[random_int(0, $last)];
            }
        } while (preg_match('/\p{L}/u', $password) !== 1 || preg_match('/\d/', $password) !== 1);

        return $password;
    }

    /**
     * @return array{must_change_password: bool, temp_password_expires_at: ?string}
     */
    private function credentialSnapshot(User $account): array
    {
        return [
            'must_change_password' => (bool) $account->must_change_password,
            'temp_password_expires_at' => $account->temp_password_expires_at?->toDateTimeString(),
        ];
    }

    /**
     * Queued, sent, or not sent, after the issuing transaction has committed.
     *
     * A synchronous send has already finished when this process owns the
     * outermost transaction. A production log mailer is not sent.
     */
    public function outcome(EmailOutbox $row): CredentialDelivery
    {
        if ($this->mail->refusesSecretTemplate($row->template)) {
            return new CredentialDelivery(CredentialDelivery::NOT_SENT);
        }

        $row->refresh();

        $state = match ($row->status) {
            EmailStatus::Sent => CredentialDelivery::SENT,
            EmailStatus::Failed => CredentialDelivery::NOT_SENT,
            default => CredentialDelivery::QUEUED,
        };

        return new CredentialDelivery($state);
    }

    /**
     * The delivery state for one outbox row.
     */
    private function outcomeForId(int $outbox_id): CredentialDelivery
    {
        $row = EmailOutbox::query()->find($outbox_id);

        if (! $row instanceof EmailOutbox) {
            return new CredentialDelivery(CredentialDelivery::NOT_SENT);
        }

        return $this->outcome($row);
    }
}
