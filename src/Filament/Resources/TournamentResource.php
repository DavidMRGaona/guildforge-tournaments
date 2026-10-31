<?php

declare(strict_types=1);

namespace Modules\Tournaments\Filament\Resources;

use App\Filament\Resources\BaseResource;
use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use Closure;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
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
use Modules\Tournaments\Filament\RegistrationClosingDateRule;
use Modules\Tournaments\Filament\Resources\TournamentResource\Pages\CreateTournament;
use Modules\Tournaments\Filament\Resources\TournamentResource\Pages\EditTournament;
use Modules\Tournaments\Filament\Resources\TournamentResource\Pages\ListTournaments;
use Modules\Tournaments\Filament\Resources\TournamentResource\RelationManagers\ParticipantsRelationManager;
use Modules\Tournaments\Filament\Resources\TournamentResource\RelationManagers\RoundsRelationManager;
use Modules\Tournaments\Filament\TournamentFormDefaults;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\GameProfileModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;

final class TournamentResource extends BaseResource
{
    protected static ?string $model = TournamentModel::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-trophy';

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): string
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

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
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

                                Select::make('organizers')
                                    ->label(__('tournaments::messages.config.organizers'))
                                    ->helperText(__('tournaments::messages.config.organizers_help'))
                                    ->relationship(
                                        'organizers',
                                        'name',
                                        fn (Builder $query): Builder => $query->whereNull('users.anonymized_at')->orderBy('name'),
                                    )
                                    ->multiple()
                                    ->searchable()
                                    ->native(false)
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
                                    ->rule(fn (Get $get): Closure => RegistrationClosingDateRule::notAfter(
                                        RegistrationClosingDateRule::eventStartsAtFrom(fn (): mixed => $get('event_id'))
                                    ))
                                    ->nullable(),
                            ])
                            ->columns(2),

                        Tab::make(__('tournaments::messages.stat_definitions.title'))
                            ->icon('heroicon-o-chart-bar')
                            ->schema([
                                self::advancedConfigNotice('tournaments::messages.stat_definitions.help'),

                                Repeater::make('stat_definitions')
                                    ->label(__('tournaments::messages.stat_definitions.title'))
                                    ->hiddenLabel()
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
                                self::advancedConfigNotice('tournaments::messages.scoring_rules.help'),

                                Repeater::make('scoring_rules')
                                    ->label(__('tournaments::messages.scoring_rules.title'))
                                    ->hiddenLabel()
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
                                self::advancedConfigNotice('tournaments::messages.tiebreaker_config.help'),

                                Repeater::make('tiebreaker_config')
                                    ->label(__('tournaments::messages.tiebreaker_config.title'))
                                    ->hiddenLabel()
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
                                    ->default(fn (): string => TournamentFormDefaults::resultReporting())
                                    ->required(),

                                Toggle::make('allow_guests')
                                    ->label(__('tournaments::messages.config.allow_guests'))
                                    ->helperText(__('tournaments::messages.config.allow_guests_help'))
                                    ->default(fn (): bool => TournamentFormDefaults::allowGuests()),

                                Toggle::make('show_participants')
                                    ->label(__('tournaments::messages.config.show_participants'))
                                    ->helperText(__('tournaments::messages.config.show_participants_help'))
                                    ->default(true),

                                Toggle::make('requires_check_in')
                                    ->label(__('tournaments::messages.config.requires_check_in'))
                                    ->live()
                                    ->default(fn (): bool => TournamentFormDefaults::requiresCheckIn()),

                                Toggle::make('requires_manual_confirmation')
                                    ->label(__('tournaments::messages.config.requires_manual_confirmation'))
                                    ->helperText(__('tournaments::messages.config.requires_manual_confirmation_help'))
                                    ->default(false),

                                TextInput::make('check_in_starts_before')
                                    ->label(__('tournaments::messages.config.check_in_starts_before'))
                                    ->helperText(__('tournaments::messages.config.check_in_starts_before_help'))
                                    ->numeric()
                                    ->suffix(__('tournaments::messages.minutes'))
                                    ->default(fn (): int => TournamentFormDefaults::checkInStartsBefore())
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
                'name' => __('tournaments::messages.condition_result.win'),
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
                'name' => __('tournaments::messages.condition_result.draw'),
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
                'name' => __('tournaments::messages.condition_result.loss'),
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
                'name' => __('tournaments::messages.condition_result.bye'),
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
                'name' => __('tournaments::messages.tiebreaker.buchholz'),
                'type' => TiebreakerType::Buchholz->value,
                'direction' => SortDirection::Descending->value,
            ],
            [
                'key' => 'progressive',
                'name' => __('tournaments::messages.tiebreaker.progressive'),
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
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    /**
     * Help line of an advanced configuration tab, with a badge when the values come from the game profile.
     */
    private static function advancedConfigNotice(string $helpKey): Flex
    {
        return Flex::make([
            Text::make(__('tournaments::messages.advanced_config.inherit_from_profile'))
                ->badge()
                ->color('primary')
                ->icon(Heroicon::OutlinedLink)
                ->visible(fn (Get $get): bool => filled($get('game_profile_id')))
                ->grow(false),
            Text::make(__($helpKey)),
        ])
            ->verticallyAlignCenter()
            ->columnSpanFull();
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
            'index' => ListTournaments::route('/'),
            'create' => CreateTournament::route('/create'),
            'edit' => EditTournament::route('/{record}/edit'),
        ];
    }
}
