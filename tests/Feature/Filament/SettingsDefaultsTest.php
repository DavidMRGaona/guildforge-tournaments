<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Feature\Filament;

use App\Filament\Resources\EventResource\Pages\EditEvent;
use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use App\Filament\Pages\ModuleSettingsPage;
use App\Infrastructure\Persistence\Eloquent\Models\SettingModel;
use Filament\Schemas\Components\Component;
use Filament\Forms\Components\Field;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Modules\Tournaments\Domain\Enums\GuestAccountPolicy;
use Modules\Tournaments\Domain\Enums\GuestConfirmationMode;
use Modules\Tournaments\Infrastructure\Services\GuestRegistrationSettings;
use Modules\Tournaments\TournamentsServiceProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use Modules\Tournaments\Filament\RelationManagers\TournamentConfigRelationManager;
use Modules\Tournaments\Filament\Resources\GameProfileResource;
use Modules\Tournaments\Filament\Resources\TournamentResource;
use Modules\Tournaments\Filament\Resources\TournamentResource\Pages\CreateTournament;
use Tests\Support\Modules\InteractsWithModulePanel;
use Tests\Support\Modules\ModuleTestCase;

/**
 * The module settings ("Valores por defecto") fill in the forms that create a tournament.
 */
final class SettingsDefaultsTest extends ModuleTestCase
{
    use InteractsWithModulePanel;

    protected ?string $moduleName = 'tournaments';

    protected bool $autoEnableModule = true;

    private const array CUSTOM_SETTINGS = [
        'default_result_reporting' => 'players_trusted',
        'default_allow_guests' => true,
        'default_requires_check_in' => true,
        'default_check_in_starts_before' => '45',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerOnAdminPanel([GameProfileResource::class, TournamentResource::class]);
        $this->actingAsPanelAdmin();
    }

    public function test_the_create_form_starts_from_the_module_settings(): void
    {
        config()->set('modules.settings.tournaments', self::CUSTOM_SETTINGS);

        Livewire::test(CreateTournament::class)->assertFormSet([
            'result_reporting' => 'players_trusted',
            'allow_guests' => true,
            'requires_check_in' => true,
            'check_in_starts_before' => 45,
        ]);
    }

    public function test_the_create_form_falls_back_to_the_schema_defaults_without_settings(): void
    {
        config()->set('modules.settings.tournaments', []);

        Livewire::test(CreateTournament::class)->assertFormSet([
            'result_reporting' => 'admin_only',
            'allow_guests' => false,
            'requires_check_in' => false,
            'check_in_starts_before' => 30,
        ]);
    }

    public function test_the_event_tournament_tab_starts_from_the_module_settings(): void
    {
        config()->set('modules.settings.tournaments', self::CUSTOM_SETTINGS);
        $event = EventModel::factory()->create();

        Livewire::test(TournamentConfigRelationManager::class, ['ownerRecord' => $event, 'pageClass' => EditEvent::class])
            ->assertSet('configData.result_reporting', 'players_trusted')
            ->assertSet('configData.allow_guests', true)
            ->assertSet('configData.requires_check_in', true)
            ->assertSet('configData.check_in_starts_before', 45);
    }

    public function test_the_shipped_settings_match_the_settings_form_defaults(): void
    {
        $shipped = require dirname(__DIR__, 3).'/config/settings.php';

        $this->assertEquals([
            'default_result_reporting' => 'admin_only',
            'default_allow_guests' => false,
            'default_requires_check_in' => false,
            'default_check_in_starts_before' => 30,
            'guest_confirmation' => ['mode' => 'hold', 'hold_hours' => 2, 'max_unconfirmed_held' => 2],
            'guest_accounts' => ['mode' => 'require_login'],
            'guest_rate_limit' => ['per_minute' => 5, 'per_hour' => 20],
            'duplicate_signal' => ['window_hours' => 24],
            'reminders' => ['enabled' => false, 'hours_before' => 24],
        ], $shipped);
    }

    public function test_settings_schema_has_the_guest_section(): void
    {
        $names = [];
        $collect = function (array $components) use (&$collect, &$names): void {
            foreach ($components as $component) {
                if ($component instanceof Field) {
                    $names[] = $component->getName();
                }
                if ($component instanceof Component) {
                    $collect($component->getDefaultChildComponents());
                }
            }
        };
        $collect((new TournamentsServiceProvider(app()))->getSettingsSchema());

        foreach ([
            'guest_confirmation.mode', 'guest_confirmation.hold_hours', 'guest_confirmation.max_unconfirmed_held',
            'guest_accounts.mode', 'guest_rate_limit.per_minute', 'guest_rate_limit.per_hour', 'duplicate_signal.window_hours',
        ] as $name) {
            $this->assertContains($name, $names);
        }
    }

    public function test_the_settings_page_shows_the_guest_section_in_spanish(): void
    {
        Livewire::test(ModuleSettingsPage::class, ['module' => 'tournaments'])
            ->assertSee('Inscripciones de invitados')
            ->assertSee('Plaza mientras no confirma')
            ->assertSee('Plazo de confirmación')
            ->assertSee('Máximo de plazas sin confirmar')
            ->assertSee('Email con cuenta registrada')
            ->assertSee('Inscripciones por IP y minuto')
            ->assertSee('Inscripciones por IP y hora')
            ->assertSee('Ventana de posible duplicado');
    }

    public function test_valid_guest_settings_are_saved(): void
    {
        $this->save([
            'guest_confirmation.mode' => 'wait',
            'guest_confirmation.hold_hours' => 6,
            'guest_confirmation.max_unconfirmed_held' => 0,
            'guest_accounts.mode' => 'allow_guest',
            'guest_rate_limit.per_minute' => 10,
            'guest_rate_limit.per_hour' => 50,
            'duplicate_signal.window_hours' => 48,
        ])->assertHasNoFormErrors();

        $settings = new GuestRegistrationSettings();
        $this->assertSame(GuestConfirmationMode::Wait, $settings->confirmationMode());
        $this->assertSame(6, $settings->holdHours());
        $this->assertSame(0, $settings->maxUnconfirmedHeld());
        $this->assertSame(GuestAccountPolicy::AllowGuest, $settings->accountPolicy());
        $this->assertSame(10, $settings->perMinute());
        $this->assertSame(50, $settings->perHour());
        $this->assertSame(48, $settings->duplicateWindowHours());
    }

    /**
     * @return array<string, array{string, mixed, string}>
     */
    public static function invalidGuestSettings(): array
    {
        return [
            'hold hours zero' => ['guest_confirmation.hold_hours', 0, 'min'],
            'hold hours negative' => ['guest_confirmation.hold_hours', -2, 'min'],
            'hold hours not a number' => ['guest_confirmation.hold_hours', 'abc', 'numeric'],
            'hold hours fractional' => ['guest_confirmation.hold_hours', '1.5', 'integer'],
            'hold hours empty' => ['guest_confirmation.hold_hours', null, 'required'],
            'cap negative' => ['guest_confirmation.max_unconfirmed_held', -1, 'min'],
            'per minute zero' => ['guest_rate_limit.per_minute', 0, 'min'],
            'per hour zero' => ['guest_rate_limit.per_hour', 0, 'min'],
            'window zero' => ['duplicate_signal.window_hours', 0, 'min'],
            'confirmation mode unknown' => ['guest_confirmation.mode', 'banana', 'in'],
            'confirmation mode empty' => ['guest_confirmation.mode', null, 'required'],
            'accounts mode unknown' => ['guest_accounts.mode', 'banana', 'in'],
            'accounts mode empty' => ['guest_accounts.mode', null, 'required'],
        ];
    }

    #[DataProvider('invalidGuestSettings')]
    public function test_invalid_guest_settings_are_refused_and_nothing_is_saved(string $field, mixed $value, string $rule): void
    {
        $this->save([$field => $value, 'default_check_in_starts_before' => 55])
            ->assertHasFormErrors([$field => $rule]);

        $this->assertNotSame(55, (int) data_get(config('modules.settings.tournaments'), 'default_check_in_starts_before'));
        $this->assertNull(SettingModel::query()->where('key', 'module_settings:tournaments')->first());
    }

    /**
     * @param  array<string, mixed>  $values
     * @return Testable<ModuleSettingsPage>
     */
    private function save(array $values): Testable
    {
        return Livewire::test(ModuleSettingsPage::class, ['module' => 'tournaments'])
            ->fillForm($values)
            ->call('save');
    }
}
