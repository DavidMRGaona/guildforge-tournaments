<?php

declare(strict_types=1);

namespace Modules\Tournaments\Console\Commands;

use App\Application\Services\TimezoneServiceInterface;
use DateInterval;
use Illuminate\Console\Command;
use Modules\Tournaments\Domain\Entities\Participant;
use Modules\Tournaments\Domain\Entities\Tournament;
use Modules\Tournaments\Domain\Enums\ParticipantStatus;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Domain\Repositories\ParticipantRepositoryInterface;
use Modules\Tournaments\Domain\Repositories\TournamentRepositoryInterface;
use Modules\Tournaments\Domain\Services\Clock;
use Modules\Tournaments\Domain\ValueObjects\ParticipantId;
use Modules\Tournaments\Domain\ValueObjects\TournamentId;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\ParticipantModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use Modules\Tournaments\Infrastructure\Services\TournamentReminderSettings;
use Modules\Tournaments\Listeners\Concerns\MailsParticipants;
use Modules\Tournaments\Notifications\AnonymousNotifiable;
use Modules\Tournaments\Notifications\TournamentReminderNotification;

/**
 * Reminds active participants of a tournament whose event starts within the configured
 * hours. Each registration is reminded once; nothing runs while reminders are off.
 */
final class SendTournamentRemindersCommand extends Command
{
    use MailsParticipants;

    protected $signature = 'tournaments:send-reminders';

    protected $description = 'Remind participants of tournaments that start soon';

    public function handle(
        TournamentReminderSettings $settings,
        TournamentRepositoryInterface $tournamentRepository,
        ParticipantRepositoryInterface $participantRepository,
        Clock $clock,
    ): int {
        if (! $settings->enabled()) {
            $this->info('Tournament reminders are disabled.');

            return self::SUCCESS;
        }

        $timezone = app(TimezoneServiceInterface::class);
        // Bound in the storage zone: bindings skip the model's conversion
        $now = $timezone->toStorage($clock->now());
        $until = $now->add(new DateInterval(sprintf('PT%dH', $settings->hoursBefore())));
        $sent = 0;

        $tournaments = TournamentModel::query()
            ->whereIn('status', [TournamentStatus::RegistrationOpen->value, TournamentStatus::RegistrationClosed->value])
            ->whereHas('event', fn ($event) => $event->where('is_published', true)->where('start_date', '>', $now)->where('start_date', '<=', $until))
            ->with('event:id,start_date')
            ->get();

        foreach ($tournaments as $model) {
            $tournament = $tournamentRepository->find(TournamentId::fromString($model->id));
            $startsAt = $model->event?->start_date;

            if (! $tournament instanceof Tournament || $startsAt === null) {
                continue;
            }

            $pending = ParticipantModel::query()
                ->where('tournament_id', $model->id)
                ->whereIn('status', array_map(
                    static fn (ParticipantStatus $status): string => $status->value,
                    array_filter(ParticipantStatus::cases(), static fn (ParticipantStatus $status): bool => $status->isActive()),
                ))
                ->whereNull('reminder_sent_at')
                ->pluck('id');

            foreach ($pending as $participantId) {
                $participant = $participantRepository->find(ParticipantId::fromString((string) $participantId));
                $recipient = $participant instanceof Participant ? $this->recipient($participant) : null;

                // An unconfirmed guest is skipped and stays pending: confirming later still gets the reminder
                if (! $participant instanceof Participant || ! $recipient instanceof AnonymousNotifiable) {
                    continue;
                }

                // Claimed before sending, so a run started by hand next to the scheduled one never doubles it
                $claimed = ParticipantModel::query()
                    ->whereKey($participantId)
                    ->whereNull('reminder_sent_at')
                    ->update(['reminder_sent_at' => $now]) === 1;

                if (! $claimed) {
                    continue;
                }

                $recipient->notify(new TournamentReminderNotification(
                    participantName: $this->participantName($participant, $recipient),
                    tournamentName: $tournament->name(),
                    tournamentSlug: $tournament->slug(),
                    eventDate: $timezone->format($startsAt),
                    requiresCheckIn: $tournament->requiresCheckIn(),
                    checkInStartsBefore: $tournament->checkInStartsBefore(),
                    cancellationToken: $participant->userId() === null ? $participant->cancellationToken() : null,
                ));

                $sent++;
            }
        }

        $this->info("Sent {$sent} reminder(s).");

        return self::SUCCESS;
    }
}
