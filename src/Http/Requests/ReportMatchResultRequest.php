<?php

declare(strict_types=1);

namespace Modules\Tournaments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Tournaments\Domain\Enums\MatchResult;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\MatchModel;

/**
 * A player's report: a win or a draw, optional scores and the tournament's stats for each
 * player, only the keys the tournament defines and within their limits.
 */
final class ReportMatchResultRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * An unknown match is not found, whatever the body says.
     */
    protected function prepareForValidation(): void
    {
        if (! MatchModel::query()->whereKey((string) $this->route('matchId'))->exists()) {
            abort(404);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = [
            'result' => ['required', 'string', Rule::in(array_map(
                static fn (MatchResult $result): string => $result->value,
                array_filter(MatchResult::cases(), static fn (MatchResult $result): bool => $result->isReportableByPlayers()),
            ))],
            'player_1_score' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'player_2_score' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ];

        $definitions = $this->statDefinitions();
        $keys = array_column($definitions, 'key');

        foreach (['player_1_stats', 'player_2_stats'] as $field) {
            $rules[$field] = $keys === [] ? ['prohibited'] : ['nullable', 'array:'.implode(',', $keys)];

            foreach ($definitions as $stat) {
                $rules["{$field}.{$stat['key']}"] = $this->statRules($stat);
            }
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $attributes = [];
        foreach ($this->statDefinitions() as $stat) {
            $attributes["player_1_stats.{$stat['key']}"] = $stat['name'];
            $attributes["player_2_stats.{$stat['key']}"] = $stat['name'];
        }

        return $attributes;
    }

    /**
     * @param  array{key: string, name: string, type?: string, min_value?: int|float|null, max_value?: int|float|null, required?: bool}  $stat
     * @return array<int, string>
     */
    private function statRules(array $stat): array
    {
        $rules = [($stat['required'] ?? false) ? 'required' : 'nullable'];

        $type = $stat['type'] ?? 'integer';
        if ($type === 'boolean') {
            $rules[] = 'boolean';

            return $rules;
        }

        $rules[] = $type === 'integer' ? 'integer' : 'numeric';
        if (isset($stat['min_value'])) {
            $rules[] = 'min:'.$stat['min_value'];
        }

        if (isset($stat['max_value'])) {
            $rules[] = 'max:'.$stat['max_value'];
        }

        return $rules;
    }

    /**
     * The stats of the match's tournament.
     *
     * @return list<array{key: string, name: string, type?: string, min_value?: int|float|null, max_value?: int|float|null, required?: bool}>
     */
    private function statDefinitions(): array
    {
        $match = MatchModel::query()->with('round.tournament')->whereKey((string) $this->route('matchId'))->first();

        return array_values($match?->round->tournament->stat_definitions ?? []);
    }
}
