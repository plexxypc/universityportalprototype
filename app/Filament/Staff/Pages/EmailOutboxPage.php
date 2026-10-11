<?php

declare(strict_types=1);

namespace App\Filament\Staff\Pages;

use App\Enums\EmailStatus;
use App\Enums\EmailTemplate;
use App\Models\EmailOutbox;
use App\Models\User;
use App\Policies\EmailOutboxPolicy;
use App\Services\MailService;
use App\Support\Audit;
use App\Support\Dates;
use App\Support\Mail\OutboundMessage;
use App\Support\StatusTone;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Super Admin email outbox.
 *
 * Send now is allowed for queued credentials and password reset rows.
 * It reveals nothing. Retry is not offered for those two templates.
 */
class EmailOutboxPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'email-outbox';

    protected static ?string $navigationLabel = 'Email outbox';

    protected static string|UnitEnum|null $navigationGroup = 'Email and notifications';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    /**
     * email_outbox.manage is not in the ADR-028 permission map.
     *
     * Gate::before allows every ability for an Active Super Admin except
     * course_registration.submit and payments.make. No other role is given
     * this ability, so the gate denies them. A Super Admin who is not Active
     * is refused because Gate::before grants nothing until the account is Active.
     */
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(EmailOutboxPolicy::class)->viewAny($user);
    }

    public function getTitle(): string|Htmlable
    {
        return 'Email outbox';
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('sendTest')
                ->label('Send test email')
                ->requiresConfirmation()
                ->modalDescription('The test email is sent only to your own address.')
                ->authorize(fn (): bool => $this->policy()->sendTest($this->actor()))
                ->action(function (): void {
                    abort_unless($this->policy()->sendTest($this->actor()), 403);

                    $queued = app(MailService::class)->sendTest($this->actor(), $this->ip());

                    Notification::make()
                        ->title($queued ? 'Test email queued.' : 'Try again in a minute.')
                        ->send();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => EmailOutbox::query())
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('status')
                    ->badge()
                    ->color(function (EmailStatus|string $state): string {
                        $status = $state instanceof EmailStatus ? $state : EmailStatus::tryFrom($state);

                        return $status instanceof EmailStatus ? StatusTone::for($status) : StatusTone::NEUTRAL;
                    }),
                TextColumn::make('template'),
                TextColumn::make('subject'),
                TextColumn::make('recipient_email')->label('Recipient'),
                TextColumn::make('attempts'),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->formatStateUsing(fn (mixed $state): string => Dates::dateTime($state)),
            ])
            ->filters([
                SelectFilter::make('status')->options($this->statusOptions()),
                SelectFilter::make('template')->options($this->templateOptions()),
                Filter::make('created_on')
                    ->label('Date')
                    ->schema([
                        DatePicker::make('created_on')->label('Date'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $created_on = $data['created_on'] ?? null;

                        if (! is_string($created_on) || $created_on === '') {
                            return $query;
                        }

                        return $query->whereDate('created_at', $created_on);
                    }),
            ])
            ->recordActions([
                ViewAction::make()
                    ->authorize(fn (EmailOutbox $record): bool => $this->policy()->view($this->actor(), $record))
                    ->schema([
                        Placeholder::make('status')->content(fn (EmailOutbox $record): string => $record->status->value),
                        Placeholder::make('template')->content(fn (EmailOutbox $record): string => $record->template),
                        Placeholder::make('subject')->content(fn (EmailOutbox $record): string => $record->subject),
                        Placeholder::make('recipient_email')->label('Recipient')->content(fn (EmailOutbox $record): string => $record->recipient_email),
                        Placeholder::make('attempts')->content(fn (EmailOutbox $record): string => (string) $record->attempts),
                        Placeholder::make('last_error')->label('Last error')->content(fn (EmailOutbox $record): string => $record->last_error ?? '—'),
                        Placeholder::make('created_at')->label('Created')->content(fn (EmailOutbox $record): string => Dates::dateTime($record->created_at)),
                        Placeholder::make('sent_at')->label('Sent')->content(fn (EmailOutbox $record): string => Dates::dateTime($record->sent_at)),
                        Placeholder::make('message')
                            ->label('Message')
                            ->content(fn (EmailOutbox $record): string => $this->visibleMessage($record)),
                    ]),
                Action::make('sendNow')
                    ->label('Send now')
                    ->requiresConfirmation()
                    ->modalDescription('Send now is allowed for queued credentials and password reset rows. It reveals nothing.')
                    ->visible(fn (EmailOutbox $record): bool => $this->policy()->send($this->actor(), $record))
                    ->authorize(fn (EmailOutbox $record): bool => $this->policy()->send($this->actor(), $record))
                    ->action(function (EmailOutbox $record): void {
                        abort_unless($this->policy()->send($this->actor(), $record), 403);

                        $queued = app(MailService::class)->sendNow($record, $this->actor(), $this->ip());

                        Notification::make()
                            ->title($queued
                                ? 'The email was queued to send now.'
                                : 'The daily send limit was reached, or sending is paused.')
                            ->send();
                    }),
                Action::make('retry')
                    ->label('Retry')
                    ->requiresConfirmation()
                    ->visible(fn (EmailOutbox $record): bool => $this->policy()->retry($this->actor(), $record))
                    ->authorize(fn (EmailOutbox $record): bool => $this->policy()->retry($this->actor(), $record))
                    ->action(function (EmailOutbox $record): void {
                        abort_unless($this->policy()->retry($this->actor(), $record), 403);

                        app(MailService::class)->retry($record, $this->actor(), $this->ip());
                    }),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Text::make(fn (): string => $this->notices()),
            EmbeddedTable::make(),
        ]);
    }

    /**
     * Page text. Secret bodies are not included.
     */
    private function notices(): string
    {
        $mail = app(MailService::class);
        $lines = [
            'Send now is allowed for queued credentials and password reset rows. It reveals nothing. Retry is not offered for those two templates.',
        ];

        if ($mail->sendingIsPaused()) {
            $lines[] = 'Mail is misconfigured. Sending is paused.';
        }

        if ($mail->dailyLimitReached()) {
            $lines[] = 'The daily send limit was reached. Further mail stays queued.';
        }

        return implode(' ', $lines);
    }

    /**
     * Text safe to show for this row.
     */
    private function visibleMessage(EmailOutbox $record): string
    {
        if (app(OutboundMessage::class)->isSecretTemplate($record->template)) {
            return 'Content hidden for security. Re-issue credentials, or ask the person to request a new reset link.';
        }

        $text = EmailOutbox::query()->whereKey($record->getKey())->value('body_text');

        return is_string($text) ? $text : '';
    }

    private function policy(): EmailOutboxPolicy
    {
        return app(EmailOutboxPolicy::class);
    }

    private function actor(): User
    {
        $user = auth()->user();

        if (! $user instanceof User || ! $this->policy()->viewAny($user)) {
            abort(403);
        }

        return $user;
    }

    private function ip(): ?string
    {
        return app(Audit::class)->ipFromRequest(request());
    }

    /**
     * @return array<string, string>
     */
    private function statusOptions(): array
    {
        $options = [];

        foreach (EmailStatus::cases() as $status) {
            $options[$status->value] = $status->value;
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    private function templateOptions(): array
    {
        $options = [];

        foreach (EmailTemplate::cases() as $template) {
            $options[$template->value] = $template->value;
        }

        return $options;
    }
}
