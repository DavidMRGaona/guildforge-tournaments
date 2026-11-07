<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Integration\Calendar;

use App\Application\Calendar\Contracts\CalendarAttendanceSourceInterface;
use App\Application\Calendar\DTOs\CalendarEntryReferenceDTO;
use App\Application\Calendar\Services\CalendarAttendanceSourceRegistryInterface;
use App\Application\Calendar\Services\PersonalCalendarServiceInterface;
use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Tournaments\Infrastructure\Calendar\TournamentAttendanceSource;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\ParticipantModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use Tests\Support\Modules\ModuleTestCase;

/**
 * The tournaments a person plays in or organizes, for their personal calendar: a reference to the
 * tournament's core event, only for confirmed or checked-in participants with an account.
 */
final class TournamentAttendanceSourceTest extends ModuleTestCase
{
    protected ?string $moduleName = 'tournaments';

    protected bool $autoEnableModule = true;

    private CalendarAttendanceSourceInterface $source;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        $this->source = $this->app->make(TournamentAttendanceSource::class);
    }

    public function test_the_module_registers_its_source_in_the_core_registry(): void
    {
        $this->assertContains(
            TournamentAttendanceSource::class,
            $this->app->make(CalendarAttendanceSourceRegistryInterface::class)->all(),
        );
    }

    public function test_confirmed_and_checked_in_participants_get_the_event_of_the_tournament(): void
    {
        $user = UserModel::factory()->create();
        $confirmed = $this->tournament();
        $checkedIn = $this->tournament();
        $this->participant($confirmed, $user, 'confirmed');
        $this->participant($checkedIn, $user, 'checked_in');

        $this->assertEqualsCanonicalizing(
            [$confirmed->event_id, $checkedIn->event_id],
            $this->eventIds($user),
        );
        $this->assertContainsOnlyInstancesOf(CalendarEntryReferenceDTO::class, $this->references($user));
        $this->assertSame('event', $this->references($user)[0]->sourceType);
    }

    public function test_statuses_that_are_not_playing_are_left_out(): void
    {
        $user = UserModel::factory()->create();

        foreach (['registered', 'withdrawn', 'disqualified', 'expired'] as $status) {
            $this->participant($this->tournament(), $user, $status);
        }

        $this->assertSame([], $this->references($user));
    }

    public function test_guests_and_other_people_are_not_attendees(): void
    {
        $user = UserModel::factory()->create();
        $other = UserModel::factory()->create();
        $tournament = $this->tournament();
        $this->participant($tournament, $other, 'confirmed');
        ParticipantModel::create([
            'tournament_id' => $tournament->id,
            'user_id' => null,
            'guest_name' => 'Invitado',
            'guest_email' => 'invitado@example.test',
            'status' => 'confirmed',
        ]);

        $this->assertSame([], $this->references($user));
        $this->assertSame([$tournament->event_id], $this->eventIds($other));
    }

    public function test_organizers_run_the_tournament(): void
    {
        $user = UserModel::factory()->create();
        $tournament = $this->tournament();
        DB::table('tournaments_organizers')->insert(['tournament_id' => $tournament->id, 'user_id' => $user->id]);

        $this->assertSame([$tournament->event_id], $this->eventIds($user));
    }

    public function test_a_person_who_plays_and_organizes_the_same_tournament_appears_once(): void
    {
        $user = UserModel::factory()->create();
        $tournament = $this->tournament();
        $this->participant($tournament, $user, 'confirmed');
        DB::table('tournaments_organizers')->insert(['tournament_id' => $tournament->id, 'user_id' => $user->id]);

        $this->assertCount(1, $this->references($user));
    }

    public function test_only_tournaments_overlapping_the_range_count(): void
    {
        $user = UserModel::factory()->create();
        $inside = $this->tournament('2026-11-20 09:00:00', '2026-11-20 20:00:00');
        $straddling = $this->tournament('2026-10-31 20:00:00', '2026-11-01 02:00:00');
        $before = $this->tournament('2026-10-30 09:00:00', '2026-10-30 20:00:00');
        $endsAtStart = $this->tournament('2026-10-31 09:00:00', '2026-11-01 00:00:00');
        $startsAtEnd = $this->tournament('2026-12-01 00:00:00', '2026-12-01 10:00:00');
        foreach ([$inside, $straddling, $before, $endsAtStart, $startsAtEnd] as $tournament) {
            $this->participant($tournament, $user, 'confirmed');
        }

        $this->assertEqualsCanonicalizing(
            [$inside->event_id, $straddling->event_id],
            $this->eventIds($user),
        );
    }

    public function test_it_runs_a_constant_number_of_queries(): void
    {
        $user = UserModel::factory()->create();
        for ($i = 0; $i < 4; $i++) {
            $this->participant($this->tournament(), $user, 'confirmed');
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->references($user);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(1, $queries);
    }

    public function test_the_personal_calendar_of_the_core_returns_the_event_of_a_tournament(): void
    {
        $user = UserModel::factory()->create();
        $mine = $this->tournament();
        $this->tournament();
        $this->participant($mine, $user, 'confirmed');

        $entries = $this->app->make(PersonalCalendarServiceInterface::class)
            ->entriesFor($user->id, $this->rangeStart(), $this->rangeEnd());

        $this->assertCount(1, $entries);
        $this->assertSame($mine->event_id, $entries[0]->id);
    }

    public function test_only_mine_on_the_calendar_shows_the_tournament_to_its_participant(): void
    {
        $user = UserModel::factory()->create();
        $mine = $this->tournament();
        $this->tournament();
        $this->participant($mine, $user, 'confirmed');

        $response = $this->actingAs($user)->getJson('/eventos/calendario?start=2026-11-01&end=2026-12-01&mine=1');

        $response->assertOk();
        $response->assertJsonCount(1);
        $response->assertJsonFragment(['id' => $mine->event_id]);
    }

    public function test_a_pending_registration_does_not_reach_the_calendar(): void
    {
        $user = UserModel::factory()->create();
        $this->participant($this->tournament(), $user, 'registered');

        $this->actingAs($user)->getJson('/eventos/calendario?start=2026-11-01&end=2026-12-01&mine=1')
            ->assertOk()
            ->assertJsonCount(0);
    }

    /**
     * @return array<CalendarEntryReferenceDTO>
     */
    private function references(UserModel $user): array
    {
        return $this->source->attendedEntries($user->id, $this->rangeStart(), $this->rangeEnd());
    }

    /**
     * @return array<string>
     */
    private function eventIds(UserModel $user): array
    {
        return array_map(static fn (CalendarEntryReferenceDTO $reference): string => $reference->id, $this->references($user));
    }

    private function rangeStart(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-11-01 00:00:00', new DateTimeZone('UTC'));
    }

    private function rangeEnd(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-12-01 00:00:00', new DateTimeZone('UTC'));
    }

    private function tournament(string $start = '2026-11-14 09:00:00', string $end = '2026-11-14 20:00:00'): TournamentModel
    {
        $event = EventModel::factory()->published()->create([
            'start_date' => new DateTimeImmutable($start, new DateTimeZone('UTC')),
            'end_date' => new DateTimeImmutable($end, new DateTimeZone('UTC')),
        ]);

        return TournamentModel::create([
            'event_id' => $event->id,
            'name' => 'Torneo de ejemplo',
            'slug' => 'torneo-'.Str::lower(Str::random(8)),
            'status' => 'registration_open',
            'result_reporting' => 'admin_only',
        ]);
    }

    private function participant(TournamentModel $tournament, UserModel $user, string $status): ParticipantModel
    {
        return ParticipantModel::create([
            'tournament_id' => $tournament->id,
            'user_id' => $user->id,
            'status' => $status,
        ]);
    }
}
