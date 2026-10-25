<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Feature\Time;

use App\Filament\Resources\EventResource\Pages\EditEvent;
use App\Infrastructure\Persistence\Eloquent\Concerns\StoresInstantsInStorageTimezone;
use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use Inertia\Testing\AssertableInertia as Assert;
use Livewire\Livewire;
use Modules\Tournaments\Console\Commands\SendTournamentRemindersCommand;
use Modules\Tournaments\Domain\Enums\ParticipantStatus;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Domain\Events\ParticipantRegistered;
use Modules\Tournaments\Domain\Events\ParticipantWithdrawn;
use Modules\Tournaments\Domain\Events\TournamentCancelled;
use Modules\Tournaments\Filament\RelationManagers\TournamentConfigRelationManager;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\ParticipantModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use Modules\Tournaments\Listeners\SendParticipantRegisteredEmails;
use Modules\Tournaments\Listeners\SendParticipantWithdrawnEmail;
use Modules\Tournaments\Listeners\SendTournamentCancelledEmails;
use Modules\Tournaments\Notifications\AnonymousNotifiable;
use Modules\Tournaments\Notifications\GuestConfirmationRequestNotification;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;
use Tests\Support\Modules\InteractsWithModulePanel;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * With UTC storage the instants in the database are UTC, but whoever reads a mail, the panel or
 * the JSON sees the site's Madrid wall time.
 */
final class UtcStorageTest extends ModuleTestCase
{
    use InteractsWithModulePanel;
    use UsesUtcStorage;

    protected ?string $moduleName = 'tournaments';

    protected bool $autoEnableModule = true;

    /** 12:00 in Madrid (CEST). */
    private const string EVENT_START = '2026-07-01T10:00:00Z';

    private const string MADRID_ISO = '2026-07-01T12:00:00+02:00';

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        app()->setLocale('es');
    }

    public function test_every_model_stores_instants_in_the_storage_timezone(): void
    {
        $files = glob(dirname(__DIR__, 3).'/src/Infrastructure/Persistence/Eloquent/Models/*.php') ?: [];
        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            $class = 'Modules\\Tournaments\\Infrastructure\\Persistence\\Eloquent\\Models\\'.basename($file, '.php');

            $this->assertContains(StoresInstantsInStorageTimezone::class, class_uses_recursive($class), $class);
        }
    }

    public function test_the_tournament_dates_are_stored_in_utc_and_serialized_in_the_display_zone(): void
    {
        $tournament = $this->tournament(['registration_opens_at' => CarbonImmutable::parse('2026-06-20T08:00:00Z')]);

        $this->assertSame('2026-06-20 08:00:00', substr((string) DB::table('tournaments_tournaments')->where('id', $tournament->id)->value('registration_opens_at'), 0, 19));

        $this->get(route('tournaments.show', $tournament->slug))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('tournament.registration_opens_at', '2026-06-20T10:00:00+02:00'));
    }

    public function test_the_check_in_window_serializes_the_display_offset_and_counts_elapsed_minutes(): void
    {
        $tournament = $this->tournament(['check_in_starts_before' => 30, 'requires_check_in' => true, 'self_check_in_allowed' => true]);

        // 30 minutes before 10:00Z is 09:30Z, wherever the clocks stand in Madrid
        $this->travelTo(CarbonImmutable::parse('2026-07-01T09:29:00Z'));
        $this->get(route('tournaments.check-in.show', $tournament->slug))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('eventStartDate', self::MADRID_ISO)
                ->where('checkInWindow.status', 'not_yet')
                ->where('checkInWindow.opens_at', '2026-07-01T11:30:00+02:00')
                ->where('checkInWindow.closes_at', self::MADRID_ISO));

        $this->travelTo(CarbonImmutable::parse('2026-07-01T09:31:00Z'));
        $this->get(route('tournaments.check-in.show', $tournament->slug))
            ->assertInertia(fn (Assert $page) => $page->where('checkInWindow.status', 'open'));

        $this->travelTo(CarbonImmutable::parse('2026-07-01T10:01:00Z'));
        $this->get(route('tournaments.check-in.show', $tournament->slug))
            ->assertInertia(fn (Assert $page) => $page->where('checkInWindow.status', 'closed'));
    }

    public function test_the_registration_closes_when_the_event_starts_whatever_the_storage_zone(): void
    {
        $tournament = $this->tournament();

        $this->travelTo(CarbonImmutable::parse('2026-07-01T09:59:00Z'));
        $this->get(route('tournaments.show', $tournament->slug))
            ->assertInertia(fn (Assert $page) => $page->where('tournament.is_registration_open', true));

        $this->travelTo(CarbonImmutable::parse('2026-07-01T10:00:00Z'));
        $this->get(route('tournaments.show', $tournament->slug))
            ->assertInertia(fn (Assert $page) => $page->where('tournament.is_registration_open', false));
    }

    public function test_the_config_tab_shows_madrid_wall_time_and_saves_the_utc_instant(): void
    {
        $this->actingAsPanelAdmin();
        $tournament = $this->tournament([
            'registration_opens_at' => CarbonImmutable::parse('2026-06-01T08:00:00Z'),
            'registration_closes_at' => CarbonImmutable::parse('2026-06-30T08:00:00Z'),
        ]);
        $event = EventModel::query()->findOrFail($tournament->event_id);

        Livewire::test(TournamentConfigRelationManager::class, ['ownerRecord' => $event, 'pageClass' => EditEvent::class])
            ->assertSet('configData.registration_opens_at', '2026-06-01 10:00:00')
            ->assertSet('configData.registration_closes_at', '2026-06-30 10:00:00')
            ->set('configData.registration_opens_at', '2026-06-02 12:00:00')
            ->set('configData.registration_closes_at', '2026-07-01 12:00:00')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('2026-06-02 10:00:00', $this->storedValue($tournament, 'registration_opens_at'));
        $this->assertSame('2026-07-01 10:00:00', $this->storedValue($tournament, 'registration_closes_at'));
    }

    public function test_the_closing_date_is_capped_at_the_event_start_in_madrid_time(): void
    {
        $this->actingAsPanelAdmin();
        $tournament = $this->tournament();
        $event = EventModel::query()->findOrFail($tournament->event_id);

        $component = Livewire::test(TournamentConfigRelationManager::class, ['ownerRecord' => $event, 'pageClass' => EditEvent::class])
            ->set('configData.registration_closes_at', '2026-07-01 12:01:00')
            ->call('save')
            ->assertHasErrors(['configData.registration_closes_at']);

        $this->assertContains(
            'El cierre de inscripción no puede ser posterior al inicio del evento (01/07/2026 12:00)',
            $component->errors()->get('configData.registration_closes_at'),
        );

        Livewire::test(TournamentConfigRelationManager::class, ['ownerRecord' => $event, 'pageClass' => EditEvent::class])
            ->set('configData.registration_closes_at', '2026-07-01 12:00:00')
            ->call('save')
            ->assertHasNoErrors();
    }

    public function test_the_guest_confirmation_mail_names_the_deadline_in_madrid_time(): void
    {
        Mail::fake();

        $notification = new GuestConfirmationRequestNotification('Torneo de prueba', 'John', 'token', new DateTimeImmutable(self::EVENT_START), true);
        $message = $notification->toMail(new AnonymousNotifiable('john.doe@example.com', 'John'));

        $this->assertStringContainsString('01/07/2026 12:00', implode(' ', $message->introLines));
    }

    public function test_the_registration_mails_name_the_event_start_in_madrid_time(): void
    {
        $tournament = $this->tournament();
        $participant = $this->participant($tournament);

        app(SendParticipantRegisteredEmails::class)->handle(ParticipantRegistered::create(
            $participant->id,
            $tournament->id,
            null,
            'ana@example.com',
            true,
            'token-de-cancelacion',
            false,
        ));

        $this->assertStringContainsString('01/07/2026 12:00', $this->mailBody('ana@example.com'));
    }

    public function test_the_withdrawal_mail_names_the_event_start_in_madrid_time(): void
    {
        $tournament = $this->tournament();
        $participant = $this->participant($tournament);

        app(SendParticipantWithdrawnEmail::class)->handle(ParticipantWithdrawn::create(
            $participant->id,
            $tournament->id,
            null,
            'ana@example.com',
            'Ana',
        ));

        $this->assertStringContainsString('01/07/2026 12:00', $this->mailBody('ana@example.com'));
    }

    public function test_the_cancellation_mail_names_the_event_start_in_madrid_time(): void
    {
        $tournament = $this->tournament();
        $this->participant($tournament);

        app(SendTournamentCancelledEmails::class)->handle(TournamentCancelled::create($tournament->id, TournamentStatus::RegistrationOpen));

        $this->assertStringContainsString('01/07/2026 12:00', $this->mailBody('ana@example.com'));
    }

    public function test_the_reminder_names_the_event_start_in_madrid_time_and_binds_the_window_in_utc(): void
    {
        config()->set('modules.settings.tournaments.reminders', ['enabled' => true, 'hours_before' => 24]);
        $tournament = $this->tournament(['status' => TournamentStatus::RegistrationClosed->value]);
        $participant = $this->participant($tournament);
        $this->app->make(Kernel::class)->registerCommand($this->app->make(SendTournamentRemindersCommand::class));

        // Twenty-three hours before the start, so the event is inside the window
        $this->travelTo(CarbonImmutable::parse('2026-06-30T11:00:00Z'));
        $this->sendReminders();

        $this->assertSame('Recordatorio: Torneo de prueba, 01/07/2026 12:00', $this->mailSubject('ana@example.com'));
        $this->assertSame('2026-06-30 11:00:00', substr((string) DB::table('tournaments_participants')->where('id', $participant->id)->value('reminder_sent_at'), 0, 19));
    }

    public function test_the_reminder_leaves_out_an_event_beyond_the_window(): void
    {
        config()->set('modules.settings.tournaments.reminders', ['enabled' => true, 'hours_before' => 24]);
        $tournament = $this->tournament(['status' => TournamentStatus::RegistrationClosed->value]);
        $this->participant($tournament);
        $this->app->make(Kernel::class)->registerCommand($this->app->make(SendTournamentRemindersCommand::class));

        // Twenty-five hours before the start
        $this->travelTo(CarbonImmutable::parse('2026-06-30T09:00:00Z'));
        $this->sendReminders();

        $this->assertSame([], $this->mailMessages());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function tournament(array $attributes = []): TournamentModel
    {
        $event = EventModel::factory()->published()->create([
            'start_date' => CarbonImmutable::parse(self::EVENT_START),
            'end_date' => CarbonImmutable::parse('2026-07-01T14:00:00Z'),
        ]);

        return TournamentModel::create([
            'id' => (string) Str::uuid(),
            'event_id' => $event->id,
            'name' => 'Torneo de prueba',
            'slug' => 'torneo-'.Str::lower(Str::random(10)),
            'status' => TournamentStatus::RegistrationOpen->value,
            'result_reporting' => 'admin_only',
            'allow_guests' => true,
            'notification_email' => 'organizacion@example.com',
            ...$attributes,
        ])->refresh();
    }

    private function participant(TournamentModel $tournament): ParticipantModel
    {
        return ParticipantModel::create([
            'id' => (string) Str::uuid(),
            'tournament_id' => $tournament->id,
            'guest_name' => 'Ana',
            'guest_email' => 'ana@example.com',
            'status' => ParticipantStatus::Registered->value,
            'cancellation_token' => Str::random(64),
            'email_confirmed_at' => CarbonImmutable::parse('2026-06-01T08:00:00Z'),
            'registered_at' => CarbonImmutable::parse('2026-06-01T08:00:00Z'),
        ]);
    }

    private function storedValue(TournamentModel $tournament, string $column): ?string
    {
        $value = DB::table('tournaments_tournaments')->where('id', $tournament->id)->value($column);

        return is_string($value) ? substr($value, 0, 19) : null;
    }

    /**
     * @return list<Email>
     */
    private function mailMessages(): array
    {
        $transport = Mail::mailer('array')->getSymfonyTransport();
        $this->assertInstanceOf(ArrayTransport::class, $transport);

        $emails = [];
        /** @var SentMessage $message */
        foreach ($transport->messages() as $message) {
            $email = $message->getOriginalMessage();
            $this->assertInstanceOf(Email::class, $email);
            $emails[] = $email;
        }

        return $emails;
    }

    private function mailBody(string $to): string
    {
        foreach ($this->mailMessages() as $email) {
            if ($email->getTo()[0]->getAddress() === $to) {
                return (string) $email->getHtmlBody();
            }
        }

        $this->fail("No mail sent to {$to}.");
    }

    private function mailSubject(string $to): string
    {
        foreach ($this->mailMessages() as $email) {
            if ($email->getTo()[0]->getAddress() === $to) {
                return (string) $email->getSubject();
            }
        }

        $this->fail("No mail sent to {$to}.");
    }

    private function sendReminders(): void
    {
        $command = $this->artisan('tournaments:send-reminders');
        $this->assertInstanceOf(PendingCommand::class, $command);
        $command->assertSuccessful();
    }
}
