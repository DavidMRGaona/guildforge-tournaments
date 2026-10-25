<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Integration\Infrastructure\Services;

use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeInterface;
use Illuminate\Support\Str;
use Modules\Tournaments\Application\Services\TournamentQueryServiceInterface;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\ParticipantModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use Modules\Tournaments\Tests\Support\PinsTheClock;
use Tests\Support\Modules\ModuleTestCase;

/**
 * What the pages are told about registering and withdrawing follows the tournament's rule:
 * status, configured dates and the event start.
 */
final class TournamentQueryServiceRegistrationTest extends ModuleTestCase
{
    use PinsTheClock;

    protected ?string $moduleName = 'tournaments';

    protected bool $autoEnableModule = true;

    private TournamentQueryServiceInterface $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(TournamentQueryServiceInterface::class);
    }

    public function test_a_guest_can_register_before_the_event_starts(): void
    {
        $tournament = $this->tournament(now()->addWeek());

        $this->assertTrue($this->service->canUserRegister($tournament->id, null));
    }

    public function test_nobody_can_register_once_the_event_has_started(): void
    {
        $tournament = $this->tournament(now()->subMinute());

        $this->assertFalse($this->service->canUserRegister($tournament->id, null));
        $this->assertFalse($this->service->canUserRegister($tournament->id, (string) UserModel::factory()->create()->id));
    }

    public function test_nobody_can_register_outside_the_configured_dates(): void
    {
        $notYetOpen = $this->tournament(now()->addWeek(), ['registration_opens_at' => now()->addDay()]);
        $alreadyClosed = $this->tournament(now()->addWeek(), ['registration_closes_at' => now()->subMinute()]);

        $this->assertFalse($this->service->canUserRegister($notYetOpen->id, null));
        $this->assertFalse($this->service->canUserRegister($alreadyClosed->id, null));
    }

    public function test_the_tournament_data_tells_the_frontend_whether_it_can_withdraw(): void
    {
        $closedBeforeStart = $this->tournament(now()->addWeek(), ['status' => TournamentStatus::RegistrationClosed]);
        $started = $this->tournament(now()->subMinute());

        $before = $this->service->find($closedBeforeStart->id)?->toArray();
        $after = $this->service->find($started->id)?->toArray();

        $this->assertNotNull($before);
        $this->assertNotNull($after);
        $this->assertFalse($before['is_registration_open']);
        $this->assertTrue($before['can_withdraw']);
        $this->assertFalse($after['is_registration_open']);
        $this->assertFalse($after['can_withdraw']);
    }

    public function test_public_participants_hide_unconfirmed_guests(): void
    {
        $tournament = $this->tournament(now()->addWeek());
        foreach ([['Confirmada', 'a@example.com', now()], ['Pendiente', 'b@example.com', null]] as [$name, $email, $confirmedAt]) {
            ParticipantModel::create([
                'id' => (string) Str::uuid(),
                'tournament_id' => $tournament->id,
                'guest_name' => $name,
                'guest_email' => $email,
                'status' => 'registered',
                'email_confirmed_at' => $confirmedAt,
            ]);
        }

        $names = array_map(
            static fn ($participant): string => $participant->displayName(),
            $this->service->getParticipants($tournament->id),
        );

        $this->assertSame(['Confirmada'], $names);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function tournament(DateTimeInterface $eventStartsAt, array $attributes = []): TournamentModel
    {
        return TournamentModel::create([
            'event_id' => EventModel::factory()->create(['start_date' => $eventStartsAt])->id,
            'name' => 'Torneo',
            'slug' => 'torneo-'.Str::lower(Str::random(10)),
            'status' => TournamentStatus::RegistrationOpen,
            'allow_guests' => true,
            'result_reporting' => 'admin_only',
            'notification_email' => 'test@example.com',
            ...$attributes,
        ]);
    }
}
