<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Feature\Filament;

use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Closure;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Modules\Tournaments\Application\Services\TournamentLifecycleServiceInterface;
use Modules\Tournaments\Domain\Enums\ParticipantStatus;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Domain\Events\TournamentStarted;
use Modules\Tournaments\Filament\Resources\TournamentResource;
use Modules\Tournaments\Filament\Resources\TournamentResource\Pages\EditTournament;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\ParticipantModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Modules\InteractsWithModulePanel;
use Tests\Support\Modules\ModuleTestCase;

/**
 * The edit page's status actions go through the lifecycle service: a tournament changed
 * meanwhile by another request is reported instead of overwritten.
 */
final class TournamentStatusActionsPanelTest extends ModuleTestCase
{
    use InteractsWithModulePanel;

    protected ?string $moduleName = 'tournaments';

    protected bool $autoEnableModule = true;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([TournamentStarted::class]);
        $this->registerOnAdminPanel([TournamentResource::class]);
        $this->actingAsPanelAdmin();
    }

    /**
     * @return iterable<string, array{string, TournamentStatus, TournamentStatus, string}>
     */
    public static function actions(): iterable
    {
        yield 'open registration' => ['openRegistration', TournamentStatus::Draft, TournamentStatus::RegistrationOpen, 'Inscripción abierta'];
        yield 'close registration' => ['closeRegistration', TournamentStatus::RegistrationOpen, TournamentStatus::RegistrationClosed, 'Inscripción cerrada'];
        yield 'start from closed registration' => ['startTournament', TournamentStatus::RegistrationClosed, TournamentStatus::InProgress, 'Torneo iniciado'];
        yield 'start from open registration' => ['startTournament', TournamentStatus::RegistrationOpen, TournamentStatus::InProgress, 'Torneo iniciado'];
    }

    #[DataProvider('actions')]
    public function test_each_action_moves_the_tournament_on(string $action, TournamentStatus $from, TournamentStatus $to, string $notification): void
    {
        $tournament = $this->tournament($from);
        $this->participants($tournament, 2, ParticipantStatus::Confirmed);

        Livewire::test(EditTournament::class, ['record' => $tournament->getRouteKey()])
            ->callAction($action)
            ->assertHasNoActionErrors()
            ->assertNotified($notification);

        $this->assertSame($to, $tournament->refresh()->status);
    }

    /**
     * @return iterable<string, array{string, TournamentStatus}>
     */
    public static function staleActions(): iterable
    {
        foreach (self::actions() as $name => [$action, $from]) {
            yield $name => [$action, $from];
        }
    }

    #[DataProvider('staleActions')]
    public function test_a_tournament_cancelled_by_a_concurrent_request_is_not_reopened(string $action, TournamentStatus $from): void
    {
        $tournament = $this->tournament($from);
        $this->participants($tournament, 2, ParticipantStatus::Confirmed);
        $this->cancelMeanwhile($tournament);

        Livewire::test(EditTournament::class, ['record' => $tournament->getRouteKey()])
            ->callAction($action)
            ->assertNotified('Transición de estado no permitida')
            ->assertNotNotified('Inscripción abierta')
            ->assertNotNotified('Inscripción cerrada')
            ->assertNotNotified('Torneo iniciado');

        $this->assertSame(TournamentStatus::Cancelled, $tournament->refresh()->status);
        Event::assertNotDispatched(TournamentStarted::class);
    }

    public function test_starting_below_the_minimum_counts_only_participants_holding_a_seat(): void
    {
        $tournament = $this->tournament(TournamentStatus::RegistrationClosed, ['min_participants' => 3]);
        $this->participants($tournament, 2, ParticipantStatus::Confirmed);
        $this->participants($tournament, 2, ParticipantStatus::Withdrawn);

        Livewire::test(EditTournament::class, ['record' => $tournament->getRouteKey()])
            ->callAction('startTournament')
            ->assertNotified('No hay suficientes participantes para iniciar el torneo')
            ->assertNotNotified('Torneo iniciado');

        $tournament->refresh();
        $this->assertSame(TournamentStatus::RegistrationClosed, $tournament->status);
        $this->assertNull($tournament->started_at);
    }

    public function test_starting_from_the_panel_announces_tournament_started_once(): void
    {
        $tournament = $this->tournament(TournamentStatus::RegistrationClosed);
        $this->participants($tournament, 2, ParticipantStatus::Confirmed);

        Livewire::test(EditTournament::class, ['record' => $tournament->getRouteKey()])->callAction('startTournament');

        $this->assertNotNull($tournament->refresh()->started_at);
        Event::assertDispatchedTimes(TournamentStarted::class, 1);
    }

    /**
     * Another request cancels the tournament after the page has checked the action is visible
     * and before the lifecycle service locks the row.
     */
    private function cancelMeanwhile(TournamentModel $tournament): void
    {
        $service = $this->app->make(TournamentLifecycleServiceInterface::class);
        $cancel = static function () use ($tournament): void {
            TournamentModel::query()->whereKey($tournament->id)->update(['status' => TournamentStatus::Cancelled->value]);
        };

        $this->app->instance(TournamentLifecycleServiceInterface::class, new readonly class($service, $cancel) implements TournamentLifecycleServiceInterface
        {
            /**
             * @param  Closure(): void  $cancel
             */
            public function __construct(private TournamentLifecycleServiceInterface $service, private Closure $cancel) {}

            public function openRegistration(string $tournamentId): void
            {
                ($this->cancel)();
                $this->service->openRegistration($tournamentId);
            }

            public function closeRegistration(string $tournamentId): void
            {
                ($this->cancel)();
                $this->service->closeRegistration($tournamentId);
            }

            public function start(string $tournamentId): void
            {
                ($this->cancel)();
                $this->service->start($tournamentId);
            }

            public function finish(string $tournamentId): void
            {
                ($this->cancel)();
                $this->service->finish($tournamentId);
            }

            public function cancel(string $tournamentId): void
            {
                ($this->cancel)();
                $this->service->cancel($tournamentId);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function tournament(TournamentStatus $status, array $attributes = []): TournamentModel
    {
        return TournamentModel::create([
            'id' => (string) Str::uuid(),
            'event_id' => EventModel::factory()->create(['is_published' => true])->id,
            'name' => 'Torneo de ejemplo',
            'slug' => 'torneo-de-ejemplo-'.Str::random(6),
            'status' => $status->value,
            'result_reporting' => 'admin_only',
            'notification_email' => 'organizacion@example.com',
            ...$attributes,
        ])->refresh();
    }

    private function participants(TournamentModel $tournament, int $count, ParticipantStatus $status): void
    {
        for ($i = 0; $i < $count; $i++) {
            ParticipantModel::create([
                'id' => (string) Str::uuid(),
                'tournament_id' => $tournament->id,
                'user_id' => UserModel::factory()->create()->id,
                'status' => $status->value,
                'registered_at' => now(),
            ]);
        }
    }
}
