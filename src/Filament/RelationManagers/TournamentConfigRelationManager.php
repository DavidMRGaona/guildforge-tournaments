<?php

declare(strict_types=1);

namespace Modules\Tournaments\Filament\RelationManagers;

use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Text;
use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Modules\Tournaments\Filament\TournamentFormDefaults;
use Modules\Tournaments\Domain\Enums\ConditionType;
use Modules\Tournaments\Domain\Enums\Tiebreaker;
use Modules\Tournaments\Domain\Enums\TiebreakerType;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Domain\ValueObjects\ScoringRule;
use Modules\Tournaments\Domain\ValueObjects\TiebreakerDefinition;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;

/**
 * @property \Filament\Schemas\Schema $configForm
 */
final class TournamentConfigRelationManager extends RelationManager
{
    protected static string $relationship = 'tournament';

    protected static ?string $recordTitleAttribute = 'name';

    // The module registers its view namespace at runtime, so Larastan cannot resolve it as a view-string
    protected string $view = 'tournaments::filament.relation-managers.tournament-config-form'; // @phpstan-ignore property.defaultValue

    /** @var array<string, mixed> */
    public array $configData = [];

    /**
     * The tournament has rules the tab cannot show (stat conditions, other tiebreaker types):
     * the tab leaves them alone and points to the tournament form.
     */
    public bool $hasAdvancedRules = false;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('tournaments::messages.config.title');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof EventModel;
    }

    public function mount(): void
    {
        parent::mount();

        $eventId = $this->getOwnerRecord()->getKey();
        $tournament = TournamentModel::where('event_id', $eventId)->first();

        if ($tournament) {
            $this->hasAdvancedRules = ($tournament->scoring_rules !== null && $this->scoreWeightsFrom($tournament->scoring_rules) === null)
                || ($tournament->tiebreaker_config !== null && $this->tiebreakersFrom($tournament->tiebreaker_config) === null);

            $this->configForm->fill([
                'tournament_enabled' => true,
                'name' => $tournament->name,
                'description' => $tournament->description,
                'max_rounds' => $tournament->max_rounds,
                'max_participants' => $tournament->max_participants,
                'min_participants' => $tournament->min_participants,
                'score_weights' => $this->scoreWeightsFrom($tournament->scoring_rules) ?? $this->getDefaultScoreWeights(),
                'tiebreakers' => $this->tiebreakersFrom($tournament->tiebreaker_config) ?? ['buchholz', 'progressive'],
                'allow_guests' => $tournament->allow_guests,
                'requires_check_in' => $tournament->requires_check_in,
                'check_in_starts_before' => $tournament->check_in_starts_before,
                'result_reporting' => $tournament->result_reporting->value,
                'registration_opens_at' => $tournament->registration_opens_at?->format('Y-m-d H:i:s'),
                'registration_closes_at' => $tournament->registration_closes_at?->format('Y-m-d H:i:s'),
            ]);
        } else {
            $event = $this->getOwnerRecord();
            $this->configForm->fill([
                'tournament_enabled' => false,
                'name' => $event->title ?? '',
                'score_weights' => $this->getDefaultScoreWeights(),
                'tiebreakers' => ['buchholz', 'progressive'],
                'min_participants' => 2,
                'allow_guests' => TournamentFormDefaults::allowGuests(),
                'requires_check_in' => TournamentFormDefaults::requiresCheckIn(),
                'check_in_starts_before' => TournamentFormDefaults::checkInStartsBefore(),
                'result_reporting' => TournamentFormDefaults::resultReporting(),
            ]);
        }
    }

    public function configForm(Schema $schema): Schema
    {
        return $schema
            ->components($this->getFormSchema())
            ->statePath('configData');
    }

    /**
     * @return array<\Filament\Schemas\Components\Component>
     */
    protected function getFormSchema(): array
    {
        return [
            Section::make(__('tournaments::messages.config.general'))
                ->schema([
                    Toggle::make('tournament_enabled')
                        ->label(__('tournaments::messages.config.tournament_enabled'))
                        ->helperText(__('tournaments::messages.config.tournament_enabled_help'))
                        ->live()
                        ->columnSpanFull(),
                    TextInput::make('name')
                        ->label(__('tournaments::messages.config.name'))
                        ->required()
                        ->maxLength(255)
                        ->visible(fn (Get $get): bool => (bool) $get('tournament_enabled')),
                    TextInput::make('description')
                        ->label(__('tournaments::messages.config.description'))
                        ->maxLength(1000)
                        ->visible(fn (Get $get): bool => (bool) $get('tournament_enabled')),
                ]),

            Fieldset::make(__('tournaments::messages.config.capacity'))
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
                ->columns(3)
                ->visible(fn (Get $get): bool => (bool) $get('tournament_enabled')),

            Fieldset::make(__('tournaments::messages.config.dates'))
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
                ->columns(2)
                ->visible(fn (Get $get): bool => (bool) $get('tournament_enabled')),

            Section::make(__('tournaments::messages.config.scoring'))
                ->schema([
                    Text::make(__('tournaments::messages.config.advanced_rules_notice'))
                        ->visible(fn (): bool => $this->hasAdvancedRules),
                    Repeater::make('score_weights')
                        ->visible(fn (): bool => ! $this->hasAdvancedRules)
                        ->label(__('tournaments::messages.config.score_weights'))
                        ->schema([
                            TextInput::make('name')
                                ->label(__('tournaments::messages.config.score_weight_name'))
                                ->required(),
                            Select::make('key')
                                ->label(__('tournaments::messages.config.score_weight_key'))
                                ->options([
                                    'win' => __('tournaments::messages.condition_result.win'),
                                    'draw' => __('tournaments::messages.condition_result.draw'),
                                    'loss' => __('tournaments::messages.condition_result.loss'),
                                    'bye' => __('tournaments::messages.condition_result.bye'),
                                ])
                                ->native(false)
                                ->required(),
                            TextInput::make('points')
                                ->label(__('tournaments::messages.config.score_weight_points'))
                                ->numeric()
                                ->required()
                                ->step(0.5),
                        ])
                        ->columns(3)
                        ->defaultItems(4)
                        ->reorderable(false)
                        ->addActionLabel(__('tournaments::messages.config.add_score_weight')),
                    Select::make('tiebreakers')
                        ->visible(fn (): bool => ! $this->hasAdvancedRules)
                        ->label(__('tournaments::messages.config.tiebreakers'))
                        ->helperText(__('tournaments::messages.config.tiebreakers_help'))
                        ->multiple()
                        ->options(Tiebreaker::options())
                        ->default(['buchholz', 'progressive']),
                ])
                ->visible(fn (Get $get): bool => (bool) $get('tournament_enabled')),

            Fieldset::make(__('tournaments::messages.config.options'))
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
                        ->helperText(__('tournaments::messages.config.allow_guests_help')),
                    Toggle::make('requires_check_in')
                        ->label(__('tournaments::messages.config.requires_check_in'))
                        ->live(),
                    TextInput::make('check_in_starts_before')
                        ->label(__('tournaments::messages.config.check_in_starts_before'))
                        ->helperText(__('tournaments::messages.config.check_in_starts_before_help'))
                        ->numeric()
                        ->suffix(__('tournaments::messages.minutes'))
                        ->default(30)
                        ->visible(fn (Get $get): bool => (bool) $get('requires_check_in')),
                ])
                ->columns(2)
                ->visible(fn (Get $get): bool => (bool) $get('tournament_enabled')),
        ];
    }

    public function save(): void
    {
        $formData = $this->configForm->getState();
        $eventId = $this->getOwnerRecord()->getKey();

        if (! $formData['tournament_enabled']) {
            // Delete tournament if it exists and is disabled
            TournamentModel::where('event_id', $eventId)->delete();

            Notification::make()
                ->title(__('tournaments::messages.config.tournament_disabled'))
                ->success()
                ->send();

            return;
        }

        $tournament = TournamentModel::where('event_id', $eventId)->first();

        $data = [
            'event_id' => $eventId,
            'name' => $formData['name'],
            'description' => $formData['description'] ?? null,
            'max_rounds' => $formData['max_rounds'] ?? null,
            'max_participants' => $formData['max_participants'] ?? null,
            'min_participants' => $formData['min_participants'] ?? 2,
            'allow_guests' => $formData['allow_guests'] ?? false,
            'requires_check_in' => $formData['requires_check_in'] ?? false,
            'check_in_starts_before' => $formData['check_in_starts_before'] ?? null,
            'result_reporting' => $formData['result_reporting'] ?? 'admin_only',
            'registration_opens_at' => $formData['registration_opens_at'] ?? null,
            'registration_closes_at' => $formData['registration_closes_at'] ?? null,
        ];

        if (! $this->hasAdvancedRules) {
            $data['scoring_rules'] = $this->scoringRulesFrom($formData['score_weights'] ?? $this->getDefaultScoreWeights());
            $data['tiebreaker_config'] = $this->tiebreakerConfigFrom($formData['tiebreakers'] ?? ['buchholz', 'progressive']);
        }

        if ($tournament) {
            $tournament->update($data);
        } else {
            $data['status'] = TournamentStatus::Draft->value;
            TournamentModel::create($data);
        }

        Notification::make()
            ->title(__('tournaments::messages.config.saved'))
            ->success()
            ->send();
    }

    /**
     * Points per result as the tournament's scoring rules.
     *
     * @param  array<mixed>  $weights
     * @return list<array<string, mixed>>
     */
    private function scoringRulesFrom(array $weights): array
    {
        $rules = [];
        foreach ($weights as $weight) {
            if (! is_array($weight) || ! isset($weight['key'], $weight['name'])) {
                continue;
            }

            $rules[] = ScoringRule::fromArray([
                'name' => (string) $weight['name'],
                'points' => (float) ($weight['points'] ?? 0),
                'priority' => 0,
                'condition' => ['type' => ConditionType::Result->value, 'result_value' => (string) $weight['key'], 'stat' => null, 'operator' => null, 'value' => null],
            ])->toArray();
        }

        return $rules;
    }

    /**
     * Chosen tiebreakers as the tournament's tiebreaker definitions, in order.
     *
     * @param  array<mixed>  $tiebreakers
     * @return list<array<string, mixed>>
     */
    private function tiebreakerConfigFrom(array $tiebreakers): array
    {
        $config = [];
        foreach ($tiebreakers as $value) {
            $tiebreaker = is_string($value) ? Tiebreaker::tryFrom($value) : null;
            if ($tiebreaker !== null) {
                $config[] = (new TiebreakerDefinition($tiebreaker->type()->value, $tiebreaker->label(), $tiebreaker->type()))->toArray();
            }
        }

        return $config;
    }

    /**
     * The tournament's rules as points per result, or null when a rule is not a plain result rule.
     *
     * @param  array<mixed>|null  $rules
     * @return list<array{name: string, key: string, points: float}>|null
     */
    private function scoreWeightsFrom(?array $rules): ?array
    {
        if ($rules === null || $rules === []) {
            return null;
        }

        $weights = [];
        foreach ($rules as $rule) {
            $condition = is_array($rule) ? ($rule['condition'] ?? null) : null;
            if (! is_array($condition) || ($condition['type'] ?? null) !== ConditionType::Result->value) {
                return null;
            }

            $weights[] = [
                'name' => (string) ($rule['name'] ?? ''),
                'key' => (string) ($condition['result_value'] ?? ''),
                'points' => (float) ($rule['points'] ?? 0),
            ];
        }

        return $weights;
    }

    /**
     * The tournament's tiebreakers as the tab's choices, or null when one has no choice here
     * or carries settings the tab cannot show.
     *
     * @param  array<mixed>|null  $config
     * @return list<string>|null
     */
    private function tiebreakersFrom(?array $config): ?array
    {
        if ($config === null || $config === []) {
            return null;
        }

        $values = [];
        foreach ($config as $definition) {
            $type = is_array($definition) && is_string($definition['type'] ?? null) ? TiebreakerType::tryFrom($definition['type']) : null;
            $tiebreaker = $type !== null ? Tiebreaker::fromType($type) : null;
            // Anything the tab cannot show (direction, floor, stat, own key) would be lost on save
            if ($tiebreaker === null
                || ($definition['direction'] ?? 'desc') !== 'desc'
                || ($definition['min_value'] ?? null) !== null
                || ($definition['stat'] ?? null) !== null
                || ($definition['key'] ?? $tiebreaker->type()->value) !== $tiebreaker->type()->value) {
                return null;
            }

            $values[] = $tiebreaker->value;
        }

        return $values;
    }

    /**
     * @return array<array{name: string, key: string, points: float}>
     */
    private function getDefaultScoreWeights(): array
    {
        return [
            ['name' => 'Victoria', 'key' => 'win', 'points' => 3],
            ['name' => 'Empate', 'key' => 'draw', 'points' => 1],
            ['name' => 'Derrota', 'key' => 'loss', 'points' => 0],
            ['name' => 'Bye', 'key' => 'bye', 'points' => 3],
        ];
    }
}
