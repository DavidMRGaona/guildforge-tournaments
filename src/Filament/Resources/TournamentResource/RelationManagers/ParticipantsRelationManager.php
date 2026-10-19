<?php

declare(strict_types=1);

namespace Modules\Tournaments\Filament\Resources\TournamentResource\RelationManagers;

use DomainException;
use Filament\Schemas\Schema;
use Filament\Actions\CreateAction;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Modules\Tournaments\Application\DTOs\RegisterParticipantDTO;
use Modules\Tournaments\Application\Services\GuestRegistrationSettingsInterface;
use Modules\Tournaments\Application\Services\ParticipantManagementServiceInterface;
use Modules\Tournaments\Domain\Enums\GuestConfirmationMode;
use Modules\Tournaments\Domain\Enums\ParticipantStatus;
use Modules\Tournaments\Domain\Exceptions\AlreadyRegisteredException;
use Modules\Tournaments\Domain\Exceptions\TournamentFullException;
use Modules\Tournaments\Domain\Repositories\ParticipantRepositoryInterface;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\ParticipantModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;

final class ParticipantsRelationManager extends RelationManager
{
    protected static string $relationship = 'participants';

    protected static ?string $recordTitleAttribute = 'id';

    /**
     * IDs flagged as possible duplicates, worked out once per request.
     *
     * @var list<string>|null
     */
    private ?array $possibleDuplicateIds = null;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('tournaments::messages.participants.title');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('user_id')
                    ->label(__('tournaments::messages.participants.user'))
                    ->relationship('user', 'name')
                    ->searchable()
                    ->preload()
                    ->nullable(),
                TextInput::make('guest_name')
                    ->label(__('tournaments::messages.participants.guest_name'))
                    ->maxLength(255)
                    ->requiredWithout('user_id'),
                TextInput::make('guest_email')
                    ->label(__('tournaments::messages.participants.guest_email'))
                    ->email()
                    ->maxLength(255),
                // A participant is added in an active status only
                Select::make('status')
                    ->label(__('tournaments::messages.participants.status'))
                    ->options(array_filter(
                        ParticipantStatus::options(),
                        static fn (string $status): bool => ParticipantStatus::from($status)->isActive(),
                        ARRAY_FILTER_USE_KEY,
                    ))
                    ->required()
                    ->default(ParticipantStatus::Registered->value),
                TextInput::make('seed')
                    ->label(__('tournaments::messages.participants.seed'))
                    ->numeric()
                    ->nullable(),
            ]);
    }

    /**
     * Managing participants needs tournaments.manage_participants, not only editing the tournament.
     */
    private function canManageParticipants(): bool
    {
        return auth()->user()?->can('manageParticipants', $this->getOwnerRecord()) ?? false;
    }

    private function participants(): ParticipantManagementServiceInterface
    {
        return app(ParticipantManagementServiceInterface::class);
    }

    /**
     * Add the participant through the service: same duplicate and capacity rules as the web,
     * without the registration window, and the guest email counts as confirmed.
     *
     * @param  array<string, mixed>  $data
     */
    private function addParticipant(array $data, CreateAction $action): ParticipantModel
    {
        /** @var TournamentModel $tournament */
        $tournament = $this->getOwnerRecord();
        $userId = filled($data['user_id'] ?? null) ? (string) $data['user_id'] : null;
        $seed = $data['seed'] ?? null;

        try {
            $participant = $this->participants()->registerByOrganization(
                new RegisterParticipantDTO(
                    tournamentId: $tournament->id,
                    userId: $userId,
                    guestName: filled($data['guest_name'] ?? null) ? (string) $data['guest_name'] : null,
                    guestEmail: filled($data['guest_email'] ?? null) ? (string) $data['guest_email'] : null,
                    seed: is_numeric($seed) ? (int) $seed : null,
                ),
                ParticipantStatus::from((string) $data['status']),
            );

            return ParticipantModel::query()->findOrFail($participant->id);
        } catch (DomainException $exception) {
            Notification::make()
                ->title($this->refusalMessage($exception, forUser: $userId !== null))
                ->danger()
                ->send();

            // The form stays open with what was typed; halt() always throws
            $action->halt();

            throw $exception;
        }
    }

    /**
     * Run a panel action through the service, telling the organisation why it was refused.
     *
     * @param  callable(string): mixed  $act
     */
    private function runOnParticipant(ParticipantModel $record, callable $act, string $successTitle): void
    {
        try {
            $act($record->id);
        } catch (DomainException $exception) {
            Notification::make()
                ->title($this->refusalMessage($exception))
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title($successTitle)
            ->success()
            ->send();
    }

    /**
     * Run a bulk action through the service one participant at a time; one refusal does not stop the others.
     *
     * @param  iterable<ParticipantModel>  $records
     * @param  callable(string): mixed  $act
     */
    private function runOnParticipants(iterable $records, callable $act, string $successTitle): void
    {
        $count = 0;
        $refusals = [];

        foreach ($records as $record) {
            try {
                $act($record->id);
                $count++;
            } catch (DomainException $exception) {
                $refusals[] = $this->refusalMessage($exception);
            }
        }

        Notification::make()
            ->title($successTitle)
            ->body($count.' '.__('tournaments::messages.participants.title'))
            ->success()
            ->send();

        if ($refusals !== []) {
            Notification::make()
                ->title(__('tournaments::messages.notifications.bulk_refused', ['count' => count($refusals)]))
                ->body(implode(' ', array_unique($refusals)))
                ->danger()
                ->send();
        }
    }

    private function refusalMessage(DomainException $exception, bool $forUser = false): string
    {
        return match (true) {
            $exception instanceof TournamentFullException => __('tournaments::messages.errors.tournament_full'),
            $exception instanceof AlreadyRegisteredException && $forUser => __('tournaments::messages.participants.user_already_registered'),
            $exception instanceof AlreadyRegisteredException => __('tournaments::messages.errors.registration_with_this_email'),
            default => __('tournaments::messages.participants.action_not_allowed'),
        };
    }

    /**
     * Whether the row is a guest registration still waiting for its email to be confirmed.
     */
    private function isAwaitingEmailConfirmation(ParticipantModel $record): bool
    {
        return $record->user_id === null
            && $record->email_confirmed_at === null
            && $record->status->isActive();
    }

    private function emailConfirmationNotice(ParticipantModel $record): ?string
    {
        if (! $this->isAwaitingEmailConfirmation($record)) {
            return null;
        }

        if ($record->confirmation_mode === GuestConfirmationMode::Wait) {
            return __('tournaments::messages.participants.email_unconfirmed_without_seat');
        }

        if ($record->confirmation_expires_at !== null) {
            return __('tournaments::messages.participants.email_unconfirmed_until', [
                'date' => $record->confirmation_expires_at->format('d/m/Y H:i'),
            ]);
        }

        return __('tournaments::messages.participants.email_unconfirmed');
    }

    /**
     * @return list<string>
     */
    private function possibleDuplicateIds(): array
    {
        /** @var TournamentModel $tournament */
        $tournament = $this->getOwnerRecord();

        return $this->possibleDuplicateIds ??= app(ParticipantRepositoryInterface::class)->findPossibleDuplicateIds(
            $tournament->id,
            app(GuestRegistrationSettingsInterface::class)->duplicateWindowHours(),
        );
    }

    private function getStatsDescription(): string
    {
        /** @var TournamentModel $tournament */
        $tournament = $this->getOwnerRecord();

        $counts = ParticipantModel::where('tournament_id', $tournament->id)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->toArray();

        $parts = [];

        $total = array_sum($counts);
        if ($total > 0) {
            $parts[] = $total.' '.__('tournaments::messages.participants.total');
        }

        $confirmed = $counts[ParticipantStatus::Confirmed->value] ?? 0;
        if ($confirmed > 0) {
            $parts[] = $confirmed.' '.__('tournaments::messages.participants.confirmed_count');
        }

        $checkedIn = $counts[ParticipantStatus::CheckedIn->value] ?? 0;
        if ($checkedIn > 0) {
            $parts[] = $checkedIn.' '.__('tournaments::messages.participants.checked_in_count');
        }

        return implode(' · ', $parts);
    }

    public function table(Table $table): Table
    {
        return $table
            ->description(fn (): string => $this->getStatsDescription())
            ->columns([
                TextColumn::make('display_name')
                    ->label(__('tournaments::messages.participants.name'))
                    ->getStateUsing(function (ParticipantModel $record): string {
                        if ($record->user) {
                            return $record->user->name;
                        }

                        return $record->guest_name ?? __('tournaments::messages.participants.unknown');
                    })
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->where(function (Builder $q) use ($search): void {
                            $q->whereHas('user', fn (Builder $uq): Builder => $uq->where('name', 'like', "%{$search}%"))
                                ->orWhere('guest_name', 'like', "%{$search}%");
                        });
                    })
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('guest_name', $direction)),
                TextColumn::make('user.email')
                    ->label(__('tournaments::messages.participants.email'))
                    ->getStateUsing(function (ParticipantModel $record): ?string {
                        if ($record->user !== null) {
                            return $record->user->email;
                        }

                        return $record->guest_email;
                    })
                    ->searchable()
                    ->toggleable(),
                IconColumn::make('is_guest')
                    ->label(__('tournaments::messages.participants.guest'))
                    ->getStateUsing(fn (ParticipantModel $record): bool => $record->user_id === null)
                    ->boolean()
                    ->trueIcon('heroicon-o-user')
                    ->falseIcon('heroicon-o-user-circle')
                    ->trueColor('warning')
                    ->falseColor('success')
                    ->toggleable(),
                TextColumn::make('status')
                    ->label(__('tournaments::messages.participants.status'))
                    ->badge()
                    ->color(fn (ParticipantStatus $state): string => $state->color())
                    ->formatStateUsing(fn (ParticipantStatus $state): string => $state->label())
                    ->description(fn (ParticipantModel $record): ?string => $this->emailConfirmationNotice($record))
                    ->sortable(),
                IconColumn::make('possible_duplicate')
                    ->label(__('tournaments::messages.participants.possible_duplicate'))
                    ->getStateUsing(fn (ParticipantModel $record): bool => in_array($record->id, $this->possibleDuplicateIds(), true))
                    ->icon(fn (bool $state): ?string => $state ? 'heroicon-o-exclamation-triangle' : null)
                    ->color('warning')
                    ->tooltip(fn (bool $state): ?string => $state ? (string) __('tournaments::messages.participants.possible_duplicate_tooltip') : null)
                    ->toggleable(),
                IconColumn::make('has_received_bye')
                    ->label(__('tournaments::messages.participants.bye'))
                    ->boolean()
                    ->toggleable(),
                TextColumn::make('seed')
                    ->label(__('tournaments::messages.participants.seed'))
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('checked_in_at')
                    ->label(__('tournaments::messages.participants.checked_in_at'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label(__('tournaments::messages.participants.registered_at'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('tournaments::messages.participants.status'))
                    ->options(ParticipantStatus::options()),
                // Users sign in, so only guest emails go unconfirmed
                TernaryFilter::make('email_confirmed')
                    ->label(__('tournaments::messages.participants.email_confirmed'))
                    ->queries(
                        true: fn (Builder $query): Builder => $query->where(fn (Builder $q): Builder => $q->whereNotNull('email_confirmed_at')->orWhereNotNull('user_id')),
                        false: fn (Builder $query): Builder => $query->whereNull('user_id')->whereNull('email_confirmed_at'),
                    ),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label(__('tournaments::messages.participants.add'))
                    ->authorize(fn (): bool => $this->canManageParticipants())
                    ->using(fn (array $data, CreateAction $action): Model => $this->addParticipant($data, $action)),
            ])
            ->recordActions([
                Action::make('confirm')
                    ->authorize(fn (): bool => $this->canManageParticipants())
                    ->label(__('tournaments::messages.participants.confirm'))
                    ->icon('heroicon-o-check-badge')
                    ->color('info')
                    ->visible(function (ParticipantModel $record): bool {
                        /** @var TournamentModel $tournament */
                        $tournament = $this->getOwnerRecord();

                        return $tournament->requires_manual_confirmation
                            && $record->status === ParticipantStatus::Registered;
                    })
                    ->requiresConfirmation()
                    ->modalHeading(__('tournaments::messages.participants.confirm_participant'))
                    ->action(fn (ParticipantModel $record) => $this->runOnParticipant(
                        $record,
                        fn (string $id) => $this->participants()->confirm($id),
                        __('tournaments::messages.notifications.participant_confirmed'),
                    )),
                Action::make('confirm_email')
                    ->authorize(fn (): bool => $this->canManageParticipants())
                    ->label(__('tournaments::messages.participants.confirm_email'))
                    ->icon('heroicon-o-envelope-open')
                    ->color('info')
                    ->visible(fn (ParticipantModel $record): bool => $this->isAwaitingEmailConfirmation($record))
                    ->requiresConfirmation()
                    ->modalDescription(__('tournaments::messages.modal.confirm_email_description'))
                    ->action(fn (ParticipantModel $record) => $this->runOnParticipant(
                        $record,
                        fn (string $id) => $this->participants()->confirmEmailByOrganization($id),
                        __('tournaments::messages.notifications.email_confirmed'),
                    )),
                Action::make('check_in')
                    ->authorize(fn (): bool => $this->canManageParticipants())
                    ->label(__('tournaments::messages.participants.check_in'))
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(function (ParticipantModel $record): bool {
                        /** @var TournamentModel $tournament */
                        $tournament = $this->getOwnerRecord();

                        // The rule self check-in follows too
                        return $this->participants()->canCheckIn($record->status, $tournament->requires_manual_confirmation);
                    })
                    ->requiresConfirmation()
                    ->action(fn (ParticipantModel $record) => $this->runOnParticipant(
                        $record,
                        fn (string $id) => $this->participants()->checkIn($id),
                        __('tournaments::messages.notifications.checked_in'),
                    )),
                Action::make('withdraw')
                    ->authorize(fn (): bool => $this->canManageParticipants())
                    ->label(__('tournaments::messages.participants.withdraw'))
                    ->icon('heroicon-o-arrow-right-start-on-rectangle')
                    ->color('warning')
                    ->visible(fn (ParticipantModel $record): bool => $record->status->isActive())
                    ->requiresConfirmation()
                    ->modalDescription(__('tournaments::messages.modal.withdraw_description'))
                    ->action(function (ParticipantModel $record): void {
                        $record->update(['status' => ParticipantStatus::Withdrawn]);

                        Notification::make()
                            ->title(__('tournaments::messages.notifications.withdrawn'))
                            ->success()
                            ->send();
                    }),
                Action::make('disqualify')
                    ->authorize(fn (): bool => $this->canManageParticipants())
                    ->label(__('tournaments::messages.participants.disqualify'))
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (ParticipantModel $record): bool => $record->status->isActive())
                    ->requiresConfirmation()
                    ->modalDescription(__('tournaments::messages.modal.disqualify_description'))
                    ->action(fn (ParticipantModel $record) => $this->runOnParticipant(
                        $record,
                        fn (string $id) => $this->participants()->disqualify($id),
                        __('tournaments::messages.notifications.disqualified'),
                    )),
                DeleteAction::make()
                    ->authorize(fn (): bool => $this->canManageParticipants()),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('confirm_selected')
                        ->authorize(fn (): bool => $this->canManageParticipants())
                        ->label(__('tournaments::messages.participants.confirm_selected'))
                        ->icon('heroicon-o-check-badge')
                        ->color('info')
                        ->visible(function (): bool {
                            /** @var TournamentModel $tournament */
                            $tournament = $this->getOwnerRecord();

                            return $tournament->requires_manual_confirmation;
                        })
                        ->requiresConfirmation()
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records): void {
                            /** @var Collection<int, ParticipantModel> $records */
                            $this->runOnParticipants(
                                $records->filter(fn (ParticipantModel $participant): bool => $participant->status === ParticipantStatus::Registered),
                                fn (string $id) => $this->participants()->confirm($id),
                                __('tournaments::messages.notifications.bulk_confirmed'),
                            );
                        }),
                    BulkAction::make('check_in_selected')
                        ->authorize(fn (): bool => $this->canManageParticipants())
                        ->label(__('tournaments::messages.participants.check_in_selected'))
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records): void {
                            /** @var TournamentModel $tournament */
                            $tournament = $this->getOwnerRecord();

                            $manualConfirmation = $tournament->requires_manual_confirmation;

                            /** @var Collection<int, ParticipantModel> $records */
                            $this->runOnParticipants(
                                $records->filter(fn (ParticipantModel $participant): bool => $this->participants()->canCheckIn($participant->status, $manualConfirmation)),
                                fn (string $id) => $this->participants()->checkIn($id),
                                __('tournaments::messages.notifications.bulk_checked_in'),
                            );
                        }),
                    BulkAction::make('disqualify_selected')
                        ->authorize(fn (): bool => $this->canManageParticipants())
                        ->label(__('tournaments::messages.participants.disqualify_selected'))
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records): void {
                            /** @var Collection<int, ParticipantModel> $records */
                            $this->runOnParticipants(
                                $records->filter(fn (ParticipantModel $participant): bool => $participant->status->isActive()),
                                fn (string $id) => $this->participants()->disqualify($id),
                                __('tournaments::messages.notifications.bulk_disqualified'),
                            );
                        }),
                    DeleteBulkAction::make()
                        ->authorize(fn (): bool => $this->canManageParticipants()),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
