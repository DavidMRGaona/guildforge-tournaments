<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Integration\Linking;

use App\Application\Linking\Contracts\LinkableRecordSourceInterface;
use App\Application\Linking\Services\LinkableRecordLookupInterface;
use App\Application\Linking\Services\LinkableRecordSourceRegistryInterface;
use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use App\Infrastructure\Persistence\Eloquent\Models\PermissionModel;
use App\Infrastructure\Persistence\Eloquent\Models\RoleModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use Illuminate\Support\Str;
use Modules\Tournaments\Filament\Resources\GameProfileResource;
use Modules\Tournaments\Filament\Resources\TournamentResource;
use Modules\Tournaments\Infrastructure\Linking\TournamentLinkableRecordSource;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use Tests\Support\Modules\InteractsWithModulePanel;
use Tests\Support\Modules\ModuleTestCase;

/**
 * Tournaments as linkable records: listed tournaments for every viewer, drafts and cancelled ones
 * only for who lists tournaments in the panel, and the panel URL only for who may edit them.
 */
final class TournamentLinkableRecordSourceTest extends ModuleTestCase
{
    use InteractsWithModulePanel;

    private const string VIEW_ANY = 'tournaments:tournaments.view_any';

    private const string UPDATE = 'tournaments:tournaments.update';

    protected ?string $moduleName = 'tournaments';

    protected bool $autoEnableModule = true;

    private TournamentLinkableRecordSource $source;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        $this->registerOnAdminPanel([GameProfileResource::class, TournamentResource::class]);
        $this->source = $this->app->make(TournamentLinkableRecordSource::class);
    }

    public function test_the_registry_offers_tournaments_with_their_label(): void
    {
        $source = $this->app->make(LinkableRecordSourceRegistryInterface::class)->source('tournament');

        $this->assertInstanceOf(LinkableRecordSourceInterface::class, $source);
        $this->assertInstanceOf(TournamentLinkableRecordSource::class, $source);
        $this->assertSame('Torneo', $source->label());
        $this->assertSame('Torneo', $this->app->make(LinkableRecordLookupInterface::class)->types()['tournament']);
    }

    public function test_the_record_has_title_event_start_and_no_panel_url_for_a_plain_viewer(): void
    {
        $tournament = $this->tournament(['name' => 'Gran torneo']);
        $viewer = $this->userWith([]);

        $record = $this->source->find($tournament->id, $viewer->id);

        $this->assertNotNull($record);
        $this->assertSame('tournament', $record->type);
        $this->assertSame($tournament->id, $record->id);
        $this->assertSame('Gran torneo', $record->title);
        $this->assertEquals(new DateTimeImmutable('2026-12-12T09:00:00Z'), $record->date);
        $this->assertFalse($record->dateIsDay);
        $this->assertNull($record->panelUrl);
    }

    public function test_listed_tournaments_are_visible_to_a_visitor(): void
    {
        $tournament = $this->tournament(['status' => 'in_progress']);

        $this->assertSame($tournament->id, $this->source->find($tournament->id, (string) Str::uuid())?->id);
        $this->assertSame([$tournament->id], $this->ids($this->source->search('', (string) Str::uuid(), 10)));
    }

    public function test_drafts_and_cancelled_tournaments_are_hidden_without_view_any(): void
    {
        $draft = $this->tournament(['status' => 'draft']);
        $cancelled = $this->tournament(['status' => 'cancelled']);
        $viewer = $this->userWith([self::UPDATE]);

        $this->assertNull($this->source->find($draft->id, $viewer->id));
        $this->assertNull($this->source->find($cancelled->id, $viewer->id));
        $this->assertSame([], $this->source->search('', $viewer->id, 10));
    }

    public function test_drafts_and_cancelled_tournaments_are_visible_with_view_any(): void
    {
        $draft = $this->tournament(['status' => 'draft']);
        $cancelled = $this->tournament(['status' => 'cancelled']);
        $viewer = $this->userWith([self::VIEW_ANY]);

        $this->assertNotNull($this->source->find($draft->id, $viewer->id));
        $this->assertNotNull($this->source->find($cancelled->id, $viewer->id));
        $this->assertEqualsCanonicalizing(
            [$draft->id, $cancelled->id],
            $this->ids($this->source->search('', $viewer->id, 10)),
        );
    }

    public function test_an_unknown_or_malformed_id_finds_nothing(): void
    {
        $viewer = $this->userWith([self::VIEW_ANY]);

        $this->assertNull($this->source->find((string) Str::uuid(), $viewer->id));
        $this->assertNull($this->source->find('not-a-uuid', $viewer->id));
    }

    public function test_the_panel_url_is_given_only_to_who_may_edit(): void
    {
        $tournament = $this->tournament();

        $editor = $this->userWith([self::UPDATE]);
        $viewer = $this->userWith([self::VIEW_ANY]);
        $withoutPanel = UserModel::factory()->create();
        $withoutPanel->roles()->attach($this->roleWith([self::UPDATE])->id);

        $this->assertSame(
            TournamentResource::getUrl('edit', ['record' => $tournament]),
            $this->source->find($tournament->id, $editor->id)?->panelUrl,
        );
        $this->assertNull($this->source->find($tournament->id, $viewer->id)?->panelUrl);
        $this->assertNull($this->source->find($tournament->id, $withoutPanel->id)?->panelUrl);
        $this->assertNull($this->source->find($tournament->id, (string) Str::uuid())?->panelUrl);
    }

    public function test_search_matches_every_word_of_the_name_and_escapes_wildcards(): void
    {
        $this->tournament(['name' => 'Torneo de invierno']);
        $this->tournament(['name' => 'Liga 100% rápida']);
        $viewer = $this->userWith([]);

        $this->assertSame(['Torneo de invierno'], $this->titles($this->source->search('INVIERNO torneo', $viewer->id, 10)));
        $this->assertSame(['Liga 100% rápida'], $this->titles($this->source->search('100%', $viewer->id, 10)));
        $this->assertSame([], $this->source->search('%%', $viewer->id, 10));
    }

    public function test_search_lists_the_latest_first_and_honours_the_limit(): void
    {
        $old = $this->tournament(['name' => 'Antiguo'], new DateTimeImmutable('2026-01-10T09:00:00Z'));
        $new = $this->tournament(['name' => 'Reciente'], new DateTimeImmutable('2026-11-10T09:00:00Z'));
        $viewer = $this->userWith([]);

        $this->assertSame([$new->id, $old->id], $this->ids($this->source->search('', $viewer->id, 10)));
        $this->assertSame([$new->id], $this->ids($this->source->search('', $viewer->id, 1)));
        $this->assertSame([], $this->source->search('', $viewer->id, 0));
    }

    /**
     * @param  list<\App\Application\Linking\DTOs\LinkableRecordDTO>  $records
     * @return list<string>
     */
    private function ids(array $records): array
    {
        return array_map(static fn ($record): string => $record->id, $records);
    }

    /**
     * @param  list<\App\Application\Linking\DTOs\LinkableRecordDTO>  $records
     * @return list<string>
     */
    private function titles(array $records): array
    {
        return array_map(static fn ($record): string => $record->title, $records);
    }

    /**
     * @param  list<string>  $permissionKeys
     */
    private function roleWith(array $permissionKeys): RoleModel
    {
        $role = RoleModel::create(['name' => 'tester_'.uniqid(), 'display_name' => 'Permissions tester']);

        foreach ($permissionKeys as $key) {
            $permission = PermissionModel::firstOrCreate(
                ['key' => $key],
                ['label' => $key, 'resource' => 'tournaments', 'action' => Str::afterLast($key, '.'), 'module' => 'tournaments'],
            );
            $role->permissions()->attach($permission->id);
        }

        return $role;
    }

    /**
     * An account with panel access holding a role with the given permissions.
     *
     * @param  list<string>  $permissionKeys
     */
    private function userWith(array $permissionKeys): UserModel
    {
        $user = UserModel::factory()->editor()->create();
        $user->roles()->attach($this->roleWith($permissionKeys)->id);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function tournament(array $attributes = [], ?DateTimeImmutable $start = null): TournamentModel
    {
        $start ??= new DateTimeImmutable('2026-12-12T09:00:00Z');
        $event = EventModel::factory()->published()->create([
            'start_date' => $start,
            'end_date' => $start->modify('+11 hours'),
        ]);

        return TournamentModel::create(array_merge([
            'event_id' => $event->id,
            'name' => 'Torneo de ejemplo',
            'slug' => 'torneo-'.Str::lower(Str::random(8)),
            'status' => 'registration_open',
            'result_reporting' => 'admin_only',
        ], $attributes));
    }
}
