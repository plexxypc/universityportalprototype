<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DocumentOwner;
use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A file owned by an applicant or a student.
 *
 * owner_type and owner_id have no foreign key. The path is on the local demo disk.
 */
#[Fillable(['owner_type', 'owner_id', 'kind', 'path', 'mime', 'size'])]
class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory;

    /**
     * Cast the owner label.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'owner_type' => DocumentOwner::class,
        ];
    }

    /**
     * Applicant or student that owns this file.
     *
     * owner_type stores the label Applicant or Student. The morph map binds those labels.
     *
     * @return MorphTo<Model, $this>
     */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }
}
