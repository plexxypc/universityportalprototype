<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * Shared class strings for form controls. Colours and sizes come from design tokens.
 */
final class ControlClasses
{
    /**
     * Classes for a button variant and size.
     */
    public static function button(string $variant, string $size): string
    {
        $variant_class = match ($variant) {
            'primary' => 'bg-primary-600 text-white hover:bg-primary-700',
            'secondary' => 'border border-border bg-surface text-text hover:bg-primary-50',
            'ghost' => 'bg-transparent text-text hover:bg-primary-50',
            'destructive' => 'bg-destructive text-white hover:bg-destructive-hover',
            'link' => 'bg-transparent text-primary-600 underline-offset-2 hover:underline',
            default => throw new InvalidArgumentException('Unknown button variant.'),
        };

        $size_class = match ($size) {
            'small' => 'min-h-11 min-w-11 px-space-12 text-small md:h-8 md:min-h-8 md:min-w-0',
            'default' => 'min-h-11 min-w-11 px-space-16 text-body md:h-10 md:min-h-10 md:min-w-0',
            'large' => 'h-12 min-h-12 min-w-11 px-space-24 text-body',
            default => throw new InvalidArgumentException('Unknown button size.'),
        };

        return 'inline-flex max-w-full items-center justify-center gap-space-8 rounded-control font-semibold transition duration-200 motion-reduce:transition-none focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-70 '.$size_class.' '.$variant_class;
    }

    /**
     * Classes for a single-line text control.
     */
    public static function input(): string
    {
        return 'h-11 w-full min-w-0 rounded-control border border-border bg-surface px-space-12 text-input font-normal text-text outline-none placeholder:text-muted focus:ring-2 focus:ring-primary focus:ring-offset-2 disabled:cursor-not-allowed disabled:bg-neutral-bg disabled:text-muted aria-invalid:border-danger-text md:h-10 md:text-body';
    }

    /**
     * Classes for a multi-line control.
     */
    public static function textarea(): string
    {
        return 'min-h-24 w-full min-w-0 rounded-control border border-border bg-surface px-space-12 py-space-8 text-input font-normal text-text outline-none placeholder:text-muted focus:ring-2 focus:ring-primary focus:ring-offset-2 disabled:cursor-not-allowed disabled:bg-neutral-bg disabled:text-muted aria-invalid:border-danger-text md:text-body';
    }
}
