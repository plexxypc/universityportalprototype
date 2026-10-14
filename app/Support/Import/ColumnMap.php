<?php

declare(strict_types=1);

namespace App\Support\Import;

use Illuminate\Validation\ValidationException;

/**
 * Matches spreadsheet headers to the student import fields.
 *
 * Matching ignores case, spaces, underscores, and hyphens.
 * A confirmed map contains only known fields and in-range indexes.
 */
final class ColumnMap
{
    public const string MESSAGE_REQUIRED = 'Choose a column for every required field.';

    public const string MESSAGE_COLUMN = 'That column is not in this file.';

    public const string MESSAGE_DUPLICATE = 'Each file column can map to only one field.';

    public const string MESSAGE_FIELD = 'That field is not an import column.';

    /**
     * Portal fields in import order.
     *
     * @var array<string, array{label: string, required: bool, aliases: list<string>}>
     */
    private const array DEFINITIONS = [
        'first_name' => ['label' => 'First name', 'required' => true, 'aliases' => ['given name']],
        'last_name' => ['label' => 'Last name', 'required' => true, 'aliases' => ['surname', 'family name']],
        'other_names' => ['label' => 'Other names', 'required' => false, 'aliases' => ['middle name', 'middle names']],
        'email' => ['label' => 'Email', 'required' => true, 'aliases' => ['email address', 'e-mail']],
        'phone' => ['label' => 'Phone', 'required' => false, 'aliases' => ['phone number', 'mobile', 'telephone']],
        'gender' => ['label' => 'Gender', 'required' => false, 'aliases' => ['sex']],
        'date_of_birth' => ['label' => 'Date of birth', 'required' => false, 'aliases' => ['dob', 'birth date', 'birthday']],
        'state_of_origin' => ['label' => 'State of origin', 'required' => false, 'aliases' => ['state']],
        'address' => ['label' => 'Address', 'required' => false, 'aliases' => ['home address']],
        'programme_code' => ['label' => 'Programme code', 'required' => true, 'aliases' => ['programme', 'program', 'program code']],
        'level' => ['label' => 'Level', 'required' => true, 'aliases' => []],
        'entry_session' => ['label' => 'Entry session', 'required' => false, 'aliases' => ['session']],
        'matric_number' => ['label' => 'Matric number', 'required' => false, 'aliases' => ['matric', 'matriculation number']],
        'guardian_name' => ['label' => 'Guardian name', 'required' => false, 'aliases' => ['next of kin', 'next of kin name']],
        'guardian_phone' => ['label' => 'Guardian phone', 'required' => false, 'aliases' => ['next of kin phone']],
        'guardian_email' => ['label' => 'Guardian email', 'required' => false, 'aliases' => []],
        'guardian_relationship' => ['label' => 'Guardian relationship', 'required' => false, 'aliases' => ['relationship']],
    ];

    /**
     * Field key, label, and whether the registrar must map it.
     *
     * @return list<array{key: string, label: string, required: bool}>
     */
    public static function fields(): array
    {
        $fields = [];

        foreach (self::DEFINITIONS as $key => $definition) {
            $fields[] = [
                'key' => $key,
                'label' => $definition['label'],
                'required' => $definition['required'],
            ];
        }

        return $fields;
    }

    /**
     * Column index for each header that matches a portal field.
     *
     * @param  list<string>  $headers
     * @return array<string, int>
     */
    public static function suggest(array $headers): array
    {
        $aliases = self::aliases();
        $map = [];

        foreach ($headers as $index => $header) {
            $compact = self::compact($header);

            if ($compact === '' || ! isset($aliases[$compact]) || isset($map[$aliases[$compact]])) {
                continue;
            }

            $map[$aliases[$compact]] = $index;
        }

        return $map;
    }

    /**
     * Keep a registrar's map only when every required field has one column.
     *
     * @param  list<string>  $headers
     * @param  array<mixed, mixed>  $submitted
     * @return array<string, int>
     */
    public static function confirmed(array $headers, array $submitted): array
    {
        $known = [];

        foreach (self::fields() as $field) {
            $known[$field['key']] = $field['required'];
        }

        $parsed = [];
        $used = [];

        foreach ($submitted as $field => $index) {
            if (! is_string($field) || ! array_key_exists($field, $known)) {
                throw ValidationException::withMessages(['column_map' => self::MESSAGE_FIELD]);
            }

            if ($index === null || $index === '') {
                continue;
            }

            if (is_string($index) && ctype_digit($index)) {
                $index = (int) $index;
            }

            if (! is_int($index) || $index < 0 || $index >= count($headers)) {
                throw ValidationException::withMessages(['column_map' => self::MESSAGE_COLUMN]);
            }

            if (isset($used[$index])) {
                throw ValidationException::withMessages(['column_map' => self::MESSAGE_DUPLICATE]);
            }

            $used[$index] = true;
            $parsed[$field] = $index;
        }

        foreach ($known as $field => $required) {
            if ($required && ! isset($parsed[$field])) {
                throw ValidationException::withMessages(['column_map' => self::MESSAGE_REQUIRED]);
            }
        }

        return $parsed;
    }

    /**
     * Compact header text pointing at one portal field.
     *
     * @return array<string, string>
     */
    private static function aliases(): array
    {
        $aliases = [];

        foreach (self::DEFINITIONS as $key => $definition) {
            $aliases[self::compact($key)] = $key;

            foreach ($definition['aliases'] as $alias) {
                $aliases[self::compact($alias)] = $key;
            }
        }

        return $aliases;
    }

    /**
     * Lower-case letters and digits only.
     */
    private static function compact(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = str_replace(['_', '-'], ' ', $value);
        $value = preg_replace('/[^a-z0-9 ]+/', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return str_replace(' ', '', trim($value));
    }
}
