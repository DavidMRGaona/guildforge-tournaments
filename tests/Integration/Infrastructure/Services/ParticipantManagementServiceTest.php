<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Integration\Infrastructure\Services;

use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Modules\Tournaments\Application\DTOs\RegisterParticipantDTO;
use Modules\Tournaments\Domain\Enums\ParticipantStatus;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Domain\Events\ParticipantRegistered;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\ParticipantModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Repositories\EloquentParticipantRepository;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Repositories\EloquentTournamentRepository;
use Modules\Tournaments\Infrastructure\Services\ParticipantManagementService;
use Modules\Tournaments\Infrastructure\Services\UserDataProvider;
use Tests\TestCase;

final class ParticipantManagementServiceTest extends TestCase
{
    use RefreshDatabase;

    private ParticipantManagementService $service;

    private TournamentModel $tournament;

    protected function setUp(): void
    {
        parent::setUp();

        $event = EventModel::factory()->create();
        $this->tournament = TournamentModel::create([
            'event_id' => $event->id,
            'name' => 'Test Tournament',
            'slug' => 'test-tournament',
            'status' => TournamentStatus::RegistrationOpen->value,
            'result_reporting' => 'admin_only',
            'allow_guests' => true,
            'notification_email' => 'test@example.com',
        ]);

        $this->service = new ParticipantManagementService(
            new EloquentTournamentRepository,
            new EloquentParticipantRepository,
            new UserDataProvider,
        );
    }

    public function test_register_reactivates_withdrawn_user_participant(): void
    {
        Event::fake([ParticipantRegistered::class]);

        $user = UserModel::factory()->create();

        // Create a withdrawn participant
        $existingParticipant = ParticipantModel::create([
            'id' => fake()->uuid(),
            'tournament_id' => $this->tournament->id,
            'user_id' => $user->id,
            'status' => ParticipantStatus::Withdrawn->value,
            'registered_at' => new DateTimeImmutable,
        ]);

        $dto = new RegisterParticipantDTO(
            tournamentId: $this->tournament->id,
            userId: $user->id,
        );

        $result = $this->service->register($dto);

        // Should reactivate the existing participant, not create a new one
        $this->assertEquals($existingParticipant->id, $result->id);
        $this->assertEquals(ParticipantStatus::Registered, $result->status);

        // Verify database state
        $this->assertDatabaseHas('tournaments_participants', [
            'id' => $existingParticipant->id,
            'status' => ParticipantStatus::Registered->value,
        ]);

        // Should still only have one participant record
        $this->assertDatabaseCount('tournaments_participants', 1);

        Event::assertDispatched(ParticipantRegistered::class);
    }

    public function test_register_reactivates_withdrawn_guest_participant(): void
    {
        Event::fake([ParticipantRegistered::class]);

        $guestEmail = 'guest@example.com';

        // Create a withdrawn guest participant
        $existingParticipant = ParticipantModel::create([
            'id' => fake()->uuid(),
            'tournament_id' => $this->tournament->id,
            'user_id' => null,
            'guest_name' => 'Old Guest Name',
            'guest_email' => $guestEmail,
            'status' => ParticipantStatus::Withdrawn->value,
            'registered_at' => new DateTimeImmutable,
        ]);

        $dto = new RegisterParticipantDTO(
            tournamentId: $this->tournament->id,
            guestName: 'New Guest Name',
            guestEmail: $guestEmail,
        );

        $result = $this->service->register($dto);

        // Should reactivate the existing participant
        $this->assertEquals($existingParticipant->id, $result->id);
        $this->assertEquals(ParticipantStatus::Registered, $result->status);

        // Should still only have one participant record
        $this->assertDatabaseCount('tournaments_participants', 1);

        Event::assertDispatched(ParticipantRegistered::class);
    }

    public function test_register_creates_new_participant_for_new_user(): void
    {
        Event::fake([ParticipantRegistered::class]);

        $user = UserModel::factory()->create();

        $dto = new RegisterParticipantDTO(
            tournamentId: $this->tournament->id,
            userId: $user->id,
        );

        $result = $this->service->register($dto);

        $this->assertEquals(ParticipantStatus::Registered, $result->status);
        $this->assertDatabaseCount('tournaments_participants', 1);

        Event::assertDispatched(ParticipantRegistered::class);
    }

    public function test_reactivated_participant_clears_checked_in_at(): void
    {
        Event::fake([ParticipantRegistered::class]);

        $user = UserModel::factory()->create();

        // Create a withdrawn participant that was previously checked in
        $existingParticipant = ParticipantModel::create([
            'id' => fake()->uuid(),
            'tournament_id' => $this->tournament->id,
            'user_id' => $user->id,
            'status' => ParticipantStatus::Withdrawn->value,
            'registered_at' => new DateTimeImmutable,
            'checked_in_at' => new DateTimeImmutable,
        ]);

        $dto = new RegisterParticipantDTO(
            tournamentId: $this->tournament->id,
            userId: $user->id,
        );

        $this->service->register($dto);

        // Verify checked_in_at is cleared
        $this->assertDatabaseHas('tournaments_participants', [
            'id' => $existingParticipant->id,
            'status' => ParticipantStatus::Registered->value,
            'checked_in_at' => null,
        ]);
    }
}
