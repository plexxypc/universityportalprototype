<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Filament\Staff\Pages\GradingConfigurationPage;
use App\Models\GradingScheme;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Services\GradingConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('previews unsaved scores and writes nothing', function () {
    $registrar = User::factory()->create();
    RoleAssignment::factory()->create([
        'user_id' => $registrar->id,
        'role' => Role::Registrar,
    ]);
    $input = app(GradingConfigService::class)->defaultInput();
    $input['preview_courses'] = [
        ['units' => '3', 'scores' => ['30.00', '39.50']],
        ['units' => '2', 'scores' => ['20.00', '50.00']],
    ];

    Livewire::actingAs($registrar)
        ->test(GradingConfigurationPage::class)
        ->fillForm($input)
        ->call('preview')
        ->assertSet('preview_lines.courses.0.total', '69.50')
        ->assertSet('preview_lines.courses.0.letter', 'B')
        ->assertSet('preview_lines.courses.0.points', '4.00')
        ->assertSet('preview_lines.courses.0.passed', true)
        ->assertSet('preview_lines.courses.1.letter', 'A')
        ->assertSet('preview_lines.gpa', '4.40')
        ->assertSet('preview_lines.classification', 'Second Class Upper');

    expect(GradingScheme::query()->count())->toBe(0);

    $officer = User::factory()->create();
    RoleAssignment::factory()->create([
        'user_id' => $officer->id,
        'role' => Role::ExamOfficer,
    ]);

    Livewire::actingAs($officer)
        ->test(GradingConfigurationPage::class)
        ->fillForm($input)
        ->call('preview')
        ->assertSet('preview_lines.courses.0.letter', 'B');
});
