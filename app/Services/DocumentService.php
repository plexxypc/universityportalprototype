<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DocumentKind;
use App\Enums\DocumentOwner;
use App\Models\Document;
use App\Models\Student;
use App\Models\User;
use App\Policies\StudentPolicy;
use App\Support\Audit;
use App\Support\CurrentStudent;
use App\Support\InstitutionLogo;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Stores student documents on the local private disk and removes them.
 *
 * The stored name is random. The download name is generated from the id
 * and the sniffed type. The client's filename is never kept.
 */
class DocumentService
{
    public const int MAX_BYTES = 5 * 1024 * 1024;

    public const int UPLOADS_PER_MINUTE = 10;

    public const string ACTION_UPLOADED = 'documents.uploaded';

    public const string ACTION_REMOVED = 'documents.removed';

    public const string MESSAGE_TYPE = 'The document must be a PDF, JPEG, or PNG.';

    public const string MESSAGE_SIZE = 'The document is larger than 5 MB.';

    public const string MESSAGE_READ = 'The document could not be read as an image.';

    public const string MESSAGE_DIMENSIONS = 'The image must be at most 2000 by 2000 pixels.';

    public const string MESSAGE_RATE = 'Too many uploads. Wait a minute and try again.';

    public const string MESSAGE_KIND = 'Choose a document type.';

    public const string MESSAGE_MISSING = 'That document was not found.';

    public const string MESSAGE_FILE_GONE = 'This file is no longer stored.';

    public const string MESSAGE_COUNT = 'You already have the maximum of 20 documents.';

    public const string MESSAGE_TOTAL = 'Your documents already use the 50 MB limit.';

    public function __construct(
        private readonly Audit $audit,
        private readonly InstitutionLogo $images,
    ) {}

    /**
     * Store one sniffed document for a student the actor may manage.
     */
    public function upload(
        User $actor,
        int $student_id,
        string $kind,
        string $contents,
        ?string $declared_mime,
        Request $request,
    ): Document {
        $document_kind = $this->kind($kind);
        $checked = $this->inspect($contents, $declared_mime);

        return DB::transaction(function () use ($actor, $student_id, $document_kind, $contents, $checked, $request): Document {
            $student = $this->lockedStudent($actor, $student_id);
            $this->limitUploads($actor);

            return $this->storeDocument($actor, $student, $document_kind, $contents, $checked, $request);
        });
    }

    /**
     * Store one sniffed document for the signed-in student.
     *
     * The student comes from the authenticated user. At most 20 files and
     * 50 MB in total. The student cannot remove a file.
     */
    public function uploadOwn(
        User $actor,
        string $kind,
        string $contents,
        ?string $declared_mime,
        Request $request,
    ): Document {
        $student = CurrentStudent::require();

        if ((int) $student->user_id !== (int) $actor->id) {
            throw new AuthorizationException;
        }

        $document_kind = $this->kind($kind);
        $checked = $this->inspect($contents, $declared_mime);

        return DB::transaction(function () use ($actor, $student, $document_kind, $contents, $checked, $request): Document {
            $locked = Student::query()->whereKey($student->getKey())->lockForUpdate()->first();

            if (! $locked instanceof Student || (int) $locked->user_id !== (int) $actor->id) {
                throw new AuthorizationException;
            }

            $this->limitUploads($actor);
            $this->enforceOwnCaps($locked, strlen($contents));

            return $this->storeDocument($actor, $locked, $document_kind, $contents, $checked, $request);
        });
    }

    /**
     * Remove one document the actor may manage, and record the removal.
     */
    public function remove(User $actor, int $document_id, Request $request): void
    {
        DB::transaction(function () use ($actor, $document_id, $request): void {
            $document = Document::query()->whereKey($document_id)->lockForUpdate()->first();

            if (! $document instanceof Document || $document->owner_type !== DocumentOwner::Student) {
                throw ValidationException::withMessages(['document' => self::MESSAGE_MISSING]);
            }

            $student = $this->lockedStudent($actor, (int) $document->owner_id);
            unset($student);

            $before = [
                'kind' => $document->kind,
                'mime' => $document->mime,
                'size' => $document->size,
            ];
            $path = $document->path;
            $id = (int) $document->getKey();
            $document->delete();
            Storage::disk('local')->delete($path);

            $this->audit->record(
                $actor->id,
                self::ACTION_REMOVED,
                'documents',
                $id,
                $before,
                null,
                $this->audit->ipFromRequest($request),
            );
        });
    }

    /**
     * A download name that does not come from the client.
     */
    public function downloadName(Document $document): string
    {
        $extension = match ($document->mime) {
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            default => 'bin',
        };

        return 'document-'.$document->getKey().'.'.$extension;
    }

    /**
     * @return array{extension: string, mime: string}
     */
    private function inspect(string $contents, ?string $declared_mime): array
    {
        if ($contents === '' || strlen($contents) > self::MAX_BYTES) {
            throw ValidationException::withMessages(['document' => self::MESSAGE_SIZE]);
        }

        if (str_starts_with($contents, '%PDF-')) {
            if ($this->declaredConflicts($declared_mime, 'application/pdf')) {
                throw ValidationException::withMessages(['document' => self::MESSAGE_TYPE]);
            }

            return [
                'extension' => 'pdf',
                'mime' => 'application/pdf',
            ];
        }

        try {
            $image = $this->images->inspect($contents, self::MAX_BYTES, $declared_mime);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'document' => $this->imageMessage($exception->getMessage()),
            ]);
        }

        return $image;
    }

    private function declaredConflicts(?string $declared_mime, string $mime): bool
    {
        if ($declared_mime === null || $declared_mime === '' || $declared_mime === 'application/octet-stream') {
            return false;
        }

        return $declared_mime !== $mime;
    }

    private function imageMessage(string $message): string
    {
        return match ($message) {
            InstitutionLogo::MESSAGE_SIZE => self::MESSAGE_SIZE,
            InstitutionLogo::MESSAGE_READ => self::MESSAGE_READ,
            InstitutionLogo::MESSAGE_DIMENSIONS => self::MESSAGE_DIMENSIONS,
            default => self::MESSAGE_TYPE,
        };
    }

    private function kind(string $kind): DocumentKind
    {
        $parsed = DocumentKind::tryFrom($kind);

        if (! $parsed instanceof DocumentKind) {
            throw ValidationException::withMessages(['kind' => self::MESSAGE_KIND]);
        }

        return $parsed;
    }

    private function limitUploads(User $actor): void
    {
        $key = 'student-document-upload:'.$actor->id;

        if (RateLimiter::tooManyAttempts($key, self::UPLOADS_PER_MINUTE)) {
            throw ValidationException::withMessages(['document' => self::MESSAGE_RATE]);
        }

        RateLimiter::hit($key, 60);
    }

    /**
     * Refuse a student upload that would pass 20 files or 50 MB.
     */
    private function enforceOwnCaps(Student $student, int $bytes): void
    {
        $rows = Document::query()
            ->where('owner_type', DocumentOwner::Student)
            ->where('owner_id', $student->getKey())
            ->lockForUpdate()
            ->get(['size']);
        $max_count = (int) config('portal.uploads.document_max_count');
        $max_total = (int) config('portal.uploads.document_max_total_kilobytes') * 1024;

        if ($rows->count() >= $max_count) {
            throw ValidationException::withMessages(['document' => self::MESSAGE_COUNT]);
        }

        $total = 0;

        foreach ($rows as $row) {
            $size = $row->getAttribute('size');

            if (! is_int($size) && ! (is_string($size) && preg_match('/\A\d+\z/', $size) === 1)) {
                throw ValidationException::withMessages(['document' => self::MESSAGE_TOTAL]);
            }

            $size = (int) $size;

            if ($size < 0 || $total > $max_total - $size) {
                throw ValidationException::withMessages(['document' => self::MESSAGE_TOTAL]);
            }

            $total += $size;
        }

        if ($bytes < 0 || $total > $max_total - $bytes) {
            throw ValidationException::withMessages(['document' => self::MESSAGE_TOTAL]);
        }
    }

    /**
     * Write the file and the row. The caller holds the student lock.
     *
     * @param  array{extension: string, mime: string}  $checked
     */
    private function storeDocument(
        User $actor,
        Student $student,
        DocumentKind $document_kind,
        string $contents,
        array $checked,
        Request $request,
    ): Document {
        $path = 'documents/'.bin2hex(random_bytes(16)).'.'.$checked['extension'];
        Storage::disk('local')->put($path, $contents);

        $document = new Document;
        $document->owner_type = DocumentOwner::Student;
        $document->owner_id = (int) $student->getKey();
        $document->kind = $document_kind->value;
        $document->path = $path;
        $document->mime = $checked['mime'];
        $document->size = strlen($contents);
        $document->save();

        $this->audit->record(
            $actor->id,
            self::ACTION_UPLOADED,
            'documents',
            (int) $document->getKey(),
            null,
            [
                'kind' => $document->kind,
                'mime' => $document->mime,
                'size' => $document->size,
            ],
            $this->audit->ipFromRequest($request),
        );

        return $document;
    }

    private function lockedStudent(User $actor, int $student_id): Student
    {
        $student = Student::query()->visibleTo($actor)->whereKey($student_id)->lockForUpdate()->first();

        if (! $student instanceof Student || ! app(StudentPolicy::class)->update($actor, $student)) {
            throw new AuthorizationException;
        }

        return $student;
    }
}
