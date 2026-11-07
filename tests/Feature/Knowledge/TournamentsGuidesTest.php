<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Feature\Knowledge;

use App\Application\Knowledge\Services\KnowledgeQueryServiceInterface;
use App\Domain\Knowledge\Enums\GuideAudience;
use App\Domain\Knowledge\ValueObjects\GuideId;
use App\Filament\Pages\KnowledgeBasePage;
use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use App\Infrastructure\Persistence\Eloquent\Models\PermissionModel;
use App\Infrastructure\Persistence\Eloquent\Models\RoleModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Filament\Resources\GameProfileResource;
use Modules\Tournaments\Filament\Resources\GameProfileResource\Pages\CreateGameProfile;
use Modules\Tournaments\Filament\Resources\GameProfileResource\Pages\EditGameProfile;
use Modules\Tournaments\Filament\Resources\GameProfileResource\Pages\ListGameProfiles;
use Modules\Tournaments\Filament\Resources\TournamentResource;
use Modules\Tournaments\Filament\Resources\TournamentResource\Pages\CreateTournament;
use Modules\Tournaments\Filament\Resources\TournamentResource\Pages\EditTournament;
use Modules\Tournaments\Filament\Resources\TournamentResource\Pages\ListTournaments;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\GameProfileModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use Tests\Support\Modules\InteractsWithModulePanel;
use Tests\Support\Modules\ModuleTestCase;

/**
 * Who sees each of the module's guides, and the help button on its panel pages.
 */
final class TournamentsGuidesTest extends ModuleTestCase
{
    use InteractsWithModulePanel;

    /** For every account: taking part in tournaments. */
    private const array SITE_GUIDES = [
        'tournaments.cancel-registration',
        'tournaments.check-in',
        'tournaments.follow-tournament',
        'tournaments.overview',
        'tournaments.report-result',
        'tournaments.sign-up',
    ];

    private const array PANEL_GUIDES = [
        'tournaments.panel.create-tournament',
        'tournaments.panel.event-tournament',
        'tournaments.panel.game-profiles',
        'tournaments.panel.overview',
        'tournaments.panel.participants',
        'tournaments.panel.results',
        'tournaments.panel.rounds',
        'tournaments.panel.scoring',
        'tournaments.panel.settings',
        'tournaments.panel.tournament-lifecycle',
    ];

    protected ?string $moduleName = 'tournaments';

    protected bool $autoEnableModule = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerOnAdminPanel([TournamentResource::class, GameProfileResource::class]);
        $this->artisanCommand('knowledge:sync')->assertSuccessful()->run();
    }

    public function test_every_account_sees_the_guides_to_take_part(): void
    {
        $this->assertSame(self::SITE_GUIDES, $this->visibleSiteGuides(UserModel::factory()->create()));
    }

    public function test_every_guide_renders_without_raw_markup(): void
    {
        $user = UserModel::factory()->create();
        $admin = UserModel::factory()->admin()->create();

        $this->assertSame(self::PANEL_GUIDES, $this->visiblePanelGuides($admin));

        $guides = [
            ...array_map(static fn (string $id): array => [$user, GuideAudience::Site, $id], self::SITE_GUIDES),
            ...array_map(static fn (string $id): array => [$admin, GuideAudience::Panel, $id], self::PANEL_GUIDES),
        ];

        foreach ($guides as [$viewer, $audience, $id]) {
            $guide = $this->queries()->guide($viewer, $audience, GuideId::fromString($id));
            $this->assertNotNull($guide, $id);
            $this->assertStringNotContainsString('[[', $guide->html, $id);
            $this->assertStringNotContainsString('{{', $guide->html, $id);
            $this->assertStringNotContainsString('[!', $guide->html, $id);
        }
    }

    public function test_each_permission_shows_only_its_panel_guides(): void
    {
        $this->assertSame(['tournaments.panel.overview'], $this->visiblePanelGuides($this->userWith('tournaments:tournaments.view_any')));

        $this->assertSame([
            'tournaments.panel.create-tournament',
            'tournaments.panel.scoring',
        ], $this->visiblePanelGuides($this->userWith('tournaments:tournaments.create')));

        $this->assertSame([
            'tournaments.panel.rounds',
            'tournaments.panel.scoring',
            'tournaments.panel.tournament-lifecycle',
        ], $this->visiblePanelGuides($this->userWith('tournaments:tournaments.update')));

        $this->assertSame(['tournaments.panel.participants'], $this->visiblePanelGuides($this->userWith('tournaments:tournaments.manage_participants')));

        $this->assertSame(['tournaments.panel.results'], $this->visiblePanelGuides($this->userWith('tournaments:tournaments.report_results')));

        $this->assertSame([
            'tournaments.panel.game-profiles',
            'tournaments.panel.scoring',
        ], $this->visiblePanelGuides($this->userWith('tournaments:tournaments.manage_config')));

        // The event's tournament tab needs both: editing the event and seeing the tournaments
        $this->assertSame([], $this->visiblePanelGuides($this->userWith('events.update')));
        $this->assertSame([
            'tournaments.panel.event-tournament',
            'tournaments.panel.overview',
        ], $this->visiblePanelGuides($this->userWith('events.update', 'tournaments:tournaments.view_any')));

        $this->assertSame(['tournaments.panel.settings'], $this->visiblePanelGuides($this->userWith('modules.configure')));

        $this->assertSame([], $this->visiblePanelGuides(UserModel::factory()->editor()->create()));
    }

    public function test_the_panel_pages_open_their_guide(): void
    {
        $this->actingAsPanelAdmin();
        $tournament = $this->tournament();
        $profile = GameProfileModel::query()->where('is_system', true)->firstOrFail();

        $pages = [
            [ListTournaments::class, [], 'tournaments.panel.overview'],
            [CreateTournament::class, [], 'tournaments.panel.create-tournament'],
            [EditTournament::class, ['record' => $tournament->getRouteKey()], 'tournaments.panel.tournament-lifecycle'],
            [ListGameProfiles::class, [], 'tournaments.panel.game-profiles'],
            [CreateGameProfile::class, [], 'tournaments.panel.game-profiles'],
            [EditGameProfile::class, ['record' => $profile->getRouteKey()], 'tournaments.panel.game-profiles'],
        ];

        foreach ($pages as [$page, $parameters, $guide]) {
            Livewire::test($page, $parameters)
                ->assertActionVisible('contextualHelp')
                ->assertActionHasUrl('contextualHelp', KnowledgeBasePage::getUrl(['guia' => $guide]));
        }
    }

    private function tournament(): TournamentModel
    {
        return TournamentModel::create([
            'id' => (string) Str::uuid(),
            'event_id' => EventModel::factory()->create(['is_published' => true])->id,
            'name' => 'Torneo de ejemplo',
            'slug' => 'torneo-de-ejemplo-'.Str::random(6),
            'status' => TournamentStatus::Draft->value,
            'result_reporting' => 'admin_only',
            'notification_email' => 'organizacion@example.com',
        ])->refresh();
    }

    /**
     * @return list<string>
     */
    private function visibleSiteGuides(UserModel $user): array
    {
        return $this->moduleGuides($this->queries()->visibleIds($user, GuideAudience::Site));
    }

    /**
     * @return list<string>
     */
    private function visiblePanelGuides(UserModel $user): array
    {
        return $this->moduleGuides($this->queries()->visibleIds($user, GuideAudience::Panel));
    }

    /**
     * @param  array<string>  $ids
     * @return list<string>
     */
    private function moduleGuides(array $ids): array
    {
        $ids = array_values(array_filter($ids, static fn (string $id): bool => str_starts_with($id, 'tournaments.')));
        sort($ids);

        return $ids;
    }

    private function userWith(string ...$permissionKeys): UserModel
    {
        $role = RoleModel::query()->create(['name' => 'tester_'.uniqid(), 'display_name' => 'Permissions tester']);

        foreach ($permissionKeys as $key) {
            $permission = PermissionModel::query()->firstOrCreate(
                ['key' => $key],
                ['label' => $key, 'resource' => 'tournaments', 'action' => 'test', 'module' => 'tournaments'],
            );
            $role->permissions()->attach($permission->id);
        }

        $user = UserModel::factory()->editor()->create();
        $user->roles()->attach($role->id);

        return $user;
    }

    private function queries(): KnowledgeQueryServiceInterface
    {
        return $this->app->make(KnowledgeQueryServiceInterface::class);
    }
}
