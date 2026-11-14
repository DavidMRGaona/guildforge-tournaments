<?php

declare(strict_types=1);

namespace Modules\Tournaments\Filament\Pages;

use App\Application\Modules\Services\ModuleManagerServiceInterface;
use App\Domain\Modules\ValueObjects\ModuleName;
use App\Filament\Clusters\SettingsCluster;
use App\Filament\Concerns\ChecksPermissions;
use App\Filament\Concerns\HasKnowledgeGuideAction;
use App\Filament\Concerns\SafeModuleNavigation;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Modules\Tournaments\Infrastructure\Services\TournamentReminderSettings;

/**
 * Defaults, guest registrations and reminders of the module, in the settings cluster.
 *
 * @property Schema $form
 */
final class TournamentsSettings extends Page implements HasForms
{
    use ChecksPermissions;
    use HasKnowledgeGuideAction;
    use InteractsWithForms;
    use SafeModuleNavigation;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $cluster = SettingsCluster::class;

    protected static ?int $navigationSort = 180;

    protected string $view = 'filament.pages.simple-settings';

    /**
     * @var array<string, mixed>
     */
    public ?array $data = [];

    public static function getNavigationLabel(): string
    {
        return __('tournaments::messages.navigation');
    }

    public function getTitle(): string
    {
        return __('tournaments::messages.settings.title');
    }

    public static function canAccess(): bool
    {
        // Granted in Roles; admins hold every permission
        return self::userCan('tournaments:tournaments.manage_settings');
    }

    public function mount(ModuleManagerServiceInterface $moduleManager): void
    {
        // Saved values over the shipped defaults (config/settings.php)
        $this->form->fill($moduleManager->getSettings(new ModuleName('tournaments')));
    }

    /**
     * @return array<Component>
     */
    public static function getFormSchemaComponents(): array
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

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components(self::getFormSchemaComponents())
            ->statePath('data');
    }

    public function save(ModuleManagerServiceInterface $moduleManager): void
    {
        // Livewire methods can be called without the page's button
        abort_unless(self::canAccess(), 403);

        $moduleManager->updateSettings(new ModuleName('tournaments'), $this->form->getState());

        Notification::make()
            ->title(__('common.saved'))
            ->success()
            ->send();
    }

    /**
     * @return array<Action>
     */
    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label(__('common.save'))
                ->submit('save'),
        ];
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return array_filter([
            $this->knowledgeGuideAction('tournaments.panel.settings'),
        ]);
    }
}
