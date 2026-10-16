<?php

declare(strict_types=1);

namespace App\Filament\Staff\Pages;

use App\Enums\ApplicantStatus;
use App\Models\Applicant;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Programme;
use App\Models\User;
use App\Policies\ApplicantPolicy;
use App\Services\ApplicantService;
use App\Support\StatusTone;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use UnitEnum;

/**
 * Applicant list. Search, filters, and 25 rows per page.
 *
 * A Faculty Admin or Department Officer only sees their scope. A filter
 * value outside that scope is ignored. A lecturer sees nobody.
 */
class ApplicantsPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'applicants';

    protected static ?string $navigationLabel = 'Applicants';

    protected static string|UnitEnum|null $navigationGroup = 'Admissions and onboarding';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInbox;

    protected static ?int $navigationSort = 1;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(ApplicantPolicy::class)->viewAny($user);
    }

    public function getTitle(): string
    {
        return 'Applicants';
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
                ->label('Add applicant')
                ->url(AddApplicantPage::getUrl())
                ->visible(fn (): bool => $this->canManage()),
            Action::make('import')
                ->label('Import applicants')
                ->url(ImportApplicantsPage::getUrl())
                ->visible(fn (): bool => $this->canManage()),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Applicant::query()
                ->visibleTo($this->actor())
                ->with(['programme.department.faculty']))
            ->defaultSort('last_name')
            ->paginated([25])
            ->defaultPaginationPageOption(25)
            ->searchable()
            ->searchUsing(function (Builder $query, string $search): void {
                $like = self::likeContains($search);

                $query->where(function (Builder $inner) use ($like): void {
                    $inner->where('first_name', 'like', $like)
                        ->orWhere('last_name', 'like', $like)
                        ->orWhere('other_names', 'like', $like)
                        ->orWhere('email', 'like', $like);
                });
            })
            ->emptyStateHeading('No applicants yet')
            ->columns([
                TextColumn::make('last_name')
                    ->label('Name')
                    ->formatStateUsing(fn (mixed $state, Applicant $record): string => trim($record->first_name.' '.$record->last_name)),
                TextColumn::make('email')->label('Email'),
                TextColumn::make('programme.name')->label('Programme'),
                TextColumn::make('programme.department.name')->label('Department'),
                TextColumn::make('level')->label('Level'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (ApplicantStatus|string $state): string => $state instanceof ApplicantStatus ? $state->value : (string) $state)
                    ->color(function (ApplicantStatus|string $state): string {
                        $status = $state instanceof ApplicantStatus ? $state : ApplicantStatus::tryFrom((string) $state);

                        return $status instanceof ApplicantStatus ? StatusTone::for($status) : StatusTone::NEUTRAL;
                    }),
            ])
            ->recordActions([
                Action::make('edit')
                    ->label('Edit')
                    ->url(fn (Applicant $record): string => AddApplicantPage::getUrl().'?applicant='.$record->getKey())
                    ->visible(fn (Applicant $record): bool => $this->canManage() && $record->status !== ApplicantStatus::Converted),
                Action::make('changeStatus')
                    ->label('Change status')
                    ->visible(fn (Applicant $record): bool => $this->canManage() && $record->status !== ApplicantStatus::Converted)
                    ->authorize(fn (): bool => $this->canManage())
                    ->schema([
                        Select::make('status')
                            ->label('Status')
                            ->options($this->statusOptions())
                            ->required(),
                        Textarea::make('reason')
                            ->label('Reason for rejection')
                            ->maxLength(500),
                    ])
                    ->action(function (Applicant $record, array $data): void {
                        try {
                            app(ApplicantService::class)->changeStatus(
                                $this->actor(),
                                (int) $record->getKey(),
                                (string) ($data['status'] ?? ''),
                                (string) ($data['reason'] ?? ''),
                                request(),
                            );
                        } catch (AuthorizationException) {
                            abort(403);
                        } catch (ValidationException $exception) {
                            Notification::make()
                                ->danger()
                                ->title((string) (collect($exception->errors())->flatten()->first() ?? ApplicantService::MESSAGE_TRANSITION))
                                ->send();

                            return;
                        }

                        Notification::make()->success()->title('Status updated.')->send();
                    }),
                Action::make('convert')
                    ->label('Admit and convert')
                    ->requiresConfirmation()
                    ->modalDescription('This creates one student account. The admission email is sent only if this applicant has not already received it.')
                    ->visible(fn (Applicant $record): bool => $this->canManage() && $this->canConvertRecord($record))
                    ->authorize(fn (): bool => $this->canManage())
                    ->action(function (Applicant $record): void {
                        try {
                            $created = app(ApplicantService::class)->convert(
                                $this->actor(),
                                (int) $record->getKey(),
                                request(),
                            );
                        } catch (AuthorizationException) {
                            abort(403);
                        } catch (ValidationException $exception) {
                            Notification::make()
                                ->danger()
                                ->title($this->firstError($exception))
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->success()
                            ->title(app(ApplicantService::class)->createdMessage($created))
                            ->send();
                    }),
            ])
            ->bulkActions([
                BulkAction::make('convert')
                    ->label('Admit and convert')
                    ->requiresConfirmation()
                    ->visible(fn (): bool => $this->canManage())
                    ->authorize(fn (): bool => $this->canManage())
                    ->modalDescription(function (Collection $records): string {
                        return app(ApplicantService::class)->previewMessage($this->actor(), $this->selectedIds($records));
                    })
                    ->action(function (Collection $records): void {
                        try {
                            $batch = app(ApplicantService::class)->convertMany(
                                $this->actor(),
                                $this->selectedIds($records),
                                request(),
                            );
                        } catch (AuthorizationException) {
                            abort(403);
                        } catch (ValidationException $exception) {
                            Notification::make()
                                ->danger()
                                ->title($this->firstError($exception))
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->success()
                            ->title(app(ApplicantService::class)->bulkMessage($batch))
                            ->send();
                    }),
            ])
            ->filters([
                Filter::make('faculty')
                    ->schema([
                        Select::make('faculty_id')->label('Faculty')->options(fn (): array => $this->facultyOptions()),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $faculty_id = $this->acceptedId($data['faculty_id'] ?? null, $this->facultyOptions());

                        if ($faculty_id === null) {
                            return $query;
                        }

                        return $query->whereIn('programme_id', function (QueryBuilder $sub) use ($faculty_id): void {
                            $sub->select('programmes.id')
                                ->from('programmes')
                                ->join('departments', 'departments.id', '=', 'programmes.department_id')
                                ->where('departments.faculty_id', $faculty_id);
                        });
                    }),
                Filter::make('department')
                    ->schema([
                        Select::make('department_id')->label('Department')->options(fn (): array => $this->departmentOptions()),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $department_id = $this->acceptedId($data['department_id'] ?? null, $this->departmentOptions());

                        if ($department_id === null) {
                            return $query;
                        }

                        return $query->whereIn('programme_id', function (QueryBuilder $sub) use ($department_id): void {
                            $sub->select('id')->from('programmes')->where('department_id', $department_id);
                        });
                    }),
                Filter::make('programme')
                    ->schema([
                        Select::make('programme_id')->label('Programme')->options(fn (): array => $this->programmeOptions()),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $programme_id = $this->acceptedId($data['programme_id'] ?? null, $this->programmeOptions());

                        if ($programme_id === null) {
                            return $query;
                        }

                        return $query->where('programme_id', $programme_id);
                    }),
                Filter::make('level')
                    ->schema([
                        Select::make('level')->label('Level')->options($this->levelOptions()),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $level = $data['level'] ?? null;

                        if (! is_numeric($level) || ! array_key_exists((int) $level, $this->levelOptions())) {
                            return $query;
                        }

                        return $query->where('level', (int) $level);
                    }),
                Filter::make('status')
                    ->schema([
                        Select::make('status')->label('Status')->options($this->filterStatusOptions()),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $status = $data['status'] ?? null;

                        if (! is_string($status) || ! array_key_exists($status, $this->filterStatusOptions())) {
                            return $query;
                        }

                        return $query->where('status', $status);
                    }),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedTable::make(),
        ]);
    }

    public static function likeContains(string $value): string
    {
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);

        return '%'.$escaped.'%';
    }

    /**
     * @param  array<int|string, string>  $options
     */
    public function acceptedId(mixed $value, array $options): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $id = (int) $value;

        return array_key_exists($id, $options) ? $id : null;
    }

    /**
     * @return array<int, string>
     */
    public function facultyOptions(): array
    {
        return Faculty::query()->visibleTo($this->actor())->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * @return array<int, string>
     */
    public function departmentOptions(): array
    {
        return Department::query()->visibleTo($this->actor())->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * @return array<int, string>
     */
    public function programmeOptions(): array
    {
        return Programme::query()->visibleTo($this->actor())->orderBy('name')->pluck('name', 'id')->all();
    }

    private function actor(): User
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            abort(403);
        }

        return $user;
    }

    private function canManage(): bool
    {
        return app(ApplicantPolicy::class)->create($this->actor());
    }

    /**
     * Applied, Under review, and Admitted can be admitted and converted.
     */
    private function canConvertRecord(Applicant $applicant): bool
    {
        return in_array($applicant->status, [
            ApplicantStatus::Applied,
            ApplicantStatus::UnderReview,
            ApplicantStatus::Admitted,
        ], true);
    }

    /**
     * @param  Collection<int, mixed>  $records
     * @return list<int>
     */
    private function selectedIds(Collection $records): array
    {
        $ids = [];

        foreach ($records as $record) {
            if ($record instanceof Applicant) {
                $ids[] = (int) $record->getKey();
            }
        }

        return $ids;
    }

    /**
     * The first validation message, for a notification title.
     */
    private function firstError(ValidationException $exception): string
    {
        $message = collect($exception->errors())->flatten()->first();

        return is_string($message) ? $message : ApplicantService::MESSAGE_TRANSITION;
    }

    /**
     * Statuses a manager may choose. Converted is not one of them.
     *
     * @return array<string, string>
     */
    private function statusOptions(): array
    {
        $options = [];

        foreach (ApplicantStatus::cases() as $status) {
            if ($status === ApplicantStatus::Converted) {
                continue;
            }

            $options[$status->value] = $status->value;
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    private function filterStatusOptions(): array
    {
        $options = [];

        foreach (ApplicantStatus::cases() as $status) {
            $options[$status->value] = $status->value;
        }

        return $options;
    }

    /**
     * @return array<int, string>
     */
    private function levelOptions(): array
    {
        $options = [];

        foreach ([100, 200, 300, 400, 500, 600] as $level) {
            $options[$level] = (string) $level;
        }

        return $options;
    }
}
