<?php

declare(strict_types=1);

use App\Enums\DocumentKind;
use App\Enums\DocumentOwner;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Filament\Staff\Pages\StudentRecordPage;
use App\Livewire\Student\Documents;
use App\Models\Document;
use App\Models\RoleAssignment;
use App\Models\Student;
use App\Models\User;
use App\Services\DocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');
});

/**
 * A student who can open the student area.
 */
function document_portal_student(string $matric): Student
{
    $user = User::factory()->create([
        'status' => UserStatus::Active,
        'must_change_password' => false,
    ]);

    return Student::factory()->create([
        'user_id' => $user->id,
        'matric_no' => $matric,
    ]);
}

test('a student can upload a pdf and a png and download their own file', function () {
    $student = document_portal_student('CSC/2026/0301');
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);

    Livewire::actingAs($student->user)
        ->test(Documents::class)
        ->set('kind', DocumentKind::Other->value)
        ->set('upload', UploadedFile::fake()->createWithContent('notes.pdf', "%PDF-1.4\n%%EOF"))
        ->call('save')
        ->assertHasNoErrors();

    Livewire::actingAs($student->user)
        ->test(Documents::class)
        ->set('kind', DocumentKind::PassportPhotograph->value)
        ->set('upload', UploadedFile::fake()->createWithContent('photo.png', (string) $png))
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Download')
        ->assertDontSee('Remove');

    $document = Document::query()->where('mime', 'application/pdf')->first();

    $this->actingAs($student->user)
        ->get(route('student.documents.download', $document))
        ->assertOk()
        ->assertHeader('content-disposition')
        ->assertHeader('x-content-type-options', 'nosniff');
});

test('an svg a renamed executable and a file over 5 mb are refused', function () {
    $student = document_portal_student('CSC/2026/0302');
    $component = Livewire::actingAs($student->user)->test(Documents::class);

    $component
        ->set('kind', DocumentKind::Other->value)
        ->set('upload', UploadedFile::fake()->createWithContent('mark.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'))
        ->call('save')
        ->assertHasErrors(['document' => DocumentService::MESSAGE_TYPE]);

    $component
        ->set('kind', DocumentKind::Other->value)
        ->set('upload', UploadedFile::fake()->createWithContent('notes.pdf', "MZ\x90this-is-not-a-pdf"))
        ->call('save')
        ->assertHasErrors(['document' => DocumentService::MESSAGE_TYPE]);

    $component
        ->set('kind', DocumentKind::Other->value)
        ->set('upload', UploadedFile::fake()->createWithContent('big.pdf', str_repeat('A', DocumentService::MAX_BYTES + 1)))
        ->call('save')
        ->assertHasErrors(['document' => DocumentService::MESSAGE_SIZE]);

    expect(Document::query()->count())->toBe(0);
});

test('another student gets the same 404 for a real document and a made up id', function () {
    $owner = document_portal_student('CSC/2026/0303');
    $other = document_portal_student('CSC/2026/0304');

    $this->actingAs($owner->user);
    $document = app(DocumentService::class)->uploadOwn(
        $owner->user,
        DocumentKind::Other->value,
        "%PDF-1.4\n%%EOF",
        null,
        request(),
    );

    $missing = $this->actingAs($other->user)->get(route('student.documents.download', 999999));
    $foreign = $this->actingAs($other->user)->get(route('student.documents.download', $document));
    $missing_body = str_replace('/student/documents/999999', '/student/documents/ID', (string) $missing->getContent());
    $foreign_body = str_replace('/student/documents/'.$document->getKey(), '/student/documents/ID', (string) $foreign->getContent());

    expect($missing->status())->toBe(404)
        ->and($foreign->status())->toBe(404)
        ->and($missing_body)->toBe($foreign_body)
        ->and($foreign_body)->not->toContain('%PDF');
});

test('the document count and total size caps are refused', function () {
    expect(config('portal.uploads.document_max_count'))->toBe(20)
        ->and(config('portal.uploads.document_max_total_kilobytes'))->toBe(51200);

    $student = document_portal_student('CSC/2026/0305');
    config([
        'portal.uploads.document_max_count' => 1,
        'portal.uploads.document_max_total_kilobytes' => 1,
    ]);

    $this->actingAs($student->user);
    app(DocumentService::class)->uploadOwn(
        $student->user,
        DocumentKind::Other->value,
        "%PDF-1.4\n%%EOF",
        null,
        request(),
    );

    Livewire::actingAs($student->user)
        ->test(Documents::class)
        ->set('kind', DocumentKind::Other->value)
        ->set('upload', UploadedFile::fake()->createWithContent('second.pdf', "%PDF-1.4\n%%EOF"))
        ->call('save')
        ->assertHasErrors(['document' => DocumentService::MESSAGE_COUNT]);

    config(['portal.uploads.document_max_count' => 20]);
    Document::query()->delete();

    $payload = "%PDF-1.4\n".str_repeat('A', 700);
    app(DocumentService::class)->uploadOwn($student->user, DocumentKind::Other->value, $payload, null, request());

    Livewire::actingAs($student->user)
        ->test(Documents::class)
        ->set('kind', DocumentKind::Other->value)
        ->set('upload', UploadedFile::fake()->createWithContent('third.pdf', $payload))
        ->call('save')
        ->assertHasErrors(['document' => DocumentService::MESSAGE_TOTAL]);
});

test('a registrar can download and remove a student upload', function () {
    $student = document_portal_student('CSC/2026/0306');
    $registrar = User::factory()->create();
    RoleAssignment::factory()->create([
        'user_id' => $registrar->id,
        'role' => Role::Registrar,
    ]);

    $this->actingAs($student->user);
    $document = app(DocumentService::class)->uploadOwn(
        $student->user,
        DocumentKind::BirthCertificate->value,
        "%PDF-1.4\n%%EOF",
        null,
        request(),
    );

    $this->actingAs($registrar)
        ->get('/staff/students/'.$student->getKey())
        ->assertOk()
        ->assertSee(DocumentKind::BirthCertificate->value)
        ->assertSee(route('staff.downloads.document', $document));

    $this->actingAs($registrar)
        ->get(route('staff.downloads.document', $document))
        ->assertOk();

    Livewire::actingAs($registrar)
        ->test(StudentRecordPage::class, ['student' => $student->getKey()])
        ->call('removeDocument', $document->getKey());

    expect(Document::query()->whereKey($document->getKey())->exists())->toBeFalse()
        ->and($document->owner_type)->toBe(DocumentOwner::Student);
});
