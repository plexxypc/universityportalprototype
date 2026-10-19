<?php

declare(strict_types=1);

namespace App\Filament\Staff\Pages;

use App\Enums\RegistrationStatus;
use App\Enums\Role;
use App\Models\CourseRegistration;
use App\Models\Student;
use App\Models\User;
use App\Policies\CourseRegistrationPolicy;
use App\Services\RegistrationService;
use App\Support\Rbac\Permissions;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;
use UnitEnum;

/**
 * Submitted registrations waiting for a decision.
 *
 * Approve, reject, and bulk approve send the version token captured when
 * the queue was shown. The result of a bulk approve is a count.
 */
class RegistrationQueuePage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'course-registrations';

    protected static ?string $navigationLabel = 'Registrations';

    protected static string|UnitEnum|null $navigationGroup = 'Student information management';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?int $navigationSort = 4;

    /**
     * Version tokens for the submitted rows on the last render.
     *
     * Locked so the browser cannot replace them.
     *
     * @var array<int, string>
     */
    #[Locked]
    public array $versions = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && self::mayApprove($user);
    }

    public function getTitle(): string
    {
        return 'Registrations';
    }

    /**
     * Store the tokens the approver is looking at.
     */
    public function dehydrate(): void
    {
        $this->rememberVersions();
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('reopen')
                ->label('Reopen registration')
                ->visible(fn (): bool => $this->canReopen())
                ->authorize(fn (): bool => $this->canReopen())
                ->schema([
                    TextInput::make('matric_no')->label('Matric number')->required()->maxLength(32),
                    Textarea::make('reason')->label('Reason')->required(),
                    TextInput::make('reopened_until')->label('Reopen until')->required(),
                ])
                ->action(function (array $data): void {
                    $this->reopenStudent(
                        $this->text($data, 'matric_no'),
                        $this->text($data, 'reason'),
                        $this->text($data, 'reopened_until'),
                    );
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => CourseRegistration::query()
                ->visibleTo($this->actor())
                ->with(['student', 'items.course'])
                ->where('status', RegistrationStatus::Submitted))
            ->defaultSort('id', 'desc')
            ->paginated([25])
            ->defaultPaginationPageOption(25)
            ->emptyStateHeading('No registrations are waiting')
            ->columns([
                TextColumn::make('student.matric_no')->label('Matric number'),
                TextColumn::make('student.first_name')
                    ->label('Student')
                    ->formatStateUsing(function (mixed $state, CourseRegistration $record): string {
                        $student = $record->student;

                        return $student instanceof Student
                            ? trim($student->first_name.' '.$student->last_name)
                            : '';
                    }),
                TextColumn::make('courses')
                    ->label('Courses')
                    ->state(fn (CourseRegistration $record): string => $this->courseList($record))
                    ->wrap(),
                TextColumn::make('total_units')->label('Total units'),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label('Approve')
                    ->requiresConfirmation()
                    ->authorize(fn (CourseRegistration $record): bool => app(CourseRegistrationPolicy::class)->approve($this->actor(), $record))
                    ->action(function (CourseRegistration $record): void {
                        $this->approveOne((int) $record->getKey());
                    }),
                Action::make('reject')
                    ->label('Reject')
                    ->requiresConfirmation()
                    ->authorize(fn (CourseRegistration $record): bool => app(CourseRegistrationPolicy::class)->approve($this->actor(), $record))
                    ->schema([
                        Textarea::make('reason')->label('Reason')->required(),
                    ])
                    ->action(function (CourseRegistration $record, array $data): void {
                        $this->rejectOne((int) $record->getKey(), $this->text($data, 'reason'));
                    }),
            ])
            ->bulkActions([
                BulkAction::make('approve')
                    ->label('Approve')
                    ->requiresConfirmation()
                    ->modalHeading('Approve these registrations')
                    ->modalDescription('The result is a count of approved and skipped rows.')
                    ->authorize(fn (): bool => self::mayApprove($this->actor()))
                    ->action(function (Collection $records): void {
                        $ids = [];

                        foreach ($records as $record) {
                            if ($record instanceof CourseRegistration) {
                                $ids[] = (int) $record->getKey();
                            }
                        }

                        $this->approveBulk($ids);
                    }),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedTable::make(),
        ]);
    }

    /**
     * Approve one row with the token from the queue.
     */
    public function approveOne(int $registration_id): void
    {
        $this->decideOne($registration_id, true, null);
    }

    /**
     * Reject one row with the token from the queue.
     */
    public function rejectOne(int $registration_id, string $reason): void
    {
        $this->decideOne($registration_id, false, $reason);
    }

    /**
     * Approve the previewed ids with the tokens from the queue.
     *
     * @param  list<mixed>  $registration_ids
     */
    public function approveBulk(array $registration_ids): void
    {
        $previews = [];

        foreach ($registration_ids as $id) {
            if (! is_numeric($id)) {
                continue;
            }

            $registration_id = (int) $id;
            $previews[] = [
                'id' => $registration_id,
                'version' => $this->versions[$registration_id] ?? '',
            ];
        }

        try {
            $result = app(RegistrationService::class)->bulkApprove($this->actor(), $previews, request());
        } catch (ValidationException $exception) {
            Notification::make()->title($this->firstError($exception, 'Those registrations could not be approved.'))->danger()->send();

            return;
        } catch (Throwable $exception) {
            report($exception);
            Notification::make()->title('Those registrations could not be approved.')->danger()->send();

            return;
        }

        Notification::make()
            ->title($result['approved'].' approved, '.$result['skipped'].' skipped')
            ->success()
            ->send();
    }

    /**
     * Open registration again for one student. The service checks the limits.
     */
    public function reopenStudent(string $matric_no, string $reason, string $reopened_until): void
    {
        if (! $this->canReopen()) {
            Notification::make()->title('You cannot reopen a registration.')->danger()->send();

            return;
        }

        $student = Student::query()->where('matric_no', trim($matric_no))->first();

        if (! $student instanceof Student) {
            Notification::make()->title('That student could not be found.')->danger()->send();

            return;
        }

        try {
            $until = Carbon::parse($reopened_until, 'Africa/Lagos');
        } catch (Throwable) {
            Notification::make()->title(RegistrationService::MESSAGE_REOPEN_TIME)->danger()->send();

            return;
        }

        try {
            app(RegistrationService::class)->reopen($this->actor(), (int) $student->getKey(), $reason, $until, request());
        } catch (ValidationException $exception) {
            Notification::make()->title($this->firstError($exception, 'That registration could not be reopened.'))->danger()->send();

            return;
        } catch (AuthorizationException) {
            Notification::make()->title('You cannot reopen a registration.')->danger()->send();

            return;
        } catch (Throwable $exception) {
            report($exception);
            Notification::make()->title('That registration could not be reopened.')->danger()->send();

            return;
        }

        Notification::make()->title('Registration reopened')->success()->send();
    }

    /**
     * Approve or reject with the stored token.
     */
    private function decideOne(int $registration_id, bool $approving, ?string $reason): void
    {
        $version = $this->versions[$registration_id] ?? '';

        try {
            $service = app(RegistrationService::class);

            if ($approving) {
                $service->approve($this->actor(), $registration_id, $version, request());
                $title = 'Registration approved';
            } else {
                $service->reject($this->actor(), $registration_id, (string) $reason, $version, request());
                $title = 'Registration rejected';
            }
        } catch (ValidationException $exception) {
            Notification::make()->title($this->firstError($exception, 'That registration could not be updated.'))->danger()->send();

            return;
        } catch (NotFoundHttpException) {
            Notification::make()->title('That registration could not be found.')->danger()->send();

            return;
        } catch (AuthorizationException) {
            Notification::make()->title('You cannot update this registration.')->danger()->send();

            return;
        } catch (Throwable $exception) {
            report($exception);
            Notification::make()->title('That registration could not be updated.')->danger()->send();

            return;
        }

        Notification::make()->title($title)->success()->send();
    }

    /**
     * Course codes and the units stored on the registration.
     */
    private function courseList(CourseRegistration $registration): string
    {
        $parts = [];

        foreach ($registration->items as $item) {
            $parts[] = $item->course->code.' ('.$item->credit_units.')';
        }

        return $parts === [] ? 'No courses' : implode(', ', $parts);
    }

    /**
     * Tokens for every submitted registration this account can see.
     */
    private function rememberVersions(): void
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            $this->versions = [];

            return;
        }

        $service = app(RegistrationService::class);
        $versions = [];
        $rows = CourseRegistration::query()
            ->visibleTo($user)
            ->where('status', RegistrationStatus::Submitted)
            ->get();

        foreach ($rows as $row) {
            $versions[(int) $row->getKey()] = $service->version($row);
        }

        $this->versions = $versions;
    }

    private function canReopen(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && ($user->hasRole(Role::Registrar) || $user->hasRole(Role::SuperAdmin));
    }

    private static function mayApprove(User $user): bool
    {
        foreach ($user->roles() as $role) {
            if (Permissions::roleGrants($role, 'course_registration.approve')) {
                return true;
            }
        }

        return false;
    }

    private function actor(): User
    {
        $user = auth()->user();

        if (! $user instanceof User || ! self::mayApprove($user)) {
            abort(403);
        }

        return $user;
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

    /**
     * @param  array<string, mixed>  $data
     */
    private function text(array $data, string $key): string
    {
        $value = $data[$key] ?? '';

        return is_string($value) ? trim($value) : '';
    }
}
