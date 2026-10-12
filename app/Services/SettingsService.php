<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\InstitutionSetting;
use App\Models\User;
use App\Support\Audit;
use App\Support\InstitutionLogo;
use App\Support\MatricPattern;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/**
 * Reads and updates the institution profile.
 *
 * An empty table, or a blank text value, falls back to config/portal.php.
 * False and zero on a saved row stay as saved. The cache is forgotten after
 * a successful save.
 */
class SettingsService
{
    public const string CACHE_KEY = 'institution.settings';

    public const string ACTION_UPDATED = 'settings.updated';

    private const int UPLOADS_PER_MINUTE = 10;

    public function __construct(
        private readonly Audit $audit,
        private readonly InstitutionLogo $logos,
    ) {}

    /**
     * Name, short code, and logo URL for pages and mail.
     *
     * @return array{name: string, code: string, logo_url: string}
     */
    public function brand(): array
    {
        $effective = $this->effective();

        return [
            'name' => $effective['name'],
            'code' => $effective['code'],
            'logo_url' => $effective['logo_url'],
        ];
    }

    /**
     * Values the settings form should show before the first save.
     *
     * @return array<string, mixed>
     */
    public function formValues(): array
    {
        $effective = $this->effective();
        unset($effective['logo_url'], $effective['logo_path']);

        return $effective;
    }

    /**
     * The saved row when one exists, otherwise the config fallback.
     *
     * @return array{
     *     name: string,
     *     code: string,
     *     logo_url: string,
     *     logo_path: ?string,
     *     address: ?string,
     *     phone: ?string,
     *     email: ?string,
     *     motto: ?string,
     *     matric_pattern: string,
     *     min_units: int,
     *     max_units: int,
     *     approval_required: bool,
     *     withhold_results_for_debt: bool,
     *     attendance_threshold: int,
     *     require_minimum_payment: bool
     * }
     */
    public function effective(): array
    {
        $cached = Cache::get(self::CACHE_KEY);

        if (is_array($cached) && isset($cached['name'], $cached['logo_url'], $cached['matric_pattern'])) {
            return $cached;
        }

        $effective = $this->readEffective();
        Cache::forever(self::CACHE_KEY, $effective);

        return $effective;
    }

    /**
     * Drop the cached profile so the next read sees the database.
     */
    public function forgetCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Public logo URL when no uploaded file is available.
     *
     * logo-default.png wins when that file is present. Otherwise the
     * placeholder image is used.
     */
    public function fallbackLogoUrl(): string
    {
        $default = public_path('images/logo-default.png');

        if (is_file($default)) {
            $hash = hash_file('sha256', $default);
            $version = is_string($hash) ? substr($hash, 0, 16) : '';

            return asset('images/logo-default.png').($version !== '' ? '?v='.$version : '');
        }

        return asset('images/logo-placeholder.svg');
    }

    /**
     * The stored logo bytes, or null when the file is missing.
     *
     * The path is the one this service wrote. A query string is not a path.
     *
     * @return array{contents: string, mime: string, hash: string}|null
     */
    public function openStoredLogo(): ?array
    {
        $path = $this->storedPath();

        if ($path === null) {
            return null;
        }

        $disk = Storage::disk($this->diskName());

        if (! $disk->exists($path)) {
            return null;
        }

        $contents = $disk->get($path);

        if (! is_string($contents) || $contents === '') {
            return null;
        }

        return [
            'contents' => $contents,
            'mime' => str_ends_with($path, '.png') ? 'image/png' : 'image/jpeg',
            'hash' => hash('sha256', $contents),
        ];
    }

    /**
     * Validate, store, and audit one settings save.
     *
     * @param  array<string, mixed>  $input
     */
    public function save(User $actor, array $input, ?UploadedFile $logo, Request $request): InstitutionSetting
    {
        if (! Gate::forUser($actor)->allows('users_roles_settings.manage')) {
            throw new AuthorizationException;
        }

        $attributes = $this->validated($input);
        $stored_path = null;

        if ($logo !== null) {
            $this->limitUploads($actor);
            $stored_path = $this->storeLogo($logo);
        }

        try {
            $saved = DB::transaction(function () use ($actor, $attributes, $stored_path, $request): array {
                $existing = InstitutionSetting::query()->lockForUpdate()->first();
                $before = $existing instanceof InstitutionSetting ? $this->snapshot($existing) : null;
                $previous_path = $existing?->logo_path;

                if (! $existing instanceof InstitutionSetting) {
                    $existing = new InstitutionSetting;
                }

                $existing->fill($attributes);

                if ($stored_path !== null) {
                    $existing->logo_path = $stored_path;
                }

                $existing->save();

                $this->audit->record(
                    $actor->id,
                    self::ACTION_UPDATED,
                    'institution_settings',
                    (int) $existing->getKey(),
                    $before,
                    $this->snapshot($existing),
                    $this->audit->ipFromRequest($request),
                );

                return [
                    'row' => $existing,
                    'previous_path' => is_string($previous_path) ? $previous_path : null,
                ];
            });
        } catch (Throwable $exception) {
            if ($stored_path !== null) {
                Storage::disk($this->diskName())->delete($stored_path);
            }

            throw $exception;
        }

        $previous_path = $saved['previous_path'];

        if (
            $stored_path !== null
            && is_string($previous_path)
            && $previous_path !== ''
            && $previous_path !== $stored_path
            && str_starts_with($previous_path, 'institution/logo-')
        ) {
            Storage::disk($this->diskName())->delete($previous_path);
        }

        $this->forgetCache();

        return $saved['row'];
    }

    /**
     * @return array{
     *     name: string,
     *     code: string,
     *     logo_url: string,
     *     logo_path: ?string,
     *     address: ?string,
     *     phone: ?string,
     *     email: ?string,
     *     motto: ?string,
     *     matric_pattern: string,
     *     min_units: int,
     *     max_units: int,
     *     approval_required: bool,
     *     withhold_results_for_debt: bool,
     *     attendance_threshold: int,
     *     require_minimum_payment: bool
     * }
     */
    private function readEffective(): array
    {
        $row = InstitutionSetting::query()->first();
        $logo_path = $row instanceof InstitutionSetting ? $this->usablePath($row->logo_path) : null;

        return [
            'name' => $this->presentText($row?->name, 'portal.institution.name'),
            'code' => $this->presentText($row?->code, 'portal.institution.code'),
            'logo_url' => $this->logoUrl($logo_path),
            'logo_path' => $logo_path,
            'address' => $this->optionalText($row?->address),
            'phone' => $this->optionalText($row?->phone),
            'email' => $this->optionalText($row?->email),
            'motto' => $this->optionalText($row?->motto),
            'matric_pattern' => $this->presentText($row?->matric_pattern, 'portal.institution.matric_pattern'),
            'min_units' => $row instanceof InstitutionSetting
                ? (int) $row->min_units
                : $this->configInt('portal.institution.min_units', 15),
            'max_units' => $row instanceof InstitutionSetting
                ? (int) $row->max_units
                : $this->configInt('portal.institution.max_units', 24),
            'approval_required' => $row instanceof InstitutionSetting
                ? (bool) $row->approval_required
                : $this->configBool('portal.institution.approval_required', true),
            'withhold_results_for_debt' => $row instanceof InstitutionSetting
                ? (bool) $row->withhold_results_for_debt
                : $this->configBool('portal.institution.withhold_results_for_debt', false),
            'attendance_threshold' => $row instanceof InstitutionSetting
                ? (int) $row->attendance_threshold
                : $this->configInt('portal.institution.attendance_threshold', 75),
            'require_minimum_payment' => $row instanceof InstitutionSetting
                && (bool) $row->require_minimum_payment,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function validated(array $input): array
    {
        $errors = [];
        $name = $this->requiredText($input['name'] ?? null, 255);

        if ($name === null) {
            $errors['name'] = 'Enter the institution name.';
        }

        $code = $this->code($input['code'] ?? null);

        if ($code === null) {
            $errors['code'] = 'Enter a short code using letters, digits, and hyphens.';
        }

        $email = $this->email($input['email'] ?? null);

        if ($email === false) {
            $errors['email'] = 'Enter a valid email address.';
        }

        $phone = $this->phone($input['phone'] ?? null);

        if ($phone === false) {
            $errors['phone'] = 'Enter a phone number of 7 to 15 digits.';
        }

        $pattern = $this->requiredText($input['matric_pattern'] ?? null, 64);
        $pattern_error = is_string($pattern) ? MatricPattern::error($pattern) : 'Enter a matric pattern.';

        if ($pattern === null || $pattern_error !== null) {
            $errors['matric_pattern'] = $pattern_error ?? 'Enter a matric pattern.';
        }

        $min_units = $this->integerIn($input['min_units'] ?? null, 1, 255);
        $max_units = $this->integerIn($input['max_units'] ?? null, 1, 255);

        if ($min_units === null) {
            $errors['min_units'] = 'Minimum units must be at least 1.';
        }

        if ($max_units === null) {
            $errors['max_units'] = 'Maximum units must be at least 1.';
        }

        if ($min_units !== null && $max_units !== null && $max_units < $min_units) {
            $errors['max_units'] = 'Maximum units must be at least the minimum.';
        }

        $attendance = $this->integerIn($input['attendance_threshold'] ?? null, 0, 100);

        if ($attendance === null) {
            $errors['attendance_threshold'] = 'Attendance threshold must be from 0 to 100.';
        }

        $approval = $this->boolean($input['approval_required'] ?? null);
        $withhold = $this->boolean($input['withhold_results_for_debt'] ?? null);
        $minimum_payment = $this->boolean($input['require_minimum_payment'] ?? null);

        if ($approval === null) {
            $errors['approval_required'] = 'Choose whether registration approval is required.';
        }

        if ($withhold === null) {
            $errors['withhold_results_for_debt'] = 'Choose whether results are withheld for debt.';
        }

        if ($minimum_payment === null) {
            $errors['require_minimum_payment'] = 'Choose whether a minimum payment is required.';
        }

        $address = $this->optionalBounded($input['address'] ?? null, 2000);
        $motto = $this->optionalBounded($input['motto'] ?? null, 255);

        if ($address === false) {
            $errors['address'] = 'The address is too long.';
        }

        if ($motto === false) {
            $errors['motto'] = 'The motto is too long.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'name' => $name,
            'code' => $code,
            'address' => $address,
            'phone' => $phone,
            'email' => $email,
            'motto' => $motto,
            'matric_pattern' => $pattern,
            'min_units' => $min_units,
            'max_units' => $max_units,
            'approval_required' => $approval,
            'withhold_results_for_debt' => $withhold,
            'attendance_threshold' => $attendance,
            'require_minimum_payment' => $minimum_payment,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(InstitutionSetting $row): array
    {
        return [
            'name' => $row->name,
            'code' => $row->code,
            'logo_path' => $row->logo_path,
            'address' => $row->address,
            'phone' => $row->phone,
            'email' => $row->email,
            'motto' => $row->motto,
            'matric_pattern' => $row->matric_pattern,
            'min_units' => (int) $row->min_units,
            'max_units' => (int) $row->max_units,
            'approval_required' => (bool) $row->approval_required,
            'withhold_results_for_debt' => (bool) $row->withhold_results_for_debt,
            'attendance_threshold' => (int) $row->attendance_threshold,
            'require_minimum_payment' => (bool) $row->require_minimum_payment,
        ];
    }

    private function limitUploads(User $actor): void
    {
        $key = 'institution-logo:'.$actor->id;

        if (RateLimiter::tooManyAttempts($key, self::UPLOADS_PER_MINUTE)) {
            throw ValidationException::withMessages([
                'logo' => 'Too many logo uploads. Wait a minute and try again.',
            ]);
        }

        RateLimiter::hit($key, 60);
    }

    private function storeLogo(UploadedFile $logo): string
    {
        $max_bytes = $this->maxLogoBytes();
        $size = $logo->getSize();

        if (! is_int($size) || $size < 1 || $size > $max_bytes) {
            throw ValidationException::withMessages([
                'logo' => InstitutionLogo::MESSAGE_SIZE,
            ]);
        }

        $contents = $logo->getContent();

        $declared_mime = $logo->getClientMimeType();

        try {
            $inspected = $this->logos->inspect(
                $contents,
                $max_bytes,
                $declared_mime !== '' ? $declared_mime : null,
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'logo' => $exception->getMessage(),
            ]);
        }

        $path = 'institution/logo-'.bin2hex(random_bytes(16)).'.'.$inspected['extension'];
        Storage::disk($this->diskName())->put($path, $contents);

        return $path;
    }

    private function logoUrl(?string $path): string
    {
        if ($path === null) {
            return $this->fallbackLogoUrl();
        }

        $contents = Storage::disk($this->diskName())->get($path);

        if (! is_string($contents) || $contents === '') {
            return $this->fallbackLogoUrl();
        }

        return route('institution.logo', ['v' => substr(hash('sha256', $contents), 0, 16)]);
    }

    private function storedPath(): ?string
    {
        $row = InstitutionSetting::query()->first();

        if (! $row instanceof InstitutionSetting) {
            return null;
        }

        return $this->usablePath($row->logo_path);
    }

    private function usablePath(mixed $path): ?string
    {
        if (! is_string($path) || $path === '' || ! str_starts_with($path, 'institution/logo-')) {
            return null;
        }

        if (! Storage::disk($this->diskName())->exists($path)) {
            return null;
        }

        return $path;
    }

    private function diskName(): string
    {
        $disk = config('filesystems.default');

        return is_string($disk) && $disk !== '' ? $disk : 'local';
    }

    private function maxLogoBytes(): int
    {
        $kilobytes = config('portal.uploads.logo_max_kilobytes');
        $value = is_int($kilobytes) ? $kilobytes : 1024;

        return max(1, $value) * 1024;
    }

    private function presentText(?string $stored, string $config_key): string
    {
        $text = $this->optionalText($stored);

        if ($text !== null) {
            return $text;
        }

        $fallback = config($config_key);

        return is_string($fallback) ? trim($fallback) : '';
    }

    private function optionalText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function configInt(string $key, int $default): int
    {
        $value = config($key);

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            return (int) $value;
        }

        return $default;
    }

    private function configBool(string $key, bool $default): bool
    {
        $value = config($key);

        return is_bool($value) ? $value : $default;
    }

    private function requiredText(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '' || strlen($trimmed) > $max || preg_match('/[\x00-\x1F\x7F]/', $trimmed) === 1) {
            return null;
        }

        return $trimmed;
    }

    private function code(mixed $value): ?string
    {
        $text = $this->requiredText($value, 32);

        if ($text === null) {
            return null;
        }

        $code = strtoupper($text);

        if (preg_match('/^[A-Z0-9][A-Z0-9-]{0,31}$/', $code) !== 1) {
            return null;
        }

        return $code;
    }

    /**
     * @return string|null|false Null is empty. False is invalid.
     */
    private function email(mixed $value): string|null|false
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            return false;
        }

        $email = trim($value);

        if ($email === '') {
            return null;
        }

        if (strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }

        return $email;
    }

    /**
     * @return string|null|false Null is empty. False is invalid.
     */
    private function phone(mixed $value): string|null|false
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            return false;
        }

        $phone = str_replace([' ', '-'], '', trim($value));

        if ($phone === '') {
            return null;
        }

        if (preg_match('/^\+?[0-9]{7,15}$/', $phone) !== 1) {
            return false;
        }

        return $phone;
    }

    /**
     * @return string|null|false Null is empty. False is too long.
     */
    private function optionalBounded(mixed $value, int $max): string|null|false
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            return false;
        }

        $text = trim($value);

        if ($text === '') {
            return null;
        }

        if (strlen($text) > $max || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $text) === 1) {
            return false;
        }

        return $text;
    }

    /**
     * A whole number inside the range, or null.
     *
     * Livewire numeric fields arrive as whole floats after the snapshot
     * round trip. A fractional value is rejected.
     */
    private function integerIn(mixed $value, int $min, int $max): ?int
    {
        if (is_int($value)) {
            $number = $value;
        } elseif (is_float($value) && fmod($value, 1.0) === 0.0) {
            $number = (int) $value;
        } elseif (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            $number = (int) $value;
        } else {
            return null;
        }

        if ($number < $min || $number > $max) {
            return null;
        }

        return $number;
    }

    private function boolean(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if ($value === 1 || $value === '1') {
            return true;
        }

        if ($value === 0 || $value === '0') {
            return false;
        }

        return null;
    }
}
