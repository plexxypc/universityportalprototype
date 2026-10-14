<?php

declare(strict_types=1);

use App\Enums\DocumentKind;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\Guardian;
use App\Models\RoleAssignment;
use App\Models\Student;
use App\Models\User;
use App\Services\DocumentService;
use App\Services\StudentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake('local');
});

function documents_actor(Role $role): User
{
    $user = User::factory()->create();
    RoleAssignment::factory()->create([
        'user_id' => $user->id,
        'role' => $role,
    ]);

    return $user;
}

function documents_request(): Request
{
    return Request::create('/', 'POST', server: ['REMOTE_ADDR' => '203.0.113.40']);
}

function documents_png(): string
{
    $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);

    expect($bytes)->toBeString();

    return (string) $bytes;
}

function documents_wide_png(): string
{
    $image = imagecreatetruecolor(2001, 1);
    ob_start();
    imagepng($image);
    $bytes = ob_get_clean();
    unset($image);

    expect($bytes)->toBeString();

    return (string) $bytes;
}

it('stores a sniffed document under a random name and audits the upload', function (): void {
    $registrar = documents_actor(Role::Registrar);
    $student = Student::factory()->create();
    $service = app(DocumentService::class);

    $first = $service->upload($registrar, (int) $student->id, DocumentKind::BirthCertificate->value, documents_png(), 'image/png', documents_request());
    $second = $service->upload($registrar, (int) $student->id, DocumentKind::Other->value, "%PDF-1.4\ntrailer\n%%EOF", null, documents_request());

    expect($first->path)->not->toContain('client-secret')
        ->and($second->path)->not->toContain('client-secret')
        ->and($first->path)->not->toBe($second->path)
        ->and($first->mime)->toBe('image/png')
        ->and($second->mime)->toBe('application/pdf')
        ->and(Storage::disk('local')->exists($first->path))->toBeTrue();

    $audit = AuditLog::query()->where('action', DocumentService::ACTION_UPLOADED)->first();

    expect($audit)->not->toBeNull()
        ->and(json_encode($audit?->after))->not->toContain('client-secret')
        ->and(json_encode($audit?->after))->toContain('Birth certificate');
});

it('refuses a document that is not a pdf or image, is over 5 MB, or is too wide', function (): void {
    $registrar = documents_actor(Role::Registrar);
    $student = Student::factory()->create();
    $service = app(DocumentService::class);
    $broken = "\x89PNG\r\n\x1a\nnot-an-image";

    expect(fn () => $service->upload($registrar, (int) $student->id, DocumentKind::Other->value, 'hello', null, documents_request()))
        ->toThrow(ValidationException::class, DocumentService::MESSAGE_TYPE)
        ->and(fn () => $service->upload($registrar, (int) $student->id, DocumentKind::Other->value, $broken, 'image/png', documents_request()))
        ->toThrow(ValidationException::class)
        ->and(fn () => $service->upload($registrar, (int) $student->id, DocumentKind::Other->value, str_repeat('a', DocumentService::MAX_BYTES + 1), null, documents_request()))
        ->toThrow(ValidationException::class, DocumentService::MESSAGE_SIZE)
        ->and(fn () => $service->upload($registrar, (int) $student->id, DocumentKind::PassportPhotograph->value, documents_wide_png(), 'image/png', documents_request()))
        ->toThrow(ValidationException::class, DocumentService::MESSAGE_DIMENSIONS)
        ->and(fn () => $service->upload($registrar, (int) $student->id, 'Essay', documents_png(), 'image/png', documents_request()))
        ->toThrow(ValidationException::class, DocumentService::MESSAGE_KIND);

    expect(Document::query()->count())->toBe(0);
});

it('allows ten uploads a minute and refuses the eleventh', function (): void {
    $registrar = documents_actor(Role::Registrar);
    $student = Student::factory()->create();
    $service = app(DocumentService::class);

    for ($i = 0; $i < DocumentService::UPLOADS_PER_MINUTE; $i++) {
        $service->upload($registrar, (int) $student->id, DocumentKind::Other->value, documents_png(), 'image/png', documents_request());
    }

    expect(fn () => $service->upload($registrar, (int) $student->id, DocumentKind::Other->value, documents_png(), 'image/png', documents_request()))
        ->toThrow(ValidationException::class, DocumentService::MESSAGE_RATE);

    expect(Document::query()->count())->toBe(DocumentService::UPLOADS_PER_MINUTE);
});

it('downloads with a generated name and the sniffed type, and hides missing ids', function (): void {
    $registrar = documents_actor(Role::Registrar);
    $student = Student::factory()->create();
    $document = app(DocumentService::class)->upload(
        $registrar,
        (int) $student->id,
        DocumentKind::OLevel->value,
        documents_png(),
        'image/png',
        documents_request(),
    );

    $response = $this->actingAs($registrar)->get('/staff/downloads/documents/'.$document->id);

    $disposition = (string) $response->headers->get('content-disposition');

    $response->assertOk();
    expect($disposition)->toContain('attachment')
        ->and($disposition)->toContain('document-'.$document->id.'.png')
        ->and($disposition)->not->toContain('client-secret')
        ->and((string) $response->headers->get('content-type'))->toContain('image/png')
        ->and((string) $response->headers->get('x-content-type-options'))->toBe('nosniff')
        ->and((string) $response->headers->get('cache-control'))->toContain('no-store');

    $faculty_admin = User::factory()->create();
    RoleAssignment::factory()->facultyAdmin()->create([
        'user_id' => $faculty_admin->id,
        'faculty_id' => $student->programme->department->faculty_id,
    ]);

    $missing = $this->actingAs($faculty_admin)->get('/staff/downloads/documents/999999999');
    $hidden = $this->actingAs($faculty_admin)->get('/staff/downloads/documents/'.$document->id);
    $missing_body = str_replace('/staff/downloads/documents/999999999', '/staff/downloads/documents/ID', (string) $missing->getContent());
    $hidden_body = str_replace('/staff/downloads/documents/'.$document->id, '/staff/downloads/documents/ID', (string) $hidden->getContent());

    expect($missing->status())->toBe(404)
        ->and($hidden->status())->toBe(404)
        ->and($missing_body)->toBe($hidden_body);

    Storage::disk('local')->delete($document->path);

    $this->actingAs($registrar)
        ->get('/staff/downloads/documents/'.$document->id)
        ->assertNotFound()
        ->assertSee(DocumentService::MESSAGE_FILE_GONE);
});

it('refuses to delete a student who still has documents and audits a removal', function (): void {
    $registrar = documents_actor(Role::Registrar);
    $student = Student::factory()->create();
    $document = app(DocumentService::class)->upload(
        $registrar,
        (int) $student->id,
        DocumentKind::AdmissionLetter->value,
        "%PDF-1.4\n%%EOF",
        null,
        documents_request(),
    );

    expect(fn () => app(StudentService::class)->delete($registrar, (int) $student->id, documents_request()))
        ->toThrow(ValidationException::class, StudentService::MESSAGE_HAS_DOCUMENTS);

    expect(Student::query()->whereKey($student->id)->exists())->toBeTrue();

    app(DocumentService::class)->remove($registrar, (int) $document->id, documents_request());

    expect(Document::query()->whereKey($document->id)->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', DocumentService::ACTION_REMOVED)->count())->toBe(1)
        ->and(Storage::disk('local')->exists($document->path))->toBeFalse();
});

it('saves a guardian phone through the shared helper and audits field names only', function (): void {
    $registrar = documents_actor(Role::Registrar);
    $student = Student::factory()->create();
    $service = app(StudentService::class);

    $service->saveGuardians($registrar, (int) $student->id, [[
        'name' => 'Ada Parent',
        'phone' => '08012345678',
        'email' => 'parent@example.com',
        'relationship' => 'Parent',
    ]], documents_request());

    $guardian = Guardian::query()->where('student_id', $student->id)->first();

    expect($guardian)->not->toBeNull()
        ->and($guardian?->phone)->toBe('+2348012345678');

    $service->saveGuardians($registrar, (int) $student->id, [[
        'name' => 'Ada Parent',
        'phone' => '08099998888',
        'email' => 'parent@example.com',
        'relationship' => 'Parent',
    ]], documents_request());

    $audit = AuditLog::query()->where('action', StudentService::ACTION_GUARDIANS)->latest('id')->first();
    $encoded = json_encode($audit?->toArray());

    expect($encoded)->toBeString()
        ->and($encoded)->toContain('phone')
        ->and($encoded)->not->toContain('+2348012345678')
        ->and($encoded)->not->toContain('+2348099998888')
        ->and($encoded)->not->toContain('08012345678')
        ->and($encoded)->not->toContain('08099998888');
});
