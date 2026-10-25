<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Integration\Infrastructure\Services;

use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\Tournaments\Application\DTOs\RegisterParticipantDTO;
use Modules\Tournaments\Application\DTOs\Response\ParticipantResponseDTO;
use Modules\Tournaments\Application\Services\ParticipantManagementServiceInterface;
use Modules\Tournaments\Domain\Enums\GuestConfirmationMode;
use Modules\Tournaments\Domain\Enums\ParticipantStatus;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Domain\Events\ParticipantRegistered;
use Modules\Tournaments\Domain\Exceptions\AlreadyRegisteredException;
use Modules\Tournaments\Domain\Exceptions\TournamentFullException;
use Modules\Tournaments\Domain\Exceptions\UserNotAllowedToRegisterException;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\ParticipantModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Repositories\EloquentParticipantRepository;
use Modules\Tournaments\Listeners\SendParticipantRegisteredEmails;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;
use Tests\Support\Modules\ModuleTestCase;

/**
 * A signed-in user registering where a guest row with the account email is active takes that row
 * over when the account email is verified, and is refused otherwise.
 */
final class GuestRegistrationClaimTest extends ModuleTestCase
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

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function participant(TournamentModel $tournament, array $attributes = []): ParticipantModel
    {
        return ParticipantModel::create([
            'id' => fake()->uuid(),
            'tournament_id' => $tournament->id,
            'status' => ParticipantStatus::Registered->value,
            'registered_at' => $this->now->subDay(),
            'email_confirmed_at' => $this->now->subDay(),
            ...$attributes,
        ]);
    }

    /**
     * A guest row of john@example.com whose email was confirmed yesterday.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function guestRow(TournamentModel $tournament, array $attributes = []): ParticipantModel
    {
        return $this->participant($tournament, [
            'guest_name' => 'John invitada',
            'guest_email' => 'john@example.com',
            'cancellation_token' => str_repeat('c', 64),
            'confirmation_token' => str_repeat('t', 64),
            'confirmation_sent_at' => $this->now->subDay(),
            'confirmation_mode' => GuestConfirmationMode::Hold->value,
            ...$attributes,
        ]);
    }

    /**
     * A guest row of john@example.com still awaiting the confirmation of its email.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function unconfirmedGuestRow(TournamentModel $tournament, array $attributes = []): ParticipantModel
    {
        return $this->guestRow($tournament, [
            'registered_at' => $this->now->subMinutes(30),
            'email_confirmed_at' => null,
            'confirmation_sent_at' => $this->now->subMinutes(30),
            'confirmation_expires_at' => $this->now->addMinutes(90),
            ...$attributes,
        ]);
    }

    private function register(TournamentModel $tournament, UserModel $user, ?string $fingerprint = null): ParticipantResponseDTO
    {
        return $this->service()->register(new RegisterParticipantDTO(
            tournamentId: $tournament->id,
            userId: (string) $user->id,
            fingerprint: $fingerprint,
        ));
    }

    public function test_a_verified_user_takes_over_the_guest_row(): void
    {
        Event::fake([ParticipantRegistered::class]);
        $user = UserModel::factory()->create(['email' => 'john@example.com']);
        $tournament = $this->tournament();
        $guest = $this->guestRow($tournament, ['status' => ParticipantStatus::Confirmed->value]);

        $result = $this->register($tournament, $user);

        $this->assertSame($guest->id, $result->id);
        $this->assertTrue($result->claimedGuestRegistration);
        $this->assertTrue($result->toArray()['claimed_guest_registration']);
        $this->assertSame((string) $user->id, $result->userId);
        $this->assertSame(ParticipantStatus::Confirmed, $result->status);
        // What the user is told
        $this->assertSame('Ya tenías una inscripción como invitado; ahora está en tu cuenta.', __('tournaments::messages.messages.guest_registration_claimed'));

        $row = ParticipantModel::query()->findOrFail($guest->id);
        $this->assertSame((string) $user->id, (string) $row->user_id);
        $this->assertNull($row->guest_email);
        $this->assertNull($row->guest_name);
        $this->assertNull($row->cancellation_token);
        $this->assertNull($row->confirmation_token);
        $this->assertSame(ParticipantStatus::Confirmed, $row->status);
        $this->assertEquals($this->now->subDay(), $row->email_confirmed_at);
        $this->assertSame(1, ParticipantModel::query()->where('tournament_id', $tournament->id)->count());
    }

    public function test_the_account_email_matches_the_guest_row_in_another_case(): void
    {
        Event::fake([ParticipantRegistered::class]);
        $user = UserModel::factory()->create(['email' => 'John@Example.COM']);
        $tournament = $this->tournament();
        $guest = $this->guestRow($tournament);

        $result = $this->register($tournament, $user);

        $this->assertSame($guest->id, $result->id);
        $this->assertTrue($result->claimedGuestRegistration);
    }

    public function test_a_held_unconfirmed_row_is_confirmed_by_the_claim(): void
    {
        Event::fake([ParticipantRegistered::class]);
        $user = UserModel::factory()->create(['email' => 'john@example.com']);
        $tournament = $this->tournament();
        $guest = $this->unconfirmedGuestRow($tournament);

        $result = $this->register($tournament, $user);

        $this->assertSame($guest->id, $result->id);
        $this->assertTrue($result->claimedGuestRegistration);
        $this->assertSame(ParticipantStatus::Registered, $result->status);

        $row = ParticipantModel::query()->findOrFail($guest->id);
        $this->assertEquals($this->now, $row->email_confirmed_at);
        $this->assertNull($row->confirmation_expires_at);
        $this->assertNull($row->confirmation_token);

        // The organisation never heard of the guest registration: it hears of the user's now
        Event::assertDispatchedTimes(ParticipantRegistered::class, 1);
        Event::assertDispatched(ParticipantRegistered::class, fn (ParticipantRegistered $event): bool => $event->participantId === $guest->id
            && $event->userId === (string) $user->id
            && $event->guestEmail === null
            && $event->isGuest === false
            && $event->notifyOrganizer === true);
    }

    public function test_a_confirmed_guest_row_claim_sends_nothing(): void
    {
        Event::fake([ParticipantRegistered::class]);
        $user = UserModel::factory()->create(['email' => 'john@example.com']);
        $tournament = $this->tournament();
        $this->guestRow($tournament);

        $result = $this->register($tournament, $user);

        $this->assertTrue($result->claimedGuestRegistration);
        Event::assertNotDispatched(ParticipantRegistered::class);
    }

    public function test_an_unverified_user_is_refused(): void
    {
        Event::fake([ParticipantRegistered::class]);
        $user = UserModel::factory()->unverified()->create(['email' => 'john@example.com']);
        $tournament = $this->tournament();
        $guest = $this->guestRow($tournament);

        try {
            $this->register($tournament, $user);
            $this->fail('An unverified account took over the guest registration of its email');
        } catch (AlreadyRegisteredException $e) {
            $this->assertSame(AlreadyRegisteredException::guestWithAccountEmail($tournament->id)->getMessage(), $e->getMessage());
        }

        // The refusal the user reads
        $this->assertSame('Ya hay una inscripción con tu email.', __('tournaments::messages.errors.registration_with_your_email'));

        $row = ParticipantModel::query()->findOrFail($guest->id);
        $this->assertNull($row->user_id);
        $this->assertSame('john@example.com', $row->guest_email);
        $this->assertSame(str_repeat('c', 64), $row->cancellation_token);
        $this->assertSame(1, ParticipantModel::query()->where('tournament_id', $tournament->id)->count());
        Event::assertNotDispatched(ParticipantRegistered::class);
    }

    public function test_a_user_without_the_role_is_refused_and_the_guest_row_stays(): void
    {
        Event::fake([ParticipantRegistered::class]);
        $user = UserModel::factory()->create(['email' => 'john@example.com']);
        $tournament = $this->tournament(['allowed_roles' => ['socio']]);
        $guest = $this->unconfirmedGuestRow($tournament);

        try {
            $this->register($tournament, $user);
            $this->fail('A user without an allowed role took over a guest registration');
        } catch (UserNotAllowedToRegisterException) {
        }

        $row = ParticipantModel::query()->findOrFail($guest->id);
        $this->assertNull($row->user_id);
        $this->assertSame('john@example.com', $row->guest_email);
        $this->assertNull($row->email_confirmed_at);
        $this->assertSame(str_repeat('t', 64), $row->confirmation_token);
        Event::assertNotDispatched(ParticipantRegistered::class);
    }

    public function test_a_wait_row_claim_needs_a_seat(): void
    {
        Event::fake([ParticipantRegistered::class]);
        $user = UserModel::factory()->create(['email' => 'john@example.com']);
        $tournament = $this->tournament(['max_participants' => 1]);
        $other = $this->participant($tournament, ['guest_name' => 'Jane', 'guest_email' => 'jane@example.com']);
        $guest = $this->unconfirmedGuestRow($tournament, [
            'confirmation_mode' => GuestConfirmationMode::Wait->value,
            'confirmation_expires_at' => null,
        ]);

        try {
            $this->register($tournament, $user);
            $this->fail('A waiting guest row was taken over in a full tournament');
        } catch (TournamentFullException) {
        }

        $row = ParticipantModel::query()->findOrFail($guest->id);
        $this->assertNull($row->user_id);
        $this->assertSame('john@example.com', $row->guest_email);
        $this->assertNull($row->email_confirmed_at);
        Event::assertNotDispatched(ParticipantRegistered::class);

        // Once a seat frees up the claim takes it
        $other->update(['status' => ParticipantStatus::Withdrawn->value]);

        $result = $this->register($tournament, $user);

        $this->assertSame($guest->id, $result->id);
        $this->assertTrue($result->claimedGuestRegistration);
        $this->assertSame(1, (new EloquentParticipantRepository)->countActiveByTournament($tournament->id));
        Event::assertDispatchedTimes(ParticipantRegistered::class, 1);
    }

    public function test_a_user_with_a_withdrawn_registration_takes_over_the_guest_row_in_their_own(): void
    {
        Event::fake([ParticipantRegistered::class]);
        $user = UserModel::factory()->create(['email' => 'john@example.com']);
        $tournament = $this->tournament();
        $own = $this->participant($tournament, [
            'user_id' => $user->id,
            'status' => ParticipantStatus::Withdrawn->value,
            'email_confirmed_at' => null,
        ]);
        $guest = $this->unconfirmedGuestRow($tournament);
        $repository = new EloquentParticipantRepository;
        $this->assertSame(1, $repository->countActiveByTournament($tournament->id));

        $result = $this->register($tournament, $user);

        $this->assertSame($own->id, $result->id);
        $this->assertTrue($result->claimedGuestRegistration);
        $this->assertSame(ParticipantStatus::Registered, $result->status);

        $ownRow = ParticipantModel::query()->findOrFail($own->id);
        $this->assertSame(ParticipantStatus::Registered, $ownRow->status);
        $this->assertEquals($this->now, $ownRow->email_confirmed_at);
        $this->assertSame(GuestConfirmationMode::Hold, $ownRow->confirmation_mode);
        $this->assertNull($ownRow->confirmation_token);
        $this->assertNull($ownRow->confirmation_expires_at);

        $guestRow = ParticipantModel::query()->findOrFail($guest->id);
        $this->assertSame(ParticipantStatus::Withdrawn, $guestRow->status);
        $this->assertNull($guestRow->user_id);
        $this->assertNull($guestRow->cancellation_token);
        $this->assertNull($guestRow->confirmation_token);
        $this->assertNull($guestRow->confirmation_expires_at);

        $this->assertSame(1, $repository->countActiveByTournament($tournament->id));
        Event::assertDispatchedTimes(ParticipantRegistered::class, 1);
        Event::assertDispatched(ParticipantRegistered::class, fn (ParticipantRegistered $event): bool => $event->participantId === $own->id);
    }

    public function test_a_withdrawn_user_taking_over_a_checked_in_guest_keeps_the_check_in(): void
    {
        Event::fake([ParticipantRegistered::class]);
        $user = UserModel::factory()->create(['email' => 'john@example.com']);
        $tournament = $this->tournament();
        $own = $this->participant($tournament, ['user_id' => $user->id, 'status' => ParticipantStatus::Withdrawn->value]);
        $checkedInAt = $this->now->subMinutes(10);
        $this->guestRow($tournament, ['status' => ParticipantStatus::CheckedIn->value, 'checked_in_at' => $checkedInAt]);

        $result = $this->register($tournament, $user);

        $this->assertSame($own->id, $result->id);
        $this->assertSame(ParticipantStatus::CheckedIn, $result->status);
        $ownRow = ParticipantModel::query()->findOrFail($own->id);
        $this->assertEquals($checkedInAt, $ownRow->checked_in_at);
        $this->assertEquals($this->now->subDay(), $ownRow->email_confirmed_at);
        Event::assertNotDispatched(ParticipantRegistered::class);
    }

    public function test_an_inactive_guest_row_is_left_alone(): void
    {
        Event::fake([ParticipantRegistered::class]);
        $user = UserModel::factory()->create(['email' => 'john@example.com']);
        $tournament = $this->tournament();
        $guest = $this->guestRow($tournament, ['status' => ParticipantStatus::Withdrawn->value]);

        $result = $this->register($tournament, $user);

        $this->assertNotSame($guest->id, $result->id);
        $this->assertFalse($result->claimedGuestRegistration);
        $this->assertFalse($result->toArray()['claimed_guest_registration']);
        $this->assertSame('john@example.com', ParticipantModel::query()->findOrFail($guest->id)->guest_email);
        Event::assertDispatchedTimes(ParticipantRegistered::class, 1);
    }

    public function test_an_overdue_guest_row_lapses_instead_of_being_taken_over(): void
    {
        Event::fake([ParticipantRegistered::class]);
        $user = UserModel::factory()->create(['email' => 'john@example.com']);
        $tournament = $this->tournament(['max_participants' => 1]);
        $guest = $this->unconfirmedGuestRow($tournament, ['confirmation_expires_at' => $this->now->subMinute()]);

        $result = $this->register($tournament, $user);

        $this->assertNotSame($guest->id, $result->id);
        $this->assertFalse($result->claimedGuestRegistration);
        $this->assertSame(ParticipantStatus::Expired, ParticipantModel::query()->findOrFail($guest->id)->status);
        $this->assertSame(1, (new EloquentParticipantRepository)->countActiveByTournament($tournament->id));
        Event::assertDispatchedTimes(ParticipantRegistered::class, 1);
    }

    public function test_a_new_user_row_records_the_fingerprint(): void
    {
        Event::fake([ParticipantRegistered::class]);
        $user = UserModel::factory()->create();
        $tournament = $this->tournament();

        $result = $this->register($tournament, $user, str_repeat('f', 64));

        $this->assertFalse($result->claimedGuestRegistration);
        $this->assertSame(str_repeat('f', 64), ParticipantModel::query()->findOrFail($result->id)->fingerprint);
    }

    public function test_the_organiser_mail_is_skipped_when_not_notifying(): void
    {
        // AnonymousNotifiable has no key, so Notification::fake() cannot record it: read the array mailer
        $user = UserModel::factory()->create(['email' => 'john@example.com']);
        $tournament = $this->tournament();
        $row = $this->participant($tournament, ['user_id' => $user->id]);
        $listener = app(SendParticipantRegisteredEmails::class);

        $listener->handle(ParticipantRegistered::create(
            participantId: $row->id,
            tournamentId: $tournament->id,
            userId: (string) $user->id,
            guestEmail: null,
            isGuest: false,
            notifyOrganizer: false,
        ));

        $this->assertSame(['john@example.com'], $this->mailRecipients());

        $listener->handle(ParticipantRegistered::create(
            participantId: $row->id,
            tournamentId: $tournament->id,
            userId: (string) $user->id,
            guestEmail: null,
            isGuest: false,
        ));

        $this->assertSame(['john@example.com', 'john@example.com', 'organizacion@example.com'], $this->mailRecipients());
    }

    /**
     * @return list<string>
     */
    private function mailRecipients(): array
    {
        $transport = Mail::mailer('array')->getSymfonyTransport();
        $this->assertInstanceOf(ArrayTransport::class, $transport);
        /** @var list<SentMessage> $messages */
        $messages = $transport->messages()->all();

        return array_map(function (SentMessage $message): string {
            $email = $message->getOriginalMessage();
            $this->assertInstanceOf(Email::class, $email);

            return $email->getTo()[0]->getAddress();
        }, $messages);
    }
}
