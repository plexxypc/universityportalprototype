<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Student navigation shared by the sidebar and the bottom bar.
 *
 * Courses, Fees, and Results stay visible and disabled until those pages exist.
 */
final class StudentNavigation
{
    /**
     * Primary student navigation.
     *
     * A disabled item has no route. The bar always has five items.
     *
     * @return list<array{id: string, label: string, icon: string, route: ?string, enabled: bool}>
     */
    public static function items(): array
    {
        return [
            ['id' => 'home', 'label' => 'Home', 'icon' => 'home', 'route' => 'student.home', 'enabled' => true],
            ['id' => 'courses', 'label' => 'Courses', 'icon' => 'book', 'route' => null, 'enabled' => false],
            ['id' => 'fees', 'label' => 'Fees', 'icon' => 'card', 'route' => null, 'enabled' => false],
            ['id' => 'results', 'label' => 'Results', 'icon' => 'file', 'route' => null, 'enabled' => false],
            ['id' => 'more', 'label' => 'More', 'icon' => 'more', 'route' => 'student.more', 'enabled' => true],
        ];
    }

    /**
     * Pages that exist, for More and the dashboard quick actions.
     *
     * @return list<array{label: string, route: string}>
     */
    public static function pages(): array
    {
        return [
            ['label' => 'Profile', 'route' => 'student.profile'],
            ['label' => 'Documents', 'route' => 'student.documents'],
            ['label' => 'Announcements', 'route' => 'student.announcements'],
            ['label' => 'Notifications', 'route' => 'student.notifications'],
        ];
    }
}
