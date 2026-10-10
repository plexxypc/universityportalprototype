<?php

declare(strict_types=1);

namespace App\Support\Rbac;

use App\Enums\Role;

/**
 * Which permission rows decide visibleTo() for one model.
 */
final readonly class VisibilityProfile
{
    /**
     * @param  list<string>  $rows
     * @param  list<Role>  $skipRoles
     */
    public function __construct(
        public array $rows,
        public VisibilityKind $kind,
        public array $skipRoles = [],
    ) {}
}
