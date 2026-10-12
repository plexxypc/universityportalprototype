<?php

declare(strict_types=1);

namespace App\Filament\Staff\Pages;

use App\Models\FeeCategory;
use App\Models\User;
use App\Services\FeeService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use UnitEnum;

/**
 * Fee categories. A category that is already priced or invoiced is deactivated.
 */
class FeeCategoriesPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'fee-categories';

    protected static ?string $navigationLabel = 'Fee categories';

    protected static string|UnitEnum|null $navigationGroup = 'Fees and finance';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?int $navigationSort = 1;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && Gate::forUser($user)->allows('fees.view');
    }

    public function getTitle(): string
    {
        return 'Fee categories';
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label('Add category')
                ->visible(fn (): bool => $this->canManage())
                ->schema($this->fields(false))
                ->action(function (array $data): void {
                    $this->saveCategory(0, $data);
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => FeeCategory::query())
            ->defaultSort('sort')
            ->emptyStateHeading('No fee categories yet')
            ->columns([
                TextColumn::make('name')->label('Name'),
                TextColumn::make('code')->label('Code'),
                TextColumn::make('is_active')->label('Active')->formatStateUsing(fn (bool $state): string => $state ? 'Yes' : 'No'),
            ])
            ->recordActions([
                Action::make('edit')
                    ->visible(fn (): bool => $this->canManage())
                    ->fillForm(fn (FeeCategory $record): array => [
                        'name' => $record->name,
                        'code' => $record->code,
                        'description' => $record->description,
                        'sort' => $record->sort,
                        'is_active' => $record->is_active,
                    ])
                    ->schema($this->fields(true))
                    ->action(function (FeeCategory $record, array $data): void {
                        $this->saveCategory((int) $record->getKey(), $data);
                    }),
                DeleteAction::make()
                    ->visible(fn (): bool => $this->canManage())
                    ->action(function (FeeCategory $record): void {
                        $this->removeCategory((int) $record->getKey());
                    }),
            ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function saveCategory(int $category_id, array $data): void
    {
        if (! $this->canManage()) {
            abort(403);
        }

        $actor = $this->actor();
        $service = app(FeeService::class);
        $name = $this->text($data, 'name');
        $code = $this->text($data, 'code');
        $description = $this->text($data, 'description');
        $sort = is_numeric($data['sort'] ?? null) ? (int) $data['sort'] : 0;
        $active = (bool) ($data['is_active'] ?? true);

        try {
            if ($category_id === 0) {
                $service->createCategory($actor, $name, $code, $description, $sort, request());
            } else {
                $service->updateCategory($actor, $category_id, $name, $code, $description, $sort, $active, request());
            }
        } catch (ValidationException $exception) {
            Notification::make()->title($this->firstError($exception, 'That category could not be saved.'))->danger()->send();

            return;
        }

        Notification::make()->title('Category saved')->success()->send();
    }

    /**
     * Delete an unused category, or deactivate one that is already in use.
     */
    public function removeCategory(int $category_id): void
    {
        if (! $this->canManage()) {
            abort(403);
        }

        try {
            $result = app(FeeService::class)->deleteCategory($this->actor(), $category_id, request());
        } catch (ValidationException $exception) {
            Notification::make()->title($this->firstError($exception, 'That category could not be removed.'))->danger()->send();

            return;
        }

        $title = $result === FeeService::MESSAGE_IN_USE ? FeeService::MESSAGE_IN_USE : 'Category deleted';

        Notification::make()->title($title)->success()->send();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedTable::make(),
        ]);
    }

    /**
     * @return array<int, TextInput|Textarea|Toggle>
     */
    private function fields(bool $editing): array
    {
        $fields = [
            TextInput::make('name')->label('Name')->required()->maxLength(255),
            TextInput::make('code')->label('Code')->required()->maxLength(32),
            Textarea::make('description')->label('Description')->rows(2),
            TextInput::make('sort')->label('Sort')->numeric()->default(0)->required(),
        ];

        if ($editing) {
            $fields[] = Toggle::make('is_active')->label('Active');
        }

        return $fields;
    }

    private function actor(): User
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            abort(403);
        }

        return $actor;
    }

    private function canManage(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && Gate::forUser($actor)->allows('fees.manage');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function text(array $data, string $key): string
    {
        $value = $data[$key] ?? '';

        return is_string($value) ? $value : '';
    }

    private function firstError(ValidationException $exception, string $fallback): string
    {
        foreach ($exception->errors() as $messages) {
            $message = $messages[0] ?? null;

            if (is_string($message) && $message !== '') {
                return $message;
            }
        }

        return $fallback;
    }
}
