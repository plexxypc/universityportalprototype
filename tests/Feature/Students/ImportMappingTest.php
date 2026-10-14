<?php

declare(strict_types=1);

use App\Exceptions\ImportNotFoundException;
use App\Filament\Staff\Pages\ImportStudentsPage;
use App\Services\ImportService;
use App\Support\Import\ColumnMap;
use App\Support\Import\StagedImportStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake('local');
});

function import_mapping_csv(): UploadedFile
{
    return UploadedFile::fake()->createWithContent(
        'students.csv',
        "First Name,Last Name,Email,Programme,Level,Relationship\nAda,Lovelace,ada@example.test,CSC,100,Parent\n",
    );
}

it('saves the auto-matched map on the staged file and keeps rows out of livewire', function () {
    $registrar = import_wizard_actor();

    $page = Livewire::actingAs($registrar)
        ->test(ImportStudentsPage::class)
        ->set('upload', import_mapping_csv())
        ->call('stageUpload')
        ->call('openMap')
        ->assertSet('step', 'map')
        ->assertSet('column_map.first_name', 0)
        ->assertSet('column_map.last_name', 1)
        ->assertSet('column_map.email', 2)
        ->assertSet('column_map.programme_code', 3)
        ->assertSet('column_map.level', 4)
        ->assertSet('column_map.guardian_relationship', 5)
        ->call('confirmMap')
        ->assertSet('step', 'validate')
        ->assertSee('Column mapping is saved');

    $state = json_encode($page->instance()->all());
    $token = $page->get('token');
    $payload = json_decode(Storage::disk('local')->get(StagedImportStore::DIRECTORY.'/'.$token.'.json') ?: '', true, 512, JSON_THROW_ON_ERROR);

    expect($state)->toBeString()
        ->and($state)->not->toContain('Lovelace')
        ->and($state)->not->toContain('ada@example.test')
        ->and($payload['column_map'])->toBe([
            'first_name' => 0,
            'last_name' => 1,
            'email' => 2,
            'programme_code' => 3,
            'level' => 4,
            'guardian_relationship' => 5,
        ])
        ->and($payload['rows'])->toBe([['Ada', 'Lovelace', 'ada@example.test', 'CSC', '100', 'Parent']]);

    $page->set('column_map.email', 0);
    $again = json_decode(Storage::disk('local')->get(StagedImportStore::DIRECTORY.'/'.$token.'.json') ?: '', true, 512, JSON_THROW_ON_ERROR);

    expect($again['column_map']['email'])->toBe(2);
});

it('does not store a tampered map and returns not found for another users token', function () {
    $owner = import_wizard_actor();
    $other = import_wizard_actor();
    $token = app(ImportService::class)->stageUpload($owner, import_mapping_csv());

    Livewire::actingAs($owner)
        ->test(ImportStudentsPage::class)
        ->set('token', $token)
        ->set('step', 'map')
        ->set('column_map', ['rows' => [['injected']]])
        ->call('confirmMap')
        ->assertHasErrors(['column_map' => ColumnMap::MESSAGE_FIELD]);

    $payload = json_decode(Storage::disk('local')->get(StagedImportStore::DIRECTORY.'/'.$token.'.json') ?: '', true, 512, JSON_THROW_ON_ERROR);
    expect($payload['column_map'])->toBeNull()
        ->and($payload['rows'][0][0])->toBe('Ada');

    Livewire::actingAs($other)
        ->test(ImportStudentsPage::class)
        ->set('token', $token)
        ->set('column_map', [
            'first_name' => 0,
            'last_name' => 1,
            'email' => 2,
            'programme_code' => 3,
            'level' => 4,
        ])
        ->call('confirmMap')
        ->assertNotFound();

    expect(fn () => app(ImportService::class)->confirmMap($other, $token, []))
        ->toThrow(ImportNotFoundException::class, ImportNotFoundException::MESSAGE);
});
