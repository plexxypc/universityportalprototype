<?php

declare(strict_types=1);

namespace App\Filament;

use Filament\Support\Colors\Color;

/**
 * Brand settings for the staff panel.
 *
 * Shades 50, 500, 600 and 700 are the design tokens. The remaining shades
 * are Filament's generated palette for the action colour, because DESIGN.md
 * does not define them. Filament stores the palette as OKLCH.
 */
final class StaffPanelTheme
{
    /**
     * Primary colour palette passed to the panel.
     *
     * @return array<int, string>
     */
    public static function primaryPalette(): array
    {
        $palette = Color::hex('#4F46E5');
        $palette[50] = '#EEF2FF';
        $palette[500] = '#6366F1';
        $palette[600] = '#4F46E5';
        $palette[700] = '#4338CA';

        return $palette;
    }

    /**
     * PRD module names, in document order.
     *
     * Empty groups are registered for later pages. Filament does not render
     * a group until it has an item. Role filtering arrives in Phase 4.
     *
     * @return list<string>
     */
    public static function navigationGroupLabels(): array
    {
        return [
            'Authentication and access control',
            'University setup and configuration',
            'Admissions and onboarding',
            'Student information management',
            'Student portal',
            'Academic management',
            'Course registration',
            'Fees and finance',
            'Payment system',
            'Email and notifications',
            'Results and academic records',
            'Examination timetable',
            'Attendance',
            'Staff and lecturer management',
            'Reports',
            'Administration and audit',
        ];
    }
}
