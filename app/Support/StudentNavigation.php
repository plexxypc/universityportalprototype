<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Placeholder destinations for the student shell.
 *
 * Real routes arrive with the student portal. These items only label the chrome.
 */
final class StudentNavigation
{
    /**
     * Primary student navigation, shared by the sidebar and the bottom bar.
     *
     * @return list<array{id: string, label: string, icon: string}>
     */
    public static function items(): array
    {
        return [
            ['id' => 'home', 'label' => 'Home', 'icon' => 'home'],
            ['id' => 'courses', 'label' => 'Courses', 'icon' => 'book'],
            ['id' => 'fees', 'label' => 'Fees', 'icon' => 'card'],
            ['id' => 'results', 'label' => 'Results', 'icon' => 'file'],
            ['id' => 'more', 'label' => 'More', 'icon' => 'more'],
        ];
    }
}
