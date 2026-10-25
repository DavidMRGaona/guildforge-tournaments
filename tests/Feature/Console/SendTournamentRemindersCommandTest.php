<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Feature\Console;

use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use Modules\Tournaments\Console\Commands\SendTournamentRemindersCommand;
use Modules\Tournaments\Domain\Enums\ParticipantStatus;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\ParticipantModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;
use Tests\Support\Modules\ModuleTestCase;

/**
 * The reminder before a tournament is ready but off until enabled (reminders.enabled).
 */
final class SendTournamentRemindersCommandTest extends ModuleTestCase
{
    protected ?string $moduleName = 'tournaments';

    protected bool $autoEnableModule = true;

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('es');
        $this->now = CarbonImmutable::parse('2026-11-02 10:00:00', 'Europe/Madrid');
        $this->travelTo($this->now);
        config()->set('modules.settings.tournaments', []);
        $this->app->make(Kernel::class)->registerCommand($this->app->make(SendTournamentRemindersCommand::class));
    }

    public function test_nothing_is_sent_while_reminders_are_off(): void
    {
        $tournament = $this->tournament($this->now->addHours(10));
        $row = $this->participant($tournament, ['user_id' => UserModel::factory()->create()->id]);

        $this->sendReminders();

        $this->assertSame([], $this->mails());
        $this->assertNull($row->fresh()?->reminder_sent_at);
    }

    public function test_active_participants_of_a_tournament_starting_soon_are_reminded_once(): void
    {
        $this->enable();
        $tournament = $this->tournament($this->now->addHours(10));
        $user = UserModel::factory()->create(['email' => 'cuenta@example.com']);
        $this->participant($tournament, ['user_id' => $user->id]);
        $this->participant($tournament, ['guest_name' => 'Invitada', 'guest_email' => 'invitada@example.com', 'email_confirmed_at' => $this->now]);
        $this->participant($tournament, ['guest_name' => 'Sin confirmar', 'guest_email' => 'pendiente@example.com', 'email_confirmed_at' => null]);
        $this->participant($tournament, ['guest_name' => 'Retirada', 'guest_email' => 'retirada@example.com', 'email_confirmed_at' => $this->now, 'status' => ParticipantStatus::Withdrawn->value]);

        $this->sendReminders();
        $this->sendReminders();

        $mails = $this->mails();
        $this->assertSame(['cuenta@example.com', 'invitada@example.com'], array_column($mails, 'to'));
        $this->assertSame('Recordatorio: Torneo de prueba, 02/11/2026 20:00', $mails[0]['subject']);
    }

    public function test_tournaments_outside_the_window_or_not_running_are_skipped(): void
    {
        $this->enable();
        $later = $this->tournament($this->now->addHours(30));
        $started = $this->tournament($this->now->subHour());
        $cancelled = $this->tournament($this->now->addHours(5), ['status' => TournamentStatus::Cancelled->value]);

        foreach ([$later, $started, $cancelled] as $tournament) {
            $this->participant($tournament, ['user_id' => UserModel::factory()->create()->id]);
        }

        $this->sendReminders();

        $this->assertSame([], $this->mails());
    }

    public function test_anonymised_accounts_and_unpublished_events_are_skipped(): void
    {
        $this->enable();
        $tournament = $this->tournament($this->now->addHours(10));
        $this->participant($tournament, ['user_id' => UserModel::factory()->create(['anonymized_at' => $this->now->subDay()])->id]);
        $hidden = $this->tournament($this->now->addHours(10));
        EventModel::query()->whereKey($hidden->event_id)->update(['is_published' => false]);
        $this->participant($hidden, ['user_id' => UserModel::factory()->create()->id]);

        $this->sendReminders();

        $this->assertSame([], $this->mails());
    }

    public function test_the_lead_time_is_configurable(): void
    {
        $this->enable(hours: 48);
        $tournament = $this->tournament($this->now->addHours(30));
        $this->participant($tournament, ['user_id' => UserModel::factory()->create()->id]);

        $this->sendReminders();

        $this->assertCount(1, $this->mails());
    }

    public function test_the_command_is_scheduled_hourly_without_overlapping(): void
    {
        $events = array_values(array_filter(
            app(Schedule::class)->events(),
            fn (ScheduledEvent $event): bool => str_contains((string) $event->command, 'tournaments:send-reminders'),
        ));

        $this->assertCount(1, $events);
        $this->assertSame('0 * * * *', $events[0]->expression);
        $this->assertTrue($events[0]->withoutOverlapping);
    }

    private function sendReminders(): void
    {
        $command = $this->artisan('tournaments:send-reminders');
        $this->assertInstanceOf(PendingCommand::class, $command);
        $command->assertSuccessful();
    }

    private function enable(int $hours = 24): void
    {
        config()->set('modules.settings.tournaments.reminders', ['enabled' => true, 'hours_before' => $hours]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function tournament(CarbonImmutable $startsAt, array $attributes = []): TournamentModel
    {
        return TournamentModel::create([
            'id' => (string) Str::uuid(),
            'event_id' => EventModel::factory()->published()->create(['start_date' => $startsAt, 'end_date' => $startsAt->addHours(5)])->id,
            'name' => 'Torneo de prueba',
            'slug' => 'torneo-'.Str::lower(Str::random(10)),
            'status' => TournamentStatus::RegistrationClosed->value,
            'result_reporting' => 'admin_only',
            'allow_guests' => true,
            ...$attributes,
        ])->refresh();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function participant(TournamentModel $tournament, array $attributes): ParticipantModel
    {
        return ParticipantModel::create([
            'id' => (string) Str::uuid(),
            'tournament_id' => $tournament->id,
            'status' => ParticipantStatus::Registered->value,
            'cancellation_token' => Str::random(64),
            'registered_at' => $this->now->subDays(3),
            ...$attributes,
        ]);
    }

    /**
     * @return list<array{to: string, subject: string}>
     */
    private function mails(): array
    {
        $transport = Mail::mailer('array')->getSymfonyTransport();
        $this->assertInstanceOf(ArrayTransport::class, $transport);

        $mails = [];
        /** @var SentMessage $message */
        foreach ($transport->messages() as $message) {
            $email = $message->getOriginalMessage();
            $this->assertInstanceOf(Email::class, $email);
            $mails[] = ['to' => $email->getTo()[0]->getAddress(), 'subject' => (string) $email->getSubject()];
        }

        usort($mails, fn (array $a, array $b): int => strcmp($a['to'], $b['to']));

        return $mails;
    }
}
