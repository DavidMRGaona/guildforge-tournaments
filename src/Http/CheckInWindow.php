<?php

declare(strict_types=1);

namespace Modules\Tournaments\Http;

use DateTimeImmutable;
use Modules\Tournaments\Application\DTOs\Response\TournamentResponseDTO;
use Modules\Tournaments\Domain\Enums\TournamentStatus;

final class CheckInWindow
{
    /**
     * The check-in window status of a tournament, shared by the check-in page and the tournament page.
     *
     * @return array{status: string, opens_at: string|null, closes_at: string|null}
     */
    public function calculate(TournamentResponseDTO $tournament, ?DateTimeImmutable $eventStartDate): array
    {
        // If check-in is not required or self check-in not allowed, window is closed
        if (! $tournament->requiresCheckIn || ! $tournament->selfCheckInAllowed) {
            return [
                'status' => 'not_available',
                'opens_at' => null,
                'closes_at' => null,
            ];
        }

        // If tournament is already in progress or finished, window is closed
        if (in_array($tournament->status, [TournamentStatus::InProgress, TournamentStatus::Finished, TournamentStatus::Cancelled], true)) {
            return [
                'status' => 'closed',
                'opens_at' => null,
                'closes_at' => null,
            ];
        }

        if ($eventStartDate === null) {
            return [
                'status' => 'not_available',
                'opens_at' => null,
                'closes_at' => null,
            ];
        }

        $checkInMinutesBefore = $tournament->checkInStartsBefore ?? 30;
        $windowOpensAt = $eventStartDate->modify("-{$checkInMinutesBefore} minutes");
        $windowClosesAt = $eventStartDate;

        $now = new DateTimeImmutable;

        if ($now < $windowOpensAt) {
            return [
                'status' => 'not_yet',
                'opens_at' => $windowOpensAt->format('c'),
                'closes_at' => $windowClosesAt->format('c'),
            ];
        }

        if ($now > $windowClosesAt) {
            return [
                'status' => 'closed',
                'opens_at' => $windowOpensAt->format('c'),
                'closes_at' => $windowClosesAt->format('c'),
            ];
        }

        return [
            'status' => 'open',
            'opens_at' => $windowOpensAt->format('c'),
            'closes_at' => $windowClosesAt->format('c'),
        ];
    }
}
