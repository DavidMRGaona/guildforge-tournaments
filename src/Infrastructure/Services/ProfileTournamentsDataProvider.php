<?php

declare(strict_types=1);

namespace Modules\Tournaments\Infrastructure\Services;

use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use DateTimeImmutable;
use Modules\Tournaments\Application\DTOs\ProfileTournamentParticipationDTO;
use Modules\Tournaments\Domain\Enums\MatchResult;
use Modules\Tournaments\Domain\Enums\ParticipantStatus;
use Modules\Tournaments\Domain\Enums\RoundStatus;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\MatchModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\ParticipantModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\RoundModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\StandingModel;

final readonly class ProfileTournamentsDataProvider
{
    /**
     * Get tournament participation data for a user's profile page.
     *
     * @return array{upcoming: array<array<string, mixed>>, inProgress: array<array<string, mixed>>, past: array<array<string, mixed>>, total: int}|null
     */
    public function getDataForUser(?string $userId): ?array
    {
        if ($userId === null) {
            return null;
        }

        $participations = $this->getUserTournamentParticipations($userId);

        if ($participations === []) {
            return null;
        }

        $upcoming = [];
        $inProgress = [];
        $past = [];

        foreach ($participations as $participation) {
            if ($participation->isPast) {
                $past[] = $participation->toArray();
            } elseif ($participation->isInProgress) {
                $inProgress[] = $participation->toArray();
            } else {
                $upcoming[] = $participation->toArray();
            }
        }

        return [
            'upcoming' => $upcoming,
            'inProgress' => $inProgress,
            'past' => $past,
            'total' => count($participations),
        ];
    }

    /**
     * Get tournament participations for a user.
     *
     * @return array<ProfileTournamentParticipationDTO>
     */
    private function getUserTournamentParticipations(string $userId): array
    {
        // Get active participations (not withdrawn/disqualified) with tournament data
        $activeStatuses = [
            ParticipantStatus::Registered,
            ParticipantStatus::Confirmed,
            ParticipantStatus::CheckedIn,
        ];

        $participants = ParticipantModel::query()
            ->where('user_id', $userId)
            ->whereIn('status', $activeStatuses)
            ->with(['tournament', 'standing'])
            ->get();

        if ($participants->isEmpty()) {
            return [];
        }

        // Filter out cancelled/draft tournaments
        $excludedStatuses = [
            TournamentStatus::Draft,
            TournamentStatus::Cancelled,
        ];

        $participations = [];

        foreach ($participants as $participant) {
            $tournament = $participant->tournament;

            if ($tournament === null || in_array($tournament->status, $excludedStatuses, true)) {
                continue;
            }

            $participations[] = $this->buildParticipationDTO(
                $participant,
                $tournament,
                $userId
            );
        }

        // Sort: in_progress first, then upcoming by date, then past by date desc
        usort($participations, function (ProfileTournamentParticipationDTO $a, ProfileTournamentParticipationDTO $b): int {
            // In progress first
            if ($a->isInProgress && ! $b->isInProgress) {
                return -1;
            }
            if (! $a->isInProgress && $b->isInProgress) {
                return 1;
            }

            // Upcoming before past
            if ($a->isUpcoming && $b->isPast) {
                return -1;
            }
            if ($a->isPast && $b->isUpcoming) {
                return 1;
            }

            // Within same category, sort by date
            $aDate = $a->startsAt;
            $bDate = $b->startsAt;

            if ($aDate === null && $bDate === null) {
                return 0;
            }
            if ($aDate === null) {
                return 1;
            }
            if ($bDate === null) {
                return -1;
            }

            // Upcoming: ascending date (soonest first)
            if ($a->isUpcoming) {
                return $aDate <=> $bDate;
            }

            // Past: descending date (most recent first)
            return $bDate <=> $aDate;
        });

        return $participations;
    }

    /**
     * Build a ProfileTournamentParticipationDTO from participant and tournament data.
     */
    private function buildParticipationDTO(
        ParticipantModel $participant,
        \Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel $tournament,
        string $userId
    ): ProfileTournamentParticipationDTO {
        // Get event data for start date and name
        $event = EventModel::query()
            ->where('id', $tournament->event_id)
            ->first(['title', 'start_date']);

        $startsAt = $event?->start_date?->toImmutable();
        $eventName = $event?->title;

        // Determine tournament phase
        $now = new DateTimeImmutable();
        $status = $tournament->status;

        $isInProgress = $status === TournamentStatus::InProgress;
        $isPast = $status === TournamentStatus::Finished;
        $isUpcoming = ! $isInProgress && ! $isPast;

        // Get standings data if tournament is in progress or finished
        $position = null;
        $points = 0.0;

        if ($isInProgress || $isPast) {
            $standing = $participant->standing;
            if ($standing !== null) {
                $position = $standing->rank;
                $points = (float) $standing->points;
            }
        }

        // Get next match data if tournament is in progress
        $nextMatch = null;
        if ($isInProgress) {
            $nextMatch = $this->getNextMatchForParticipant($participant->id, $tournament->id);
        }

        // Get total participants
        $totalParticipants = ParticipantModel::query()
            ->where('tournament_id', $tournament->id)
            ->whereIn('status', [
                ParticipantStatus::Registered,
                ParticipantStatus::Confirmed,
                ParticipantStatus::CheckedIn,
            ])
            ->count();

        // Determine check-in availability
        $canCheckIn = false;
        $checkInDeadline = null;

        if ($tournament->requires_check_in
            && $participant->status !== ParticipantStatus::CheckedIn
            && $startsAt !== null
            && $tournament->check_in_starts_before !== null
            && $tournament->self_check_in_allowed
        ) {
            $checkInOpensAt = $startsAt->modify("-{$tournament->check_in_starts_before} minutes");
            $checkInDeadline = $startsAt;

            if ($now >= $checkInOpensAt && $now < $startsAt) {
                $canCheckIn = true;
            }
        }

        return new ProfileTournamentParticipationDTO(
            id: $tournament->id,
            name: $tournament->name,
            slug: $tournament->slug,
            imagePublicId: $tournament->image_public_id,
            status: $status,
            startsAt: $startsAt,
            eventName: $eventName,
            totalParticipants: $totalParticipants,
            participantStatus: $participant->status,
            participantId: $participant->id,
            position: $position,
            points: $points,
            nextMatch: $nextMatch,
            canCheckIn: $canCheckIn,
            checkInDeadline: $checkInDeadline,
            isUpcoming: $isUpcoming,
            isInProgress: $isInProgress,
            isPast: $isPast,
        );
    }

    /**
     * Get the next pending match for a participant.
     *
     * @return array{matchId: string, roundNumber: int, tableNumber: int|null, opponentId: string|null, opponentName: string|null, isBye: bool}|null
     */
    private function getNextMatchForParticipant(string $participantId, string $tournamentId): ?array
    {
        // Find current/pending round
        $currentRound = RoundModel::query()
            ->where('tournament_id', $tournamentId)
            ->whereIn('status', [RoundStatus::Pending, RoundStatus::InProgress])
            ->orderBy('round_number', 'asc')
            ->first();

        if ($currentRound === null) {
            return null;
        }

        // Find match where participant is player 1 or player 2 with pending result
        $match = MatchModel::query()
            ->where('round_id', $currentRound->id)
            ->where('result', MatchResult::NotPlayed)
            ->where(function ($query) use ($participantId): void {
                $query->where('player_1_id', $participantId)
                    ->orWhere('player_2_id', $participantId);
            })
            ->first();

        if ($match === null) {
            return null;
        }

        // Determine opponent
        $opponentId = null;
        $opponentName = null;
        $isBye = $match->player_2_id === null;

        if (! $isBye) {
            $opponentId = $match->player_1_id === $participantId
                ? $match->player_2_id
                : $match->player_1_id;

            // Get opponent name
            $opponent = ParticipantModel::query()
                ->with('user')
                ->where('id', $opponentId)
                ->first();

            if ($opponent !== null) {
                $opponentName = $opponent->user?->name ?? $opponent->guest_name;
            }
        }

        return [
            'matchId' => $match->id,
            'roundNumber' => $currentRound->round_number,
            'tableNumber' => $match->table_number,
            'opponentId' => $opponentId,
            'opponentName' => $opponentName,
            'isBye' => $isBye,
        ];
    }
}
