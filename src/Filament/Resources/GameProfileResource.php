<?php

declare(strict_types=1);

namespace Modules\Tournaments\Filament\Resources;

use App\Filament\Resources\BaseResource;
use Filament\Forms\Components\Group;
use Illuminate\Database\Eloquent\Model;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Tabs;
use Filament\Forms\Components\Tabs\Tab;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Modules\Tournaments\Domain\Enums\ByeAssignment;
use Modules\Tournaments\Domain\Enums\ConditionType;
use Modules\Tournaments\Domain\Enums\PairingMethod;
use Modules\Tournaments\Domain\Enums\PairingSortCriteria;
use Modules\Tournaments\Domain\Enums\SortDirection;
use Modules\Tournaments\Domain\Enums\StatType;
use Modules\Tournaments\Domain\Enums\TiebreakerType;
use Modules\Tournaments\Filament\Resources\GameProfileResource\Pages;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\GameProfileModel;

final class GameProfileResource extends BaseResource
{
    protected static ?string $model = GameProfileModel::class;

    protected static ?string $navigationIcon = 'heroicon-o-cube';

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): ?string
    {
        return __('tournaments::messages.navigation.group');
    }

    public static function getNavigationLabel(): string
    {
        return __('tournaments::messages.game_profile.navigation');
    }

    public static function getModelLabel(): string
    {
        return __('tournaments::messages.game_profile.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('tournaments::messages.game_profile.plural');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Tabs::make('GameProfileTabs')
                    ->tabs([
                        Tab::make(__('tournaments::messages.config.general'))
                            ->icon('heroicon-o-document-text')
                            ->schema([
                                Placeholder::make('system_profile_warning')
                                    ->hiddenLabel()
                                    ->content(__('tournaments::messages.game_profile.system_warning'))
                                    ->visible(fn (?GameProfileModel $record): bool => $record !== null && $record->is_system)
                                    ->columnSpanFull(),

                                TextInput::make('name')
                                    ->label(__('tournaments::messages.game_profile.fields.name'))
                                    ->required()
                                    ->maxLength(255)
                                    ->disabled(fn (?GameProfileModel $record): bool => $record !== null && $record->is_system),

                                Textarea::make('description')
                                    ->label(__('tournaments::messages.game_profile.fields.description'))
                                    ->maxLength(1000)
                                    ->rows(3)
                                    ->columnSpanFull(),

                                Toggle::make('is_system')
                                    ->label(__('tournaments::messages.game_profile.fields.is_system'))
                                    ->disabled()
                                    ->visible(fn (?GameProfileModel $record): bool => $record !== null),
                            ])
                            ->columns(2),

                        Tab::make(__('tournaments::messages.stat_definitions.title'))
                            ->icon('heroicon-o-chart-bar')
                            ->schema([
                                Placeholder::make('stat_definitions_info')
                                    ->hiddenLabel()
                                    ->content(__('tournaments::messages.stat_definitions.help'))
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
                                            ->default(false),
                                    ])
                                    ->columns(3)
                                    ->addActionLabel(__('tournaments::messages.stat_definitions.add'))
                                    ->reorderable()
                                    ->collapsible()
                                    ->disabled(fn (?GameProfileModel $record): bool => $record !== null && $record->is_system)
                                    ->columnSpanFull(),
                            ]),

                        Tab::make(__('tournaments::messages.scoring_rules.title'))
                            ->icon('heroicon-o-calculator')
                            ->schema([
                                Placeholder::make('scoring_rules_info')
                                    ->hiddenLabel()
                                    ->content(__('tournaments::messages.scoring_rules.help'))
                                    ->columnSpanFull(),

                                Repeater::make('scoring_rules')
                                    ->label('')
                                    ->schema([
                                        TextInput::make('name')
                                            ->label(__('tournaments::messages.scoring_rules.name'))
                                            ->required(),

                                        TextInput::make('points')
                                            ->label(__('tournaments::messages.scoring_rules.points'))
                                            ->numeric()
                                            ->required()
                                            ->step(0.5),

                                        TextInput::make('priority')
                                            ->label(__('tournaments::messages.scoring_rules.priority'))
                                            ->helperText(__('tournaments::messages.scoring_rules.priority_help'))
                                            ->numeric()
                                            ->default(0),

                                        Select::make('condition.type')
                                            ->label(__('tournaments::messages.scoring_rules.condition_type'))
                                            ->options(ConditionType::options())
                                            ->required()
                                            ->native(false)
                                            ->live(),

                                        Select::make('condition.result_value')
                                            ->label(__('tournaments::messages.scoring_rules.condition_value'))
                                            ->options([
                                                'win' => __('tournaments::messages.condition_result.win'),
                                                'draw' => __('tournaments::messages.condition_result.draw'),
                                                'loss' => __('tournaments::messages.condition_result.loss'),
                                                'bye' => __('tournaments::messages.condition_result.bye'),
                                            ])
                                            ->visible(fn (Get $get): bool => $get('condition.type') === ConditionType::Result->value)
                                            ->native(false),

                                        TextInput::make('condition.stat')
                                            ->label(__('tournaments::messages.scoring_rules.condition_stat'))
                                            ->visible(fn (Get $get): bool => in_array($get('condition.type'), [
                                                ConditionType::StatComparison->value,
                                                ConditionType::StatThreshold->value,
                                                ConditionType::MarginDifference->value,
                                            ], true)),

                                        Select::make('condition.operator')
                                            ->label(__('tournaments::messages.scoring_rules.condition_operator'))
                                            ->options([
                                                '>' => __('tournaments::messages.condition_operator.greater_than'),
                                                '>=' => __('tournaments::messages.condition_operator.greater_or_equal'),
                                                '<' => __('tournaments::messages.condition_operator.less_than'),
                                                '<=' => __('tournaments::messages.condition_operator.less_or_equal'),
                                                '==' => __('tournaments::messages.condition_operator.equal'),
                                            ])
                                            ->visible(fn (Get $get): bool => in_array($get('condition.type'), [
                                                ConditionType::StatComparison->value,
                                                ConditionType::StatThreshold->value,
                                                ConditionType::MarginDifference->value,
                                            ], true))
                                            ->native(false),

                                        TextInput::make('condition.value')
                                            ->label(__('tournaments::messages.scoring_rules.condition_threshold'))
                                            ->numeric()
                                            ->visible(fn (Get $get): bool => in_array($get('condition.type'), [
                                                ConditionType::StatThreshold->value,
                                                ConditionType::MarginDifference->value,
                                            ], true)),
                                    ])
                                    ->columns(3)
                                    ->addActionLabel(__('tournaments::messages.scoring_rules.add'))
                                    ->reorderable()
                                    ->collapsible()
                                    ->disabled(fn (?GameProfileModel $record): bool => $record !== null && $record->is_system)
                                    ->columnSpanFull(),
                            ]),

                        Tab::make(__('tournaments::messages.tiebreaker_config.title'))
                            ->icon('heroicon-o-scale')
                            ->schema([
                                Placeholder::make('tiebreaker_config_info')
                                    ->hiddenLabel()
                                    ->content(__('tournaments::messages.tiebreaker_config.help'))
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
                                    ->disabled(fn (?GameProfileModel $record): bool => $record !== null && $record->is_system)
                                    ->columnSpanFull(),
                            ]),

                        Tab::make(__('tournaments::messages.pairing_config.title'))
                            ->icon('heroicon-o-arrows-right-left')
                            ->schema([
                                Group::make()
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
                                    ->columns(2)
                                    ->disabled(fn (?GameProfileModel $record): bool => $record !== null && $record->is_system),
                            ]),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('tournaments::messages.game_profile.fields.name'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('description')
                    ->label(__('tournaments::messages.game_profile.fields.description'))
                    ->limit(50)
                    ->toggleable(),

                IconColumn::make('is_system')
                    ->label(__('tournaments::messages.game_profile.fields.is_system'))
                    ->boolean()
                    ->sortable(),

                TextColumn::make('tournaments_count')
                    ->label(__('tournaments::messages.resource.plural'))
                    ->counts('tournaments')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label(__('Created at'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
                Action::make('duplicate')
                    ->label(__('tournaments::messages.game_profile.actions.duplicate'))
                    ->icon('heroicon-o-document-duplicate')
                    ->form([
                        TextInput::make('name')
                            ->label(__('tournaments::messages.game_profile.fields.name'))
                            ->required()
                            ->maxLength(255),
                    ])
                    ->action(function (GameProfileModel $record, array $data): void {
                        $copy = $record->replicate(['id', 'slug']);
                        $copy->name = $data['name'];
                        $copy->is_system = false;
                        $copy->save();
                    })
                    ->successNotificationTitle(__('tournaments::messages.game_profile.notifications.duplicated'))
                    ->color('gray'),

                EditAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->before(function (DeleteBulkAction $action): void {
                            $systemProfiles = $action->getRecords()
                                ->filter(fn (GameProfileModel $record): bool => $record->is_system);

                            if ($systemProfiles->isNotEmpty()) {
                                $action->cancel();
                            }
                        }),
                ]),
            ])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListGameProfiles::route('/'),
            'create' => Pages\CreateGameProfile::route('/create'),
            'edit' => Pages\EditGameProfile::route('/{record}/edit'),
        ];
    }

    public static function canDelete(Model $record): bool
    {
        if ($record instanceof GameProfileModel) {
            return ! $record->is_system;
        }

        return true;
    }
}
