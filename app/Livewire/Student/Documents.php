<?php

declare(strict_types=1);

namespace App\Livewire\Student;

use App\Enums\DocumentKind;
use App\Enums\DocumentOwner;
use App\Models\Document;
use App\Models\User;
use App\Services\DocumentService;
use App\Support\CurrentStudent;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * The signed-in student's documents.
 *
 * The component does not keep a student id. Removal is not offered.
 */
class Documents extends Component
{
    use WithFileUploads;

    public string $kind = '';

    public ?TemporaryUploadedFile $upload = null;

    /**
     * Store one file for the signed-in student.
     */
    public function save(DocumentService $documents): void
    {
        $student = CurrentStudent::require();
        $user = $student->user;
        $upload = $this->upload;

        if (! $user instanceof User || ! $upload instanceof TemporaryUploadedFile) {
            throw ValidationException::withMessages([
                'document' => 'Choose a file.',
            ]);
        }

        $contents = $upload->get();

        if (! is_string($contents)) {
            throw ValidationException::withMessages([
                'document' => DocumentService::MESSAGE_TYPE,
            ]);
        }

        $documents->uploadOwn($user, $this->kind, $contents, $upload->getMimeType(), request());
        $this->reset('upload', 'kind');
        session()->flash('toast', 'Your document was saved.');
    }

    /**
     * List this student's documents.
     */
    public function render(): View
    {
        $student = CurrentStudent::require();
        $user = $student->user;
        $rows = $user instanceof User
            ? Document::query()
                ->visibleTo($user)
                ->where('owner_type', DocumentOwner::Student)
                ->where('owner_id', $student->getKey())
                ->orderByDesc('id')
                ->get()
            : collect();

        return view('livewire.student.documents', [
            'documents' => $rows,
            'kinds' => DocumentKind::cases(),
        ]);
    }
}
