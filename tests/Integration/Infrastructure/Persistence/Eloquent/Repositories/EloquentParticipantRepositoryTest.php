<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Integration\Infrastructure\Persistence\Eloquent\Repositories;

use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Tournaments\Domain\Entities\Participant;
use Modules\Tournaments\Domain\Enums\GuestConfirmationMode;
use Modules\Tournaments\Domain\Enums\ParticipantStatus;
use Modules\Tournaments\Domain\Exceptions\AlreadyRegisteredException;
use Modules\Tournaments\Domain\Exceptions\ParticipantNotFoundException;
use Modules\Tournaments\Domain\Repositories\ParticipantRepositoryInterface;
use Modules\Tournaments\Domain\ValueObjects\ParticipantId;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\ParticipantModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Repositories\EloquentParticipantRepository;
use Modules\Tournaments\Tests\Support\AssertsContainerBindings;
use Modules\Tournaments\TournamentsServiceProvider;
use Tests\TestCase;

final class EloquentParticipantRepositoryTest extends TestCase
{
    use AssertsContainerBindings;
    use RefreshDatabase;

    private EloquentParticipantRepository $repository;

    private TournamentModel $tournament;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new EloquentParticipantRepository;

        $event = EventModel::factory()->create();
        $this->tournament = TournamentModel::create([
            'event_id' => $event->id,
            'name' => 'Test Tournament',
            'slug' => 'test-tournament',
            'status' => 'draft',
            'result_reporting' => 'admin_only',
        ]);
    }

    public function test_container_resolves_participant_repository_interface_to_eloquent_repository(): void
    {
        // The module may not auto-boot in the test environment
        $this->app->register(TournamentsServiceProvider::class);

        $this->assertContainerBinds(ParticipantRepositoryInterface::class, EloquentParticipantRepository::class);
    }

    public function test_it_saves_new_participant_with_user(): void
    {
        $user = UserModel::factory()->create();
        $id = ParticipantId::generate();

        $participant = new Participant(
            id: $id,
            tournamentId: $this->tournament->id,
            status: ParticipantStatus::Registered,
            userId: $user->id,
            seed: 1,
            registeredAt: new DateTimeImmutable,
        );

        $this->repository->save($participant);

        $this->assertDatabaseHas('tournaments_participants', [
            'id' => $id->value,
            'tournament_id' => $this->tournament->id,
            'user_id' => $user->id,
            'status' => 'registered',
            'seed' => 1,
        ]);
    }

    public function test_it_saves_new_guest_participant(): void
    {
        $id = ParticipantId::generate();

        $participant = new Participant(
            id: $id,
            tournamentId: $this->tournament->id,
            status: ParticipantStatus::Registered,
            guestName: 'Guest Player',
            guestEmail: 'guest@example.com',
            registeredAt: new DateTimeImmutable,
        );

        $this->repository->save($participant);

        $this->assertDatabaseHas('tournaments_participants', [
            'id' => $id->value,
            'tournament_id' => $this->tournament->id,
            'user_id' => null,
            'guest_name' => 'Guest Player',
            'guest_email' => 'guest@example.com',
            'status' => 'registered',
        ]);
    }

    public function test_it_updates_existing_participant(): void
    {
        $user = UserModel::factory()->create();
        ParticipantModel::create([
            'id' => $id = ParticipantId::generate()->value,
            'tournament_id' => $this->tournament->id,
            'user_id' => $user->id,
            'status' => 'registered',
        ]);

        $participant = new Participant(
            id: ParticipantId::fromString($id),
            tournamentId: $this->tournament->id,
            status: ParticipantStatus::Confirmed,
            userId: $user->id,
            seed: 5,
        );

        $this->repository->save($participant);

        $this->assertDatabaseHas('tournaments_participants', [
            'id' => $id,
            'status' => 'confirmed',
            'seed' => 5,
        ]);
    }

    public function test_it_finds_by_id(): void
    {
        $user = UserModel::factory()->create();
        ParticipantModel::create([
            'id' => $id = ParticipantId::generate()->value,
            'tournament_id' => $this->tournament->id,
            'user_id' => $user->id,
            'status' => 'registered',
            'seed' => 3,
        ]);

        $participant = $this->repository->find(ParticipantId::fromString($id));

        $this->assertNotNull($participant);
        $this->assertEquals($id, $participant->id()->value);
        $this->assertEquals($this->tournament->id, $participant->tournamentId());
        $this->assertEquals($user->id, $participant->userId());
        $this->assertEquals(ParticipantStatus::Registered, $participant->status());
        $this->assertEquals(3, $participant->seed());
    }

    public function test_it_returns_null_when_not_found(): void
    {
        $participant = $this->repository->find(ParticipantId::generate());

        $this->assertNull($participant);
    }

    public function test_find_or_fail_throws_exception_when_not_found(): void
    {
        $this->expectException(ParticipantNotFoundException::class);

        $this->repository->findOrFail(ParticipantId::generate());
    }

    public function test_it_finds_by_tournament(): void
    {
        $users = UserModel::factory()->count(3)->create();

        foreach ($users as $user) {
            ParticipantModel::create([
                'tournament_id' => $this->tournament->id,
                'user_id' => $user->id,
                'status' => 'registered',
            ]);
        }

        // Create participant in different tournament
        $otherEvent = EventModel::factory()->create();
        $otherTournament = TournamentModel::create([
            'event_id' => $otherEvent->id,
            'name' => 'Other Tournament',
            'slug' => 'other-tournament',
            'status' => 'draft',
            'result_reporting' => 'admin_only',
        ]);
        ParticipantModel::create([
            'tournament_id' => $otherTournament->id,
            'user_id' => UserModel::factory()->create()->id,
            'status' => 'registered',
        ]);

        $participants = $this->repository->findByTournament($this->tournament->id);

        $this->assertCount(3, $participants);
        foreach ($participants as $participant) {
            $this->assertEquals($this->tournament->id, $participant->tournamentId());
        }
    }

    public function test_it_finds_by_tournament_and_status(): void
    {
        $users = UserModel::factory()->count(4)->create()->all();

        ParticipantModel::create([
            'tournament_id' => $this->tournament->id,
            'user_id' => $users[0]->id,
            'status' => 'registered',
        ]);
        ParticipantModel::create([
            'tournament_id' => $this->tournament->id,
            'user_id' => $users[1]->id,
            'status' => 'confirmed',
        ]);
        ParticipantModel::create([
            'tournament_id' => $this->tournament->id,
            'user_id' => $users[2]->id,
            'status' => 'confirmed',
        ]);
        ParticipantModel::create([
            'tournament_id' => $this->tournament->id,
            'user_id' => $users[3]->id,
            'status' => 'withdrawn',
        ]);

        $confirmed = $this->repository->findByTournamentAndStatus(
            $this->tournament->id,
            ParticipantStatus::Confirmed
        );

        $this->assertCount(2, $confirmed);
        foreach ($confirmed as $participant) {
            $this->assertEquals(ParticipantStatus::Confirmed, $participant->status());
        }
    }

    public function test_it_finds_by_user_and_tournament(): void
    {
        $user = UserModel::factory()->create();
        ParticipantModel::create([
            'id' => $id = ParticipantId::generate()->value,
            'tournament_id' => $this->tournament->id,
            'user_id' => $user->id,
            'status' => 'registered',
        ]);

        $participant = $this->repository->findByUserAndTournament($user->id, $this->tournament->id);

        $this->assertNotNull($participant);
        $this->assertEquals($id, $participant->id()->value);
        $this->assertEquals($user->id, $participant->userId());
    }

    public function test_it_returns_null_when_user_not_in_tournament(): void
    {
        $user = UserModel::factory()->create();

        $participant = $this->repository->findByUserAndTournament($user->id, $this->tournament->id);

        $this->assertNull($participant);
    }

    public function test_it_finds_by_guest_email_and_tournament(): void
    {
        ParticipantModel::create([
            'id' => $id = ParticipantId::generate()->value,
            'tournament_id' => $this->tournament->id,
            'user_id' => null,
            'guest_name' => 'Guest Player',
            'guest_email' => 'guest@example.com',
            'status' => 'registered',
        ]);

        $participant = $this->repository->findByGuestEmailAndTournament(
            'guest@example.com',
            $this->tournament->id
        );

        $this->assertNotNull($participant);
        $this->assertEquals($id, $participant->id()->value);
        $this->assertEquals('guest@example.com', $participant->guestEmail());
    }

    public function test_it_counts_by_tournament(): void
    {
        $users = UserModel::factory()->count(5)->create();
        foreach ($users as $user) {
            ParticipantModel::create([
                'tournament_id' => $this->tournament->id,
                'user_id' => $user->id,
                'status' => 'registered',
            ]);
        }

        $count = $this->repository->countByTournament($this->tournament->id);

        $this->assertEquals(5, $count);
    }

    public function test_it_counts_active_by_tournament(): void
    {
        $users = UserModel::factory()->count(5)->create()->all();

        ParticipantModel::create([
            'tournament_id' => $this->tournament->id,
            'user_id' => $users[0]->id,
            'status' => 'registered',
        ]);
        ParticipantModel::create([
            'tournament_id' => $this->tournament->id,
            'user_id' => $users[1]->id,
            'status' => 'confirmed',
        ]);
        ParticipantModel::create([
            'tournament_id' => $this->tournament->id,
            'user_id' => $users[2]->id,
            'status' => 'checked_in',
        ]);
        ParticipantModel::create([
            'tournament_id' => $this->tournament->id,
            'user_id' => $users[3]->id,
            'status' => 'withdrawn',
        ]);
        ParticipantModel::create([
            'tournament_id' => $this->tournament->id,
            'user_id' => $users[4]->id,
            'status' => 'disqualified',
        ]);

        $count = $this->repository->countActiveByTournament($this->tournament->id);

        $this->assertEquals(3, $count); // registered, confirmed, checked_in
    }

    public function test_it_finds_playable_by_tournament(): void
    {
        $users = UserModel::factory()->count(5)->create()->all();

        ParticipantModel::create([
            'tournament_id' => $this->tournament->id,
            'user_id' => $users[0]->id,
            'status' => 'registered',
        ]);
        ParticipantModel::create([
            'tournament_id' => $this->tournament->id,
            'user_id' => $users[1]->id,
            'status' => 'confirmed',
        ]);
        ParticipantModel::create([
            'tournament_id' => $this->tournament->id,
            'user_id' => $users[2]->id,
            'status' => 'checked_in',
        ]);
        ParticipantModel::create([
            'tournament_id' => $this->tournament->id,
            'user_id' => $users[3]->id,
            'status' => 'withdrawn',
        ]);
        ParticipantModel::create([
            'tournament_id' => $this->tournament->id,
            'user_id' => $users[4]->id,
            'status' => 'disqualified',
        ]);

        $playable = $this->repository->findPlayableByTournament($this->tournament->id);

        $this->assertCount(2, $playable); // confirmed and checked_in
        foreach ($playable as $participant) {
            $this->assertTrue($participant->canPlay());
        }
    }

    public function test_it_deletes_participant(): void
    {
        $user = UserModel::factory()->create();
        ParticipantModel::create([
            'id' => $id = ParticipantId::generate()->value,
            'tournament_id' => $this->tournament->id,
            'user_id' => $user->id,
            'status' => 'registered',
        ]);

        $this->assertDatabaseHas('tournaments_participants', ['id' => $id]);

        $this->repository->delete(ParticipantId::fromString($id));

        $this->assertDatabaseMissing('tournaments_participants', ['id' => $id]);
    }

    public function test_it_handles_has_received_bye_flag(): void
    {
        $user = UserModel::factory()->create();
        $id = ParticipantId::generate();

        $participant = new Participant(
            id: $id,
            tournamentId: $this->tournament->id,
            status: ParticipantStatus::Confirmed,
            userId: $user->id,
            hasReceivedBye: true,
        );

        $this->repository->save($participant);

        $retrieved = $this->repository->find($id);

        $this->assertNotNull($retrieved);
        $this->assertTrue($retrieved->hasReceivedBye());
    }

    public function test_it_handles_checked_in_at_timestamp(): void
    {
        $user = UserModel::factory()->create();
        $checkedInAt = new DateTimeImmutable('2026-02-15 14:30:00');
        $id = ParticipantId::generate();

        $participant = new Participant(
            id: $id,
            tournamentId: $this->tournament->id,
            status: ParticipantStatus::CheckedIn,
            userId: $user->id,
            checkedInAt: $checkedInAt,
        );

        $this->repository->save($participant);

        $retrieved = $this->repository->find($id);

        $this->assertNotNull($retrieved);
        $this->assertNotNull($retrieved->checkedInAt());
        $this->assertEquals('2026-02-15', $retrieved->checkedInAt()->format('Y-m-d'));
    }

    private function guest(
        string $email,
        ParticipantStatus $status = ParticipantStatus::Registered,
        ?string $tournamentId = null,
    ): Participant {
        return new Participant(
            id: ParticipantId::generate(),
            tournamentId: $tournamentId ?? $this->tournament->id,
            status: $status,
            guestName: 'Ana',
            guestEmail: $email,
            registeredAt: new DateTimeImmutable,
        );
    }

    private function otherTournament(): TournamentModel
    {
        return TournamentModel::create([
            'event_id' => EventModel::factory()->create()->id,
            'name' => 'Otro torneo',
            'slug' => 'otro-torneo',
            'status' => 'draft',
            'result_reporting' => 'admin_only',
        ]);
    }

    public function test_save_translates_the_violation(): void
    {
        $this->repository->save($this->guest('ana@example.com'));

        $this->expectException(AlreadyRegisteredException::class);

        $this->repository->save($this->guest('ANA@example.com'));
    }

    public function test_the_translated_violation_does_not_name_the_email(): void
    {
        $this->repository->save($this->guest('ana@example.com'));
        $second = $this->guest('ana@example.com');

        try {
            $this->repository->save($second);
            $this->fail('The duplicate email was saved');
        } catch (AlreadyRegisteredException $e) {
            $this->assertStringNotContainsString('ana@example.com', $e->getMessage());
            $this->assertStringContainsString($second->id()->value, $e->getMessage());
        }
    }

    public function test_save_allows_the_same_email_after_the_first_registration_was_withdrawn(): void
    {
        $this->repository->save($this->guest('ana@example.com', ParticipantStatus::Withdrawn));

        $this->repository->save($this->guest('ana@example.com'));

        $this->assertSame(2, $this->repository->countByTournament($this->tournament->id));
    }

    public function test_guest_email_is_stored_normalised(): void
    {
        $participant = $this->guest('  Ana@Example.COM ');

        $this->repository->save($participant);

        $this->assertDatabaseHas('tournaments_participants', [
            'id' => $participant->id()->value,
            'guest_email' => 'ana@example.com',
        ]);
    }

    public function test_it_saves_and_reads_the_confirmation_state(): void
    {
        $participant = $this->guest('ana@example.com');
        $participant->startEmailConfirmation(
            'token-abc',
            GuestConfirmationMode::Hold,
            new DateTimeImmutable('2030-01-01 12:00:00'),
            new DateTimeImmutable('2030-01-01 10:00:00'),
        );
        $participant->recordFingerprint('fp-1');
        $this->repository->save($participant);

        $found = $this->repository->findByConfirmationToken('token-abc');

        $this->assertNotNull($found);
        $this->assertSame($participant->id()->value, $found->id()->value);
        $this->assertSame(GuestConfirmationMode::Hold, $found->confirmationMode());
        $this->assertSame('fp-1', $found->fingerprint());
        $this->assertSame('2030-01-01 12:00:00', $found->confirmationExpiresAt()?->format('Y-m-d H:i:s'));
        $this->assertSame('2030-01-01 10:00:00', $found->confirmationSentAt()?->format('Y-m-d H:i:s'));
        $this->assertNull($found->emailConfirmedAt());
        $this->assertNull($this->repository->findByConfirmationToken('nope'));
    }

    public function test_find_by_guest_email_ignores_case_and_spaces(): void
    {
        $participant = $this->guest('ana@example.com');
        $this->repository->save($participant);

        $found = $this->repository->findByGuestEmailAndTournament('  ANA@Example.com ', $this->tournament->id);

        $this->assertNotNull($found);
        $this->assertSame($participant->id()->value, $found->id()->value);
    }

    public function test_find_by_guest_email_prefers_the_active_row(): void
    {
        $this->repository->save($this->guest('ana@example.com', ParticipantStatus::Withdrawn));
        $active = $this->guest('ana@example.com');
        $this->repository->save($active);

        $found = $this->repository->findByGuestEmailAndTournament('ana@example.com', $this->tournament->id);

        $this->assertNotNull($found);
        $this->assertSame($active->id()->value, $found->id()->value);
    }

    public function test_find_active_by_email_matches_a_user_email_in_another_case(): void
    {
        $user = UserModel::factory()->create(['email' => 'Maria@Example.com']);
        ParticipantModel::create([
            'id' => ParticipantId::generate()->value,
            'tournament_id' => $this->tournament->id,
            'user_id' => $user->id,
            'status' => 'registered',
        ]);

        $found = $this->repository->findActiveByEmailAndTournament(' maria@example.COM ', $this->tournament->id);

        $this->assertNotNull($found);
        $this->assertSame($user->id, $found->userId());
    }

    public function test_find_active_by_email_matches_a_guest_email_in_another_case(): void
    {
        $this->repository->save($this->guest('ana@example.com'));

        $this->assertNotNull($this->repository->findActiveByEmailAndTournament('ANA@example.com', $this->tournament->id));
    }

    public function test_find_active_by_email_skips_inactive_guest_rows_for_the_account_row(): void
    {
        $user = UserModel::factory()->create(['email' => 'ana@example.com']);
        $this->repository->save($this->guest('ana@example.com', ParticipantStatus::Expired));
        $this->repository->save($this->guest('ana@example.com', ParticipantStatus::Withdrawn));
        ParticipantModel::create([
            'id' => ParticipantId::generate()->value,
            'tournament_id' => $this->tournament->id,
            'user_id' => $user->id,
            'status' => 'confirmed',
        ]);

        $found = $this->repository->findActiveByEmailAndTournament('ana@example.com', $this->tournament->id);

        $this->assertNotNull($found);
        $this->assertSame($user->id, $found->userId());
    }

    public function test_find_active_by_email_ignores_inactive_rows(): void
    {
        $user = UserModel::factory()->create(['email' => 'ana@example.com']);
        $this->repository->save($this->guest('ana@example.com', ParticipantStatus::Expired));
        ParticipantModel::create([
            'id' => ParticipantId::generate()->value,
            'tournament_id' => $this->tournament->id,
            'user_id' => $user->id,
            'status' => 'withdrawn',
        ]);

        $this->assertNull($this->repository->findActiveByEmailAndTournament('ana@example.com', $this->tournament->id));
    }

    public function test_wait_rows_awaiting_confirmation_do_not_count(): void
    {
        $confirmed = $this->guest('a@example.com');
        $confirmed->markEmailConfirmed(new DateTimeImmutable('2030-01-01 10:00:00'));
        $this->repository->save($confirmed);

        $held = $this->guest('b@example.com');
        $held->startEmailConfirmation('t-b', GuestConfirmationMode::Hold, null, new DateTimeImmutable('2030-01-01 10:00:00'));
        $this->repository->save($held);

        $waiting = $this->guest('c@example.com');
        $waiting->startEmailConfirmation('t-c', GuestConfirmationMode::Wait, null, new DateTimeImmutable('2030-01-01 10:00:00'));
        $this->repository->save($waiting);

        $this->assertSame(2, $this->repository->countActiveByTournament($this->tournament->id));
        $this->assertSame(1, $this->repository->countUnconfirmedHeldByTournament($this->tournament->id));
    }

    public function test_find_ids_awaiting_confirmation_lists_active_unconfirmed_guests(): void
    {
        $pending = $this->guest('a@example.com');
        $pending->startEmailConfirmation('t-a', GuestConfirmationMode::Hold, null, new DateTimeImmutable('2030-01-01 10:00:00'));
        $this->repository->save($pending);

        $confirmed = $this->guest('b@example.com');
        $confirmed->markEmailConfirmed(new DateTimeImmutable('2030-01-01 10:00:00'));
        $this->repository->save($confirmed);

        $withdrawn = $this->guest('c@example.com', ParticipantStatus::Withdrawn);
        $this->repository->save($withdrawn);

        $this->assertSame([$pending->id()->value], $this->repository->findIdsAwaitingConfirmation());
    }

    public function test_fingerprints_can_be_listed_and_cleared_per_tournament(): void
    {
        $other = $this->otherTournament();
        $a = $this->guest('a@example.com');
        $a->recordFingerprint('fp');
        $this->repository->save($a);
        $b = $this->guest('b@example.com', tournamentId: $other->id);
        $b->recordFingerprint('fp');
        $this->repository->save($b);
        $this->repository->save($this->guest('c@example.com'));

        $ids = $this->repository->findTournamentIdsWithFingerprints();
        sort($ids);
        $expected = [$this->tournament->id, $other->id];
        sort($expected);
        $this->assertSame($expected, $ids);

        $this->assertSame(1, $this->repository->clearFingerprints($this->tournament->id));

        $this->assertSame([$other->id], $this->repository->findTournamentIdsWithFingerprints());
        $this->assertNull($this->repository->find($a->id())?->fingerprint());
    }

    public function test_possible_duplicates_share_fingerprint_within_the_window(): void
    {
        $first = $this->guest('a@example.com');
        $first->recordFingerprint('fp');
        $this->repository->save($first);
        $second = $this->guest('b@example.com');
        $second->recordFingerprint('fp');
        $this->repository->save($second);

        ParticipantModel::where('id', $first->id()->value)->update(['created_at' => '2030-01-01 10:00:00']);
        ParticipantModel::where('id', $second->id()->value)->update(['created_at' => '2030-01-01 13:00:00']);

        $ids = $this->repository->findPossibleDuplicateIds($this->tournament->id, 24);
        sort($ids);
        $expected = [$first->id()->value, $second->id()->value];
        sort($expected);
        $this->assertSame($expected, $ids);
    }

    public function test_possible_duplicates_ignore_rows_outside_the_window(): void
    {
        $first = $this->guest('a@example.com');
        $first->recordFingerprint('fp');
        $this->repository->save($first);
        $second = $this->guest('b@example.com');
        $second->recordFingerprint('fp');
        $this->repository->save($second);

        ParticipantModel::where('id', $first->id()->value)->update(['created_at' => '2030-01-01 10:00:00']);
        ParticipantModel::where('id', $second->id()->value)->update(['created_at' => '2030-01-02 16:00:00']);

        $this->assertSame([], $this->repository->findPossibleDuplicateIds($this->tournament->id, 24));
    }

    public function test_possible_duplicates_ignore_other_tournaments_and_pairs_of_users(): void
    {
        $other = $this->otherTournament();
        $a = $this->guest('a@example.com');
        $a->recordFingerprint('fp');
        $this->repository->save($a);
        $b = $this->guest('b@example.com', tournamentId: $other->id);
        $b->recordFingerprint('fp');
        $this->repository->save($b);

        $beforeUsers = $this->repository->findPossibleDuplicateIds($this->tournament->id, 24);
        $this->assertSame([], $beforeUsers);

        $userA = UserModel::factory()->create();
        $userB = UserModel::factory()->create();
        foreach ([$userA, $userB] as $user) {
            ParticipantModel::create([
                'id' => ParticipantId::generate()->value,
                'tournament_id' => $this->tournament->id,
                'user_id' => $user->id,
                'status' => 'registered',
                'fingerprint' => 'fp-users',
            ]);
        }

        $this->assertSame([], $this->repository->findPossibleDuplicateIds($this->tournament->id, 24));
    }

    public function test_possible_duplicates_pair_by_the_confirmation_send_time(): void
    {
        $first = $this->guest('a@example.com');
        $first->recordFingerprint('fp');
        $this->repository->save($first);
        $second = $this->guest('b@example.com');
        $second->recordFingerprint('fp');
        $second->startEmailConfirmation('t-b', GuestConfirmationMode::Hold, null, new DateTimeImmutable('2030-01-05 10:00:00'));
        $this->repository->save($second);

        // The second row is an old reactivated one: only its send time is recent
        ParticipantModel::where('id', $first->id()->value)->update(['created_at' => '2030-01-05 09:00:00']);
        ParticipantModel::where('id', $second->id()->value)->update(['created_at' => '2029-01-01 09:00:00']);

        $this->assertCount(2, $this->repository->findPossibleDuplicateIds($this->tournament->id, 24));
    }
}
