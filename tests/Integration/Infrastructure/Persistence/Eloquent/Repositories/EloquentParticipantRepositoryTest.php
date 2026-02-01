<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Integration\Infrastructure\Persistence\Eloquent\Repositories;

use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Tournaments\Domain\Entities\Participant;
use Modules\Tournaments\Domain\Enums\ParticipantStatus;
use Modules\Tournaments\Domain\Exceptions\ParticipantNotFoundException;
use Modules\Tournaments\Domain\Repositories\ParticipantRepositoryInterface;
use Modules\Tournaments\Domain\ValueObjects\ParticipantId;
use Modules\Tournaments\Domain\ValueObjects\TournamentId;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\ParticipantModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Repositories\EloquentParticipantRepository;
use Tests\TestCase;

final class EloquentParticipantRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private EloquentParticipantRepository $repository;

    private TournamentModel $tournament;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new EloquentParticipantRepository();

        $event = EventModel::factory()->create();
        $this->tournament = TournamentModel::create([
            'event_id' => $event->id,
            'name' => 'Test Tournament',
            'slug' => 'test-tournament',
            'status' => 'draft',
            'result_reporting' => 'admin_only',
        ]);
    }

    public function test_it_implements_participant_repository_interface(): void
    {
        $this->assertInstanceOf(ParticipantRepositoryInterface::class, $this->repository);
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
            registeredAt: new DateTimeImmutable(),
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
            userId: null,
            guestName: 'Guest Player',
            guestEmail: 'guest@example.com',
            registeredAt: new DateTimeImmutable(),
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
        $model = ParticipantModel::create([
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
        $model = ParticipantModel::create([
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

        foreach ($users as $i => $user) {
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
        $users = UserModel::factory()->count(4)->create();

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
        $users = UserModel::factory()->count(5)->create();

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
        $users = UserModel::factory()->count(5)->create();

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
}
