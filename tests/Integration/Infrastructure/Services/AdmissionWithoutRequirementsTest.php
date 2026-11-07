<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Integration\Infrastructure\Services;

use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Modules\Tournaments\Application\DTOs\RegisterParticipantDTO;
use Modules\Tournaments\Application\Services\ParticipantManagementServiceInterface;
use Modules\Tournaments\Application\Services\RoundManagementServiceInterface;
use Modules\Tournaments\Domain\Enums\ParticipantStatus;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Domain\Events\GuestConfirmationRequested;
use Modules\Tournaments\Domain\Events\ParticipantConfirmed;
use Modules\Tournaments\Domain\Events\ParticipantRegistered;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\MatchModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\ParticipantModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Modules\ModuleTestCase;

/**
 * A tournament that asks neither for manual confirmation nor for check-in lets every registration
 * play: it is confirmed as soon as nothing is pending (a guest, once the email is confirmed).
 */
final class AdmissionWithoutRequirementsTest extends ModuleTestCase
{
    protected ?string $moduleName = 'tournaments';

    protected bool $autoEnableModule = true;

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = CarbonImmutable::parse('2026-11-02 10:00:00', 'Europe/Madrid');
        $this->travelTo($this->now);
        config()->set('modules.settings.tournaments', []);
        Event::fake([GuestConfirmationRequested::class, ParticipantRegistered::class, ParticipantConfirmed::class]);
    }

    public function test_a_web_registration_is_confirmed_when_the_tournament_asks_for_nothing(): void
    {
        $tournament = $this->tournament();

        $registration = $this->service()->register(new RegisterParticipantDTO($tournament->id, UserModel::factory()->create()->id));

        $this->assertSame(ParticipantStatus::Confirmed, $registration->status);
        // Not an approval: no «Inscripción aprobada» email
        Event::assertNotDispatched(ParticipantConfirmed::class);
        Event::assertDispatched(ParticipantRegistered::class);
    }

    /**
     * @param  array<string, bool>  $requirements
     */
    #[DataProvider('requirements')]
    public function test_a_web_registration_waits_when_the_tournament_asks_for_something(array $requirements): void
    {
        $tournament = $this->tournament($requirements);

        $registration = $this->service()->register(new RegisterParticipantDTO($tournament->id, UserModel::factory()->create()->id));

        $this->assertSame(ParticipantStatus::Registered, $registration->status);
    }

    /**
     * @return array<string, array{array<string, bool>}>
     */
    public static function requirements(): array
    {
        return [
            'manual confirmation' => [['requires_manual_confirmation' => true]],
            'check-in' => [['requires_check_in' => true]],
            'both' => [['requires_manual_confirmation' => true, 'requires_check_in' => true]],
        ];
    }

    public function test_a_withdrawn_registration_comes_back_confirmed(): void
    {
        $tournament = $this->tournament();
        $user = UserModel::factory()->create();
        $first = $this->service()->register(new RegisterParticipantDTO($tournament->id, $user->id));
        $this->service()->withdraw($first->id);

        $again = $this->service()->register(new RegisterParticipantDTO($tournament->id, $user->id));

        $this->assertSame($first->id, $again->id);
        $this->assertSame(ParticipantStatus::Confirmed, $again->status);
    }

    public function test_a_guest_is_confirmed_once_the_email_is_confirmed(): void
    {
        $tournament = $this->tournament();
        $guest = $this->pendingGuest($tournament);

        $this->assertSame(ParticipantStatus::Registered, $guest->status);

        $this->service()->confirmGuestEmail((string) $guest->confirmation_token);

        $this->assertSame(ParticipantStatus::Confirmed, $guest->refresh()->status);
    }

    public function test_a_guest_of_a_tournament_with_check_in_stays_registered(): void
    {
        $tournament = $this->tournament(['requires_check_in' => true]);
        $guest = $this->pendingGuest($tournament);

        $this->service()->confirmGuestEmail((string) $guest->confirmation_token);

        $this->assertSame(ParticipantStatus::Registered, $guest->refresh()->status);
    }

    public function test_the_organization_vouching_for_a_guest_email_confirms_the_registration(): void
    {
        $tournament = $this->tournament();
        $guest = $this->pendingGuest($tournament);

        $this->service()->confirmEmailByOrganization($guest->id);

        $this->assertSame(ParticipantStatus::Confirmed, $guest->refresh()->status);
    }

    public function test_an_account_taking_over_its_guest_registration_is_confirmed(): void
    {
        $tournament = $this->tournament();
        $guest = $this->pendingGuest($tournament, 'ana@example.com');
        $user = UserModel::factory()->create(['email' => 'ana@example.com', 'email_verified_at' => $this->now]);

        $registration = $this->service()->register(new RegisterParticipantDTO($tournament->id, $user->id));

        $this->assertSame($guest->id, $registration->id);
        $this->assertSame(ParticipantStatus::Confirmed, $registration->status);
    }

    public function test_the_first_round_pairs_the_web_registrations(): void
    {
        $tournament = $this->tournament();
        foreach (range(1, 2) as $ignored) {
            $this->service()->register(new RegisterParticipantDTO($tournament->id, UserModel::factory()->create()->id));
        }
        $tournament->update(['status' => TournamentStatus::InProgress->value]);

        $round = app(RoundManagementServiceInterface::class)->generateNextRound($tournament->id);

        $this->assertSame(1, MatchModel::query()->where('round_id', $round->id)->count());
    }

    public function test_removing_the_requirements_confirms_the_registrations_with_nothing_pending(): void
    {
        $tournament = $this->tournament(['requires_manual_confirmation' => true, 'requires_check_in' => true]);
        $user = $this->service()->register(new RegisterParticipantDTO($tournament->id, UserModel::factory()->create()->id));
        $guest = $this->pendingGuest($tournament);
        $tournament->update(['requires_manual_confirmation' => false, 'requires_check_in' => false]);

        $admitted = $this->service()->admitPendingRegistrations($tournament->id);

        $this->assertSame(1, $admitted);
        $this->assertSame(ParticipantStatus::Confirmed, ParticipantModel::query()->findOrFail($user->id)->status);
        // The guest still has to confirm the email
        $this->assertSame(ParticipantStatus::Registered, $guest->refresh()->status);
        Event::assertNotDispatched(ParticipantConfirmed::class);
    }

    public function test_nothing_is_confirmed_while_a_requirement_remains(): void
    {
        $tournament = $this->tournament(['requires_manual_confirmation' => true]);
        $user = $this->service()->register(new RegisterParticipantDTO($tournament->id, UserModel::factory()->create()->id));

        $this->assertSame(0, $this->service()->admitPendingRegistrations($tournament->id));
        $this->assertSame(ParticipantStatus::Registered, ParticipantModel::query()->findOrFail($user->id)->status);
    }

    public function test_nothing_is_confirmed_once_the_tournament_has_started(): void
    {
        $tournament = $this->tournament(['requires_manual_confirmation' => true]);
        $user = $this->service()->register(new RegisterParticipantDTO($tournament->id, UserModel::factory()->create()->id));
        $tournament->update(['requires_manual_confirmation' => false, 'status' => TournamentStatus::InProgress->value]);

        $this->assertSame(0, $this->service()->admitPendingRegistrations($tournament->id));
        $this->assertSame(ParticipantStatus::Registered, ParticipantModel::query()->findOrFail($user->id)->status);
    }

    private function service(): ParticipantManagementServiceInterface
    {
        return app(ParticipantManagementServiceInterface::class);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function tournament(array $attributes = []): TournamentModel
    {
        return TournamentModel::create([
            'event_id' => EventModel::factory()->create(['start_date' => $this->now->addDays(3)])->id,
            'name' => 'Torneo de primavera',
            'slug' => 'torneo-'.Str::lower(Str::random(10)),
            'status' => TournamentStatus::RegistrationOpen->value,
            'result_reporting' => 'admin_only',
            'allow_guests' => true,
            'notification_email' => 'organizacion@example.com',
            ...$attributes,
        ]);
    }

    private function pendingGuest(TournamentModel $tournament, string $email = 'john@example.com'): ParticipantModel
    {
        $result = $this->service()->registerGuest(new RegisterParticipantDTO(
            tournamentId: $tournament->id,
            guestName: 'John',
            guestEmail: $email,
        ));

        return ParticipantModel::query()->findOrFail($result->participantId);
    }
}
