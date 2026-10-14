<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Filament\Staff\Pages\ImportGuidePage;
use App\Support\CsvSafe;
use App\Support\Import\ImportTemplate;
use App\Support\Import\SpreadsheetReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
});

it('builds csv and excel templates from the import columns through csv safe', function () {
    $template = app(ImportTemplate::class);
    $headers = $template->headers();
    $row = array_fill(0, count($headers), '');
    $row[0] = '=Ada';

    expect($headers)->toBe([
        'first_name',
        'last_name',
        'other_names',
        'email',
        'phone',
        'gender',
        'date_of_birth',
        'state_of_origin',
        'address',
        'programme_code',
        'level',
        'entry_session',
        'matric_number',
        'guardian_name',
        'guardian_phone',
        'guardian_email',
        'guardian_relationship',
    ])
        ->and($headers)->not->toContain('password');

    $csv = $template->csv([$row]);

    expect($csv)->toContain(implode(',', $headers))
        ->and($csv)->toContain(CsvSafe::cell('=Ada'))
        ->and(stripos($csv, 'password'))->toBeFalse();

    $path = tempnam(sys_get_temp_dir(), 'import-template');
    expect($path)->toBeString();

    try {
        $xlsx_path = $path.'.xlsx';
        file_put_contents($xlsx_path, $template->xlsx([$row]));
        $parsed = app(SpreadsheetReader::class)->read($xlsx_path);

        expect($parsed->headers)->toBe($headers)
            ->and($parsed->rows[0][0])->toBe(CsvSafe::cell('=Ada'));
    } finally {
        if (is_file($path)) {
            unlink($path);
        }

        if (isset($xlsx_path) && is_file($xlsx_path)) {
            unlink($xlsx_path);
        }
    }
});

it('serves the templates and the google form guide to a registrar', function () {
    $registrar = import_wizard_actor();

    $csv = $this->actingAs($registrar)->get(route('staff.downloads.import-template-csv'));
    $csv->assertOk();
    expect((string) $csv->getContent())->toContain('first_name,last_name,other_names,email')
        ->and(stripos((string) $csv->getContent(), 'password'))->toBeFalse()
        ->and((string) $csv->headers->get('content-disposition'))->toContain('students-import-template.csv')
        ->and((string) $csv->headers->get('cache-control'))->toContain('no-store')
        ->and((string) $csv->headers->get('x-content-type-options'))->toBe('nosniff');

    $xlsx = $this->actingAs($registrar)->get(route('staff.downloads.import-template-xlsx'));
    $xlsx->assertOk();
    expect((string) $xlsx->headers->get('content-disposition'))->toContain('students-import-template.xlsx')
        ->and((string) $xlsx->headers->get('cache-control'))->toContain('no-store');

    Livewire::actingAs($registrar)
        ->test(ImportGuidePage::class)
        ->assertSee('First name')
        ->assertSee('Relationship')
        ->assertSee('does not ask for a password')
        ->assertDontSee('Password');

    $this->actingAs(import_wizard_actor(Role::FacultyAdmin))
        ->get(route('staff.downloads.import-template-csv'))
        ->assertNotFound();

    auth()->logout();

    $this->get(route('staff.downloads.import-template-csv'))->assertRedirect('/login');

    Livewire::actingAs(import_wizard_actor(Role::FacultyAdmin))
        ->test(ImportGuidePage::class)
        ->assertForbidden();
});
