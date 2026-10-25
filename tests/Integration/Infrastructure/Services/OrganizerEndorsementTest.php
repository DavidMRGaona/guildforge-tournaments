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
use Modules\Tournaments\Application\Services\ParticipantManagementServiceInterface;
use Modules\Tournaments\Domain\Enums\GuestConfirmationMode;
use Modules\Tournaments\Domain\Enums\ParticipantStatus;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Domain\Events\GuestConfirmationRequested;
use Modules\Tournaments\Domain\Events\ParticipantRegistered;
use Modules\Tournaments\Domain\Exceptions\AlreadyRegisteredException;
use Modules\Tournaments\Domain\Exceptions\TournamentFullException;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\ParticipantModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;
use Tests\Support\Modules\ModuleTestCase;

/**
 * The organisation acting on a guest registration vouches for its email; registrations it adds
 * itself need no confirmation.
 */
final class OrganizerEndorsementTest extends ModuleTestCase
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
    private function tournament(array $attributes = []): TournamentModel
    {
        return TournamentModel::create([
            'event_id' => EventModel::factory()->create(['start_date' => $this->now->addDays(3)])->id,
            'name' => 'Torneo de primavera',
            'slug' => 'torneo-'.Str::lower(Str::random(10)),
            'status' => TournamentStatus::RegistrationOpen->value,
            'result_reporting' => 'admin_only',
            'allow_guests' => true,
            'requires_manual_confirmation' => true,
            'notification_email' => 'organizacion@example.com',
            ...$attributes,
        ]);
    }

    /**
     * A guest registration awaiting confirmation, as the form leaves it.
     */
    private function pendingGuest(TournamentModel $tournament, string $email = 'john@example.com'): ParticipantModel
    {
        $result = $this->service()->registerGuest(new RegisterParticipantDTO(
            tournamentId: $tournament->id,
            guestName: 'John',
            guestEmail: $email,
            fingerprint: str_repeat('a', 64),
        ));

        return ParticipantModel::query()->findOrFail($result->participantId);
    }

    private function confirmedGuest(TournamentModel $tournament, string $email): ParticipantModel
    {
        return ParticipantModel::create([
            'id' => fake()->uuid(),
            'tournament_id' => $tournament->id,
            'status' => ParticipantStatus::Registered->value,
            'guest_name' => 'Jane',
            'guest_email' => $email,
            'registered_at' => $this->now,
            'email_confirmed_at' => $this->now->subDay(),
        ]);
    }

    private function fakeEvents(): void
    {
        Event::fake([GuestConfirmationRequested::class, ParticipantRegistered::class]);
    }

    public function test_confirm_endorses_an_unconfirmed_guest(): void
    {
        $this->fakeEvents();
        $row = $this->pendingGuest($this->tournament());

        $this->service()->confirm($row->id);

        $row->refresh();
        $this->assertSame(ParticipantStatus::Confirmed, $row->status);
        $this->assertEquals($this->now, $row->email_confirmed_at);
        $this->assertNull($row->confirmation_expires_at);

        Event::assertDispatchedTimes(ParticipantRegistered::class, 1);
        Event::assertDispatched(ParticipantRegistered::class, fn (ParticipantRegistered $event): bool => $event->participantId === $row->id
            && $event->isGuest
            && $event->userId === null
            && $event->guestEmail === 'john@example.com'
            && $event->cancellationToken !== null
            && $event->cancellationToken === $row->cancellation_token
            && ! $event->notifyOrganizer);
    }

    public function test_confirming_by_the_organisation_mails_the_guest_only(): void
    {
        Event::fake([GuestConfirmationRequested::class]);
        $row = $this->pendingGuest($this->tournament());

        $this->service()->confirm($row->id);

        $transport = Mail::mailer('array')->getSymfonyTransport();
        $this->assertInstanceOf(ArrayTransport::class, $transport);
        /** @var list<SentMessage> $messages */
        $messages = $transport->messages()->all();
        $recipients = [];
        foreach ($messages as $message) {
            $email = $message->getOriginalMessage();
            $this->assertInstanceOf(Email::class, $email);
            $recipients[] = $email->getTo()[0]->getAddress();
        }

        $this->assertSame(['john@example.com'], $recipients);
    }

    public function test_confirming_a_confirmed_guest_sends_nothing(): void
    {
        $this->fakeEvents();
        $row = $this->confirmedGuest($this->tournament(), 'jane@example.com');

        $this->service()->confirm($row->id);

        $row->refresh();
        $this->assertSame(ParticipantStatus::Confirmed, $row->status);
        $this->assertEquals($this->now->subDay(), $row->email_confirmed_at);
        Event::assertNotDispatched(ParticipantRegistered::class);
    }

    public function test_check_in_endorses_silently(): void
    {
        $this->fakeEvents();
        $row = $this->pendingGuest($this->tournament(['requires_manual_confirmation' => false]));

        // Straight from registered, as the panel allows without manual confirmation
        $this->service()->checkIn($row->id);

        $row->refresh();
        $this->assertSame(ParticipantStatus::CheckedIn, $row->status);
        $this->assertEquals($this->now, $row->checked_in_at);
        $this->assertEquals($this->now, $row->email_confirmed_at);
        $this->assertNull($row->confirmation_expires_at);
        Event::assertNotDispatched(ParticipantRegistered::class);
    }

    public function test_bulk_check_in_endorses_silently(): void
    {
        $this->fakeEvents();
        $tournament = $this->tournament(['requires_manual_confirmation' => false]);
        $first = $this->pendingGuest($tournament, 'john@example.com');
        $second = $this->pendingGuest($tournament, 'mary@example.com');

        $results = $this->service()->bulkCheckIn([$first->id, $second->id]);

        $this->assertCount(2, $results);
        foreach ([$first, $second] as $row) {
            $row->refresh();
            $this->assertSame(ParticipantStatus::CheckedIn, $row->status);
            $this->assertEquals($this->now, $row->email_confirmed_at);
        }
        Event::assertNotDispatched(ParticipantRegistered::class);
    }

    public function test_disqualify_endorses_silently(): void
    {
        $this->fakeEvents();
        $row = $this->pendingGuest($this->tournament());

        // Straight from registered, as the panel allows
        $this->service()->disqualify($row->id);

        $row->refresh();
        $this->assertSame(ParticipantStatus::Disqualified, $row->status);
        $this->assertEquals($this->now, $row->email_confirmed_at);
        Event::assertNotDispatched(ParticipantRegistered::class);
    }

    public function test_bulk_confirm_sends_one_email_per_unconfirmed_row_only(): void
    {
        $this->fakeEvents();
        $tournament = $this->tournament();
        $first = $this->pendingGuest($tournament, 'john@example.com');
        $second = $this->pendingGuest($tournament, 'mary@example.com');
        $confirmed = $this->confirmedGuest($tournament, 'jane@example.com');

        foreach ([$first, $second, $confirmed] as $row) {
            $this->service()->confirm($row->id);
        }

        Event::assertDispatchedTimes(ParticipantRegistered::class, 2);
        $announced = [];
        Event::assertDispatched(ParticipantRegistered::class, function (ParticipantRegistered $event) use (&$announced): bool {
            $announced[] = $event->participantId;

            return true;
        });
        sort($announced);
        $expected = [$first->id, $second->id];
        sort($expected);
        $this->assertSame($expected, $announced);
    }

    public function test_marking_the_email_confirmed_announces_it_to_the_guest_only(): void
    {
        $this->fakeEvents();
        $row = $this->pendingGuest($this->tournament());

        $this->service()->confirmEmailByOrganization($row->id);

        $row->refresh();
        $this->assertSame(ParticipantStatus::Registered, $row->status);
        $this->assertEquals($this->now, $row->email_confirmed_at);
        $this->assertNull($row->confirmation_expires_at);
        Event::assertDispatchedTimes(ParticipantRegistered::class, 1);
        Event::assertDispatched(ParticipantRegistered::class, fn (ParticipantRegistered $event): bool => $event->participantId === $row->id
            && $event->cancellationToken === $row->cancellation_token
            && ! $event->notifyOrganizer);

        // A second click changes and sends nothing
        $this->service()->confirmEmailByOrganization($row->id);
        Event::assertDispatchedTimes(ParticipantRegistered::class, 1);
    }

    public function test_endorsing_a_wait_row_in_a_full_tournament_fails(): void
    {
        $this->fakeEvents();
        config()->set('modules.settings.tournaments', ['guest_confirmation' => ['mode' => 'wait']]);
        $tournament = $this->tournament(['max_participants' => 1, 'requires_manual_confirmation' => false]);
        $row = $this->pendingGuest($tournament);
        $this->assertSame(GuestConfirmationMode::Wait, $row->confirmation_mode);
        $this->confirmedGuest($tournament, 'jane@example.com');

        foreach ([
            fn () => $this->service()->confirmEmailByOrganization($row->id),
            fn () => $this->service()->confirm($row->id),
            fn () => $this->service()->checkIn($row->id),
        ] as $endorse) {
            try {
                $endorse();
                $this->fail('A registration waiting without a seat took one in a full tournament.');
            } catch (TournamentFullException) {
                $row->refresh();
                $this->assertSame(ParticipantStatus::Registered, $row->status);
                $this->assertNull($row->email_confirmed_at);
            }
        }

        Event::assertNotDispatched(ParticipantRegistered::class);
    }

    public function test_endorsing_a_wait_row_with_a_free_seat_takes_it(): void
    {
        $this->fakeEvents();
        config()->set('modules.settings.tournaments', ['guest_confirmation' => ['mode' => 'wait']]);
        $tournament = $this->tournament(['max_participants' => 1]);
        $row = $this->pendingGuest($tournament);

        $this->service()->confirmEmailByOrganization($row->id);

        $this->assertEquals($this->now, $row->refresh()->email_confirmed_at);
        Event::assertDispatchedTimes(ParticipantRegistered::class, 1);
    }

    public function test_organizer_creation_normalises_and_refuses_duplicates(): void
    {
        $this->fakeEvents();
        $tournament = $this->tournament();

        $created = $this->service()->registerByOrganization(new RegisterParticipantDTO(
            tournamentId: $tournament->id,
            guestName: 'John',
            guestEmail: 'JOHN@x.com ',
        ));

        $row = ParticipantModel::query()->findOrFail($created->id);
        $this->assertSame('john@x.com', $row->guest_email);
        $this->assertSame(ParticipantStatus::Registered, $row->status);
        $this->assertEquals($this->now, $row->email_confirmed_at);
        $this->assertEquals($this->now, $row->registered_at);
        $this->assertNotNull($row->cancellation_token);
        $this->assertNull($row->confirmation_token);
        $this->assertNull($row->fingerprint);

        $this->expectException(AlreadyRegisteredException::class);

        try {
            $this->service()->registerByOrganization(new RegisterParticipantDTO(
                tournamentId: $tournament->id,
                guestName: 'John otra vez',
                guestEmail: 'john@x.com',
            ));
        } finally {
            $this->assertSame(1, ParticipantModel::query()->where('tournament_id', $tournament->id)->count());
            Event::assertNotDispatched(ParticipantRegistered::class);
            Event::assertNotDispatched(GuestConfirmationRequested::class);
        }
    }

    public function test_organizer_creation_refuses_the_email_of_an_unconfirmed_guest(): void
    {
        $this->fakeEvents();
        $tournament = $this->tournament();
        $this->pendingGuest($tournament, 'john@example.com');

        $this->expectException(AlreadyRegisteredException::class);

        $this->service()->registerByOrganization(new RegisterParticipantDTO(
            tournamentId: $tournament->id,
            guestName: 'John',
            guestEmail: ' John@Example.com',
        ));
    }

    public function test_organizer_creation_refuses_a_user_already_registered(): void
    {
        $this->fakeEvents();
        $tournament = $this->tournament();
        $user = UserModel::factory()->create();
        $dto = new RegisterParticipantDTO(tournamentId: $tournament->id, userId: $user->id);

        $created = $this->service()->registerByOrganization($dto);

        $row = ParticipantModel::query()->findOrFail($created->id);
        $this->assertSame($user->id, $row->user_id);
        $this->assertNull($row->guest_email);
        $this->assertNull($row->cancellation_token);

        $this->expectException(AlreadyRegisteredException::class);

        $this->service()->registerByOrganization($dto);
    }

    public function test_organizer_creation_respects_capacity(): void
    {
        $this->fakeEvents();
        $tournament = $this->tournament(['max_participants' => 1]);
        $this->confirmedGuest($tournament, 'jane@example.com');

        try {
            $this->service()->registerByOrganization(new RegisterParticipantDTO(
                tournamentId: $tournament->id,
                guestName: 'John',
                guestEmail: 'john@example.com',
            ));
            $this->fail('The organisation added a participant to a full tournament.');
        } catch (TournamentFullException) {
            $this->assertSame(1, ParticipantModel::query()->where('tournament_id', $tournament->id)->count());
        }

        // A registration that ended takes no seat
        $created = $this->service()->registerByOrganization(new RegisterParticipantDTO(
            tournamentId: $tournament->id,
            guestName: 'John',
            guestEmail: 'john@example.com',
        ), ParticipantStatus::Withdrawn);

        $this->assertSame(ParticipantStatus::Withdrawn, $created->status);
    }

    public function test_organizer_creation_ignores_the_registration_window(): void
    {
        $this->fakeEvents();
        $tournament = $this->tournament(['status' => TournamentStatus::InProgress->value]);

        $created = $this->service()->registerByOrganization(new RegisterParticipantDTO(
            tournamentId: $tournament->id,
            guestName: 'John',
        ), ParticipantStatus::CheckedIn);

        $row = ParticipantModel::query()->findOrFail($created->id);
        $this->assertSame(ParticipantStatus::CheckedIn, $row->status);
        $this->assertEquals($this->now, $row->checked_in_at);
        $this->assertNull($row->guest_email);
        Event::assertNotDispatched(ParticipantRegistered::class);
    }
}
