<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Integration\Infrastructure\Services;

use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Tournaments\Application\DTOs\RegisterParticipantDTO;
use Modules\Tournaments\Application\DTOs\Response\GuestRegistrationOutcome;
use Modules\Tournaments\Application\DTOs\Response\GuestRegistrationResultDTO;
use Modules\Tournaments\Application\Services\ParticipantManagementServiceInterface;
use Modules\Tournaments\Domain\Enums\GuestConfirmationMode;
use Modules\Tournaments\Domain\Enums\ParticipantStatus;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Domain\Events\GuestConfirmationRequested;
use Modules\Tournaments\Domain\Events\ParticipantRegistered;
use Modules\Tournaments\Domain\Exceptions\AlreadyRegisteredException;
use Modules\Tournaments\Domain\Exceptions\GuestEmailBelongsToAccountException;
use Modules\Tournaments\Domain\Exceptions\GuestRegistrationNotAllowedException;
use Modules\Tournaments\Domain\Exceptions\TournamentFullException;
use Modules\Tournaments\Domain\Exceptions\TournamentNotOpenException;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\ParticipantModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Repositories\EloquentParticipantRepository;
use Modules\Tournaments\Notifications\AnonymousNotifiable;
use Modules\Tournaments\Notifications\GuestConfirmationRequestNotification;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;
use Tests\Support\Modules\ModuleTestCase;

/**
 * Guests register through registerGuest(): one registration per email whatever its case, confirmed
 * by email before the organisation hears of it, holding a seat for a limited time only.
 */
final class GuestRegistrationTest extends ModuleTestCase
{
    protected ?string $moduleName = 'tournaments';

    protected bool $autoEnableModule = true;

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = CarbonImmutable::parse('2026-11-02 10:00:00');
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
    private function tournament(array $attributes = [], ?DateTimeInterface $eventStartsAt = null): TournamentModel
    {
        return TournamentModel::create([
            'event_id' => EventModel::factory()->create(['start_date' => $eventStartsAt ?? $this->now->addDays(3)])->id,
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
            'registered_at' => $this->now,
            ...$attributes,
        ]);
    }

    private function registerGuest(
        TournamentModel $tournament,
        string $email = 'ana@example.com',
        string $name = 'Ana',
        ?string $fingerprint = null,
    ): GuestRegistrationResultDTO {
        return $this->service()->registerGuest(new RegisterParticipantDTO(
            tournamentId: $tournament->id,
            guestName: $name,
            guestEmail: $email,
            fingerprint: $fingerprint,
        ));
    }

    private function onlyRow(TournamentModel $tournament): ParticipantModel
    {
        $rows = ParticipantModel::query()->where('tournament_id', $tournament->id)->get();
        $this->assertCount(1, $rows);

        return $rows->firstOrFail();
    }

    private function fakeEvents(): void
    {
        Event::fake([GuestConfirmationRequested::class, ParticipantRegistered::class]);
    }

    public function test_a_new_guest_waits_for_confirmation_holding_a_seat(): void
    {
        $this->fakeEvents();
        $tournament = $this->tournament();

        $result = $this->registerGuest($tournament, fingerprint: str_repeat('a', 64));

        $this->assertSame(GuestRegistrationOutcome::ConfirmationSent, $result->outcome);
        $this->assertTrue($result->seatHeld);
        $this->assertEquals($this->now->addHours(2), $result->expiresAt);

        $row = $this->onlyRow($tournament);
        $this->assertSame($row->id, $result->participantId);
        $this->assertSame($tournament->id, $result->tournamentId);
        $this->assertSame(ParticipantStatus::Registered, $row->status);
        $this->assertNull($row->email_confirmed_at);
        $this->assertSame(GuestConfirmationMode::Hold, $row->confirmation_mode);
        $this->assertSame($this->now->addHours(2)->format('Y-m-d H:i:s'), $row->confirmation_expires_at?->format('Y-m-d H:i:s'));
        $this->assertSame($this->now->format('Y-m-d H:i:s'), $row->confirmation_sent_at?->format('Y-m-d H:i:s'));
        $this->assertSame(64, strlen((string) $row->confirmation_token));
        $this->assertNotNull($row->cancellation_token);
        $this->assertNotSame($row->cancellation_token, $row->confirmation_token);
        $this->assertSame(str_repeat('a', 64), $row->fingerprint);

        Event::assertDispatchedTimes(GuestConfirmationRequested::class, 1);
        Event::assertDispatched(GuestConfirmationRequested::class, fn (GuestConfirmationRequested $event): bool => $event->participantId === $row->id
            && $event->tournamentId === $tournament->id
            && $event->guestEmail === 'ana@example.com'
            && $event->guestName === 'Ana'
            && $event->confirmationToken === $row->confirmation_token
            && $event->seatHeld
            && $event->expiresAt?->format('Y-m-d H:i:s') === $this->now->addHours(2)->format('Y-m-d H:i:s'));
        Event::assertNotDispatched(ParticipantRegistered::class);
    }

    public function test_the_deadline_never_passes_the_registration_close(): void
    {
        $this->fakeEvents();
        $closingSoon = $this->tournament(['registration_closes_at' => $this->now->addMinutes(10)]);
        $startingSoon = $this->tournament(eventStartsAt: $this->now->addMinutes(30));

        $this->assertEquals($this->now->addMinutes(10), $this->registerGuest($closingSoon)->expiresAt);
        $this->assertSame(
            $this->now->addMinutes(10)->format('Y-m-d H:i:s'),
            $this->onlyRow($closingSoon)->confirmation_expires_at?->format('Y-m-d H:i:s'),
        );

        $this->assertEquals($this->now->addMinutes(30), $this->registerGuest($startingSoon)->expiresAt);
        $this->assertSame(
            $this->now->addMinutes(30)->format('Y-m-d H:i:s'),
            $this->onlyRow($startingSoon)->confirmation_expires_at?->format('Y-m-d H:i:s'),
        );
    }

    public function test_the_same_email_in_another_case_is_pending_not_new(): void
    {
        $this->fakeEvents();
        $tournament = $this->tournament();
        $this->registerGuest($tournament, 'ana@example.com', 'Ana');
        $before = $this->onlyRow($tournament);

        $result = $this->registerGuest($tournament, ' ANA@example.com ', 'Otra persona');

        $this->assertSame(GuestRegistrationOutcome::ConfirmationPending, $result->outcome);
        $this->assertSame($before->id, $result->participantId);
        $after = $this->onlyRow($tournament);
        $this->assertSame('Ana', $after->guest_name);
        $this->assertSame('ana@example.com', $after->guest_email);
        $this->assertEquals($before->confirmation_expires_at, $after->confirmation_expires_at);
        $this->assertSame($before->confirmation_token, $after->confirmation_token);
        Event::assertDispatchedTimes(GuestConfirmationRequested::class, 1);
    }

    public function test_a_resubmit_after_five_minutes_resends_without_extending(): void
    {
        $this->fakeEvents();
        $tournament = $this->tournament();
        $this->registerGuest($tournament);
        $expiresAt = $this->onlyRow($tournament)->confirmation_expires_at;

        $this->travelTo($this->now->addMinutes(4));
        $pending = $this->registerGuest($tournament, name: 'Ana María');

        $this->assertSame(GuestRegistrationOutcome::ConfirmationPending, $pending->outcome);
        Event::assertDispatchedTimes(GuestConfirmationRequested::class, 1);

        $this->travelTo($this->now->addMinutes(6));
        $resent = $this->registerGuest($tournament, name: 'Ana María');

        $this->assertSame(GuestRegistrationOutcome::ConfirmationResent, $resent->outcome);
        $this->assertTrue($resent->seatHeld);
        $this->assertEquals($this->now->addHours(2), $resent->expiresAt);
        Event::assertDispatchedTimes(GuestConfirmationRequested::class, 2);

        $row = $this->onlyRow($tournament);
        $this->assertEquals($expiresAt, $row->confirmation_expires_at);
        $this->assertSame('Ana', $row->guest_name);
        $this->assertSame($this->now->addMinutes(6)->format('Y-m-d H:i:s'), $row->confirmation_sent_at?->format('Y-m-d H:i:s'));

        // The margin counts from the last send
        $this->travelTo($this->now->addMinutes(10));
        $this->assertSame(GuestRegistrationOutcome::ConfirmationPending, $this->registerGuest($tournament)->outcome);
        Event::assertDispatchedTimes(GuestConfirmationRequested::class, 2);
    }

    public function test_a_confirmed_guest_cannot_register_twice(): void
    {
        $this->fakeEvents();
        $tournament = $this->tournament();
        $this->participant($tournament, [
            'guest_name' => 'Ana',
            'guest_email' => 'ana@example.com',
            'email_confirmed_at' => $this->now->subDay(),
        ]);

        try {
            $this->registerGuest($tournament, 'Ana@Example.com');
            $this->fail('A confirmed guest registered twice');
        } catch (AlreadyRegisteredException $e) {
            // Exception messages reach the logs: ids, never the email
            $this->assertStringNotContainsString('ana@example.com', $e->getMessage());
            $this->assertStringContainsString($tournament->id, $e->getMessage());
        }

        $this->onlyRow($tournament);
        Event::assertNotDispatched(GuestConfirmationRequested::class);
    }

    public function test_a_pending_guest_in_a_full_tournament_gets_the_pending_answer(): void
    {
        $this->fakeEvents();
        $tournament = $this->tournament(['max_participants' => 1]);
        $this->registerGuest($tournament);

        $result = $this->registerGuest($tournament);

        $this->assertSame(GuestRegistrationOutcome::ConfirmationPending, $result->outcome);
    }

    public function test_over_the_cap_a_guest_waits_without_a_seat(): void
    {
        $this->fakeEvents();
        $tournament = $this->tournament();
        $this->registerGuest($tournament, 'uno@example.com', 'Uno');
        $this->registerGuest($tournament, 'dos@example.com', 'Dos');

        $result = $this->registerGuest($tournament, 'tres@example.com', 'Tres');

        $this->assertSame(GuestRegistrationOutcome::ConfirmationSent, $result->outcome);
        $this->assertFalse($result->seatHeld);
        $this->assertNull($result->expiresAt);
        $row = ParticipantModel::query()->findOrFail($result->participantId);
        $this->assertSame(GuestConfirmationMode::Wait, $row->confirmation_mode);
        $this->assertNull($row->confirmation_expires_at);
        $this->assertSame(2, (new EloquentParticipantRepository)->countActiveByTournament($tournament->id));
        Event::assertDispatched(GuestConfirmationRequested::class, fn (GuestConfirmationRequested $event): bool => $event->participantId === $row->id
            && ! $event->seatHeld
            && $event->expiresAt === null);
    }

    public function test_wait_mode_setting_never_holds_a_seat(): void
    {
        $this->fakeEvents();
        config()->set('modules.settings.tournaments', ['guest_confirmation' => ['mode' => 'wait']]);
        $tournament = $this->tournament();

        $result = $this->registerGuest($tournament);

        $this->assertSame(GuestRegistrationOutcome::ConfirmationSent, $result->outcome);
        $this->assertFalse($result->seatHeld);
        $this->assertNull($result->expiresAt);
        $row = $this->onlyRow($tournament);
        $this->assertSame(GuestConfirmationMode::Wait, $row->confirmation_mode);
        $this->assertNull($row->confirmation_expires_at);
        $this->assertSame(0, (new EloquentParticipantRepository)->countActiveByTournament($tournament->id));
    }

    public function test_a_full_tournament_refuses_the_guest(): void
    {
        $this->fakeEvents();
        $tournament = $this->tournament(['max_participants' => 1]);
        $this->participant($tournament, ['user_id' => UserModel::factory()->create()->id]);

        $this->expectException(TournamentFullException::class);
        $this->expectExceptionMessage('has reached its maximum of 1 participants');

        $this->registerGuest($tournament);
    }

    public function test_an_account_email_asks_to_sign_in(): void
    {
        $this->fakeEvents();
        UserModel::factory()->create(['email' => 'Ana@Example.com']);
        $tournament = $this->tournament();

        try {
            $this->registerGuest($tournament, ' ana@example.COM ');
            $this->fail('A guest registered with the email of an account');
        } catch (GuestEmailBelongsToAccountException $e) {
            $this->assertStringContainsString($tournament->id, $e->getMessage());
        }

        $this->assertSame(0, ParticipantModel::query()->where('tournament_id', $tournament->id)->count());
        Event::assertNotDispatched(GuestConfirmationRequested::class);
    }

    public function test_an_account_that_could_not_register_is_treated_as_a_guest(): void
    {
        $this->fakeEvents();
        UserModel::factory()->create(['email' => 'ana@example.com']);
        $tournament = $this->tournament(['allowed_roles' => ['socio']]);

        $result = $this->registerGuest($tournament);

        $this->assertSame(GuestRegistrationOutcome::ConfirmationSent, $result->outcome);
        $this->assertNull($this->onlyRow($tournament)->user_id);
    }

    public function test_allow_guest_policy_admits_the_account_email_unless_it_is_registered(): void
    {
        $this->fakeEvents();
        config()->set('modules.settings.tournaments', ['guest_accounts' => ['mode' => 'allow_guest']]);
        $account = UserModel::factory()->create(['email' => 'ana@example.com']);
        $open = $this->tournament();
        $registered = $this->tournament();
        $this->participant($registered, ['user_id' => $account->id]);

        $this->assertSame(GuestRegistrationOutcome::ConfirmationSent, $this->registerGuest($open)->outcome);

        try {
            $this->registerGuest($registered);
            $this->fail('A guest registered with the email of an account already registered');
        } catch (AlreadyRegisteredException $e) {
            $this->assertStringContainsString((string) $account->id, $e->getMessage());
        }

        $this->onlyRow($registered);
    }

    public function test_an_unverified_account_email_does_not_ask_to_sign_in(): void
    {
        $this->fakeEvents();
        UserModel::factory()->unverified()->create(['email' => 'ana@example.com']);
        $tournament = $this->tournament();

        $result = $this->registerGuest($tournament);

        $this->assertSame(GuestRegistrationOutcome::ConfirmationSent, $result->outcome);
        $this->assertNull($this->onlyRow($tournament)->user_id);
    }

    public function test_an_unverified_account_already_registered_does_not_block_the_guest(): void
    {
        $this->fakeEvents();
        config()->set('modules.settings.tournaments', ['guest_accounts' => ['mode' => 'allow_guest']]);
        $account = UserModel::factory()->unverified()->create(['email' => 'ana@example.com']);
        $tournament = $this->tournament();
        $this->participant($tournament, ['user_id' => $account->id]);

        $result = $this->registerGuest($tournament);

        $this->assertSame(GuestRegistrationOutcome::ConfirmationSent, $result->outcome);
        $this->assertSame(2, ParticipantModel::query()->where('tournament_id', $tournament->id)->count());
    }

    public function test_an_overdue_registration_not_yet_expired_is_replaced_by_a_new_one(): void
    {
        $this->fakeEvents();
        $tournament = $this->tournament(['max_participants' => 1]);
        $this->registerGuest($tournament);
        $before = $this->onlyRow($tournament);

        $later = $this->now->addHours(2)->addMinute();
        $this->travelTo($later);
        $result = $this->registerGuest($tournament, name: 'Ana María');

        $this->assertSame(GuestRegistrationOutcome::ConfirmationSent, $result->outcome);
        $this->assertSame($before->id, $result->participantId);
        $this->assertTrue($result->seatHeld);
        $this->assertEquals($later->addHours(2), $result->expiresAt);

        $row = $this->onlyRow($tournament);
        $this->assertSame(ParticipantStatus::Registered, $row->status);
        $this->assertSame('Ana María', $row->guest_name);
        $this->assertSame($later->addHours(2)->format('Y-m-d H:i:s'), $row->confirmation_expires_at?->format('Y-m-d H:i:s'));
        $this->assertNotSame($before->confirmation_token, $row->confirmation_token);
        $this->assertNotSame($before->cancellation_token, $row->cancellation_token);
        Event::assertDispatchedTimes(GuestConfirmationRequested::class, 2);
        Event::assertDispatched(GuestConfirmationRequested::class, fn (GuestConfirmationRequested $event): bool => $event->confirmationToken === $row->confirmation_token
            && $event->expiresAt?->format('Y-m-d H:i:s') === $later->addHours(2)->format('Y-m-d H:i:s'));
    }

    /**
     * @return iterable<string, array{ParticipantStatus}>
     */
    public static function endedStatuses(): iterable
    {
        yield 'expired' => [ParticipantStatus::Expired];
        yield 'withdrawn' => [ParticipantStatus::Withdrawn];
    }

    #[DataProvider('endedStatuses')]
    public function test_an_expired_registration_is_reactivated_with_a_new_token(ParticipantStatus $status): void
    {
        $this->fakeEvents();
        $tournament = $this->tournament();
        $old = $this->participant($tournament, [
            'guest_name' => 'Ana',
            'guest_email' => 'ana@example.com',
            'status' => $status->value,
            'cancellation_token' => str_repeat('c', 64),
            'confirmation_token' => str_repeat('t', 64),
            'confirmation_sent_at' => $this->now->subMinute(),
            'email_confirmed_at' => $status === ParticipantStatus::Withdrawn ? $this->now->subDay() : null,
        ]);

        $result = $this->registerGuest($tournament, 'ANA@example.com', 'Ana María', str_repeat('f', 64));

        $this->assertSame(GuestRegistrationOutcome::ConfirmationSent, $result->outcome);
        $this->assertSame($old->id, $result->participantId);
        $row = $this->onlyRow($tournament);
        $this->assertSame(ParticipantStatus::Registered, $row->status);
        $this->assertSame('Ana María', $row->guest_name);
        $this->assertNull($row->email_confirmed_at);
        $this->assertSame(GuestConfirmationMode::Hold, $row->confirmation_mode);
        $this->assertSame($this->now->addHours(2)->format('Y-m-d H:i:s'), $row->confirmation_expires_at?->format('Y-m-d H:i:s'));
        $this->assertNotNull($row->cancellation_token);
        $this->assertNotSame(str_repeat('c', 64), $row->cancellation_token);
        $this->assertNotSame(str_repeat('t', 64), $row->confirmation_token);
        $this->assertSame(64, strlen((string) $row->confirmation_token));
        $this->assertSame(str_repeat('f', 64), $row->fingerprint);
        Event::assertDispatchedTimes(GuestConfirmationRequested::class, 1);
        Event::assertNotDispatched(ParticipantRegistered::class);
    }

    public function test_a_disqualified_guest_cannot_register_again(): void
    {
        $this->fakeEvents();
        $tournament = $this->tournament();
        $this->participant($tournament, [
            'guest_name' => 'Ana',
            'guest_email' => 'ana@example.com',
            'status' => ParticipantStatus::Disqualified->value,
        ]);

        try {
            $this->registerGuest($tournament);
            $this->fail('A disqualified guest registered again');
        } catch (AlreadyRegisteredException $e) {
            $this->assertStringContainsString('disqualified', $e->getMessage());
        }

        $this->onlyRow($tournament);
    }

    public function test_a_guest_cannot_register_when_guests_are_not_allowed(): void
    {
        $this->fakeEvents();
        $tournament = $this->tournament(['allow_guests' => false]);

        $this->expectException(GuestRegistrationNotAllowedException::class);

        $this->registerGuest($tournament);
    }

    public function test_a_guest_cannot_register_once_the_event_has_started(): void
    {
        $this->fakeEvents();
        $tournament = $this->tournament(eventStartsAt: $this->now->subMinute());

        try {
            $this->registerGuest($tournament);
            $this->fail('A guest registered after the event started');
        } catch (TournamentNotOpenException $e) {
            $this->assertTrue($e->eventHasStarted());
        }

        $this->assertSame(0, ParticipantModel::query()->where('tournament_id', $tournament->id)->count());
    }

    public function test_a_guest_cannot_register_after_the_closing_date(): void
    {
        $this->fakeEvents();
        $tournament = $this->tournament(['registration_closes_at' => $this->now->subMinute()]);

        $this->expectException(TournamentNotOpenException::class);
        $this->expectExceptionMessage('already closed');

        $this->registerGuest($tournament);
    }

    public function test_a_guest_dto_to_register_is_rejected(): void
    {
        $tournament = $this->tournament();

        $this->expectException(InvalidArgumentException::class);

        $this->service()->register(new RegisterParticipantDTO($tournament->id, guestName: 'Ana', guestEmail: 'ana@example.com'));
    }

    public function test_the_listener_mails_the_confirmation_to_the_guest(): void
    {
        Event::fake([ParticipantRegistered::class]);
        $tournament = $this->tournament();

        $this->registerGuest($tournament, ' Ana@Example.com ');

        /** @var list<SentMessage> $messages */
        $messages = Mail::mailer('array')->getSymfonyTransport()->messages()->all();
        $this->assertCount(1, $messages);
        $email = $messages[0]->getOriginalMessage();
        $this->assertInstanceOf(Email::class, $email);
        $this->assertSame('ana@example.com', $email->getTo()[0]->getAddress());
        $this->assertSame('Confirma tu inscripción en Torneo de primavera', $email->getSubject());
        $this->assertStringContainsString('/torneos/confirmar/'.$this->onlyRow($tournament)->confirmation_token, (string) $email->getHtmlBody());
    }

    public function test_the_confirmation_email_copy(): void
    {
        $notifiable = new AnonymousNotifiable('ana@example.com', 'Ana');

        $hold = (new GuestConfirmationRequestNotification(
            tournamentName: 'Torneo de primavera',
            guestName: 'Ana',
            confirmationToken: 'token-de-prueba',
            expiresAt: new DateTimeImmutable('2026-11-02 12:00:00'),
            seatHeld: true,
        ))->toMail($notifiable);

        $this->assertSame('Confirma tu inscripción en Torneo de primavera', $hold->subject);
        $this->assertSame('Hola, Ana', $hold->greeting);
        $this->assertSame([
            'Has pedido inscribirte en Torneo de primavera. Confirma la inscripción con el botón.',
            'Si no la confirmas antes del 02/11/2026 12:00, la plaza se liberará.',
        ], $hold->introLines);
        $this->assertSame('Confirmar inscripción', $hold->actionText);
        $this->assertSame(url('/torneos/confirmar/token-de-prueba'), $hold->actionUrl);
        $this->assertSame(['Si no has sido tú, ignora este mensaje.'], $hold->outroLines);

        $wait = (new GuestConfirmationRequestNotification(
            tournamentName: 'Torneo de primavera',
            guestName: 'Ana',
            confirmationToken: 'token-de-prueba',
            expiresAt: null,
            seatHeld: false,
        ))->toMail($notifiable);

        $this->assertSame([
            'Has pedido inscribirte en Torneo de primavera. Confirma la inscripción con el botón.',
            'Tu plaza no está garantizada hasta que la confirmes.',
        ], $wait->introLines);
        $this->assertSame(url('/torneos/confirmar/token-de-prueba'), $wait->actionUrl);
    }
}
