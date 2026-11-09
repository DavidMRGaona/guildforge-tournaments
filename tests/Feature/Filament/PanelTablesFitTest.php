<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Feature\Filament;

use App\Filament\Actions\RowDetailsAction;
use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use Filament\Actions\ActionGroup;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\Alignment;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Filament\Resources\GameProfileResource;
use Modules\Tournaments\Filament\Resources\GameProfileResource\Pages\ListGameProfiles;
use Modules\Tournaments\Filament\Resources\TournamentResource;
use Modules\Tournaments\Filament\Resources\TournamentResource\Pages\EditTournament;
use Modules\Tournaments\Filament\Resources\TournamentResource\Pages\ListTournaments;
use Modules\Tournaments\Filament\Resources\TournamentResource\RelationManagers\MatchesRelationManager;
use Modules\Tournaments\Filament\Resources\TournamentResource\RelationManagers\ParticipantsRelationManager;
use Modules\Tournaments\Filament\Resources\TournamentResource\RelationManagers\RoundsRelationManager;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Modules\InteractsWithModulePanel;
use Tests\Support\Modules\ModuleTestCase;

/**
 * The panel tables measured wider than their container at every width, so the row actions
 * fell outside the view: the actions sit in one menu and the secondary columns only show
 * from the width where they fit.
 */
final class PanelTablesFitTest extends ModuleTestCase
{
    use InteractsWithModulePanel;

    protected ?string $moduleName = 'tournaments';

    protected bool $autoEnableModule = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerOnAdminPanel([GameProfileResource::class, TournamentResource::class]);
        $this->actingAsPanelAdmin();
    }

    /**
     * Tables with several row actions, which go in one menu.
     *
     * @return array<string, array{class-string<HasTable>}>
     */
    public static function tablesWithSeveralActions(): array
    {
        return [
            'tournaments' => [ListTournaments::class],
            'game profiles' => [ListGameProfiles::class],
            'participants' => [ParticipantsRelationManager::class],
            'rounds' => [RoundsRelationManager::class],
            'matches' => [MatchesRelationManager::class],
        ];
    }

    /**
     * @param  class-string<HasTable>  $page
     */
    #[DataProvider('tablesWithSeveralActions')]
    public function test_row_actions_sit_in_a_menu(string $page): void
    {
        $actions = $this->tableOf($page)->getRecordActions();

        $this->assertCount(1, $actions);
        $this->assertInstanceOf(ActionGroup::class, $actions[0]);
    }

    /**
     * @param  class-string<HasTable>  $page
     */
    #[DataProvider('tablesWithSeveralActions')]
    public function test_row_details_is_the_first_entry_of_the_menu(string $page): void
    {
        $group = $this->tableOf($page)->getRecordActions()[0];

        $this->assertInstanceOf(ActionGroup::class, $group);
        $this->assertSame(RowDetailsAction::NAME, array_key_first($group->getFlatActions()));
    }

    /**
     * At 375 px the name, the status, the details icon and the edit button measured 23 px
     * wider than the container: edit moved into the menu, since the row opens it anyway.
     */
    public function test_the_tournament_list_offers_row_details_and_edit_in_its_menu(): void
    {
        $group = $this->tableOf(ListTournaments::class)->getRecordActions()[0];

        $this->assertInstanceOf(ActionGroup::class, $group);
        $this->assertSame([RowDetailsAction::NAME, 'edit'], array_keys($group->getFlatActions()));
    }

    /**
     * @return array<string, array{class-string<HasTable>, list<string>}>
     */
    public static function centredCounters(): array
    {
        return [
            'tournaments' => [ListTournaments::class, ['participants_count']],
            'game profiles' => [ListGameProfiles::class, ['tournaments_count']],
            'participants' => [ParticipantsRelationManager::class, ['seed']],
            'rounds' => [RoundsRelationManager::class, ['matches_count', 'completed_matches']],
            'matches' => [MatchesRelationManager::class, ['table_number', 'player_1_score', 'player_2_score']],
        ];
    }

    /**
     * @param  class-string<HasTable>  $page
     * @param  list<string>  $columns
     */
    #[DataProvider('centredCounters')]
    public function test_short_counters_are_centred(string $page, array $columns): void
    {
        $table = $this->tableOf($page);

        foreach ($columns as $name) {
            $this->assertSame(Alignment::Center, $table->getColumn($name)?->getAlignment(), "Column {$name}");
        }
    }

    /**
     * Column => breakpoint it shows from; null for the ones that always show.
     *
     * @return array<string, array{class-string<HasTable>, array<string, string|null>}>
     */
    public static function columnBreakpoints(): array
    {
        return [
            'tournaments' => [ListTournaments::class, [
                'name' => null,
                'status' => null,
                'event.title' => 'xl',
                'participants_count' => 'md',
                'current_round' => '2xl',
                'image_public_id' => '2xl',
            ]],
            'game profiles' => [ListGameProfiles::class, [
                'name' => null,
                'is_system' => 'sm',
                'tournaments_count' => 'md',
                'description' => '2xl',
            ]],
            'participants' => [ParticipantsRelationManager::class, [
                'display_name' => null,
                'status' => null,
                'possible_duplicate' => 'sm',
                'is_guest' => 'md',
                'user.email' => 'xl',
                'created_at' => 'lg',
                'checked_in_at' => '2xl',
                'has_received_bye' => '2xl',
                'seed' => '2xl',
            ]],
            'rounds' => [RoundsRelationManager::class, [
                'round_number' => null,
                'status' => null,
                'matches_count' => 'sm',
                'completed_matches' => 'md',
                'started_at' => 'lg',
                'completed_at' => 'xl',
            ]],
            'matches' => [MatchesRelationManager::class, [
                'table_number' => null,
                'player1.participant_name' => null,
                'player2.participant_name' => null,
                'result' => 'sm',
                'report_status' => 'md',
                'player_1_score' => 'lg',
                'player_2_score' => 'lg',
                'round.round_number' => 'xl',
            ]],
        ];
    }

    /**
     * @param  class-string<HasTable>  $page
     * @param  array<string, string|null>  $breakpoints
     */
    #[DataProvider('columnBreakpoints')]
    public function test_secondary_columns_show_only_from_the_width_where_they_fit(string $page, array $breakpoints): void
    {
        $table = $this->tableOf($page);

        foreach ($breakpoints as $name => $breakpoint) {
            $column = $table->getColumn($name);

            $this->assertNotNull($column, "Column {$name} is missing");
            $this->assertSame($breakpoint, $column->getVisibleFrom(), "Column {$name}");
        }
    }

    /**
     * «Ver detalles» shows every column with its label, so a column without one read «Created at».
     */
    public function test_the_creation_date_column_has_a_translated_label(): void
    {
        $this->assertSame('Creado', __('tournaments::messages.fields.created_at', [], 'es'));
        $this->assertSame(__('tournaments::messages.fields.created_at'), $this->tableOf(ListTournaments::class)->getColumn('created_at')?->getLabel());
    }

    private function tableOf(string $page): Table
    {
        $params = is_subclass_of($page, RelationManager::class)
            ? ['ownerRecord' => $this->tournament(), 'pageClass' => EditTournament::class]
            : [];

        /** @var HasTable $component */
        $component = Livewire::test($page, $params)->instance();

        return $component->getTable();
    }

    private function tournament(): TournamentModel
    {
        return TournamentModel::create([
            'id' => (string) Str::uuid(),
            'event_id' => EventModel::factory()->create()->id,
            'name' => 'Torneo suizo',
            'slug' => 'torneo-suizo',
            'status' => TournamentStatus::InProgress->value,
            'result_reporting' => 'admin_only',
        ])->refresh();
    }
}
