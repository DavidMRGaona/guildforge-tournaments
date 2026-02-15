<?php

declare(strict_types=1);

namespace Modules\Tournaments\Filament\Resources;

use App\Filament\Resources\BaseResource;
use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Group;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Tabs;
use Filament\Forms\Components\Tabs\Tab;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Modules\Tournaments\Domain\Enums\ByeAssignment;
use Modules\Tournaments\Domain\Enums\ConditionType;
use Modules\Tournaments\Domain\Enums\PairingMethod;
use Modules\Tournaments\Domain\Enums\PairingSortCriteria;
use Modules\Tournaments\Domain\Enums\SortDirection;
use Modules\Tournaments\Domain\Enums\StatType;
use Modules\Tournaments\Domain\Enums\TiebreakerType;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Filament\Resources\TournamentResource\Pages;
use Modules\Tournaments\Filament\Resources\TournamentResource\RelationManagers\ParticipantsRelationManager;
use Modules\Tournaments\Filament\Resources\TournamentResource\RelationManagers\RoundsRelationManager;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\GameProfileModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;

final class TournamentResource extends BaseResource
{
    protected static ?string $model = TournamentModel::class;

    protected static ?string $navigationIcon = 'heroicon-o-trophy';

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): ?string
    {
        return __('tournaments::messages.navigation.group');
    }

    public static function getNavigationLabel(): string
    {
        return __('tournaments::messages.resource.navigation');
    }

    public static function getModelLabel(): string
    {
        return __('tournaments::messages.resource.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('tournaments::messages.resource.plural');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Tabs::make('TournamentTabs')
                    ->tabs([
                        Tab::make(__('tournaments::messages.config.general'))
                            ->icon('heroicon-o-document-text')
                            ->schema([
                                Select::make('event_id')
                                    ->label(__('tournaments::messages.resource.fields.event'))
                                    ->options(
                                        EventModel::query()
                                            ->where('is_published', true)
                                            ->orderBy('start_date', 'desc')
                                            ->pluck('title', 'id')
                                    )
                                    ->searchable()
                                    ->required()
                                    ->native(false)
                                    ->unique(ignoreRecord: true)
                                    ->columnSpanFull(),

                                TextInput::make('name')
                                    ->label(__('tournaments::messages.config.name'))
                                    ->required()
                                    ->maxLength(255),

                                TextInput::make('description')
                                    ->label(__('tournaments::messages.config.description'))
                                    ->maxLength(1000),

                                FileUpload::make('image_public_id')
                                    ->label(__('tournaments::messages.fields.image'))
                                    ->image()
                                    ->disk('images')
                                    ->directory(fn (): string => 'tournaments/'.now()->format('Y/m'))
                                    ->getUploadedFileNameForStorageUsing(
                                        fn (TemporaryUploadedFile $file): string => Str::uuid()->toString().'.'.$file->getClientOriginalExtension()
                                    )
                                    ->maxSize(2048)
                                    ->nullable()
                                    ->columnSpanFull(),

                                Select::make('game_profile_id')
                                    ->label(__('tournaments::messages.game_profile.select'))
                                    ->helperText(__('tournaments::messages.game_profile.select_help'))
                                    ->options(fn (): array => self::getGameProfileOptions())
                                    ->searchable()
                                    ->preload()
                                    ->native(false)
                                    ->live()
                                    ->afterStateUpdated(fn (?string $state, Set $set) => self::loadGameProfileDefaults($state, $set))
                                    ->columnSpanFull(),
                            ])
                            ->columns(2),

                        Tab::make(__('tournaments::messages.config.capacity'))
                            ->icon('heroicon-o-users')
                            ->schema([
                                TextInput::make('max_participants')
                                    ->label(__('tournaments::messages.config.max_participants'))
                                    ->helperText(__('tournaments::messages.config.max_participants_help'))
                                    ->numeric()
                                    ->minValue(2)
                                    ->nullable(),

                                TextInput::make('min_participants')
                                    ->label(__('tournaments::messages.config.min_participants'))
                                    ->numeric()
                                    ->minValue(2)
                                    ->default(2)
                                    ->required(),

                                TextInput::make('max_rounds')
                                    ->label(__('tournaments::messages.config.max_rounds'))
                                    ->helperText(__('tournaments::messages.config.max_rounds_help'))
                                    ->numeric()
                                    ->minValue(1)
                                    ->nullable(),
                            ])
                            ->columns(3),

                        Tab::make(__('tournaments::messages.config.dates'))
                            ->icon('heroicon-o-calendar')
                            ->schema([
                                DateTimePicker::make('registration_opens_at')
                                    ->label(__('tournaments::messages.config.registration_opens_at'))
                                    ->native(false)
                                    ->displayFormat('d/m/Y H:i')
                                    ->nullable(),

                                DateTimePicker::make('registration_closes_at')
                                    ->label(__('tournaments::messages.config.registration_closes_at'))
                                    ->native(false)
                                    ->displayFormat('d/m/Y H:i')
                                    ->after('registration_opens_at')
                                    ->nullable(),
                            ])
                            ->columns(2),

                        Tab::make(__('tournaments::messages.stat_definitions.title'))
                            ->icon('heroicon-o-chart-bar')
                            ->schema([
                                Placeholder::make('stat_definitions_info')
                                    ->label('')
                                    ->content(fn (Get $get): HtmlString => new HtmlString(
                                        '<div class="flex flex-wrap items-center gap-2">'.
                                        ($get('game_profile_id')
                                            ? '<span class="inline-flex items-center gap-1.5 px-2.5 py-1 text-xs font-medium text-primary-700 bg-primary-50 rounded-md dark:bg-primary-900/50 dark:text-primary-300">
                                                <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m13.35-.622 1.757-1.757a4.5 4.5 0 0 0-6.364-6.364l-4.5 4.5a4.5 4.5 0 0 0 1.242 7.244" /></svg>
                                                '.e(__('tournaments::messages.advanced_config.inherit_from_profile')).'
                                               </span>'
                                            : '').
                                        '<span class="text-sm text-gray-500 dark:text-gray-400">'.e(__('tournaments::messages.stat_definitions.help')).'</span>'.
                                        '</div>'
                                    ))
                                    ->columnSpanFull(),

                                Repeater::make('stat_definitions')
                                    ->label('')
                                    ->schema([
                                        TextInput::make('key')
                                            ->label(__('tournaments::messages.stat_definitions.key'))
                                            ->helperText(__('tournaments::messages.stat_definitions.key_help'))
                                            ->required()
                                            ->regex('/^[a-z_]+$/'),

                                        TextInput::make('name')
                                            ->label(__('tournaments::messages.stat_definitions.name'))
                                            ->required(),

                                        Select::make('type')
                                            ->label(__('tournaments::messages.stat_definitions.type'))
                                            ->helperText(__('tournaments::messages.stat_definitions.type_help'))
                                            ->hintIcon('heroicon-o-information-circle', tooltip: __('tournaments::messages.stat_definitions.type_hint'))
                                            ->options(StatType::options())
                                            ->default(StatType::Integer->value)
                                            ->required()
                                            ->native(false),

                                        TextInput::make('min_value')
                                            ->label(__('tournaments::messages.stat_definitions.min_value'))
                                            ->numeric()
                                            ->nullable(),

                                        TextInput::make('max_value')
                                            ->label(__('tournaments::messages.stat_definitions.max_value'))
                                            ->numeric()
                                            ->nullable(),

                                        Toggle::make('required')
                                            ->label(__('tournaments::messages.stat_definitions.required'))
                                            ->helperText(__('tournaments::messages.stat_definitions.required_help'))
                                            ->default(false),
                                    ])
                                    ->columns(3)
                                    ->addActionLabel(__('tournaments::messages.stat_definitions.add'))
                                    ->reorderable()
                                    ->collapsible()
                                    ->columnSpanFull(),
                            ]),

                        Tab::make(__('tournaments::messages.scoring_rules.title'))
                            ->icon('heroicon-o-calculator')
                            ->schema([
                                Placeholder::make('scoring_rules_info')
                                    ->label('')
                                    ->content(fn (Get $get): HtmlString => new HtmlString(
                                        '<div class="flex flex-wrap items-center gap-2">'.
                                        ($get('game_profile_id')
                                            ? '<span class="inline-flex items-center gap-1.5 px-2.5 py-1 text-xs font-medium text-primary-700 bg-primary-50 rounded-md dark:bg-primary-900/50 dark:text-primary-300">
                                                <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m13.35-.622 1.757-1.757a4.5 4.5 0 0 0-6.364-6.364l-4.5 4.5a4.5 4.5 0 0 0 1.242 7.244" /></svg>
                                                '.e(__('tournaments::messages.advanced_config.inherit_from_profile')).'
                                               </span>'
                                            : '').
                                        '<span class="text-sm text-gray-500 dark:text-gray-400">'.e(__('tournaments::messages.scoring_rules.help')).'</span>'.
                                        '</div>'
                                    ))
                                    ->columnSpanFull(),

                                Repeater::make('scoring_rules')
                                    ->label('')
                                    ->schema([
                                        TextInput::make('name')
                                            ->label(__('tournaments::messages.scoring_rules.name'))
                                            ->required(),

                                        TextInput::make('points')
                                            ->label(__('tournaments::messages.scoring_rules.points'))
                                            ->helperText(__('tournaments::messages.scoring_rules.points_help'))
                                            ->numeric()
                                            ->required()
                                            ->step(0.5),

                                        TextInput::make('priority')
                                            ->label(__('tournaments::messages.scoring_rules.priority'))
                                            ->helperText(__('tournaments::messages.scoring_rules.priority_help'))
                                            ->numeric()
                                            ->default(0),

                                        Group::make()
                                            ->statePath('condition')
                                            ->schema([
                                                Select::make('type')
                                                    ->label(__('tournaments::messages.scoring_rules.condition_type'))
                                                    ->helperText(__('tournaments::messages.scoring_rules.condition_type_help'))
                                                    ->hintIcon('heroicon-o-information-circle', tooltip: __('tournaments::messages.scoring_rules.condition_type_hint'))
                                                    ->options(ConditionType::options())
                                                    ->required()
                                                    ->native(false)
                                                    ->live(),

                                                Select::make('result_value')
                                                    ->label(__('tournaments::messages.scoring_rules.condition_value'))
                                                    ->options([
                                                        'win' => __('tournaments::messages.condition_result.win'),
                                                        'draw' => __('tournaments::messages.condition_result.draw'),
                                                        'loss' => __('tournaments::messages.condition_result.loss'),
                                                        'bye' => __('tournaments::messages.condition_result.bye'),
                                                    ])
                                                    ->visible(fn (Get $get): bool => $get('type') === ConditionType::Result->value)
                                                    ->native(false),

                                                TextInput::make('stat')
                                                    ->label(__('tournaments::messages.scoring_rules.condition_stat'))
                                                    ->visible(fn (Get $get): bool => in_array($get('type'), [
                                                        ConditionType::StatComparison->value,
                                                        ConditionType::StatThreshold->value,
                                                        ConditionType::MarginDifference->value,
                                                    ], true)),

                                                Select::make('operator')
                                                    ->label(__('tournaments::messages.scoring_rules.condition_operator'))
                                                    ->options([
                                                        '>' => __('tournaments::messages.condition_operator.greater_than'),
                                                        '>=' => __('tournaments::messages.condition_operator.greater_or_equal'),
                                                        '<' => __('tournaments::messages.condition_operator.less_than'),
                                                        '<=' => __('tournaments::messages.condition_operator.less_or_equal'),
                                                        '==' => __('tournaments::messages.condition_operator.equal'),
                                                    ])
                                                    ->visible(fn (Get $get): bool => in_array($get('type'), [
                                                        ConditionType::StatComparison->value,
                                                        ConditionType::StatThreshold->value,
                                                        ConditionType::MarginDifference->value,
                                                    ], true))
                                                    ->native(false),

                                                TextInput::make('value')
                                                    ->label(__('tournaments::messages.scoring_rules.condition_threshold'))
                                                    ->numeric()
                                                    ->visible(fn (Get $get): bool => in_array($get('type'), [
                                                        ConditionType::StatThreshold->value,
                                                        ConditionType::MarginDifference->value,
                                                    ], true)),
                                            ])
                                            ->columns(3)
                                            ->columnSpanFull(),
                                    ])
                                    ->columns(3)
                                    ->addActionLabel(__('tournaments::messages.scoring_rules.add'))
                                    ->reorderable()
                                    ->collapsible()
                                    ->defaultItems(4)
                                    ->default(self::getDefaultScoringRules())
                                    ->columnSpanFull(),
                            ]),

                        Tab::make(__('tournaments::messages.tiebreaker_config.title'))
                            ->icon('heroicon-o-scale')
                            ->schema([
                                Placeholder::make('tiebreaker_config_info')
                                    ->label('')
                                    ->content(fn (Get $get): HtmlString => new HtmlString(
                                        '<div class="flex flex-wrap items-center gap-2">'.
                                        ($get('game_profile_id')
                                            ? '<span class="inline-flex items-center gap-1.5 px-2.5 py-1 text-xs font-medium text-primary-700 bg-primary-50 rounded-md dark:bg-primary-900/50 dark:text-primary-300">
                                                <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m13.35-.622 1.757-1.757a4.5 4.5 0 0 0-6.364-6.364l-4.5 4.5a4.5 4.5 0 0 0 1.242 7.244" /></svg>
                                                '.e(__('tournaments::messages.advanced_config.inherit_from_profile')).'
                                               </span>'
                                            : '').
                                        '<span class="text-sm text-gray-500 dark:text-gray-400">'.e(__('tournaments::messages.tiebreaker_config.help')).'</span>'.
                                        '</div>'
                                    ))
                                    ->columnSpanFull(),

                                Repeater::make('tiebreaker_config')
                                    ->label('')
                                    ->schema([
                                        TextInput::make('key')
                                            ->label(__('tournaments::messages.tiebreaker_config.key'))
                                            ->required()
                                            ->regex('/^[a-z_]+$/'),

                                        TextInput::make('name')
                                            ->label(__('tournaments::messages.tiebreaker_config.name'))
                                            ->required(),

                                        Select::make('type')
                                            ->label(__('tournaments::messages.tiebreaker_config.type'))
                                            ->helperText(__('tournaments::messages.tiebreaker_config.type_help'))
                                            ->hintIcon('heroicon-o-information-circle', tooltip: __('tournaments::messages.tiebreaker_config.type_hint'))
                                            ->options(TiebreakerType::options())
                                            ->required()
                                            ->native(false)
                                            ->live(),

                                        TextInput::make('stat')
                                            ->label(__('tournaments::messages.tiebreaker_config.stat'))
                                            ->helperText(__('tournaments::messages.tiebreaker_config.stat_help'))
                                            ->visible(fn (Get $get): bool => in_array($get('type'), [
                                                TiebreakerType::StatSum->value,
                                                TiebreakerType::StatDiff->value,
                                                TiebreakerType::StatAverage->value,
                                                TiebreakerType::StatMax->value,
                                            ], true)),

                                        Select::make('direction')
                                            ->label(__('tournaments::messages.tiebreaker_config.direction'))
                                            ->helperText(__('tournaments::messages.tiebreaker_config.direction_help'))
                                            ->hintIcon('heroicon-o-information-circle', tooltip: __('tournaments::messages.tiebreaker_config.direction_hint'))
                                            ->options([
                                                SortDirection::Descending->value => __('tournaments::messages.tiebreaker_config.direction_desc'),
                                                SortDirection::Ascending->value => __('tournaments::messages.tiebreaker_config.direction_asc'),
                                            ])
                                            ->default(SortDirection::Descending->value)
                                            ->native(false),

                                        TextInput::make('min_value')
                                            ->label(__('tournaments::messages.tiebreaker_config.min_value'))
                                            ->helperText(__('tournaments::messages.tiebreaker_config.min_value_help'))
                                            ->numeric()
                                            ->step(0.01)
                                            ->nullable(),
                                    ])
                                    ->columns(3)
                                    ->addActionLabel(__('tournaments::messages.tiebreaker_config.add'))
                                    ->reorderable()
                                    ->collapsible()
                                    ->defaultItems(2)
                                    ->default(self::getDefaultTiebreakerConfig())
                                    ->columnSpanFull(),
                            ]),

                        Tab::make(__('tournaments::messages.pairing_config.title'))
                            ->icon('heroicon-o-arrows-right-left')
                            ->schema([
                                Select::make('pairing_config.method')
                                    ->label(__('tournaments::messages.pairing_config.method'))
                                    ->options(PairingMethod::options())
                                    ->default(PairingMethod::Swiss->value)
                                    ->native(false),

                                Select::make('pairing_config.sort_by')
                                    ->label(__('tournaments::messages.pairing_config.sort_by'))
                                    ->options(PairingSortCriteria::options())
                                    ->default(PairingSortCriteria::Points->value)
                                    ->native(false)
                                    ->live(),

                                TextInput::make('pairing_config.sort_by_stat')
                                    ->label(__('tournaments::messages.pairing_config.sort_by_stat'))
                                    ->visible(fn (Get $get): bool => $get('pairing_config.sort_by') === PairingSortCriteria::Stat->value),

                                Toggle::make('pairing_config.avoid_rematches')
                                    ->label(__('tournaments::messages.pairing_config.avoid_rematches'))
                                    ->helperText(__('tournaments::messages.pairing_config.avoid_rematches_help'))
                                    ->default(true),

                                TextInput::make('pairing_config.max_byes_per_player')
                                    ->label(__('tournaments::messages.pairing_config.max_byes_per_player'))
                                    ->numeric()
                                    ->default(1)
                                    ->minValue(1),

                                Select::make('pairing_config.bye_assignment')
                                    ->label(__('tournaments::messages.pairing_config.bye_assignment'))
                                    ->options(ByeAssignment::options())
                                    ->default(ByeAssignment::LowestRanked->value)
                                    ->native(false),
                            ])
                            ->columns(2),

                        Tab::make(__('tournaments::messages.config.options'))
                            ->icon('heroicon-o-cog-6-tooth')
                            ->schema([
                                Select::make('result_reporting')
                                    ->label(__('tournaments::messages.config.result_reporting'))
                                    ->helperText(__('tournaments::messages.config.result_reporting_help'))
                                    ->options([
                                        'admin_only' => __('tournaments::messages.result_reporting.admin_only'),
                                        'players_with_confirmation' => __('tournaments::messages.result_reporting.players_with_confirmation'),
                                        'players_trusted' => __('tournaments::messages.result_reporting.players_trusted'),
                                    ])
                                    ->default('admin_only')
                                    ->required(),

                                Toggle::make('allow_guests')
                                    ->label(__('tournaments::messages.config.allow_guests'))
                                    ->helperText(__('tournaments::messages.config.allow_guests_help'))
                                    ->default(false),

                                Toggle::make('show_participants')
                                    ->label(__('tournaments::messages.config.show_participants'))
                                    ->helperText(__('tournaments::messages.config.show_participants_help'))
                                    ->default(true),

                                Toggle::make('requires_check_in')
                                    ->label(__('tournaments::messages.config.requires_check_in'))
                                    ->live()
                                    ->default(false),

                                Toggle::make('requires_manual_confirmation')
                                    ->label(__('tournaments::messages.config.requires_manual_confirmation'))
                                    ->helperText(__('tournaments::messages.config.requires_manual_confirmation_help'))
                                    ->default(false),

                                TextInput::make('check_in_starts_before')
                                    ->label(__('tournaments::messages.config.check_in_starts_before'))
                                    ->helperText(__('tournaments::messages.config.check_in_starts_before_help'))
                                    ->numeric()
                                    ->suffix(__('tournaments::messages.minutes'))
                                    ->default(30)
                                    ->visible(fn (Get $get): bool => (bool) $get('requires_check_in')),

                                Toggle::make('self_check_in_allowed')
                                    ->label(__('tournaments::messages.config.self_check_in_allowed'))
                                    ->helperText(__('tournaments::messages.config.self_check_in_allowed_help'))
                                    ->default(false)
                                    ->visible(fn (Get $get): bool => (bool) $get('requires_check_in')),

                                TextInput::make('notification_email')
                                    ->label(__('tournaments::messages.config.notification_email'))
                                    ->helperText(__('tournaments::messages.config.notification_email_help'))
                                    ->email()
                                    ->required()
                                    ->maxLength(255),
                            ])
                            ->columns(2),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    /**
     * @return array<string, string>
     */
    private static function getGameProfileOptions(): array
    {
        $profiles = GameProfileModel::query()
            ->orderBy('is_system', 'desc')
            ->orderBy('name')
            ->get(['id', 'name', 'is_system']);

        $options = [];
        foreach ($profiles as $profile) {
            $options[$profile->id] = $profile->is_system
                ? $profile->name.' ⭐'
                : $profile->name;
        }

        return $options;
    }

    private static function loadGameProfileDefaults(?string $profileId, Set $set): void
    {
        if ($profileId === null) {
            return;
        }

        $profile = GameProfileModel::find($profileId);
        if ($profile === null) {
            return;
        }

        $set('stat_definitions', $profile->stat_definitions);
        $set('scoring_rules', $profile->scoring_rules);
        $set('tiebreaker_config', $profile->tiebreaker_config);
        $set('pairing_config', $profile->pairing_config);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function getDefaultScoringRules(): array
    {
        return [
            [
                'name' => 'Victoria',
                'points' => 3,
                'priority' => 5,
                'condition' => [
                    'type' => ConditionType::Result->value,
                    'result_value' => 'win',
                    'stat' => null,
                    'operator' => null,
                    'value' => null,
                ],
            ],
            [
                'name' => 'Empate',
                'points' => 1,
                'priority' => 5,
                'condition' => [
                    'type' => ConditionType::Result->value,
                    'result_value' => 'draw',
                    'stat' => null,
                    'operator' => null,
                    'value' => null,
                ],
            ],
            [
                'name' => 'Derrota',
                'points' => 0,
                'priority' => 5,
                'condition' => [
                    'type' => ConditionType::Result->value,
                    'result_value' => 'loss',
                    'stat' => null,
                    'operator' => null,
                    'value' => null,
                ],
            ],
            [
                'name' => 'Bye',
                'points' => 3,
                'priority' => 5,
                'condition' => [
                    'type' => ConditionType::Result->value,
                    'result_value' => 'bye',
                    'stat' => null,
                    'operator' => null,
                    'value' => null,
                ],
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function getDefaultTiebreakerConfig(): array
    {
        return [
            [
                'key' => 'buchholz',
                'name' => 'Buchholz',
                'type' => TiebreakerType::Buchholz->value,
                'direction' => SortDirection::Descending->value,
            ],
            [
                'key' => 'progressive',
                'name' => 'Progresivo',
                'type' => TiebreakerType::Progressive->value,
                'direction' => SortDirection::Descending->value,
            ],
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('image_public_id')
                    ->label(__('tournaments::messages.fields.image'))
                    ->square()
                    ->disk('images')
                    ->toggleable(),

                TextColumn::make('name')
                    ->label(__('tournaments::messages.config.name'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('event.title')
                    ->label(__('tournaments::messages.resource.fields.event'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('status')
                    ->label(__('tournaments::messages.fields.status'))
                    ->badge()
                    ->color(fn (TournamentStatus $state): string => $state->color())
                    ->formatStateUsing(fn (TournamentStatus $state): string => $state->label())
                    ->sortable(),

                TextColumn::make('participants_count')
                    ->label(__('tournaments::messages.resource.fields.participants_count'))
                    ->counts('participants')
                    ->sortable(),

                TextColumn::make('current_round')
                    ->label(__('tournaments::messages.resource.fields.current_round_display'))
                    ->formatStateUsing(fn (int $state): string => $state > 0
                        ? __('tournaments::messages.table.round_number', ['number' => $state])
                        : __('tournaments::messages.table.no_round'))
                    ->sortable(),

                TextColumn::make('registration_opens_at')
                    ->label(__('tournaments::messages.config.registration_opens_at'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('tournaments::messages.fields.status'))
                    ->options(TournamentStatus::options()),
                SelectFilter::make('event_id')
                    ->label(__('tournaments::messages.resource.fields.event'))
                    ->options(
                        EventModel::query()
                            ->orderBy('start_date', 'desc')
                            ->pluck('title', 'id')
                    )
                    ->searchable(),
            ])
            ->actions([
                EditAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            ParticipantsRelationManager::class,
            RoundsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTournaments::route('/'),
            'create' => Pages\CreateTournament::route('/create'),
            'edit' => Pages\EditTournament::route('/{record}/edit'),
        ];
    }
}
