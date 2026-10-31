<?php

declare(strict_types=1);

namespace Modules\Tournaments\Filament\Resources\TournamentResource\Pages;

use App\Application\Services\TimezoneServiceInterface;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use LogicException;
use Modules\Tournaments\Application\Services\TournamentLifecycleServiceInterface;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Domain\Exceptions\InsufficientParticipantsException;
use Modules\Tournaments\Domain\Exceptions\InvalidStateTransitionException;
use Modules\Tournaments\Filament\Resources\TournamentResource;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;

final class EditTournament extends EditRecord
{
    protected static string $resource = TournamentResource::class;

    public function getTitle(): string
    {
        return __('tournaments::messages.resource.actions.edit');
    }

    protected function getHeaderActions(): array
    {
        $record = $this->tournament();

        return [
            Action::make('openRegistration')
                ->label(__('tournaments::messages.actions.open_registration'))
                ->icon('heroicon-o-lock-open')
                ->color('success')
                ->visible(fn (): bool => $record->status === TournamentStatus::Draft)
                ->requiresConfirmation()
                ->action(function (): void {
                    if (! $this->changeStatus(fn (TournamentLifecycleServiceInterface $lifecycle) => $lifecycle->openRegistration($this->tournament()->id))) {
                        return;
                    }

                    Notification::make()
                        ->title(__('tournaments::messages.messages.registration_opened'))
                        ->success()
                        ->send();

                    $this->warnWhenTheWebStaysClosed();
                }),

            Action::make('closeRegistration')
                ->label(__('tournaments::messages.actions.close_registration'))
                ->icon('heroicon-o-lock-closed')
                ->color('warning')
                ->visible(fn (): bool => $record->status === TournamentStatus::RegistrationOpen)
                ->requiresConfirmation()
                ->action(function (): void {
                    if (! $this->changeStatus(fn (TournamentLifecycleServiceInterface $lifecycle) => $lifecycle->closeRegistration($this->tournament()->id))) {
                        return;
                    }

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
                    // The service checks the minimum of participants
                    if (! $this->changeStatus(fn (TournamentLifecycleServiceInterface $lifecycle) => $lifecycle->start($this->tournament()->id))) {
                        return;
                    }

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
                    if (! $this->changeStatus(fn (TournamentLifecycleServiceInterface $lifecycle) => $lifecycle->finish($this->tournament()->id))) {
                        return;
                    }

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
                    // Participants are told by email
                    if (! $this->changeStatus(fn (TournamentLifecycleServiceInterface $lifecycle) => $lifecycle->cancel($this->tournament()->id))) {
                        return;
                    }

                    Notification::make()
                        ->title(__('tournaments::messages.messages.tournament_cancelled'))
                        ->success()
                        ->send();
                }),

            DeleteAction::make(),
        ];
    }

    /**
     * Opening registration only changes the status: the web stays closed before the opening
     * date, after the closing date and once the event has started. Say why.
     */
    private function warnWhenTheWebStaysClosed(): void
    {
        $record = $this->tournament()->refresh();
        $now = now();
        $eventStartsAt = $record->event?->start_date;

        $reason = match (true) {
            $eventStartsAt === null || $now->greaterThanOrEqualTo($eventStartsAt) => __('tournaments::messages.registration_still_closed.event_started'),
            $record->registration_closes_at !== null && $now->greaterThanOrEqualTo($record->registration_closes_at) => __('tournaments::messages.registration_still_closed.closing_date_passed'),
            $record->registration_opens_at !== null && $now->lessThan($record->registration_opens_at) => __('tournaments::messages.registration_still_closed.opens_later', [
                'date' => app(TimezoneServiceInterface::class)->format($record->registration_opens_at),
            ]),
            default => null,
        };

        if (! is_string($reason)) {
            return;
        }

        Notification::make()
            ->title(__('tournaments::messages.registration_still_closed.title'))
            ->body($reason)
            ->warning()
            ->send();
    }

    /**
     * Change the status through the domain, which also announces it (finishing and cancelling
     * tell the surveys); a tournament that changed meanwhile (another tab) is reported instead
     * of overwritten.
     *
     * @param  Closure(TournamentLifecycleServiceInterface): void  $change
     */
    private function changeStatus(Closure $change): bool
    {
        try {
            $change(app(TournamentLifecycleServiceInterface::class));
        } catch (InvalidStateTransitionException|InsufficientParticipantsException $exception) {
            Notification::make()
                ->title(__($exception instanceof InsufficientParticipantsException
                    ? 'tournaments::messages.errors.insufficient_participants'
                    : 'tournaments::messages.errors.invalid_state_transition'))
                ->danger()
                ->send();

            return false;
        } finally {
            $this->tournament()->refresh();
            $this->refreshFormData(['status']);
        }

        return true;
    }

    private function tournament(): TournamentModel
    {
        $record = $this->getRecord();

        if (! $record instanceof TournamentModel) {
            throw new LogicException('The edited record is not a tournament.');
        }

        return $record;
    }
}
