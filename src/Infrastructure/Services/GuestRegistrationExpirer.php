<?php

declare(strict_types=1);

namespace Modules\Tournaments\Infrastructure\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Modules\Tournaments\Application\Services\RegistrationLockInterface;
use Modules\Tournaments\Domain\Entities\Participant;
use Modules\Tournaments\Domain\Entities\Tournament;
use Modules\Tournaments\Domain\Enums\GuestConfirmationMode;
use Modules\Tournaments\Domain\Enums\ParticipantStatus;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Domain\Repositories\ParticipantRepositoryInterface;
use Modules\Tournaments\Domain\Repositories\TournamentRepositoryInterface;
use Modules\Tournaments\Domain\ValueObjects\ParticipantId;
use Modules\Tournaments\Domain\ValueObjects\TournamentId;
use Modules\Tournaments\Notifications\GuestRegistrationExpiredNotification;
use Throwable;

/**
 * Unconfirmed guest registrations lapse with the clock: a held seat at its deadline (the guest is
 * told), any other unconfirmed row when registration closes (silently, it never held a seat; a
 * held one closing early is told too). Each row is read again under the tournament lock, so one
 * confirmed meanwhile is left alone, and one that fails is logged without stopping the rest. The
 * fingerprints of tournaments that have started, finished or been cancelled are cleared as well.
 */
final readonly class GuestRegistrationExpirer
{
    public function __construct(
        private ParticipantRepositoryInterface $participantRepository,
        private TournamentRepositoryInterface $tournamentRepository,
        private RegistrationLockInterface $lock,
    ) {}

    /**
     * @return array{expired: int, fingerprints_cleared: int}
     */
    public function run(CarbonImmutable $now): array
    {
        $expired = 0;

        foreach ($this->participantRepository->findIdsAwaitingConfirmation() as $participantId) {
            try {
                /** @var array{expired: bool, notice: array{email: string, name: string, tournament: string, slug: string}|null} $outcome */
                $outcome = $this->lock->forParticipant(
                    $participantId,
                    fn (): array => $this->expireUnderLock($participantId, $now),
                );
            } catch (Throwable $e) {
                Log::warning('tournaments: guest registration expiry failed', [
                    'participant_id' => $participantId,
                    'exception' => $e,
                ]);

                continue;
            }

            if (! $outcome['expired']) {
                continue;
            }

            $expired++;

            // Mailed once the expiry is committed; a mail failure leaves the row expired
            if ($outcome['notice'] !== null) {
                try {
                    GuestRegistrationExpiredNotification::sendTo(
                        $outcome['notice']['email'],
                        $outcome['notice']['name'],
                        $outcome['notice']['tournament'],
                        $outcome['notice']['slug'],
                    );
                } catch (Throwable $e) {
                    Log::warning('tournaments: guest registration expiry email failed', [
                        'participant_id' => $participantId,
                        'exception' => $e,
                    ]);
                }
            }
        }

        return [
            'expired' => $expired,
            'fingerprints_cleared' => $this->clearPastFingerprints($now),
        ];
    }

    /**
     * @return array{expired: bool, notice: array{email: string, name: string, tournament: string, slug: string}|null}
     */
    private function expireUnderLock(string $participantId, CarbonImmutable $now): array
    {
        $none = ['expired' => false, 'notice' => null];
        $participant = $this->participantRepository->find(ParticipantId::fromString($participantId));

        // Confirmed, endorsed by the organisation or ended meanwhile: not ours any more
        if (!$participant instanceof \Modules\Tournaments\Domain\Entities\Participant
            || ! $participant->isAwaitingEmailConfirmation()
            || $participant->status() !== ParticipantStatus::Registered) {
            return $none;
        }

        $tournament = $this->tournamentRepository->find(TournamentId::fromString($participant->tournamentId()));

        if (!$tournament instanceof \Modules\Tournaments\Domain\Entities\Tournament) {
            return $none;
        }

        $held = $participant->confirmationMode() === GuestConfirmationMode::Hold;
        $expiresAt = $participant->confirmationExpiresAt();
        $overdue = $held && $expiresAt instanceof \DateTimeImmutable && $expiresAt <= $now;

        if (! $overdue && $tournament->isRegistrationOpen($now->toDateTimeImmutable())) {
            return $none;
        }

        $participant->expire();
        $this->participantRepository->save($participant);

        return ['expired' => true, 'notice' => $held ? $this->notice($participant, $tournament) : null];
    }

    /**
     * @return array{email: string, name: string, tournament: string, slug: string}|null
     */
    private function notice(Participant $participant, Tournament $tournament): ?array
    {
        $email = $participant->guestEmail();

        if ($email === null) {
            return null;
        }

        return [
            'email' => $email,
            'name' => $participant->guestName() ?? $email,
            'tournament' => $tournament->name(),
            'slug' => $tournament->slug(),
        ];
    }

    private function clearPastFingerprints(CarbonImmutable $now): int
    {
        $cleared = 0;

        foreach ($this->participantRepository->findTournamentIdsWithFingerprints() as $tournamentId) {
            $tournament = $this->tournamentRepository->find(TournamentId::fromString($tournamentId));

            if (!$tournament instanceof \Modules\Tournaments\Domain\Entities\Tournament) {
                continue;
            }

            $past = $tournament->hasEventStarted($now->toDateTimeImmutable())
                || in_array($tournament->status(), [TournamentStatus::Finished, TournamentStatus::Cancelled], true);

            if ($past) {
                $cleared += $this->participantRepository->clearFingerprints($tournamentId);
            }
        }

        return $cleared;
    }
}
