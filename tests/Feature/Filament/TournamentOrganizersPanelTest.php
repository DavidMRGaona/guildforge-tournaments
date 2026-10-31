<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Feature\Filament;

use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Filament\Forms\Components\Select;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Modules\Tournaments\Filament\Resources\TournamentResource;
use Modules\Tournaments\Filament\Resources\TournamentResource\Pages\EditTournament;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use Tests\Support\Modules\InteractsWithModulePanel;
use Tests\Support\Modules\ModuleTestCase;

/**
 * The tournament's organizers are chosen in the panel: they manage its surveys on the public site.
 */
final class TournamentOrganizersPanelTest extends ModuleTestCase
{
    use InteractsWithModulePanel;

    protected ?string $moduleName = 'tournaments';

    protected bool $autoEnableModule = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerOnAdminPanel([TournamentResource::class]);
        $this->actingAsPanelAdmin();
    }

    public function test_the_organizers_are_saved_from_the_edit_form(): void
    {
        $tournament = $this->tournament();
        $kept = UserModel::factory()->create();
        $removed = UserModel::factory()->create();
        $added = UserModel::factory()->create();
        $tournament->organizers()->attach([$kept->id, $removed->id]);

        Livewire::test(EditTournament::class, ['record' => $tournament->getRouteKey()])
            ->assertFormSet(static function (array $state) use ($kept, $removed): bool {
                $ids = $state['organizers'] ?? null;

                return is_array($ids) && count($ids) === 2 && array_diff([$kept->id, $removed->id], $ids) === [];
            })
            ->fillForm(['organizers' => [$kept->id, $added->id]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertEqualsCanonicalizing([$kept->id, $added->id], $tournament->organizers()->pluck('users.id')->all());
    }

    public function test_anonymized_accounts_are_not_offered_as_organizers(): void
    {
        $active = UserModel::factory()->create(['name' => 'Persona de ejemplo activa']);
        $anonymized = UserModel::factory()->create(['name' => 'Persona de ejemplo anonimizada']);
        $anonymized->forceFill(['anonymized_at' => now()])->save();

        Livewire::test(EditTournament::class, ['record' => $this->tournament()->getRouteKey()])
            ->assertFormFieldExists('organizers', function (Select $field) use ($active, $anonymized): bool {
                $results = $field->getSearchResults('Persona de ejemplo');

                return array_key_exists($active->id, $results) && ! array_key_exists($anonymized->id, $results);
            });
    }

    private function tournament(): TournamentModel
    {
        return TournamentModel::create([
            'id' => (string) Str::uuid(),
            'event_id' => EventModel::factory()->create(['is_published' => true])->id,
            'name' => 'Torneo de ejemplo',
            'slug' => 'torneo-de-ejemplo-'.Str::random(6),
            'status' => 'draft',
            'result_reporting' => 'admin_only',
            'notification_email' => 'organizacion@example.com',
        ])->refresh();
    }
}
