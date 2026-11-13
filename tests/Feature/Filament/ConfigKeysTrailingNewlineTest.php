<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Feature\Filament;

use Closure;
use Filament\Forms\Components\Repeater;
use Livewire\Livewire;
use Modules\Tournaments\Filament\Resources\GameProfileResource;
use Modules\Tournaments\Filament\Resources\GameProfileResource\Pages\CreateGameProfile;
use Modules\Tournaments\Filament\Resources\TournamentResource;
use Modules\Tournaments\Filament\Resources\TournamentResource\Pages\CreateTournament;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Modules\InteractsWithModulePanel;
use Tests\Support\Modules\ModuleTestCase;

/**
 * The keys of stats and tiebreakers are stored and read back as identifiers, so a key with a
 * final "\n" is refused: `$` alone would match just before it.
 */
final class ConfigKeysTrailingNewlineTest extends ModuleTestCase
{
    use InteractsWithModulePanel;

    protected ?string $moduleName = 'tournaments';

    protected bool $autoEnableModule = true;

    private ?Closure $undoRepeaterFake = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerOnAdminPanel([GameProfileResource::class, TournamentResource::class]);
        $this->actingAsPanelAdmin();
        $this->undoRepeaterFake = Repeater::fake();
    }

    protected function tearDown(): void
    {
        if ($this->undoRepeaterFake instanceof Closure) {
            ($this->undoRepeaterFake)();
        }

        parent::tearDown();
    }

    /**
     * @param  class-string  $page
     */
    #[DataProvider('keyFields')]
    public function test_a_key_with_a_trailing_newline_is_refused(string $page, string $repeater): void
    {
        Livewire::test($page)
            ->fillForm([$repeater => [['key' => "vp\n", 'name' => 'Puntos']]])
            ->call('create')
            ->assertHasFormErrors(["{$repeater}.0.key" => 'regex']);
    }

    /**
     * @param  class-string  $page
     */
    #[DataProvider('keyFields')]
    public function test_a_plain_key_passes(string $page, string $repeater): void
    {
        Livewire::test($page)
            ->fillForm([$repeater => [['key' => 'vp', 'name' => 'Puntos']]])
            ->call('create')
            ->assertHasNoFormErrors(["{$repeater}.0.key"]);
    }

    /**
     * @return array<string, array{class-string, string}>
     */
    public static function keyFields(): array
    {
        return [
            'game profile stats' => [CreateGameProfile::class, 'stat_definitions'],
            'game profile tiebreakers' => [CreateGameProfile::class, 'tiebreaker_config'],
            'tournament stats' => [CreateTournament::class, 'stat_definitions'],
            'tournament tiebreakers' => [CreateTournament::class, 'tiebreaker_config'],
        ];
    }
}
