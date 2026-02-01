<?php

declare(strict_types=1);

namespace Modules\Tournaments\Filament\Resources\TournamentResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\Support\Htmlable;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Filament\Resources\TournamentResource;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;

final class EditTournament extends EditRecord
{
    protected static string $resource = TournamentResource::class;

    public function getTitle(): string|Htmlable
    {
        return __('tournaments::messages.resource.actions.edit');
    }

    protected function getHeaderActions(): array
    {
        /** @var TournamentModel $record */
        $record = $this->record;

        return [
            Action::make('openRegistration')
                ->label(__('tournaments::messages.actions.open_registration'))
                ->icon('heroicon-o-lock-open')
                ->color('success')
                ->visible(fn (): bool => $record->status === TournamentStatus::Draft)
                ->requiresConfirmation()
                ->action(function (): void {
                    $this->record->update([
                        'status' => TournamentStatus::RegistrationOpen->value,
                    ]);
                    $this->refreshFormData(['status']);

                    Notification::make()
                        ->title(__('tournaments::messages.messages.registration_opened'))
                        ->success()
                        ->send();
                }),

            Action::make('closeRegistration')
                ->label(__('tournaments::messages.actions.close_registration'))
                ->icon('heroicon-o-lock-closed')
                ->color('warning')
                ->visible(fn (): bool => $record->status === TournamentStatus::RegistrationOpen)
                ->requiresConfirmation()
                ->action(function (): void {
                    $this->record->update([
                        'status' => TournamentStatus::RegistrationClosed->value,
                    ]);
                    $this->refreshFormData(['status']);

                    Notification::make()
                        ->title(__('tournaments::messages.messages.registration_closed'))
                        ->success()
                        ->send();
                }),

            Action::make('startTournament')
                ->label(__('tournaments::messages.actions.start_tournament'))
                ->icon('heroicon-o-play')
                ->color('primary')
                ->visible(fn (): bool => in_array($record->status, [
                    TournamentStatus::RegistrationOpen,
                    TournamentStatus::RegistrationClosed,
                ], true))
                ->requiresConfirmation()
                ->action(function (): void {
                    /** @var TournamentModel $record */
                    $record = $this->record;

                    // Check minimum participants
                    $participantCount = $record->participants()->count();
                    if ($participantCount < $record->min_participants) {
                        Notification::make()
                            ->title(__('tournaments::messages.errors.insufficient_participants'))
                            ->danger()
                            ->send();

                        return;
                    }

                    $this->record->update([
                        'status' => TournamentStatus::InProgress->value,
                        'started_at' => now(),
                    ]);
                    $this->refreshFormData(['status']);

                    Notification::make()
                        ->title(__('tournaments::messages.messages.tournament_started'))
                        ->success()
                        ->send();
                }),

            Action::make('finishTournament')
                ->label(__('tournaments::messages.actions.finish_tournament'))
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (): bool => $record->status === TournamentStatus::InProgress)
                ->requiresConfirmation()
                ->action(function (): void {
                    $this->record->update([
                        'status' => TournamentStatus::Finished->value,
                        'completed_at' => now(),
                    ]);
                    $this->refreshFormData(['status']);

                    Notification::make()
                        ->title(__('tournaments::messages.messages.tournament_finished'))
                        ->success()
                        ->send();
                }),

            Action::make('cancelTournament')
                ->label(__('tournaments::messages.actions.cancel_tournament'))
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (): bool => ! in_array($record->status, [
                    TournamentStatus::Finished,
                    TournamentStatus::Cancelled,
                ], true))
                ->requiresConfirmation()
                ->modalHeading(__('tournaments::messages.actions.cancel_tournament'))
                ->modalDescription(__('tournaments::messages.modal.cancel_description'))
                ->action(function (): void {
                    $this->record->update([
                        'status' => TournamentStatus::Cancelled->value,
                    ]);
                    $this->refreshFormData(['status']);

                    Notification::make()
                        ->title(__('tournaments::messages.messages.tournament_cancelled'))
                        ->success()
                        ->send();
                }),

            DeleteAction::make(),
        ];
    }
}
