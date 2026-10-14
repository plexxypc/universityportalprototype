<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The 36 states and the Federal Capital Territory.
 */
final class NigerianStates
{
    /**
     * Canonical names, in alphabetical order, with the capital territory last.
     *
     * @var list<string>
     */
    private const array NAMES = [
        'Abia',
        'Adamawa',
        'Akwa Ibom',
        'Anambra',
        'Bauchi',
        'Bayelsa',
        'Benue',
        'Borno',
        'Cross River',
        'Delta',
        'Ebonyi',
        'Edo',
        'Ekiti',
        'Enugu',
        'Gombe',
        'Imo',
        'Jigawa',
        'Kaduna',
        'Kano',
        'Katsina',
        'Kebbi',
        'Kogi',
        'Kwara',
        'Lagos',
        'Nasarawa',
        'Niger',
        'Ogun',
        'Ondo',
        'Osun',
        'Oyo',
        'Plateau',
        'Rivers',
        'Sokoto',
        'Taraba',
        'Yobe',
        'Zamfara',
        'Federal Capital Territory',
    ];

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return self::NAMES;
    }

    /**
     * The canonical name, or null when the text is not a state.
     */
    public static function canonical(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        foreach (self::NAMES as $name) {
            if (mb_strtolower($name) === mb_strtolower($trimmed)) {
                return $name;
            }
        }

        return null;
    }
}
