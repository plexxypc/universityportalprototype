<?php

declare(strict_types=1);

namespace App\Support\Rbac;

use App\Enums\PermissionAccess;
use App\Enums\PermissionScope;

/**
 * One cell of the PRD section 5 permission matrix.
 */
final readonly class PermissionCell
{
    /**
     * @param  list<PermissionScope>  $scopes
     */
    public function __construct(
        public PermissionAccess $access,
        public array $scopes = [],
    ) {}
}
