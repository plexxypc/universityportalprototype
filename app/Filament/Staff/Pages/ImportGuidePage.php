<?php

declare(strict_types=1);

namespace App\Filament\Staff\Pages;

use App\Models\User;
use App\Policies\StudentPolicy;
use Filament\Pages\Page;

/**
 * Question list for a Google Form whose sheet can be imported.
 *
 * The order matches PRD 6.3. Matric number and entry session stay off
 * this list because the form does not ask for them.
 */
class ImportGuidePage extends Page
{
    protected static ?string $slug = 'students/import-guide';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.staff.import-guide';

    /**
     * Super Admin and Registrar. Other staff roles are refused.
     */
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(StudentPolicy::class)->create($user);
    }

    public function getTitle(): string
    {
        return 'Google Form guide';
    }

    /**
     * Questions in the order the form should ask them.
     *
     * @return list<string>
     */
    public function questions(): array
    {
        return [
            'First name',
            'Last name',
            'Other names',
            'Email',
            'Phone',
            'Gender',
            'Date of birth',
            'State of origin',
            'Address',
            'Programme (dropdown containing programme codes)',
            'Level',
            'Guardian name',
            'Guardian phone',
            'Guardian email',
            'Relationship',
        ];
    }
}
