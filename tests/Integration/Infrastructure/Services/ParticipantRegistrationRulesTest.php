<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Integration\Infrastructure\Services;

use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Tournaments\Application\DTOs\RegisterParticipantDTO;
use Modules\Tournaments\Domain\Enums\ParticipantStatus;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Domain\Exceptions\AlreadyRegisteredException;
use Modules\Tournaments\Domain\Exceptions\CannotWithdrawException;
use Modules\Tournaments\Domain\Exceptions\GuestRegistrationNotAllowedException;
use Modules\Tournaments\Domain\Exceptions\TournamentFullException;
use Modules\Tournaments\Domain\Exceptions\TournamentNotOpenException;
use Modules\Tournaments\Domain\Exceptions\UserNotAllowedToRegisterException;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\ParticipantModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Repositories\EloquentParticipantRepository;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Repositories\EloquentTournamentRepository;
use Modules\Tournaments\Infrastructure\Services\ParticipantManagementService;
use Modules\Tournaments\Infrastructure\Services\UserDataProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Each rejected registration or withdrawal must raise the domain exception the
 * controllers turn into a message, never a PHP error (500).
 */
final class ParticipantRegistrationRulesTest extends TestCase
{
    use RefreshDatabase;

    private ParticipantManagementService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ParticipantManagementService(
            new EloquentTournamentRepository,
            new EloquentParticipantRepository,
            new UserDataProvider,
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function tournament(array $attributes = []): TournamentModel
    {
        return TournamentModel::create([
            'event_id' => EventModel::factory()->create()->id,
            'name' => 'Torneo',
            'slug' => 'torneo-'.bin2hex(random_bytes(4)),
            'status' => TournamentStatus::RegistrationOpen->value,
            'result_reporting' => 'admin_only',
            'allow_guests' => true,
            'notification_email' => 'test@example.com',
            ...$attributes,
        ]);
    }

    private function participant(TournamentModel $tournament, array $attributes = []): ParticipantModel
    {
        return ParticipantModel::create([
            'id' => fake()->uuid(),
            'tournament_id' => $tournament->id,
            'status' => ParticipantStatus::Registered->value,
            'registered_at' => new DateTimeImmutable,
            ...$attributes,
        ]);
    }

    /**
     * @return iterable<string, array{TournamentStatus, string}>
     */
    public static function closedStatuses(): iterable
    {
        yield 'draft' => [TournamentStatus::Draft, 'not yet open'];
        yield 'registration closed' => [TournamentStatus::RegistrationClosed, 'already closed'];
        yield 'in progress' => [TournamentStatus::InProgress, 'in progress'];
        yield 'finished' => [TournamentStatus::Finished, 'has finished'];
        yield 'cancelled' => [TournamentStatus::Cancelled, 'cancelled'];
    }

    #[DataProvider('closedStatuses')]
    public function test_registering_when_registration_is_not_open_says_why(TournamentStatus $status, string $reason): void
    {
        $tournament = $this->tournament(['status' => $status->value]);

        $this->expectException(TournamentNotOpenException::class);
        $this->expectExceptionMessage($reason);

        $this->service->register(new RegisterParticipantDTO($tournament->id, UserModel::factory()->create()->id));
    }

    public function test_registering_in_a_full_tournament_is_rejected(): void
    {
        $tournament = $this->tournament(['max_participants' => 1]);
        $this->participant($tournament, ['user_id' => UserModel::factory()->create()->id]);

        $this->expectException(TournamentFullException::class);

        $this->service->register(new RegisterParticipantDTO($tournament->id, UserModel::factory()->create()->id));
    }

    public function test_a_guest_cannot_register_when_guests_are_not_allowed(): void
    {
        $tournament = $this->tournament(['allow_guests' => false]);

        $this->expectException(GuestRegistrationNotAllowedException::class);

        $this->service->register(new RegisterParticipantDTO($tournament->id, guestName: 'Ana', guestEmail: 'ana@example.com'));
    }

    public function test_a_guest_cannot_register_twice_with_the_same_email(): void
    {
        $tournament = $this->tournament();
        $this->participant($tournament, ['guest_name' => 'Ana', 'guest_email' => 'ana@example.com']);

        $this->expectException(AlreadyRegisteredException::class);
        $this->expectExceptionMessage('ana@example.com');

        $this->service->register(new RegisterParticipantDTO($tournament->id, guestName: 'Ana', guestEmail: 'ana@example.com'));
    }

    public function test_a_user_without_an_allowed_role_cannot_register(): void
    {
        $tournament = $this->tournament(['allowed_roles' => ['socio']]);

        $this->expectException(UserNotAllowedToRegisterException::class);

        $this->service->register(new RegisterParticipantDTO($tournament->id, UserModel::factory()->create()->id));
    }

    public function test_withdrawing_from_a_finished_tournament_is_rejected(): void
    {
        $tournament = $this->tournament(['status' => TournamentStatus::Finished->value]);
        $participant = $this->participant($tournament, ['user_id' => UserModel::factory()->create()->id]);

        $this->expectException(CannotWithdrawException::class);
        $this->expectExceptionMessage('finished');

        $this->service->withdraw($participant->id);
    }

    public function test_withdrawing_by_token_from_a_finished_tournament_is_rejected(): void
    {
        $tournament = $this->tournament(['status' => TournamentStatus::Finished->value]);
        $this->participant($tournament, ['guest_name' => 'Ana', 'guest_email' => 'ana@example.com', 'cancellation_token' => 'finished-token']);

        $this->expectException(CannotWithdrawException::class);
        $this->expectExceptionMessage('finished');

        $this->service->withdrawByToken('finished-token');
    }
}
