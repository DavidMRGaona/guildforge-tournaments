<?php

declare(strict_types=1);

namespace Modules\Tournaments\Database\Seeders;

use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Modules\Tournaments\Application\Services\StandingsServiceInterface;
use Modules\Tournaments\Domain\Enums\MatchResult;
use Modules\Tournaments\Domain\Enums\ParticipantStatus;
use Modules\Tournaments\Domain\Enums\ResultReporting;
use Modules\Tournaments\Domain\Enums\RoundStatus;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\GameProfileModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\MatchModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\ParticipantModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\RoundModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use Modules\Tournaments\TournamentsServiceProvider;

/**
 * Seeds development/test data for tournaments module.
 * Only runs in local/testing environments.
 */
final class DevelopmentSeeder extends Seeder
{
    /** @var list<EventModel> */
    private array $events = [];

    /** @var list<UserModel> */
    private array $users = [];

    /** @var array<string, TournamentModel> */
    private array $tournaments = [];

    /** @var array<string, array<int, ParticipantModel>> */
    private array $participants = [];

    /** @var array<string, array<int, RoundModel>> */
    private array $rounds = [];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        // Ensure game profiles exist
        $this->call(GameProfilesSeeder::class);

        // Load dependencies
        $this->events = array_values(EventModel::query()->orderBy('start_date')->take(3)->get()->all());
        $this->users = array_values(UserModel::query()->take(6)->get()->all());

        if (count($this->events) < 3 || count($this->users) < 6) {
            return;
        }

        $this->seedTournaments();
        $this->seedParticipants();
        $this->seedRounds();
        $this->seedMatches();

        // The standings are the module's own, computed from the results above (as the panel
        // does after each result), never written by hand
        $standings = $this->standingsService();
        $standings->recalculate($this->tournaments['liga-w40k']->id);
        $standings->recalculate($this->tournaments['campeonato-clausurado']->id);
    }

    /**
     * The module's standings service. When the module is being enabled its seeders run before
     * its provider is registered: its bindings are all this needs (nothing from boot()).
     */
    private function standingsService(): StandingsServiceInterface
    {
        if (! app()->bound(StandingsServiceInterface::class)) {
            (new TournamentsServiceProvider(app()))->register();
        }

        return app(StandingsServiceInterface::class);
    }

    private function seedTournaments(): void
    {
        $w40kProfile = GameProfileModel::where('slug', 'warhammer-40k')->first();
        $mtgProfile = GameProfileModel::where('slug', 'magic-the-gathering')->first();
        $genericProfile = GameProfileModel::where('slug', 'generic')->first();

        $tournamentsData = [
            'liga-w40k' => [
                'event_id' => $this->events[0]->id,
                'game_profile_id' => $w40kProfile?->id,
                'name' => 'Liga Warhammer 40K - Enero 2026',
                'slug' => 'liga-w40k-enero-2026',
                'description' => 'Liga mensual de Warhammer 40.000 con formato suizo a 3 rondas.',
                'status' => TournamentStatus::InProgress,
                'max_rounds' => 3,
                'current_round' => 3,
                'max_participants' => 16,
                'min_participants' => 4,
                'allow_guests' => true,
                'result_reporting' => ResultReporting::PlayersWithConfirmation,
                'requires_check_in' => true,
                'check_in_starts_before' => 30,
                'notification_email' => '',
                'registration_opens_at' => now()->subDays(14),
                'registration_closes_at' => now()->subDays(1),
                'started_at' => now()->subHours(4),
            ],
            'torneo-mtg' => [
                'event_id' => $this->events[1]->id,
                'game_profile_id' => $mtgProfile?->id,
                'name' => 'Torneo de Magic: The Gathering',
                'slug' => 'torneo-mtg-febrero-2026',
                'description' => 'Torneo estándar de Magic con premios para los 3 primeros clasificados.',
                'status' => TournamentStatus::RegistrationOpen,
                'max_rounds' => 4,
                'current_round' => 0,
                'max_participants' => 32,
                'min_participants' => 8,
                'allow_guests' => false,
                'result_reporting' => ResultReporting::AdminOnly,
                'requires_check_in' => false,
                'notification_email' => '',
                'registration_opens_at' => now()->subDays(7),
                'registration_closes_at' => now()->addDays(7),
            ],
            'campeonato-clausurado' => [
                'event_id' => $this->events[2]->id,
                'game_profile_id' => $genericProfile?->id,
                'name' => 'Campeonato de juegos de mesa',
                'slug' => 'campeonato-juegos-mesa-2025',
                'description' => 'Campeonato anual de juegos de mesa de la asociación.',
                'status' => TournamentStatus::Finished,
                'max_rounds' => 3,
                'current_round' => 3,
                'max_participants' => 12,
                'min_participants' => 4,
                'allow_guests' => false,
                'result_reporting' => ResultReporting::AdminOnly,
                'requires_check_in' => true,
                'check_in_starts_before' => 60,
                'notification_email' => '',
                'registration_opens_at' => now()->subMonths(2),
                'registration_closes_at' => now()->subMonths(1)->subDays(15),
                'started_at' => now()->subMonths(1),
                'completed_at' => now()->subMonths(1)->addHours(6),
            ],
        ];

        foreach ($tournamentsData as $key => $data) {
            $this->tournaments[$key] = TournamentModel::firstOrCreate(
                ['slug' => $data['slug']],
                array_merge($data, ['id' => Str::uuid()->toString()]),
            );
        }
    }

    private function seedParticipants(): void
    {
        // Liga W40K: 8 participantes (6 usuarios + 2 invitados)
        $this->participants['liga-w40k'] = [];
        foreach ($this->users as $index => $user) {
            $this->participants['liga-w40k'][] = $this->createParticipant(
                $this->tournaments['liga-w40k'],
                $user,
                ParticipantStatus::CheckedIn,
                $index + 1,
            );
        }
        // 2 invitados
        $this->participants['liga-w40k'][] = $this->createGuestParticipant(
            $this->tournaments['liga-w40k'],
            'Carlos García',
            'carlos.garcia@example.com',
            ParticipantStatus::CheckedIn,
            7,
        );
        $this->participants['liga-w40k'][] = $this->createGuestParticipant(
            $this->tournaments['liga-w40k'],
            'Ana Martínez',
            'ana.martinez@example.com',
            ParticipantStatus::CheckedIn,
            8,
        );

        // Torneo MTG: 4 participantes (2 confirmed, 2 registered)
        $this->participants['torneo-mtg'] = [];
        for ($i = 0; $i < 4; $i++) {
            $status = $i < 2 ? ParticipantStatus::Confirmed : ParticipantStatus::Registered;
            $this->participants['torneo-mtg'][] = $this->createParticipant(
                $this->tournaments['torneo-mtg'],
                $this->users[$i],
                $status,
            );
        }

        // Campeonato clausurado: 6 participantes
        $this->participants['campeonato-clausurado'] = [];
        foreach ($this->users as $index => $user) {
            $this->participants['campeonato-clausurado'][] = $this->createParticipant(
                $this->tournaments['campeonato-clausurado'],
                $user,
                ParticipantStatus::CheckedIn,
                $index + 1,
            );
        }
    }

    private function createParticipant(
        TournamentModel $tournament,
        UserModel $user,
        ParticipantStatus $status,
        ?int $seed = null,
    ): ParticipantModel {
        return ParticipantModel::firstOrCreate(
            [
                'tournament_id' => $tournament->id,
                'user_id' => $user->id,
            ],
            [
                'id' => Str::uuid()->toString(),
                'status' => $status,
                'seed' => $seed,
                'has_received_bye' => false,
                'registered_at' => now()->subDays(rand(1, 10)),
                'checked_in_at' => $status === ParticipantStatus::CheckedIn ? now() : null,
            ],
        );
    }

    private function createGuestParticipant(
        TournamentModel $tournament,
        string $name,
        string $email,
        ParticipantStatus $status,
        ?int $seed = null,
    ): ParticipantModel {
        return ParticipantModel::firstOrCreate(
            [
                'tournament_id' => $tournament->id,
                'guest_email' => $email,
            ],
            [
                'id' => Str::uuid()->toString(),
                'guest_name' => $name,
                'status' => $status,
                'seed' => $seed,
                'has_received_bye' => false,
                'registered_at' => now()->subDays(rand(1, 10)),
                'checked_in_at' => $status === ParticipantStatus::CheckedIn ? now() : null,
            ],
        );
    }

    private function seedRounds(): void
    {
        // Liga W40K: 3 rondas (2 finished, 1 in_progress)
        $this->rounds['liga-w40k'] = [
            $this->createRound($this->tournaments['liga-w40k'], 1, RoundStatus::Finished),
            $this->createRound($this->tournaments['liga-w40k'], 2, RoundStatus::Finished),
            $this->createRound($this->tournaments['liga-w40k'], 3, RoundStatus::InProgress),
        ];

        // Campeonato clausurado: 3 rondas (todas finished)
        $this->rounds['campeonato-clausurado'] = [
            $this->createRound($this->tournaments['campeonato-clausurado'], 1, RoundStatus::Finished),
            $this->createRound($this->tournaments['campeonato-clausurado'], 2, RoundStatus::Finished),
            $this->createRound($this->tournaments['campeonato-clausurado'], 3, RoundStatus::Finished),
        ];
    }

    private function createRound(TournamentModel $tournament, int $number, RoundStatus $status): RoundModel
    {
        $startedAt = $status !== RoundStatus::Pending ? now()->subHours(4 - $number) : null;
        $completedAt = $status === RoundStatus::Finished ? now()->subHours(3 - $number) : null;

        return RoundModel::firstOrCreate(
            [
                'tournament_id' => $tournament->id,
                'round_number' => $number,
            ],
            [
                'id' => Str::uuid()->toString(),
                'status' => $status,
                'started_at' => $startedAt,
                'completed_at' => $completedAt,
            ],
        );
    }

    private function seedMatches(): void
    {
        $this->seedLigaW40kMatches();
        $this->seedCampeonatoMatches();
    }

    private function seedLigaW40kMatches(): void
    {
        $participants = $this->participants['liga-w40k'];
        $rounds = $this->rounds['liga-w40k'];

        // Swiss pairing: players on the same points meet, nobody meets the same opponent twice
        // Round 1 (by seed): P1 vs P8, P2 vs P7, P3 vs P6, P4 vs P5
        $this->createMatch($rounds[0], $participants[0], $participants[7], MatchResult::PlayerOneWin, 1, 85, 60);
        $this->createMatch($rounds[0], $participants[1], $participants[6], MatchResult::PlayerOneWin, 2, 78, 45);
        $this->createMatch($rounds[0], $participants[2], $participants[5], MatchResult::PlayerTwoWin, 3, 55, 72);
        $this->createMatch($rounds[0], $participants[3], $participants[4], MatchResult::Draw, 4, 65, 65);

        // Round 2, after P1/P2/P6 3 pts, P4/P5 1 pt, P3/P7/P8 0 pts
        $this->createMatch($rounds[1], $participants[0], $participants[1], MatchResult::PlayerTwoWin, 1, 62, 80);
        $this->createMatch($rounds[1], $participants[5], $participants[3], MatchResult::PlayerOneWin, 2, 75, 50);
        $this->createMatch($rounds[1], $participants[4], $participants[2], MatchResult::Draw, 3, 60, 60);
        $this->createMatch($rounds[1], $participants[6], $participants[7], MatchResult::PlayerOneWin, 4, 70, 55);

        // Round 3 (in progress, not played yet), after P2/P6 6 pts, P1/P7 3 pts, P5 2 pts, P3/P4 1 pt, P8 0 pts
        $this->createMatch($rounds[2], $participants[1], $participants[5], MatchResult::NotPlayed, 1);
        $this->createMatch($rounds[2], $participants[0], $participants[6], MatchResult::NotPlayed, 2);
        $this->createMatch($rounds[2], $participants[4], $participants[7], MatchResult::NotPlayed, 3);
        $this->createMatch($rounds[2], $participants[2], $participants[3], MatchResult::NotPlayed, 4);
    }

    private function seedCampeonatoMatches(): void
    {
        $participants = $this->participants['campeonato-clausurado'];
        $rounds = $this->rounds['campeonato-clausurado'];

        // Swiss pairing with 6 players (no byes); nobody meets the same opponent twice
        // Round 1 (by seed): P1 vs P6, P2 vs P5, P3 vs P4
        $this->createMatch($rounds[0], $participants[0], $participants[5], MatchResult::PlayerOneWin, 1);
        $this->createMatch($rounds[0], $participants[1], $participants[4], MatchResult::PlayerOneWin, 2);
        $this->createMatch($rounds[0], $participants[2], $participants[3], MatchResult::PlayerTwoWin, 3);

        // Round 2, after P1/P2/P4 3 pts, P3/P5/P6 0 pts
        $this->createMatch($rounds[1], $participants[0], $participants[1], MatchResult::Draw, 1);
        $this->createMatch($rounds[1], $participants[3], $participants[5], MatchResult::PlayerOneWin, 2);
        $this->createMatch($rounds[1], $participants[2], $participants[4], MatchResult::PlayerTwoWin, 3);

        // Round 3 (final), after P4 6 pts, P1/P2 4 pts, P5 3 pts, P3/P6 0 pts
        $this->createMatch($rounds[2], $participants[0], $participants[3], MatchResult::PlayerOneWin, 1);
        $this->createMatch($rounds[2], $participants[1], $participants[2], MatchResult::PlayerTwoWin, 2);
        $this->createMatch($rounds[2], $participants[4], $participants[5], MatchResult::PlayerOneWin, 3);
    }

    private function createMatch(
        RoundModel $round,
        ParticipantModel $player1,
        ParticipantModel $player2,
        MatchResult $result,
        int $tableNumber,
        ?int $p1Score = null,
        ?int $p2Score = null,
    ): MatchModel {
        $isPlayed = $result !== MatchResult::NotPlayed;
        $user = $this->users[0];

        return MatchModel::firstOrCreate(
            [
                'round_id' => $round->id,
                'player_1_id' => $player1->id,
                'player_2_id' => $player2->id,
            ],
            [
                'id' => Str::uuid()->toString(),
                'table_number' => $tableNumber,
                'result' => $result,
                'player_1_score' => $p1Score,
                'player_2_score' => $p2Score,
                'reported_by_id' => $isPlayed ? $user->id : null,
                'reported_at' => $isPlayed ? now()->subHours(rand(1, 3)) : null,
                'confirmed_by_id' => $isPlayed ? $user->id : null,
                'confirmed_at' => $isPlayed ? now()->subHours(rand(0, 2)) : null,
                'is_disputed' => false,
            ],
        );
    }
}
