<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Integration\Infrastructure\Services;

use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\Tournaments\Application\DTOs\RegisterParticipantDTO;
use Modules\Tournaments\Application\DTOs\Response\GuestConfirmationOutcome;
use Modules\Tournaments\Application\Services\ParticipantManagementServiceInterface;
use Modules\Tournaments\Application\Services\RegistrationLockInterface;
use Modules\Tournaments\Domain\Enums\ParticipantStatus;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Domain\Repositories\ParticipantRepositoryInterface;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\ParticipantModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use Modules\Tournaments\Infrastructure\Services\GuestRegistrationExpirer;
use RuntimeException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;
use Tests\Support\Modules\ModuleTestCase;

/**
 * Unconfirmed guest registrations lapse with the clock; the fingerprints of past tournaments go.
 */
final class GuestRegistrationExpirerTest extends ModuleTestCase
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

    /**
     * The expiry emails sent so far, as [recipient, subject, body].
     *
     * @return list<array{string, string, string}>
     */
    private function expiryEmails(): array
    {
        $transport = Mail::mailer('array')->getSymfonyTransport();
        $this->assertInstanceOf(ArrayTransport::class, $transport);
        /** @var list<SentMessage> $messages */
        $messages = $transport->messages()->all();
        $emails = [];

        foreach ($messages as $message) {
            $email = $message->getOriginalMessage();
            $this->assertInstanceOf(Email::class, $email);

            if (str_contains((string) $email->getSubject(), 'ha caducado')) {
                $emails[] = [$email->getTo()[0]->getAddress(), (string) $email->getSubject(), (string) $email->getHtmlBody()];
            }
        }

        return $emails;
    }

    private function expirer(): GuestRegistrationExpirer
    {
        return app(GuestRegistrationExpirer::class);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function tournament(array $attributes = [], ?CarbonImmutable $eventStart = null): TournamentModel
    {
        return TournamentModel::create([
            'event_id' => EventModel::factory()->create(['start_date' => $eventStart ?? $this->now->addDays(3)])->id,
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
        $result = app(ParticipantManagementServiceInterface::class)->registerGuest(new RegisterParticipantDTO(
            tournamentId: $tournament->id,
            guestName: 'John',
            guestEmail: $email,
            fingerprint: str_repeat('a', 64),
        ));

        return ParticipantModel::query()->findOrFail($result->participantId);
    }

    public function test_an_overdue_hold_row_expires_and_is_told(): void
    {
        $tournament = $this->tournament(['max_participants' => 1]);
        $row = $this->pendingGuest($tournament);
        $repository = app(ParticipantRepositoryInterface::class);
        $this->assertSame(1, $repository->countActiveByTournament($tournament->id));

        $this->travelTo($this->now->addHours(2)->addMinute());
        $counts = $this->expirer()->run(CarbonImmutable::now());

        $this->assertSame(1, $counts['expired']);
        $this->assertSame(ParticipantStatus::Expired, $row->refresh()->status);
        $this->assertSame(0, $repository->countActiveByTournament($tournament->id));
        $emails = $this->expiryEmails();
        $this->assertCount(1, $emails);
        [$recipient, $subject, $body] = $emails[0];
        $this->assertSame('john@example.com', $recipient);
        $this->assertSame('Tu inscripción en Torneo de primavera ha caducado', $subject);
        $this->assertStringContainsString('No confirmaste tu inscripción a tiempo y la plaza se ha liberado.', $body);
        $this->assertStringContainsString('Si quedan plazas, puedes volver a apuntarte.', $body);
        $this->assertStringContainsString('Ver el torneo', $body);
        $this->assertStringContainsString('/'.$tournament->slug, $body);
    }

    public function test_a_row_due_later_is_left_and_one_at_the_deadline_expires(): void
    {
        $tournament = $this->tournament();
        $row = $this->pendingGuest($tournament);

        $this->travelTo($this->now->addHours(2)->subMinute());
        $this->assertSame(0, $this->expirer()->run(CarbonImmutable::now())['expired']);
        $this->assertSame(ParticipantStatus::Registered, $row->refresh()->status);

        $this->travelTo($this->now->addHours(2));
        $this->assertSame(1, $this->expirer()->run(CarbonImmutable::now())['expired']);
        $this->assertSame(ParticipantStatus::Expired, $row->refresh()->status);
    }

    public function test_a_wait_row_expires_silently_when_registration_closes(): void
    {
        config()->set('modules.settings.tournaments', ['guest_confirmation' => ['mode' => 'wait']]);
        $tournament = $this->tournament(['registration_closes_at' => $this->now->addHours(5)]);
        $row = $this->pendingGuest($tournament);

        $this->travelTo($this->now->addHours(5)->subMinute());
        $this->assertSame(0, $this->expirer()->run(CarbonImmutable::now())['expired']);
        $this->assertSame(ParticipantStatus::Registered, $row->refresh()->status);

        $this->travelTo($this->now->addHours(5));
        $this->assertSame(1, $this->expirer()->run(CarbonImmutable::now())['expired']);
        $this->assertSame(ParticipantStatus::Expired, $row->refresh()->status);
        $this->assertSame([], $this->expiryEmails());
    }

    public function test_a_row_confirmed_meanwhile_is_skipped(): void
    {
        $tournament = $this->tournament();
        $row = $this->pendingGuest($tournament);
        app(ParticipantManagementServiceInterface::class)->confirmGuestEmail((string) $row->confirmation_token);

        $this->travelTo($this->now->addHours(3));
        $counts = $this->expirer()->run(CarbonImmutable::now());

        $this->assertSame(0, $counts['expired']);
        $this->assertSame(ParticipantStatus::Registered, $row->refresh()->status);
        $this->assertSame([], $this->expiryEmails());
    }

    public function test_two_runs_expire_and_notify_once(): void
    {
        $tournament = $this->tournament();
        $this->pendingGuest($tournament);

        $this->travelTo($this->now->addHours(3));
        $first = $this->expirer()->run(CarbonImmutable::now());
        $second = $this->expirer()->run(CarbonImmutable::now());

        $this->assertSame(1, $first['expired']);
        $this->assertSame(0, $second['expired']);
        $this->assertCount(1, $this->expiryEmails());
    }

    public function test_one_failing_row_does_not_stop_the_others(): void
    {
        $tournament = $this->tournament();
        $first = $this->pendingGuest($tournament, 'john@example.com');
        $second = $this->pendingGuest($tournament, 'jane@example.com');
        $failing = $first->id;
        $real = app(RegistrationLockInterface::class);

        $this->app->instance(RegistrationLockInterface::class, new class($real, $failing) implements RegistrationLockInterface
        {
            public function __construct(private readonly RegistrationLockInterface $real, private readonly string $failing) {}

            public function forTournament(string $tournamentId, Closure $callback): mixed
            {
                return $this->real->forTournament($tournamentId, $callback);
            }

            public function forParticipant(string $participantId, Closure $callback): mixed
            {
                if ($participantId === $this->failing) {
                    throw new RuntimeException('lock failed');
                }

                return $this->real->forParticipant($participantId, $callback);
            }

            public function forConfirmationToken(string $token, Closure $callback): mixed
            {
                return $this->real->forConfirmationToken($token, $callback);
            }
        });

        $this->travelTo($this->now->addHours(3));
        $counts = $this->expirer()->run(CarbonImmutable::now());

        $this->assertSame(1, $counts['expired']);
        $this->assertSame(ParticipantStatus::Registered, $first->refresh()->status);
        $this->assertSame(ParticipantStatus::Expired, $second->refresh()->status);
    }

    public function test_fingerprints_are_cleared_after_the_event_starts(): void
    {
        $tournament = $this->tournament(eventStart: $this->now->addDay());
        $row = $this->pendingGuest($tournament);

        $this->travelTo($this->now->addHours(23));
        $this->assertSame(0, $this->expirer()->run(CarbonImmutable::now())['fingerprints_cleared']);
        $this->assertNotNull($row->refresh()->fingerprint);

        $this->travelTo($this->now->addDay());
        $this->assertSame(1, $this->expirer()->run(CarbonImmutable::now())['fingerprints_cleared']);
        $this->assertNull($row->refresh()->fingerprint);
    }

    public function test_fingerprints_are_cleared_when_the_tournament_is_finished_or_cancelled(): void
    {
        $finished = $this->pendingGuest($this->tournament(), 'john@example.com');
        $cancelled = $this->pendingGuest($this->tournament(), 'jane@example.com');
        $running = $this->pendingGuest($this->tournament(), 'mary@example.com');
        TournamentModel::query()->whereKey($finished->tournament_id)->update(['status' => TournamentStatus::Finished->value]);
        TournamentModel::query()->whereKey($cancelled->tournament_id)->update(['status' => TournamentStatus::Cancelled->value]);

        $counts = $this->expirer()->run(CarbonImmutable::now());

        $this->assertSame(2, $counts['fingerprints_cleared']);
        $this->assertNull($finished->refresh()->fingerprint);
        $this->assertNull($cancelled->refresh()->fingerprint);
        $this->assertNotNull($running->refresh()->fingerprint);
    }

    public function test_an_overdue_confirmation_link_tells_the_guest(): void
    {
        $tournament = $this->tournament();
        $row = $this->pendingGuest($tournament);
        $service = app(ParticipantManagementServiceInterface::class);

        $this->travelTo($this->now->addHours(2)->addMinute());
        $result = $service->confirmGuestEmail((string) $row->confirmation_token);

        $this->assertSame(GuestConfirmationOutcome::Invalid, $result->outcome);
        $this->assertSame(ParticipantStatus::Expired, $row->refresh()->status);
        $this->assertCount(1, $this->expiryEmails());
        // The command finds nothing left to tell
        $this->assertSame(0, $this->expirer()->run(CarbonImmutable::now())['expired']);
        $this->assertCount(1, $this->expiryEmails());
    }

    public function test_a_resubmission_over_an_overdue_row_sends_no_expiry_email(): void
    {
        $tournament = $this->tournament();
        $this->pendingGuest($tournament);

        $this->travelTo($this->now->addHours(3));
        $this->pendingGuest($tournament);

        $this->assertSame([], $this->expiryEmails());
    }
}
