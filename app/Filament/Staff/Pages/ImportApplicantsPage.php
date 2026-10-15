<?php

declare(strict_types=1);

namespace App\Filament\Staff\Pages;

use App\Enums\ImportTarget;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Applicant import. The staged file is marked Applicant and cannot be switched.
 *
 * The public target property can be changed in the browser. Staging and
 * commit use importTarget(), then the value stored on the file.
 */
class ImportApplicantsPage extends ImportStudentsPage
{
    protected static ?string $slug = 'applicants/import';

    protected static ?string $navigationLabel = 'Import applicants';

    protected static string|UnitEnum|null $navigationGroup = 'Admissions and onboarding';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static ?int $navigationSort = 3;

    public string $target = 'Applicant';

    public string $send_mode = 'later';

    public function getTitle(): string
    {
        return 'Import applicants';
    }

    /**
     * Applicant rows never create student accounts from this page.
     */
    public function importTarget(): ImportTarget
    {
        return ImportTarget::Applicant;
    }
}
