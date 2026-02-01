<?php

declare(strict_types=1);

namespace Modules\Tournaments\Filament\RelationManagers;

use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\CreateAction;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Modules\Tournaments\Domain\Enums\ParticipantStatus;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\ParticipantModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;

final class ParticipantsRelationManager extends RelationManager
{
    protected static string $relationship = 'participants';

    protected static ?string $recordTitleAttribute = 'id';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('tournaments::messages.participants.title');
    }

    /**
     * Only show this tab if a tournament exists for this event.
     */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        if (! $ownerRecord instanceof EventModel) {
            return false;
        }

        return TournamentModel::where('event_id', $ownerRecord->id)->exists();
    }

    /**
     * @return Builder<ParticipantModel>
     */
    protected function getTableQuery(): Builder
    {
        $eventId = $this->getOwnerRecord()->getKey();
        $tournament = TournamentModel::where('event_id', $eventId)->first();

        if (! $tournament) {
            return ParticipantModel::query()->whereRaw('1 = 0');
        }

        return ParticipantModel::query()
            ->where('tournament_id', $tournament->id)
            ->with('user');
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
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
                Select::make('status')
                    ->label(__('tournaments::messages.participants.status'))
                    ->options(ParticipantStatus::options())
                    ->required()
                    ->default(ParticipantStatus::Registered->value),
                TextInput::make('seed')
                    ->label(__('tournaments::messages.participants.seed'))
                    ->numeric()
                    ->nullable(),
            ]);
    }

    private function getStatsDescription(): string
    {
        $eventId = $this->getOwnerRecord()->getKey();
        $tournament = TournamentModel::where('event_id', $eventId)->first();

        if (! $tournament) {
            return '';
        }

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
                    ->sortable(),
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
            ])
            ->headerActions([
                CreateAction::make()
                    ->label(__('tournaments::messages.participants.add'))
                    ->mutateFormDataUsing(function (array $data): array {
                        $eventId = $this->getOwnerRecord()->getKey();
                        $tournament = TournamentModel::where('event_id', $eventId)->first();
                        $data['tournament_id'] = $tournament?->id;
                        $data['registered_at'] = now();

                        return $data;
                    }),
            ])
            ->actions([
                Action::make('check_in')
                    ->label(__('tournaments::messages.participants.check_in'))
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (ParticipantModel $record): bool => $record->status !== ParticipantStatus::CheckedIn && $record->status->canPlay())
                    ->requiresConfirmation()
                    ->action(function (ParticipantModel $record): void {
                        $record->update([
                            'status' => ParticipantStatus::CheckedIn,
                            'checked_in_at' => now(),
                        ]);

                        Notification::make()
                            ->title(__('tournaments::messages.notifications.checked_in'))
                            ->success()
                            ->send();
                    }),
                Action::make('withdraw')
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
                    ->label(__('tournaments::messages.participants.disqualify'))
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (ParticipantModel $record): bool => $record->status->isActive())
                    ->requiresConfirmation()
                    ->modalDescription(__('tournaments::messages.modal.disqualify_description'))
                    ->action(function (ParticipantModel $record): void {
                        $record->update(['status' => ParticipantStatus::Disqualified]);

                        Notification::make()
                            ->title(__('tournaments::messages.notifications.disqualified'))
                            ->success()
                            ->send();
                    }),
                DeleteAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    BulkAction::make('check_in_selected')
                        ->label(__('tournaments::messages.participants.check_in_selected'))
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records): void {
                            $count = 0;

                            foreach ($records as $record) {
                                /** @var ParticipantModel $participant */
                                $participant = $record;

                                if ($participant->status === ParticipantStatus::CheckedIn) {
                                    continue;
                                }

                                if (! $participant->status->canPlay()) {
                                    continue;
                                }

                                $participant->update([
                                    'status' => ParticipantStatus::CheckedIn,
                                    'checked_in_at' => now(),
                                ]);
                                $count++;
                            }

                            Notification::make()
                                ->title(__('tournaments::messages.notifications.bulk_checked_in'))
                                ->body($count.' '.__('tournaments::messages.participants.title'))
                                ->success()
                                ->send();
                        }),
                    BulkAction::make('disqualify_selected')
                        ->label(__('tournaments::messages.participants.disqualify_selected'))
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records): void {
                            $count = 0;

                            foreach ($records as $record) {
                                /** @var ParticipantModel $participant */
                                $participant = $record;

                                if (! $participant->status->isActive()) {
                                    continue;
                                }

                                $participant->update(['status' => ParticipantStatus::Disqualified]);
                                $count++;
                            }

                            Notification::make()
                                ->title(__('tournaments::messages.notifications.bulk_disqualified'))
                                ->body($count.' '.__('tournaments::messages.participants.title'))
                                ->success()
                                ->send();
                        }),
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
