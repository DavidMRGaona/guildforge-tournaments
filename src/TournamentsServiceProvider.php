<?php

declare(strict_types=1);

namespace Modules\Tournaments;

use App\Application\Modules\DTOs\ModuleRouteDTO;
use App\Application\Modules\DTOs\NavigationItemDTO;
use App\Application\Modules\DTOs\PagePrefixDTO;
use App\Application\Modules\DTOs\PermissionDTO;
use App\Application\Modules\DTOs\SlotRegistrationDTO;
use App\Domain\Navigation\Enums\MenuLocation;
use App\Filament\Resources\EventResource;
use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use App\Modules\ModuleServiceProvider;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Illuminate\Support\Facades\Event;
use Inertia\Inertia;
use Livewire\Livewire;
use Modules\Tournaments\Application\Services\MatchManagementServiceInterface;
use Modules\Tournaments\Application\Services\MatchQueryServiceInterface;
use Modules\Tournaments\Application\Services\ParticipantManagementServiceInterface;
use Modules\Tournaments\Application\Services\RoundManagementServiceInterface;
use Modules\Tournaments\Application\Services\RoundQueryServiceInterface;
use Modules\Tournaments\Application\Services\TournamentManagementServiceInterface;
use Modules\Tournaments\Application\Services\TournamentQueryServiceInterface;
use Modules\Tournaments\Application\Services\UserDataProviderInterface;
use Modules\Tournaments\Domain\Events\ParticipantRegistered;
use Modules\Tournaments\Domain\Events\ParticipantWithdrawn;
use Modules\Tournaments\Domain\Repositories\GameProfileRepositoryInterface;
use Modules\Tournaments\Domain\Repositories\MatchHistoryRepositoryInterface;
use Modules\Tournaments\Domain\Repositories\MatchRepositoryInterface;
use Modules\Tournaments\Domain\Repositories\ParticipantRepositoryInterface;
use Modules\Tournaments\Domain\Repositories\RoundRepositoryInterface;
use Modules\Tournaments\Domain\Repositories\StandingRepositoryInterface;
use Modules\Tournaments\Domain\Repositories\TournamentRepositoryInterface;
use Modules\Tournaments\Domain\Services\StandingCalculatorServiceInterface;
use Modules\Tournaments\Domain\Services\SwissPairingServiceInterface;
use Modules\Tournaments\Filament\RelationManagers\ParticipantsRelationManager;
use Modules\Tournaments\Filament\RelationManagers\TournamentConfigRelationManager;
use Modules\Tournaments\Filament\Resources\GameProfileResource\Pages\CreateGameProfile;
use Modules\Tournaments\Filament\Resources\GameProfileResource\Pages\EditGameProfile;
use Modules\Tournaments\Filament\Resources\GameProfileResource\Pages\ListGameProfiles;
use Modules\Tournaments\Filament\Resources\TournamentResource\Pages\CreateTournament;
use Modules\Tournaments\Filament\Resources\TournamentResource\Pages\EditTournament;
use Modules\Tournaments\Filament\Resources\TournamentResource\Pages\ListTournaments;
use Modules\Tournaments\Filament\Resources\TournamentResource\RelationManagers\MatchesRelationManager;
use Modules\Tournaments\Filament\Resources\TournamentResource\RelationManagers\ParticipantsRelationManager as TournamentParticipantsRelationManager;
use Modules\Tournaments\Filament\Resources\TournamentResource\RelationManagers\RoundsRelationManager;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\GameProfileModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Repositories\EloquentGameProfileRepository;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Repositories\EloquentMatchHistoryRepository;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Repositories\EloquentMatchRepository;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Repositories\EloquentParticipantRepository;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Repositories\EloquentRoundRepository;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Repositories\EloquentStandingRepository;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Repositories\EloquentTournamentRepository;
use Modules\Tournaments\Infrastructure\Services\MatchQueryService;
use Modules\Tournaments\Infrastructure\Services\ParticipantManagementService;
use Modules\Tournaments\Infrastructure\Services\RoundQueryService;
use Modules\Tournaments\Infrastructure\Services\StandingCalculatorService;
use Modules\Tournaments\Infrastructure\Services\SwissPairingService;
use Modules\Tournaments\Infrastructure\Services\TournamentQueryService;
use Modules\Tournaments\Infrastructure\Services\UserDataProvider;
use Modules\Tournaments\Listeners\SendParticipantRegisteredEmails;
use Modules\Tournaments\Listeners\SendParticipantWithdrawnEmail;
use Modules\Tournaments\Policies\GameProfilePolicy;
use Modules\Tournaments\Policies\TournamentPolicy;

final class TournamentsServiceProvider extends ModuleServiceProvider
{
    public function moduleName(): string
    {
        return 'tournaments';
    }

    public function register(): void
    {
        parent::register();

        $this->registerModelExtensions();
        $this->registerRepositories();
        $this->registerDomainServices();
        $this->registerApplicationServices();
    }

    public function boot(): void
    {
        parent::boot();

        $this->registerEventListeners();
        $this->shareInertiaData();
        $this->registerLivewireComponents();
        $this->registerFilamentExtensions();
    }

    private function registerEventListeners(): void
    {
        Event::listen(ParticipantRegistered::class, SendParticipantRegisteredEmails::class);
        Event::listen(ParticipantWithdrawn::class, SendParticipantWithdrawnEmail::class);
    }

    private function registerModelExtensions(): void
    {
        EventModel::resolveRelationUsing('tournament', function (EventModel $eventModel) {
            return $eventModel->hasOne(TournamentModel::class, 'event_id', 'id');
        });
    }

    private function registerRepositories(): void
    {
        $this->app->bind(
            TournamentRepositoryInterface::class,
            EloquentTournamentRepository::class
        );

        $this->app->bind(
            ParticipantRepositoryInterface::class,
            EloquentParticipantRepository::class
        );

        $this->app->bind(
            RoundRepositoryInterface::class,
            EloquentRoundRepository::class
        );

        $this->app->bind(
            MatchRepositoryInterface::class,
            EloquentMatchRepository::class
        );

        $this->app->bind(
            MatchHistoryRepositoryInterface::class,
            EloquentMatchHistoryRepository::class
        );

        $this->app->bind(
            StandingRepositoryInterface::class,
            EloquentStandingRepository::class
        );

        $this->app->bind(
            GameProfileRepositoryInterface::class,
            EloquentGameProfileRepository::class
        );
    }

    private function registerDomainServices(): void
    {
        $this->app->bind(
            SwissPairingServiceInterface::class,
            SwissPairingService::class
        );

        $this->app->bind(
            StandingCalculatorServiceInterface::class,
            StandingCalculatorService::class
        );
    }

    private function registerApplicationServices(): void
    {
        $this->app->bind(
            TournamentQueryServiceInterface::class,
            TournamentQueryService::class
        );

        $this->app->bind(
            ParticipantManagementServiceInterface::class,
            ParticipantManagementService::class
        );

        $this->app->bind(
            RoundQueryServiceInterface::class,
            RoundQueryService::class
        );

        $this->app->bind(
            UserDataProviderInterface::class,
            UserDataProvider::class
        );

        $this->app->bind(
            MatchQueryServiceInterface::class,
            MatchQueryService::class
        );

        // TODO: Implement when needed
        // $this->app->bind(TournamentManagementServiceInterface::class, TournamentManagementService::class);
        // $this->app->bind(RoundManagementServiceInterface::class, RoundManagementService::class);
        // $this->app->bind(MatchManagementServiceInterface::class, MatchManagementService::class);
    }

    private function registerLivewireComponents(): void
    {
        if (! class_exists(Livewire::class)) {
            return;
        }

        // Event extension relation managers
        Livewire::component(
            'modules.tournaments.filament.relation-managers.tournament-config-relation-manager',
            TournamentConfigRelationManager::class
        );

        Livewire::component(
            'modules.tournaments.filament.relation-managers.participants-relation-manager',
            ParticipantsRelationManager::class
        );

        // TournamentResource pages
        Livewire::component(
            'modules.tournaments.filament.resources.tournament-resource.pages.list-tournaments',
            ListTournaments::class
        );

        Livewire::component(
            'modules.tournaments.filament.resources.tournament-resource.pages.create-tournament',
            CreateTournament::class
        );

        Livewire::component(
            'modules.tournaments.filament.resources.tournament-resource.pages.edit-tournament',
            EditTournament::class
        );

        // TournamentResource relation managers
        Livewire::component(
            'modules.tournaments.filament.resources.tournament-resource.relation-managers.participants-relation-manager',
            TournamentParticipantsRelationManager::class
        );

        Livewire::component(
            'modules.tournaments.filament.resources.tournament-resource.relation-managers.rounds-relation-manager',
            RoundsRelationManager::class
        );

        Livewire::component(
            'modules.tournaments.filament.resources.tournament-resource.relation-managers.matches-relation-manager',
            MatchesRelationManager::class
        );

        // GameProfileResource pages
        Livewire::component(
            'modules.tournaments.filament.resources.game-profile-resource.pages.list-game-profiles',
            ListGameProfiles::class
        );

        Livewire::component(
            'modules.tournaments.filament.resources.game-profile-resource.pages.create-game-profile',
            CreateGameProfile::class
        );

        Livewire::component(
            'modules.tournaments.filament.resources.game-profile-resource.pages.edit-game-profile',
            EditGameProfile::class
        );
    }

    private function registerFilamentExtensions(): void
    {
        if (! class_exists(EventResource::class)) {
            return;
        }

        EventResource::extendRelations([
            TournamentConfigRelationManager::class,
            ParticipantsRelationManager::class,
        ]);
    }

    private function shareInertiaData(): void
    {
        Inertia::share([
            'tournament' => fn () => $this->getTournamentForCurrentRoute(),
            'userRegistration' => fn () => $this->getUserRegistrationForCurrentRoute(),
            'canRegister' => fn () => $this->getCanRegisterForCurrentRoute(),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getTournamentForCurrentRoute(): ?array
    {
        $eventId = $this->getEventIdFromCurrentRoute();
        if ($eventId === null) {
            return null;
        }

        $tournamentQuery = app(TournamentQueryServiceInterface::class);
        $tournament = $tournamentQuery->findByEventId($eventId);

        return $tournament?->toArray();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getUserRegistrationForCurrentRoute(): ?array
    {
        $user = auth()->user();
        if ($user === null) {
            return null;
        }

        $eventId = $this->getEventIdFromCurrentRoute();
        if ($eventId === null) {
            return null;
        }

        $tournamentQuery = app(TournamentQueryServiceInterface::class);
        $tournament = $tournamentQuery->findByEventId($eventId);
        if ($tournament === null) {
            return null;
        }

        $participantService = app(ParticipantManagementServiceInterface::class);
        $registration = $participantService->findByUserAndTournament(
            (string) $user->id,
            $tournament->id
        );

        return $registration?->toArray();
    }

    private function getCanRegisterForCurrentRoute(): bool
    {
        $eventId = $this->getEventIdFromCurrentRoute();
        if ($eventId === null) {
            return false;
        }

        $tournamentQuery = app(TournamentQueryServiceInterface::class);
        $tournament = $tournamentQuery->findByEventId($eventId);
        if ($tournament === null) {
            return false;
        }

        $user = auth()->user();
        if ($user !== null) {
            $userDataProvider = app(UserDataProviderInterface::class);

            return $tournamentQuery->canUserRegister(
                $tournament->id,
                (string) $user->id,
                $userDataProvider->getUserRoles((string) $user->id)
            );
        }

        return $tournamentQuery->canUserRegister(
            $tournament->id,
            null,
            []
        );
    }

    private function getEventIdFromCurrentRoute(): ?string
    {
        $route = request()->route();
        if ($route === null || $route->getName() !== 'events.show') {
            return null;
        }

        $slug = $route->parameter('slug');
        if (! is_string($slug)) {
            return null;
        }

        $event = EventModel::query()
            ->where('slug', $slug)
            ->where('is_published', true)
            ->first(['id']);

        return $event?->id;
    }

    /**
     * Register policies provided by this module.
     *
     * @return array<class-string, class-string>
     */
    public function registerPolicies(): array
    {
        return [
            TournamentModel::class => TournamentPolicy::class,
            GameProfileModel::class => GameProfilePolicy::class,
        ];
    }

    /**
     * Register navigation groups provided by this module.
     *
     * @return array<string, array{icon?: string, sort?: int}>
     */
    public function registerNavigationGroups(): array
    {
        return [
            __('tournaments::messages.navigation.group') => [
                'sort' => 11, // After 'Contenido' (10), before 'Mesas de rol' (12)
            ],
        ];
    }

    /**
     * Register navigation items provided by this module.
     *
     * @return array<NavigationItemDTO>
     */
    public function registerNavigation(): array
    {
        return [
            new NavigationItemDTO(
                label: __('tournaments::messages.public.title'),
                route: 'tournaments.index',
                icon: 'heroicon-o-trophy',
                group: MenuLocation::Header->value,
                sort: 25, // Between Eventos (20) and Calendario (30)
                module: $this->moduleName(),
            ),
        ];
    }

    /**
     * Register public routes for menu item configuration.
     *
     * @return array<ModuleRouteDTO>
     */
    public function registerRoutes(): array
    {
        return [
            new ModuleRouteDTO(
                routeName: 'tournaments.index',
                label: __('tournaments::messages.public.title'),
                module: $this->moduleName(),
            ),
        ];
    }

    /**
     * @return array<PermissionDTO>
     */
    public function registerPermissions(): array
    {
        return [
            new PermissionDTO(
                name: 'tournaments.view_any',
                label: __('tournaments::messages.permissions.view_any'),
                group: __('tournaments::messages.navigation'),
                module: 'tournaments',
                roles: ['editor'],
            ),
            new PermissionDTO(
                name: 'tournaments.view',
                label: __('tournaments::messages.permissions.view'),
                group: __('tournaments::messages.navigation'),
                module: 'tournaments',
                roles: ['editor'],
            ),
            new PermissionDTO(
                name: 'tournaments.create',
                label: __('tournaments::messages.permissions.create'),
                group: __('tournaments::messages.navigation'),
                module: 'tournaments',
                roles: ['editor'],
            ),
            new PermissionDTO(
                name: 'tournaments.update',
                label: __('tournaments::messages.permissions.update'),
                group: __('tournaments::messages.navigation'),
                module: 'tournaments',
                roles: ['editor'],
            ),
            new PermissionDTO(
                name: 'tournaments.delete',
                label: __('tournaments::messages.permissions.delete'),
                group: __('tournaments::messages.navigation'),
                module: 'tournaments',
                roles: [],
            ),
            new PermissionDTO(
                name: 'tournaments.manage_config',
                label: __('tournaments::messages.permissions.manage_config'),
                group: __('tournaments::messages.navigation'),
                module: 'tournaments',
                roles: ['editor'],
            ),
            new PermissionDTO(
                name: 'tournaments.report_results',
                label: __('tournaments::messages.permissions.report_results'),
                group: __('tournaments::messages.navigation'),
                module: 'tournaments',
                roles: ['editor'],
            ),
            new PermissionDTO(
                name: 'tournaments.manage_participants',
                label: __('tournaments::messages.permissions.manage_participants'),
                group: __('tournaments::messages.navigation'),
                module: 'tournaments',
                roles: ['editor'],
            ),
        ];
    }

    /**
     * @return array<\Filament\Forms\Components\Component>
     */
    public function getSettingsSchema(): array
    {
        return [
            Section::make(__('tournaments::messages.settings.defaults'))
                ->schema([
                    Select::make('default_result_reporting')
                        ->label(__('tournaments::messages.settings.default_result_reporting'))
                        ->options([
                            'admin_only' => __('tournaments::messages.result_reporting.admin_only'),
                            'players_with_confirmation' => __('tournaments::messages.result_reporting.players_with_confirmation'),
                            'players_trusted' => __('tournaments::messages.result_reporting.players_trusted'),
                        ])
                        ->default('admin_only'),
                    Toggle::make('default_allow_guests')
                        ->label(__('tournaments::messages.settings.default_allow_guests'))
                        ->default(false),
                    Toggle::make('default_requires_check_in')
                        ->label(__('tournaments::messages.settings.default_requires_check_in'))
                        ->default(false),
                    TextInput::make('default_check_in_starts_before')
                        ->label(__('tournaments::messages.settings.default_check_in_starts_before'))
                        ->helperText(__('tournaments::messages.settings.default_check_in_starts_before_help'))
                        ->numeric()
                        ->suffix(__('tournaments::messages.settings.minutes'))
                        ->default(30),
                ]),
        ];
    }

    /**
     * Register slots provided by this module.
     *
     * @return array<SlotRegistrationDTO>
     */
    public function registerSlots(): array
    {
        return [
            new SlotRegistrationDTO(
                slot: 'event-detail-actions',
                component: 'components/EventTournamentSection.vue',
                module: $this->moduleName(),
                order: 20, // After game-tables (10)
                props: [],
                dataKeys: ['tournament', 'userRegistration', 'canRegister'],
            ),
        ];
    }

    /**
     * Register page prefixes provided by this module.
     * Allows module Vue pages to be resolved by Inertia.
     *
     * @return array<PagePrefixDTO>
     */
    public function registerPagePrefixes(): array
    {
        return [
            new PagePrefixDTO(prefix: 'Tournaments', module: $this->moduleName()),
        ];
    }
}
