<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Integration\Infrastructure\Profiles;

use App\Application\Exceptions\NothingToDrawException;
use App\Application\Profiles\Services\ProfileRendererInterface;
use App\Application\Profiles\Services\ProfileSectionRegistryInterface;
use App\Application\Services\TimezoneServiceInterface;
use App\Domain\Profiles\ProfileRegion;
use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Tournaments\Domain\Enums\MatchResult;
use Modules\Tournaments\Domain\Enums\ParticipantStatus;
use Modules\Tournaments\Domain\Enums\RoundStatus;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\MatchModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\MatchReportModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\RoundModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use Modules\Tournaments\Infrastructure\Profiles\TournamentsProfileSection;
use Modules\Tournaments\Tests\Support\BuildsProfileTournaments;
use Modules\Tournaments\Tests\Support\PinsTheClock;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Modules\ModuleTestCase;

/**
 * The «Torneos» section of the public profile: only what the person looking could already see on
 * the tournament's own page.
 */
final class TournamentsProfileSectionTest extends ModuleTestCase
{
    use BuildsProfileTournaments;
    use PinsTheClock;

    protected ?string $moduleName = 'tournaments';

    protected bool $autoEnableModule = true;

    public function test_it_lists_what_is_under_way_what_comes_next_and_the_latest_results(): void
    {
        $owner = UserModel::factory()->create();
        $underWay = $this->tournament(TournamentStatus::InProgress, ['name' => 'Liga de otoño', 'current_round' => 3]);
        $this->enroll($underWay, $owner, rank: 2, points: 7.0);
        $next = $this->tournament(TournamentStatus::RegistrationOpen, ['name' => 'Copa de invierno']);
        $this->enroll($next, $owner, ParticipantStatus::Registered);
        $over = $this->tournament(TournamentStatus::Finished, ['name' => 'Torneo de verano']);
        $this->enroll($over, $owner, rank: 1, points: 12.0);

        $props = $this->props($owner, null);

        $this->assertSame(['Liga de otoño'], array_column($props['ongoing'], 'name'));
        $this->assertSame(3, $props['ongoing'][0]['round']);
        $this->assertSame(2, $props['ongoing'][0]['position']);
        $this->assertSame(7.0, $props['ongoing'][0]['points']);
        $this->assertSame(['Copa de invierno'], array_column($props['upcoming'], 'name'));
        $this->assertNull($props['upcoming'][0]['position']);
        $this->assertSame(['Torneo de verano'], array_column($props['recent'], 'name'));
        $this->assertSame(1, $props['recent'][0]['position']);
        $this->assertSame(12.0, $props['recent'][0]['points']);
    }

    public function test_a_tournament_links_to_its_public_page_and_names_its_event_and_date(): void
    {
        $owner = UserModel::factory()->create();
        $event = EventModel::factory()->published()->create(['title' => 'Jornadas de otoño', 'start_date' => now()->addDays(5)]);
        $tournament = $this->tournament(TournamentStatus::RegistrationOpen, [], $event);
        $this->enroll($tournament, $owner);

        $item = $this->props($owner, null)['upcoming'][0];

        $this->assertSame(route('tournaments.show', $tournament->slug, false), $item['url']);
        $this->assertSame('Jornadas de otoño', $item['eventTitle']);
        $this->assertSame(
            app(TimezoneServiceInterface::class)->iso($event->start_date),
            $item['startsAt'],
        );
    }

    public function test_the_next_ones_go_by_the_date_of_their_event_and_the_ones_under_way_come_first(): void
    {
        $owner = UserModel::factory()->create();
        $later = $this->tournament(TournamentStatus::RegistrationOpen, ['name' => 'Después'], EventModel::factory()->published()->create(['start_date' => now()->addDays(20)]));
        $sooner = $this->tournament(TournamentStatus::RegistrationClosed, ['name' => 'Antes'], EventModel::factory()->published()->create(['start_date' => now()->addDays(2)]));
        $this->enroll($later, $owner);
        $this->enroll($sooner, $owner);

        $this->assertSame(['Antes', 'Después'], array_column($this->props($owner, null)['upcoming'], 'name'));
    }

    public function test_the_latest_results_are_the_most_recent_five(): void
    {
        $owner = UserModel::factory()->create();
        foreach (range(1, 7) as $days) {
            $event = EventModel::factory()->published()->create(['start_date' => now()->subDays($days), 'end_date' => now()->subDays($days)->addHours(4)]);
            $this->enroll($this->tournament(TournamentStatus::Finished, ['name' => "Hace {$days}"], $event), $owner, rank: 1);
        }

        $this->assertSame(
            ['Hace 1', 'Hace 2', 'Hace 3', 'Hace 4', 'Hace 5'],
            array_column($this->props($owner, null)['recent'], 'name'),
        );
    }

    public function test_drafts_cancelled_tournaments_and_unpublished_events_never_show(): void
    {
        $owner = UserModel::factory()->create();
        $this->enroll($this->tournament(TournamentStatus::Draft), $owner);
        $this->enroll($this->tournament(TournamentStatus::Cancelled), $owner);
        $this->enroll($this->tournament(TournamentStatus::InProgress, [], EventModel::factory()->draft()->create()), $owner, rank: 1);
        $this->enroll($this->tournament(TournamentStatus::Finished, [], EventModel::factory()->draft()->create()), $owner, rank: 1);
        $shown = $this->tournament(TournamentStatus::InProgress, ['name' => 'Publicado']);
        $this->enroll($shown, $owner, rank: 1);

        $props = $this->props($owner, null);

        $this->assertSame(['Publicado'], array_column($props['ongoing'], 'name'));
        $this->assertSame([], $props['upcoming']);
        $this->assertSame([], $props['recent']);
    }

    public function test_before_it_starts_only_active_registrations_count(): void
    {
        $owner = UserModel::factory()->create();
        foreach ([ParticipantStatus::Withdrawn, ParticipantStatus::Disqualified, ParticipantStatus::Expired] as $status) {
            $this->enroll($this->tournament(TournamentStatus::RegistrationOpen), $owner, $status);
        }

        $this->expectException(NothingToDrawException::class);

        $this->props($owner, null);
    }

    // Once started the page shows only the standings, whoever hides the participants, and only the
    // people in them. The standings are the module's own (recalculated, never written by hand here).

    #[DataProvider('startedStatuses')]
    public function test_a_registration_never_approved_is_not_in_the_standings_so_it_does_not_show(TournamentStatus $status): void
    {
        $owner = UserModel::factory()->create();
        $tournament = $this->tournament($status, ['show_participants' => false]);
        $this->enroll($tournament, $owner, ParticipantStatus::Registered);
        $this->enroll($tournament, UserModel::factory()->create(), ParticipantStatus::Confirmed);
        $this->recalculateStandings($tournament);

        $this->assertNothingToDraw($owner, null);
        $this->assertNothingToDraw($owner, UserModel::factory()->create());
        $this->assertNothingToDraw($owner, $owner);
    }

    #[DataProvider('startedStatuses')]
    public function test_a_tournament_without_standings_yet_shows_nobody(TournamentStatus $status): void
    {
        $owner = UserModel::factory()->create();
        $tournament = $this->tournament($status, ['show_participants' => false]);
        $this->enroll($tournament, $owner, ParticipantStatus::Confirmed);

        $this->assertNothingToDraw($owner, null);
        $this->assertNothingToDraw($owner, $owner);

        $this->recalculateStandings($tournament);

        $this->assertNotSame([], $this->profileUrls($owner, null));
        $this->assertNotSame([], $this->profileUrls($owner, $owner));
    }

    /**
     * @return array<string, array{TournamentStatus}>
     */
    public static function startedStatuses(): array
    {
        return [
            'under way' => [TournamentStatus::InProgress],
            'finished' => [TournamentStatus::Finished],
        ];
    }

    public function test_a_withdrawn_or_disqualified_player_who_already_played_keeps_their_place(): void
    {
        $withdrawn = UserModel::factory()->create();
        $disqualified = UserModel::factory()->create();
        $tournament = $this->tournament(TournamentStatus::InProgress, ['show_participants' => false, 'name' => 'Con baja']);
        $one = $this->enroll($tournament, $withdrawn, ParticipantStatus::Withdrawn);
        $two = $this->enroll($tournament, $disqualified, ParticipantStatus::Disqualified);
        $other = $this->enroll($tournament, UserModel::factory()->create(), ParticipantStatus::Confirmed);
        $this->playMatch($tournament, $one, $other);
        $this->playMatch($tournament, $two, null);
        $this->recalculateStandings($tournament);

        $this->assertSame(['Con baja'], array_column($this->props($withdrawn, null)['ongoing'], 'name'));
        $this->assertSame(['Con baja'], array_column($this->props($disqualified, null)['ongoing'], 'name'));
    }

    public function test_a_withdrawn_player_who_never_played_does_not_show(): void
    {
        $owner = UserModel::factory()->create();
        $tournament = $this->tournament(TournamentStatus::InProgress, ['show_participants' => false]);
        $this->enroll($tournament, $owner, ParticipantStatus::Withdrawn);
        $this->enroll($tournament, UserModel::factory()->create(), ParticipantStatus::Confirmed);
        $this->recalculateStandings($tournament);

        $this->assertNothingToDraw($owner, null);
        $this->assertNothingToDraw($owner, $owner);
    }

    public function test_the_profile_and_the_page_agree_on_who_is_in_the_standings(): void
    {
        $approved = UserModel::factory()->create();
        $pending = UserModel::factory()->create();
        $tournament = $this->tournament(TournamentStatus::InProgress, ['show_participants' => false]);
        $this->enroll($tournament, $approved, ParticipantStatus::Confirmed);
        $this->enroll($tournament, $pending, ParticipantStatus::Registered);
        $this->recalculateStandings($tournament);

        $onThePage = [];
        $this->get(route('tournaments.show', $tournament->slug))
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$onThePage): void {
                $onThePage = $page->toArray()['props'];
            });

        $this->assertCount(1, $onThePage['standings']);
        $this->assertNotSame([], $this->profileUrls($approved, null));
        $this->assertSame([], $this->profileUrls($pending, null));
    }

    public function test_nothing_to_show_draws_nothing(): void
    {
        $this->expectException(NothingToDrawException::class);

        $this->props(UserModel::factory()->create(), null);
    }

    public function test_the_registrations_of_somebody_else_are_never_listed(): void
    {
        $owner = UserModel::factory()->create();
        $other = UserModel::factory()->create();
        $this->enroll($this->tournament(TournamentStatus::InProgress, ['name' => 'De otra persona']), $other, rank: 1);
        $mine = $this->tournament(TournamentStatus::InProgress, ['name' => 'Mío']);
        $this->enroll($mine, $owner, rank: 2);
        $this->enroll($mine, $other, rank: 1);

        $this->assertSame(['Mío'], array_column($this->props($owner, null)['ongoing'], 'name'));
        $this->assertSame(2, $this->props($owner, null)['ongoing'][0]['position']);
    }

    // Visibility: the tournament page lists the participants of an upcoming tournament only when the
    // organisation shows them, and the standings of one under way or over to everybody. Their own
    // registration is the one thing a person always sees about themselves.

    /**
     * @return array<string, array{string}>
     */
    public static function viewers(): array
    {
        return [
            'a guest' => ['guest'],
            'a signed-in member' => ['member'],
            'an organizer of the tournament' => ['organizer'],
            'another participant' => ['participant'],
        ];
    }

    #[DataProvider('viewers')]
    public function test_hidden_participants_stay_hidden_from_everybody_but_the_owner(string $kind): void
    {
        $owner = UserModel::factory()->create();
        $hidden = $this->tournament(TournamentStatus::RegistrationOpen, ['show_participants' => false, 'name' => 'Con lista oculta']);
        $this->enroll($hidden, $owner);
        $viewer = $this->viewer($kind, $hidden);

        $this->assertNothingToDraw($owner, $viewer);

        // The owner looking at their own profile sees their own registration, like on the page
        $this->assertSame(['Con lista oculta'], array_column($this->props($owner, $owner)['upcoming'], 'name'));
    }

    #[DataProvider('viewers')]
    public function test_a_tournament_under_way_or_over_shows_to_everybody_even_with_hidden_participants(string $kind): void
    {
        $owner = UserModel::factory()->create();
        $underWay = $this->tournament(TournamentStatus::InProgress, ['show_participants' => false, 'name' => 'En curso']);
        $over = $this->tournament(TournamentStatus::Finished, ['show_participants' => false, 'name' => 'Terminado']);
        $this->enroll($underWay, $owner, rank: 4, points: 3.0);
        $this->enroll($over, $owner, rank: 2, points: 9.0);
        $viewer = $this->viewer($kind, $underWay);

        $props = $this->props($owner, $viewer);

        $this->assertSame(['En curso'], array_column($props['ongoing'], 'name'));
        $this->assertSame(['Terminado'], array_column($props['recent'], 'name'));
    }

    #[DataProvider('viewers')]
    public function test_a_visible_list_shows_the_registration_to_everybody(string $kind): void
    {
        $owner = UserModel::factory()->create();
        $open = $this->tournament(TournamentStatus::RegistrationOpen, ['show_participants' => true, 'name' => 'Con lista']);
        $this->enroll($open, $owner);
        $viewer = $this->viewer($kind, $open);

        $this->assertSame(['Con lista'], array_column($this->props($owner, $viewer)['upcoming'], 'name'));
    }

    public function test_the_same_rule_as_the_tournament_page_decides(): void
    {
        $owner = UserModel::factory()->create();
        $cases = [
            'open and hidden' => $this->tournament(TournamentStatus::RegistrationOpen, ['show_participants' => false]),
            'open and shown' => $this->tournament(TournamentStatus::RegistrationOpen, ['show_participants' => true]),
            'under way and hidden' => $this->tournament(TournamentStatus::InProgress, ['show_participants' => false]),
            'over and hidden' => $this->tournament(TournamentStatus::Finished, ['show_participants' => false]),
        ];
        foreach ($cases as $tournament) {
            $this->enroll($tournament, $owner, rank: 1);
        }

        $onTheProfile = $this->profileUrls($owner, null);

        foreach ($cases as $label => $tournament) {
            $onThePage = [];
            $this->get(route('tournaments.show', $tournament->slug))
                ->assertOk()
                ->assertInertia(function (Assert $page) use (&$onThePage): void {
                    $onThePage = $page->toArray()['props'];
                });

            $seenOnThePage = [...$onThePage['participants'], ...$onThePage['standings']] !== [];
            $this->assertSame(
                $seenOnThePage,
                in_array(route('tournaments.show', $tournament->slug, false), $onTheProfile, true),
                "The profile and the page disagree for a tournament that is {$label}",
            );
        }
    }

    public function test_a_report_waiting_for_confirmation_changes_nothing(): void
    {
        $owner = UserModel::factory()->create();
        $opponent = UserModel::factory()->create();
        $tournament = $this->tournament(TournamentStatus::InProgress, ['current_round' => 1, 'result_reporting' => 'players_with_confirmation']);
        $mine = $this->enroll($tournament, $owner, rank: 1, points: 0.0);
        $theirs = $this->enroll($tournament, $opponent, rank: 2, points: 0.0);
        $before = $this->props($owner, null);

        $round = RoundModel::create([
            'id' => (string) Str::uuid(),
            'tournament_id' => $tournament->id,
            'round_number' => 1,
            'status' => RoundStatus::InProgress->value,
        ]);
        $match = MatchModel::create([
            'id' => (string) Str::uuid(),
            'round_id' => $round->id,
            'player_1_id' => $mine->id,
            'player_2_id' => $theirs->id,
            'result' => MatchResult::NotPlayed->value,
        ]);
        MatchReportModel::create([
            'id' => (string) Str::uuid(),
            'match_id' => $match->id,
            'result' => MatchResult::PlayerOneWin->value,
            'player_1_score' => 2,
            'player_2_score' => 0,
            'reported_by_id' => $owner->id,
            'reported_at' => now(),
        ]);

        $this->assertSame($before, $this->props($owner, null));
        $this->assertStringNotContainsString('player_one_win', (string) json_encode($this->props($owner, null)));
    }

    public function test_the_props_carry_only_what_the_component_draws(): void
    {
        $owner = UserModel::factory()->create(['email' => 'ana-privada@example.com', 'name' => 'Ana García López']);
        $other = UserModel::factory()->create(['email' => 'rival@example.com', 'name' => 'Rival Secreto']);
        $tournament = $this->tournament(TournamentStatus::InProgress, ['current_round' => 2, 'notification_email' => 'contacto@example.com', 'description' => 'Texto interno']);
        $this->enroll($tournament, $owner, rank: 1, points: 6.0);
        $this->enroll($tournament, $other, rank: 2, points: 3.0);

        $props = $this->props($owner, null);

        $this->assertSame(
            ['eventTitle', 'id', 'name', 'points', 'position', 'round', 'startsAt', 'url'],
            $this->sortedKeys($props['ongoing'][0]),
        );
        $json = (string) json_encode($props);
        foreach (['ana-privada@example.com', 'Ana García López', 'rival@example.com', 'Rival Secreto', 'contacto@example.com', 'Texto interno', 'registered_at'] as $private) {
            $this->assertStringNotContainsString($private, $json);
        }
    }

    public function test_the_number_of_queries_does_not_grow_with_the_number_of_tournaments(): void
    {
        $owner = UserModel::factory()->create();
        $this->enroll($this->tournament(TournamentStatus::InProgress), $owner, rank: 1);
        $this->enroll($this->tournament(TournamentStatus::RegistrationOpen), $owner);
        $this->enroll($this->tournament(TournamentStatus::Finished), $owner, rank: 1);
        $few = $this->queriesOfProps($owner);

        foreach (range(1, 8) as $ignored) {
            $this->enroll($this->tournament(TournamentStatus::InProgress), $owner, rank: 1);
            $this->enroll($this->tournament(TournamentStatus::RegistrationClosed), $owner);
            $this->enroll($this->tournament(TournamentStatus::Finished), $owner, rank: 1);
        }

        $this->assertSame($few, $this->queriesOfProps($owner));
        $this->assertLessThanOrEqual(6, $few);
    }

    public function test_the_section_is_offered_in_the_main_column_and_drawn_by_the_core_for_the_viewer(): void
    {
        $registered = $this->app->make(ProfileSectionRegistryInterface::class)->find('tournaments.tournaments');
        $this->assertNotNull($registered);
        $this->assertSame('tournaments', $registered->module);
        $this->assertSame(TournamentsProfileSection::class, $registered->class);

        $owner = UserModel::factory()->create();
        $hidden = $this->tournament(TournamentStatus::RegistrationOpen, ['show_participants' => false]);
        $this->enroll($hidden, $owner);
        $this->enroll($this->tournament(TournamentStatus::InProgress, ['name' => 'Visible']), $owner, rank: 1);

        $profile = $this->app->make(ProfileRendererInterface::class)->render($this->ownerDto($owner), null);

        $section = $profile->main[0] ?? null;
        $this->assertNotNull($section);
        $this->assertSame('tournaments.tournaments', $section->key);
        $this->assertSame('components/profile/PublicProfileTournamentsSection.vue', $section->component);
        $this->assertSame(['Visible'], array_column($section->props['ongoing'], 'name'));
        $this->assertSame([], $section->props['upcoming']);
    }

    public function test_the_section_describes_itself_for_the_profile_editor(): void
    {
        $section = $this->app->make(TournamentsProfileSection::class);

        $this->assertSame('tournaments.tournaments', $section->key());
        $this->assertSame(ProfileRegion::Main, $section->region());
        $this->assertTrue($section->enabledByDefault());
        $this->assertTrue($section->isAvailable());
        $this->assertSame('components/profile/PublicProfileTournamentsSection.vue', $section->component());
        $this->assertNotSame('', $section->label());
        $this->assertStringNotContainsString('tournaments::', $section->label().$section->description());
    }

    /**
     * @return array{ongoing: list<array<string, mixed>>, upcoming: list<array<string, mixed>>, recent: list<array<string, mixed>>}
     */
    private function props(UserModel $owner, ?Authenticatable $viewer): array
    {
        /** @var array{ongoing: list<array<string, mixed>>, upcoming: list<array<string, mixed>>, recent: list<array<string, mixed>>} $props */
        $props = $this->app->make(TournamentsProfileSection::class)->props($this->ownerDto($owner), $viewer);

        return $props;
    }

    private function assertNothingToDraw(UserModel $owner, ?Authenticatable $viewer): void
    {
        try {
            $this->props($owner, $viewer);
            $this->fail('Nothing should have been drawn for this viewer');
        } catch (NothingToDrawException) {
            $this->addToAssertionCount(1);
        }
    }

    /**
     * @return list<string>
     */
    private function profileUrls(UserModel $owner, ?Authenticatable $viewer): array
    {
        try {
            $props = $this->props($owner, $viewer);
        } catch (NothingToDrawException) {
            return [];
        }

        return array_column([...$props['ongoing'], ...$props['upcoming'], ...$props['recent']], 'url');
    }

    private function viewer(string $kind, TournamentModel $tournament): ?UserModel
    {
        if ($kind === 'guest') {
            return null;
        }

        $viewer = UserModel::factory()->create();

        if ($kind === 'organizer') {
            $this->organize($tournament, $viewer);
        }

        if ($kind === 'participant') {
            $this->enroll($tournament, $viewer);
        }

        return $viewer;
    }

    private function queriesOfProps(UserModel $owner): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->props($owner, null);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return list<string>
     */
    private function sortedKeys(array $item): array
    {
        $keys = array_keys($item);
        sort($keys);

        return $keys;
    }
}
