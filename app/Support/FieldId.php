<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Builds stable ids so a field label, control, help text and error share one name.
 */
final class FieldId
{
    /**
     * Id for the control that belongs to this field name.
     */
    public static function for(string $name): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_-]/', '-', $name) ?? $name;

        return 'field-'.$safe;
    }

    /**
     * Id for the help text linked with aria-describedby.
     */
    public static function help(string $name): string
    {
        return self::for($name).'-help';
    }

    /**
     * Id for the error text linked with aria-describedby.
     */
    public static function error(string $name): string
    {
        return self::for($name).'-error';
    }
}
