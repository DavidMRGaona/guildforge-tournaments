<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Integration\Infrastructure\Services;

use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Modules\Tournaments\Application\DTOs\RegisterParticipantDTO;
use Modules\Tournaments\Application\DTOs\Response\GuestConfirmationOutcome;
use Modules\Tournaments\Application\Services\ParticipantManagementServiceInterface;
use Modules\Tournaments\Domain\Enums\GuestConfirmationMode;
use Modules\Tournaments\Domain\Enums\ParticipantStatus;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Domain\Events\GuestConfirmationRequested;
use Modules\Tournaments\Domain\Events\ParticipantRegistered;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\ParticipantModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use RuntimeException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;
use Tests\Support\Modules\ModuleTestCase;

/**
 * A guest confirms the email through the link: only then does the registration count for the
 * organisation and the usual registration emails go out.
 */
final class GuestConfirmationTest extends ModuleTestCase
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
     * A guest registration awaiting confirmation, as the form leaves it.
     */
    private function pendingGuest(TournamentModel $tournament, string $email = 'john@example.com'): ParticipantModel
    {
        $result = $this->service()->registerGuest(new RegisterParticipantDTO(
            tournamentId: $tournament->id,
            guestName: 'John',
            guestEmail: $email,
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
            'email_confirmed_at' => $this->now,
        ]);
    }

    private function fakeEvents(): void
    {
        Event::fake([GuestConfirmationRequested::class, ParticipantRegistered::class]);
    }

    public function test_confirming_marks_the_email_and_announces_the_registration(): void
    {
        $this->fakeEvents();
        $tournament = $this->tournament();
        $row = $this->pendingGuest($tournament);
        $token = (string) $row->confirmation_token;

        $result = $this->service()->confirmGuestEmail($token);

        $this->assertSame(GuestConfirmationOutcome::Confirmed, $result->outcome);
        $this->assertSame($tournament->id, $result->tournamentId);
        $this->assertSame($tournament->slug, $result->tournamentSlug);

        $row->refresh();
        $this->assertSame(ParticipantStatus::Registered, $row->status);
        $this->assertEquals($this->now, $row->email_confirmed_at);
        $this->assertNull($row->confirmation_expires_at);
        // Kept, so a second click can say it was already confirmed
        $this->assertSame($token, $row->confirmation_token);

        Event::assertDispatchedTimes(ParticipantRegistered::class, 1);
        Event::assertDispatched(ParticipantRegistered::class, fn (ParticipantRegistered $event): bool => $event->participantId === $row->id
            && $event->tournamentId === $tournament->id
            && $event->userId === null
            && $event->isGuest
            && $event->guestEmail === 'john@example.com'
            && $event->cancellationToken !== null
            && $event->cancellationToken === $row->cancellation_token
            && $event->notifyOrganizer);
    }

    public function test_the_registration_email_carries_the_cancellation_link(): void
    {
        Event::fake([GuestConfirmationRequested::class]);
        $tournament = $this->tournament();
        $row = $this->pendingGuest($tournament);

        $this->service()->confirmGuestEmail((string) $row->confirmation_token);

        $transport = Mail::mailer('array')->getSymfonyTransport();
        $this->assertInstanceOf(ArrayTransport::class, $transport);
        /** @var list<SentMessage> $messages */
        $messages = $transport->messages()->all();
        $recipients = [];
        $guestBody = null;
        foreach ($messages as $message) {
            $email = $message->getOriginalMessage();
            $this->assertInstanceOf(Email::class, $email);
            $address = $email->getTo()[0]->getAddress();
            $recipients[] = $address;
            if ($address === 'john@example.com') {
                $guestBody = (string) $email->getHtmlBody();
            }
        }

        sort($recipients);
        $this->assertSame(['john@example.com', 'organizacion@example.com'], $recipients);
        $this->assertNotNull($guestBody);
        $this->assertStringContainsString('/torneos/cancelar/'.$row->cancellation_token, $guestBody);
    }

    public function test_a_reused_link_is_already_confirmed(): void
    {
        $this->fakeEvents();
        $tournament = $this->tournament();
        $token = (string) $this->pendingGuest($tournament)->confirmation_token;

        $this->service()->confirmGuestEmail($token);
        $result = $this->service()->confirmGuestEmail($token);

        $this->assertSame(GuestConfirmationOutcome::AlreadyConfirmed, $result->outcome);
        $this->assertSame($tournament->slug, $result->tournamentSlug);
        Event::assertDispatchedTimes(ParticipantRegistered::class, 1);
    }

    public function test_a_cancelled_or_unknown_token_is_invalid(): void
    {
        $this->fakeEvents();
        $tournament = $this->tournament();
        $row = $this->pendingGuest($tournament);
        $row->update(['status' => ParticipantStatus::Withdrawn->value]);

        $cancelled = $this->service()->confirmGuestEmail((string) $row->confirmation_token);

        $this->assertSame(GuestConfirmationOutcome::Invalid, $cancelled->outcome);
        $this->assertSame($tournament->slug, $cancelled->tournamentSlug);
        $this->assertNull($row->refresh()->email_confirmed_at);

        $unknown = $this->service()->confirmGuestEmail('no-such-token');

        $this->assertSame(GuestConfirmationOutcome::Invalid, $unknown->outcome);
        $this->assertNull($unknown->tournamentId);
        $this->assertNull($unknown->tournamentSlug);
        Event::assertNotDispatched(ParticipantRegistered::class);
    }

    public function test_after_the_close_the_registration_expires(): void
    {
        $this->fakeEvents();
        $tournament = $this->tournament();
        $row = $this->pendingGuest($tournament);
        $tournament->update(['status' => TournamentStatus::InProgress->value]);

        $result = $this->service()->confirmGuestEmail((string) $row->confirmation_token);

        $this->assertSame(GuestConfirmationOutcome::RegistrationClosed, $result->outcome);
        $row->refresh();
        $this->assertSame(ParticipantStatus::Expired, $row->status);
        $this->assertNull($row->email_confirmed_at);
        Event::assertNotDispatched(ParticipantRegistered::class);
    }

    public function test_an_overdue_link_expires_the_registration_instead_of_confirming_it(): void
    {
        $this->fakeEvents();
        $tournament = $this->tournament(['max_participants' => 1]);
        $row = $this->pendingGuest($tournament);
        $this->travelTo($this->now->addHours(2)->addMinute());

        $result = $this->service()->confirmGuestEmail((string) $row->confirmation_token);

        $this->assertSame(GuestConfirmationOutcome::Invalid, $result->outcome);
        $this->assertSame($tournament->slug, $result->tournamentSlug);
        $row->refresh();
        $this->assertSame(ParticipantStatus::Expired, $row->status);
        $this->assertNull($row->email_confirmed_at);
        // The seat is free again
        $this->assertSame(0, ParticipantModel::query()->where('tournament_id', $tournament->id)->whereIn('status', ['registered', 'confirmed', 'checked_in'])->count());
        Event::assertNotDispatched(ParticipantRegistered::class);
    }

    public function test_a_failing_expiry_email_does_not_break_the_confirmation_page(): void
    {
        $this->fakeEvents();
        $tournament = $this->tournament();
        $row = $this->pendingGuest($tournament);
        $this->travelTo($this->now->addHours(2)->addMinute());
        Notification::shouldReceive('send')->andThrow(new RuntimeException('queue down'));
        $log = Log::spy();

        $result = $this->service()->confirmGuestEmail((string) $row->confirmation_token);

        $this->assertSame(GuestConfirmationOutcome::Invalid, $result->outcome);
        $this->assertSame(ParticipantStatus::Expired, $row->refresh()->status);
        $log->shouldHaveReceived('warning')->once()->withArgs(
            static fn (string $message, array $context): bool => $context['participant_id'] === $row->id,
        );
    }

    public function test_a_wait_row_in_a_full_tournament_expires(): void
    {
        $this->fakeEvents();
        config()->set('modules.settings.tournaments', ['guest_confirmation' => ['mode' => 'wait']]);
        $tournament = $this->tournament(['max_participants' => 1]);
        $row = $this->pendingGuest($tournament);
        $this->assertSame(GuestConfirmationMode::Wait, $row->confirmation_mode);
        $this->confirmedGuest($tournament, 'jane@example.com');

        $result = $this->service()->confirmGuestEmail((string) $row->confirmation_token);

        $this->assertSame(GuestConfirmationOutcome::Full, $result->outcome);
        $row->refresh();
        $this->assertSame(ParticipantStatus::Expired, $row->status);
        $this->assertNull($row->email_confirmed_at);
        Event::assertNotDispatched(ParticipantRegistered::class);
    }

    public function test_a_wait_row_with_a_free_seat_takes_it(): void
    {
        $this->fakeEvents();
        config()->set('modules.settings.tournaments', ['guest_confirmation' => ['mode' => 'wait']]);
        $tournament = $this->tournament(['max_participants' => 1]);
        $row = $this->pendingGuest($tournament);

        $result = $this->service()->confirmGuestEmail((string) $row->confirmation_token);

        $this->assertSame(GuestConfirmationOutcome::Confirmed, $result->outcome);
        $this->assertEquals($this->now, $row->refresh()->email_confirmed_at);
        Event::assertDispatchedTimes(ParticipantRegistered::class, 1);
    }

    public function test_a_hold_row_confirms_even_when_now_full(): void
    {
        $this->fakeEvents();
        $tournament = $this->tournament(['max_participants' => 1]);
        // Its held seat is the one that fills the tournament
        $row = $this->pendingGuest($tournament);
        $this->assertSame(GuestConfirmationMode::Hold, $row->confirmation_mode);

        $result = $this->service()->confirmGuestEmail((string) $row->confirmation_token);

        $this->assertSame(GuestConfirmationOutcome::Confirmed, $result->outcome);
        $row->refresh();
        $this->assertSame(ParticipantStatus::Registered, $row->status);
        $this->assertEquals($this->now, $row->email_confirmed_at);
        Event::assertDispatchedTimes(ParticipantRegistered::class, 1);
    }

    public function test_the_page_data_of_a_pending_registration(): void
    {
        $this->fakeEvents();
        $tournament = $this->tournament();
        $row = $this->pendingGuest($tournament);

        $this->assertSame([
            'state' => 'pending',
            'tournament_name' => 'Torneo de primavera',
            'tournament_slug' => $tournament->slug,
            'guest_name' => 'John',
            'expires_at' => $this->now->addHours(2)->format('c'),
            'seat_held' => true,
        ], $this->service()->findGuestConfirmation((string) $row->confirmation_token));

        // Reading it changes nothing
        $row->refresh();
        $this->assertNull($row->email_confirmed_at);
        $this->assertSame(ParticipantStatus::Registered, $row->status);
    }

    public function test_the_page_data_of_a_wait_registration(): void
    {
        $this->fakeEvents();
        config()->set('modules.settings.tournaments', ['guest_confirmation' => ['mode' => 'wait']]);
        $row = $this->pendingGuest($this->tournament());

        $page = $this->service()->findGuestConfirmation((string) $row->confirmation_token);

        $this->assertNotNull($page);
        $this->assertSame('pending', $page['state']);
        $this->assertNull($page['expires_at']);
        $this->assertFalse($page['seat_held']);
    }

    public function test_the_page_data_of_a_confirmed_cancelled_overdue_or_unknown_link(): void
    {
        $this->fakeEvents();
        $tournament = $this->tournament();

        $confirmed = $this->pendingGuest($tournament, 'john@example.com');
        $this->service()->confirmGuestEmail((string) $confirmed->confirmation_token);
        $this->assertSame('already_confirmed', $this->service()->findGuestConfirmation((string) $confirmed->confirmation_token)['state'] ?? null);

        $cancelled = $this->pendingGuest($tournament, 'mary@example.com');
        $cancelled->update(['status' => ParticipantStatus::Withdrawn->value]);
        $this->assertSame('invalid', $this->service()->findGuestConfirmation((string) $cancelled->confirmation_token)['state'] ?? null);

        $overdue = $this->pendingGuest($tournament, 'dora@example.com');
        $this->travelTo($this->now->addHours(2)->addMinute());
        $this->assertSame('invalid', $this->service()->findGuestConfirmation((string) $overdue->confirmation_token)['state'] ?? null);
        // Still only read: the command or the button expires it
        $this->assertSame(ParticipantStatus::Registered, $overdue->refresh()->status);

        $this->assertNull($this->service()->findGuestConfirmation('no-such-token'));
    }
}
