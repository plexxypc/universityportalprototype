<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\ScopesVisibleTo;
use App\Models\Concerns\VisibleToUser;
use App\Support\Rbac\VisibilityKind;
use App\Support\Rbac\VisibilityProfile;
use Database\Factories\InstitutionSettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * The one institution profile. singleton_key is not fillable.
 *
 * The table may contain zero rows. Reading these settings, and falling back
 * to config/portal.php, is enforced in a service later.
 */
#[Fillable([
    'name',
    'code',
    'logo_path',
    'address',
    'phone',
    'email',
    'motto',
    'matric_pattern',
    'min_units',
    'max_units',
    'approval_required',
    'withhold_results_for_debt',
    'attendance_threshold',
    'require_minimum_payment',
])]
class InstitutionSetting extends Model implements VisibleToUser
{
    /** @use HasFactory<InstitutionSettingFactory> */
    use HasFactory;

    use ScopesVisibleTo;

    /**
     * Cast the setting flags.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'approval_required' => 'boolean',
            'withhold_results_for_debt' => 'boolean',
            'require_minimum_payment' => 'boolean',
        ];
    }

    /**
     * The profile follows users_roles_settings. Only Super Admin holds that key.
     */
    protected static function visibilityProfile(): VisibilityProfile
    {
        return new VisibilityProfile(
            rows: ['users_roles_settings'],
            kind: VisibilityKind::InstitutionSetting,
        );
    }
}
