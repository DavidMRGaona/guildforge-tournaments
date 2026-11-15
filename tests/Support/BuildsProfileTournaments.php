<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Support;

use App\Application\Profiles\DTOs\ProfileOwnerDTO;
use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Tournaments\Domain\Enums\ParticipantStatus;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\ParticipantModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\StandingModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;

/**
 * Tournaments and registrations for the tests of the public profile's section and counter.
 */
trait BuildsProfileTournaments
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    private function tournament(TournamentStatus $status = TournamentStatus::InProgress, array $attributes = [], ?EventModel $event = null): TournamentModel
    {
        $event ??= EventModel::factory()->published()->create(['start_date' => now()->addDays(3), 'end_date' => now()->addDays(3)->addHours(6)]);

        return TournamentModel::create([
            'event_id' => $event->id,
            'name' => 'Torneo '.Str::random(6),
            'slug' => 'torneo-'.Str::lower(Str::random(10)),
            'status' => $status,
            'allow_guests' => false,
            'result_reporting' => 'admin_only',
            'notification_email' => 'organizacion@example.com',
            'show_participants' => true,
            ...$attributes,
        ]);
    }

    private function enroll(TournamentModel $tournament, UserModel $user, ParticipantStatus $status = ParticipantStatus::Confirmed, ?int $rank = null, float $points = 0.0): ParticipantModel
    {
        $participant = ParticipantModel::create([
            'id' => (string) Str::uuid(),
            'tournament_id' => $tournament->id,
            'user_id' => $user->id,
            'status' => $status->value,
            'registered_at' => now()->subDay(),
        ]);

        if ($rank !== null) {
            StandingModel::create([
                'id' => (string) Str::uuid(),
                'tournament_id' => $tournament->id,
                'participant_id' => $participant->id,
                'rank' => $rank,
                'points' => $points,
            ]);
        }

        return $participant;
    }

    private function organize(TournamentModel $tournament, UserModel $user): void
    {
        DB::table('tournaments_organizers')->insert(['tournament_id' => $tournament->id, 'user_id' => $user->id]);
    }

    private function ownerDto(UserModel $user): ProfileOwnerDTO
    {
        return new ProfileOwnerDTO((string) $user->id, 'ana-garcia', 'Ana García');
    }
}
