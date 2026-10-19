<?php

declare(strict_types=1);

namespace Modules\Tournaments;

use Filament\Schemas\Components\Section;
use App\Application\Modules\DTOs\ModuleRouteDTO;
use App\Application\Modules\DTOs\NavigationItemDTO;
use App\Application\Modules\DTOs\PagePrefixDTO;
use App\Application\Modules\DTOs\PermissionDTO;
use App\Application\Modules\DTOs\SlotRegistrationDTO;
use App\Domain\Navigation\Enums\MenuLocation;
use App\Filament\Resources\EventResource;
use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use App\Modules\ModuleServiceProvider;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;
use Livewire\Livewire;
use Modules\Tournaments\Application\DTOs\Response\TournamentResponseDTO;
use Modules\CookieConsent\Application\DTOs\DeclaredCookieDTO;
use Modules\CookieConsent\Application\Services\CookieDeclarationRegistryInterface;
use Modules\Tournaments\Application\Services\GuestConfirmationQueryInterface;
use Modules\Tournaments\Application\Services\GuestRegistrationSettingsInterface;
use Modules\Tournaments\Application\Services\MatchQueryServiceInterface;
use Modules\Tournaments\Application\Services\MatchResultServiceInterface;
use Modules\Tournaments\Application\Services\ParticipantManagementServiceInterface;
use Modules\Tournaments\Application\Services\RegistrationLockInterface;
use Modules\Tournaments\Application\Services\RoundManagementServiceInterface;
use Modules\Tournaments\Application\Services\RoundQueryServiceInterface;
use Modules\Tournaments\Application\Services\ScoringRuleEvaluatorInterface;
use Modules\Tournaments\Application\Services\StandingsServiceInterface;
use Modules\Tournaments\Application\Services\TournamentQueryServiceInterface;
use Modules\Tournaments\Application\Services\UserDataProviderInterface;
use Modules\Tournaments\Console\Commands\ExpireGuestRegistrationsCommand;
use Modules\Tournaments\Console\Commands\SendTournamentRemindersCommand;
use Modules\Tournaments\Domain\Events\GuestConfirmationRequested;
use Modules\Tournaments\Domain\Events\ParticipantDisqualified;
use Modules\Tournaments\Domain\Events\ParticipantRegistered;
use Modules\Tournaments\Domain\Events\ParticipantWithdrawn;
use Modules\Tournaments\Domain\Events\TournamentCancelled;
use Modules\Tournaments\Domain\Repositories\GameProfileRepositoryInterface;
use Modules\Tournaments\Domain\Repositories\MatchHistoryRepositoryInterface;
use Modules\Tournaments\Domain\Repositories\MatchRepositoryInterface;
use Modules\Tournaments\Domain\Repositories\ParticipantRepositoryInterface;
use Modules\Tournaments\Domain\Repositories\RoundRepositoryInterface;
use Modules\Tournaments\Domain\Repositories\StandingRepositoryInterface;
use Modules\Tournaments\Domain\Repositories\TournamentRepositoryInterface;
use Modules\Tournaments\Domain\Services\StandingCalculatorServiceInterface;
use Modules\Tournaments\Domain\Services\SwissPairingServiceInterface;
use Modules\Tournaments\Domain\Services\TiebreakerCalculatorInterface;
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
use Modules\Tournaments\Infrastructure\Services\DatabaseRegistrationLock;
use Modules\Tournaments\Infrastructure\Services\GuestConfirmationQuery;
use Modules\Tournaments\Infrastructure\Services\GuestRegistrationSettings;
use Modules\Tournaments\Infrastructure\Services\TournamentReminderSettings;
use Modules\Tournaments\Infrastructure\Services\MatchQueryService;
use Modules\Tournaments\Infrastructure\Services\MatchResultService;
use Modules\Tournaments\Infrastructure\Services\ParticipantManagementService;
use Modules\Tournaments\Infrastructure\Services\ProfileTournamentsDataProvider;
use Modules\Tournaments\Infrastructure\Services\RoundManagementService;
use Modules\Tournaments\Infrastructure\Services\RoundQueryService;
use Modules\Tournaments\Infrastructure\Services\ScoringRuleEvaluator;
use Modules\Tournaments\Infrastructure\Services\StandingCalculatorService;
use Modules\Tournaments\Infrastructure\Services\StandingsService;
use Modules\Tournaments\Infrastructure\Services\SwissPairingService;
use Modules\Tournaments\Infrastructure\Services\TiebreakerCalculator;
use Modules\Tournaments\Infrastructure\Services\TournamentQueryService;
use Modules\Tournaments\Infrastructure\Services\UserDataProvider;
use Modules\Tournaments\Listeners\SendGuestConfirmationRequest;
use Modules\Tournaments\Listeners\SendParticipantDisqualifiedEmail;
use Modules\Tournaments\Listeners\SendParticipantRegisteredEmails;
use Modules\Tournaments\Listeners\SendParticipantWithdrawnEmail;
use Modules\Tournaments\Listeners\SendTournamentCancelledEmails;
use Modules\Tournaments\Policies\GameProfilePolicy;
use Modules\Tournaments\Policies\TournamentPolicy;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

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
        $this->registerCommands();
        $this->registerSchedule();
        $this->registerRateLimiters();
        $this->declareGuestCookie();
        $this->shareInertiaData();
        $this->shareProfileTournamentsData();
        $this->registerLivewireComponents();
        $this->registerFilamentExtensions();
    }

    /**
     * The browser remembers guest registrations in a `preferences` cookie. The module does not
     * require cookie-consent: the declaration is only made when its registry exists.
     */
    private function declareGuestCookie(): void
    {
        if (! interface_exists(CookieDeclarationRegistryInterface::class)) {
            return;
        }

        $this->callAfterResolving(
            CookieDeclarationRegistryInterface::class,
            function (CookieDeclarationRegistryInterface $registry): void {
                $registry->declare(new DeclaredCookieDTO(
                    name: 'tn_guest_registrations',
                    categorySlug: 'preferences',
                    provider: (string) config('app.name'),
                    purpose: (string) __('tournaments::messages.cookies.guest_registrations_purpose'),
                    duration: (string) __('tournaments::messages.cookies.guest_registrations_duration'),
                ));
            },
        );
    }

    private function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                ExpireGuestRegistrationsCommand::class,
                SendTournamentRemindersCommand::class,
            ]);
        }
    }

    /**
     * Unconfirmed guest registrations lapse with the clock.
     */
    private function registerSchedule(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('tournaments:expire-guest-registrations')
                ->everyFiveMinutes()
                // A run takes seconds; a killed one must not block the expiry for a day
                ->withoutOverlapping(10);

            // Does nothing until reminders are enabled in the module settings
            $schedule->command('tournaments:send-reminders')
                ->hourly()
                ->withoutOverlapping(10);
        });
    }

    private function registerEventListeners(): void
    {
        Event::listen(ParticipantRegistered::class, SendParticipantRegisteredEmails::class);
        Event::listen(ParticipantWithdrawn::class, SendParticipantWithdrawnEmail::class);
        Event::listen(GuestConfirmationRequested::class, SendGuestConfirmationRequest::class);
        Event::listen(TournamentCancelled::class, SendTournamentCancelledEmails::class);
        Event::listen(ParticipantDisqualified::class, SendParticipantDisqualifiedEmail::class);
    }

    /**
     * Guest registrations per IP, from the module settings; signed-in users are not limited (the
     * route is shared). The guest confirmation link (page and button) per IP, wide enough for a
     * person and tight enough to stop guessing tokens. The public check-in per IP, for everyone,
     * so emails cannot be tried in bulk. Over any limit: a translated message, never an error page.
     */
    private function registerRateLimiters(): void
    {
        RateLimiter::for('tournaments-guest-registration', static function (Request $request): Limit|array {
            if ($request->user() !== null) {
                return Limit::none();
            }

            $settings = app(GuestRegistrationSettingsInterface::class);
            $ip = $request->ip() ?? 'unknown';
            $tooMany = static function (Request $request, array $headers): HttpResponse {
                $message = (string) __('tournaments::messages.errors.too_many_registration_attempts');

                // Inertia visits go back to the form with the error, like any other refusal
                if ($request->header('X-Inertia') === null && $request->expectsJson()) {
                    return response()->json(['message' => $message], 429, $headers);
                }

                return back()->withErrors(['registration' => $message])->with('error', $message);
            };

            return [
                Limit::perMinute($settings->perMinute())->by('tn-guest-min|'.$ip)->response($tooMany),
                Limit::perHour($settings->perHour())->by('tn-guest-hour|'.$ip)->response($tooMany),
            ];
        });

        RateLimiter::for('tournaments-guest-confirmation', static function (Request $request): array {
            $ip = $request->ip() ?? 'unknown';
            $tooMany = static function (Request $request, array $headers): HttpResponse {
                $message = (string) __('tournaments::messages.guest_confirmation.too_many_attempts');

                return $request->expectsJson()
                    ? response()->json(['message' => $message], 429, $headers)
                    : response($message, 429, $headers);
            };

            // Distinct keys: limits sharing a key would share one counter
            return [
                Limit::perMinute(20)->by('minute:'.$ip)->response($tooMany),
                Limit::perHour(100)->by('hour:'.$ip)->response($tooMany),
            ];
        });

        RateLimiter::for('tournaments-public-check-in', static function (Request $request): array {
            $ip = $request->ip() ?? 'unknown';
            $tooMany = static function (Request $request, array $headers): HttpResponse {
                $message = (string) __('tournaments::messages.check_in.too_many_attempts');

                // Inertia visits go back to the form with the error, like any other refusal
                if ($request->header('X-Inertia') === null && $request->expectsJson()) {
                    return response()->json(['message' => $message], 429, $headers);
                }

                return back()->withErrors(['check_in' => $message])->with('error', $message);
            };

            // Distinct keys: limits sharing a key would share one counter
            return [
                Limit::perMinute(10)->by('tn-check-in-min|'.$ip)->response($tooMany),
                Limit::perHour(60)->by('tn-check-in-hour|'.$ip)->response($tooMany),
            ];
        });
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

        $this->app->bind(
            TiebreakerCalculatorInterface::class,
            TiebreakerCalculator::class
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

        $this->app->bind(
            StandingsServiceInterface::class,
            StandingsService::class
        );

        $this->app->bind(
            RoundManagementServiceInterface::class,
            RoundManagementService::class
        );

        $this->app->bind(
            MatchResultServiceInterface::class,
            MatchResultService::class
        );

        $this->app->bind(
            ScoringRuleEvaluatorInterface::class,
            ScoringRuleEvaluator::class
        );

        $this->app->bind(
            GuestRegistrationSettingsInterface::class,
            GuestRegistrationSettings::class
        );

        $this->app->bind(
            RegistrationLockInterface::class,
            DatabaseRegistrationLock::class
        );

        $this->app->bind(
            GuestConfirmationQueryInterface::class,
            GuestConfirmationQuery::class
        );
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
        ]);
    }

    private function shareInertiaData(): void
    {
        Inertia::share([
            'tournament' => fn () => $this->getTournamentForCurrentRoute(),
            'userRegistration' => fn () => $this->getUserRegistrationForCurrentRoute(),
            'canRegister' => fn () => $this->getCanRegisterForCurrentRoute(),
            'guestConfirmation' => fn () => $this->getGuestConfirmationForCurrentRoute(),
        ]);
    }

    /**
     * What the guest form of the event page says before submitting.
     *
     * @return array{mode: 'hold'|'wait', expires_at: string|null}|null
     */
    private function getGuestConfirmationForCurrentRoute(): ?array
    {
        $eventId = $this->getEventIdFromCurrentRoute();
        if ($eventId === null) {
            return null;
        }

        $tournament = $this->findTournamentByEventId($eventId);
        if ($tournament === null) {
            return null;
        }

        return app(GuestConfirmationQueryInterface::class)->forTournament($tournament->id);
    }

    /**
     * Share profile tournaments data via Inertia for the profile page.
     */
    private function shareProfileTournamentsData(): void
    {
        if (! class_exists(Inertia::class)) {
            return;
        }

        Inertia::share('profileTournaments', function (): ?array {
            $route = request()->route();
            if ($route?->getName() !== 'profile.show') {
                return null;
            }

            $user = auth()->user();
            if ($user === null) {
                return null;
            }

            $provider = app(ProfileTournamentsDataProvider::class);

            return $provider->getDataForUser((string) $user->id);
        });

        Inertia::share('profileTournamentsTotal', function (): ?int {
            $route = request()->route();
            if ($route?->getName() !== 'profile.show') {
                return null;
            }

            $user = auth()->user();
            if ($user === null) {
                return null;
            }

            $provider = app(ProfileTournamentsDataProvider::class);
            $data = $provider->getDataForUser((string) $user->id);

            return $data['total'] ?? 0;
        });
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

        $tournament = $this->findTournamentByEventId($eventId);

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

        $tournament = $this->findTournamentByEventId($eventId);
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

        $tournament = $this->findTournamentByEventId($eventId);
        if ($tournament === null) {
            return false;
        }

        $tournamentQuery = app(TournamentQueryServiceInterface::class);
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

    /**
     * The event page asks for its tournament in several props: resolve it once per request.
     */
    private function findTournamentByEventId(string $eventId): ?TournamentResponseDTO
    {
        $key = 'tournaments.by_event.'.$eventId;
        $attributes = request()->attributes;

        if (! $attributes->has($key)) {
            $attributes->set($key, app(TournamentQueryServiceInterface::class)->findByEventId($eventId));
        }

        $tournament = $attributes->get($key);

        return $tournament instanceof TournamentResponseDTO ? $tournament : null;
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
     * @return array<\Filament\Schemas\Components\Component>
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
            Section::make(__('tournaments::messages.settings.guest_registrations'))
                ->schema([
                    Select::make('guest_confirmation.mode')
                        ->label(__('tournaments::messages.settings.guest_confirmation_mode'))
                        ->options([
                            'hold' => __('tournaments::messages.settings.guest_confirmation_modes.hold'),
                            'wait' => __('tournaments::messages.settings.guest_confirmation_modes.wait'),
                        ])
                        ->in(['hold', 'wait'])
                        ->native(false)
                        ->default('hold')
                        ->required(),
                    TextInput::make('guest_confirmation.hold_hours')
                        ->label(__('tournaments::messages.settings.guest_hold_hours'))
                        ->suffix(__('tournaments::messages.settings.hours'))
                        ->numeric()
                        ->integer()
                        ->minValue(1)
                        ->maxValue(168)
                        ->default(2)
                        ->required(),
                    TextInput::make('guest_confirmation.max_unconfirmed_held')
                        ->label(__('tournaments::messages.settings.guest_max_unconfirmed_held'))
                        ->helperText(__('tournaments::messages.settings.guest_max_unconfirmed_held_help'))
                        ->numeric()
                        ->integer()
                        ->minValue(0)
                        ->maxValue(50)
                        ->default(2)
                        ->required(),
                    Select::make('guest_accounts.mode')
                        ->label(__('tournaments::messages.settings.guest_accounts_mode'))
                        ->options([
                            'require_login' => __('tournaments::messages.settings.guest_accounts_modes.require_login'),
                            'allow_guest' => __('tournaments::messages.settings.guest_accounts_modes.allow_guest'),
                        ])
                        ->in(['require_login', 'allow_guest'])
                        ->native(false)
                        ->default('require_login')
                        ->required(),
                    TextInput::make('guest_rate_limit.per_minute')
                        ->label(__('tournaments::messages.settings.guest_rate_per_minute'))
                        ->numeric()
                        ->integer()
                        ->minValue(1)
                        ->maxValue(100)
                        ->default(5)
                        ->required(),
                    TextInput::make('guest_rate_limit.per_hour')
                        ->label(__('tournaments::messages.settings.guest_rate_per_hour'))
                        ->numeric()
                        ->integer()
                        ->minValue(1)
                        ->maxValue(1000)
                        ->default(20)
                        ->required(),
                    TextInput::make('duplicate_signal.window_hours')
                        ->label(__('tournaments::messages.settings.duplicate_window_hours'))
                        ->suffix(__('tournaments::messages.settings.hours'))
                        ->numeric()
                        ->integer()
                        ->minValue(1)
                        ->maxValue(720)
                        ->default(24)
                        ->required(),
                ])
                ->columns(2),
            Section::make(__('tournaments::messages.settings.reminders'))
                ->schema([
                    Toggle::make('reminders.enabled')
                        ->label(__('tournaments::messages.settings.reminders_enabled'))
                        ->helperText(__('tournaments::messages.settings.reminders_enabled_help'))
                        ->default(false),
                    TextInput::make('reminders.hours_before')
                        ->label(__('tournaments::messages.settings.reminders_hours_before'))
                        ->suffix(__('tournaments::messages.settings.hours'))
                        ->numeric()
                        ->integer()
                        ->minValue(1)
                        ->maxValue(TournamentReminderSettings::MAX_HOURS_BEFORE)
                        ->default(TournamentReminderSettings::DEFAULT_HOURS_BEFORE)
                        ->required(),
                ])
                ->columns(2),
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
                dataKeys: ['tournament', 'userRegistration', 'canRegister', 'guestConfirmation'],
            ),
            new SlotRegistrationDTO(
                slot: 'profile-sections',
                component: 'components/profile/ProfileTournamentsSection.vue',
                module: $this->moduleName(),
                order: 20, // After game-tables (10, 15)
                props: [],
                dataKeys: ['profileTournaments'],
                profileTab: [
                    'tabId' => 'tournaments',
                    'icon' => 'trophy',
                    'labelKey' => 'tournaments.profile.tabLabel',
                    'badgeKey' => 'profileTournamentsTotal',
                ],
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
