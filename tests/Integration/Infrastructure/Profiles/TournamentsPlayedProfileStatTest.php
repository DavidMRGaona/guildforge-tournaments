<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Integration\Infrastructure\Profiles;

use App\Application\Profiles\DTOs\ProfileStatDTO;
use App\Application\Profiles\Services\ProfileRendererInterface;
use App\Application\Profiles\Services\ProfileStatRegistryInterface;
use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Support\Facades\DB;
use Modules\Tournaments\Domain\Enums\ParticipantStatus;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Infrastructure\Profiles\TournamentsPlayedProfileStat;
use Modules\Tournaments\Tests\Support\BuildsProfileTournaments;
use Modules\Tournaments\Tests\Support\PinsTheClock;
use Tests\Support\Modules\ModuleTestCase;

/**
 * The «Torneos jugados» counter of the public profile: the finished tournaments the person took
 * part in that anybody can see.
 */
final class TournamentsPlayedProfileStatTest extends ModuleTestCase
{
    use BuildsProfileTournaments;
    use PinsTheClock;

    protected ?string $moduleName = 'tournaments';

    protected bool $autoEnableModule = true;

    public function test_it_counts_the_finished_tournaments_of_the_owner(): void
    {
        $owner = UserModel::factory()->create();
        $this->enroll($this->tournament(TournamentStatus::Finished), $owner, rank: 1);
        $this->enroll($this->tournament(TournamentStatus::Finished), $owner, rank: 3);
        $this->enroll($this->tournament(TournamentStatus::Finished), UserModel::factory()->create(), rank: 1);

        $stat = $this->stat($owner);

        $this->assertNotNull($stat);
        $this->assertSame(2, $stat->value);
    }

    public function test_it_does_not_link_anywhere_since_the_list_has_no_filter_by_person(): void
    {
        $owner = UserModel::factory()->create();
        $this->enroll($this->tournament(TournamentStatus::Finished), $owner, rank: 1);

        $this->assertNull($this->stat($owner)?->url);
    }

    public function test_what_is_not_over_or_not_public_does_not_count(): void
    {
        $owner = UserModel::factory()->create();
        $this->enroll($this->tournament(TournamentStatus::InProgress), $owner, rank: 1);
        $this->enroll($this->tournament(TournamentStatus::RegistrationOpen), $owner);
        $this->enroll($this->tournament(TournamentStatus::RegistrationClosed), $owner);
        $this->enroll($this->tournament(TournamentStatus::Draft), $owner);
        $this->enroll($this->tournament(TournamentStatus::Cancelled), $owner);
        $this->enroll($this->tournament(TournamentStatus::Finished, [], EventModel::factory()->draft()->create()), $owner, rank: 1);
        foreach ([ParticipantStatus::Withdrawn, ParticipantStatus::Disqualified, ParticipantStatus::Expired] as $status) {
            $this->enroll($this->tournament(TournamentStatus::Finished), $owner, $status, rank: 1);
        }

        $this->assertNull($this->stat($owner));
    }

    public function test_nothing_played_is_left_out_rather_than_shown_as_zero(): void
    {
        $this->assertNull($this->stat(UserModel::factory()->create()));
    }

    public function test_everybody_sees_the_same_count_as_the_standings_of_a_finished_tournament_are_public(): void
    {
        $owner = UserModel::factory()->create();
        $hidden = $this->tournament(TournamentStatus::Finished, ['show_participants' => false]);
        $this->enroll($hidden, $owner, rank: 1);
        $organizer = UserModel::factory()->create();
        $this->organize($hidden, $organizer);

        foreach ([null, UserModel::factory()->create(), $organizer, $owner] as $viewer) {
            $stat = $this->app->make(TournamentsPlayedProfileStat::class)->stat($this->ownerDto($owner), $viewer);
            $this->assertSame(1, $stat?->value);
        }
    }

    public function test_it_is_one_query_whatever_the_number_of_tournaments(): void
    {
        $owner = UserModel::factory()->create();
        foreach (range(1, 6) as $ignored) {
            $this->enroll($this->tournament(TournamentStatus::Finished), $owner, rank: 1);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->stat($owner);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(1, $queries);
    }

    public function test_the_counter_is_offered_by_the_module_and_drawn_by_the_core(): void
    {
        $registered = $this->app->make(ProfileStatRegistryInterface::class)->find('tournaments.played');
        $this->assertNotNull($registered);
        $this->assertSame('tournaments', $registered->module);
        $this->assertSame(TournamentsPlayedProfileStat::class, $registered->class);

        $owner = UserModel::factory()->create();
        $this->enroll($this->tournament(TournamentStatus::Finished), $owner, rank: 1);

        $profile = $this->app->make(ProfileRendererInterface::class)->render($this->ownerDto($owner), null);

        $stats = array_values(array_filter($profile->stats, static fn ($stat): bool => $stat->key === 'tournaments.played'));
        $this->assertCount(1, $stats);
        $this->assertSame(1, $stats[0]->value);
        $this->assertNotSame('', $stats[0]->label);
    }

    public function test_the_counter_describes_itself_for_the_profile_editor(): void
    {
        $stat = $this->app->make(TournamentsPlayedProfileStat::class);

        $this->assertSame('tournaments.played', $stat->key());
        $this->assertTrue($stat->enabledByDefault());
        $this->assertTrue($stat->isAvailable());
        $this->assertStringNotContainsString('tournaments::', $stat->label());
    }

    private function stat(UserModel $owner): ?ProfileStatDTO
    {
        return $this->app->make(TournamentsPlayedProfileStat::class)->stat($this->ownerDto($owner), null);
    }
}
