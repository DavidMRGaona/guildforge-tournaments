<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Support;

use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Support\Str;
use Modules\Tournaments\Domain\Enums\ParticipantStatus;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\GameProfileModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\ParticipantModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\StandingModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;

/**
 * Finished tournaments with their players, for the tests of the activity source (core#774). Builds on
 * BuildsProfileTournaments.
 */
trait BuildsFinishedTournaments
{
    use BuildsProfileTournaments;

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function finishedTournament(array $attributes = [], ?EventModel $event = null): TournamentModel
    {
        return $this->tournament(TournamentStatus::Finished, ['completed_at' => now()->subDay(), ...$attributes], $event);
    }

    private function gameProfile(string $name = 'Juego de prueba'): GameProfileModel
    {
        return GameProfileModel::create([
            'id' => (string) Str::uuid(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'stat_definitions' => [],
            'scoring_rules' => [],
            'tiebreaker_config' => [],
            'pairing_config' => [],
            'is_system' => false,
        ]);
    }

    /**
     * Somebody with an account who played: a participant that took part in a match and stands
     * at the given rank.
     */
    private function player(TournamentModel $tournament, int $rank, float $points = 0.0, ParticipantStatus $status = ParticipantStatus::Confirmed, ?UserModel $user = null): ParticipantModel
    {
        $participant = $this->enroll($tournament, $user ?? UserModel::factory()->create(), $status, $rank, $points);
        $this->playMatch($tournament, $participant, null);

        return $participant;
    }

    /**
     * A guest without an account who played.
     */
    private function guestPlayer(TournamentModel $tournament, int $rank, float $points = 0.0): ParticipantModel
    {
        $guest = ParticipantModel::create([
            'id' => (string) Str::uuid(),
            'tournament_id' => $tournament->id,
            'user_id' => null,
            'guest_name' => 'Invitada',
            'guest_email' => 'invitada@example.com',
            'status' => ParticipantStatus::Confirmed->value,
            'registered_at' => now()->subDay(),
        ]);
        StandingModel::create([
            'id' => (string) Str::uuid(),
            'tournament_id' => $tournament->id,
            'participant_id' => $guest->id,
            'rank' => $rank,
            'points' => $points,
        ]);
        $this->playMatch($tournament, $guest, null);

        return $guest;
    }
}
