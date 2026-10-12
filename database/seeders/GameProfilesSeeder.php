<?php

declare(strict_types=1);

namespace Modules\Tournaments\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\GameProfileModel;

/**
 * Creates the system game profiles and keeps their rules current; never touches
 * admin-made profiles or descriptions.
 */
final class GameProfilesSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->getProfiles() as $profile) {
            $existing = GameProfileModel::query()->where('slug', $profile['slug'])->first();

            if ($existing === null) {
                GameProfileModel::query()->create(array_merge($profile, ['id' => Str::uuid()->toString()]));

                continue;
            }

            // The panel does not let admins edit a system profile's rules, so the seeder keeps
            // them current; name and description stay as they are
            if ($existing->is_system) {
                $existing->update(Arr::only($profile, ['stat_definitions', 'scoring_rules', 'tiebreaker_config', 'pairing_config']));
            }
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getProfiles(): array
    {
        return [
            [
                'name' => 'Warhammer 40.000',
                'slug' => 'warhammer-40k',
                'description' => 'Torneos de miniaturas Warhammer 40.000 con sistema de Victory Points y desempates por diferencial.',
                'is_system' => true,
                'stat_definitions' => [
                    ['name' => 'Victory Points', 'key' => 'vp', 'type' => 'integer'],
                    ['name' => 'VP cedidos', 'key' => 'vp_against', 'type' => 'integer'],
                ],
                'scoring_rules' => [
                    ['name' => 'Victoria', 'points' => 3.0, 'priority' => 5, 'condition' => ['type' => 'result', 'result_value' => 'win', 'stat' => null, 'operator' => null, 'value' => null]],
                    ['name' => 'Empate', 'points' => 1.0, 'priority' => 5, 'condition' => ['type' => 'result', 'result_value' => 'draw', 'stat' => null, 'operator' => null, 'value' => null]],
                    ['name' => 'Derrota', 'points' => 0.0, 'priority' => 5, 'condition' => ['type' => 'result', 'result_value' => 'loss', 'stat' => null, 'operator' => null, 'value' => null]],
                ],
                'tiebreaker_config' => [
                    ['key' => 'buchholz', 'name' => 'Buchholz', 'type' => 'buchholz', 'stat' => null, 'direction' => 'desc', 'min_value' => null],
                    ['key' => 'stat_diff_vp', 'name' => 'Diferencia de estadísticas (vp)', 'type' => 'stat_diff', 'stat' => 'vp', 'direction' => 'desc', 'min_value' => null],
                    ['key' => 'owp', 'name' => 'Porcentaje de victorias de oponentes', 'type' => 'owp', 'stat' => null, 'direction' => 'desc', 'min_value' => null],
                ],
                'pairing_config' => [
                    'method' => 'swiss',
                    'avoid_rematches' => true,
                    'bye_assignment' => 'lowest_ranked',
                ],
            ],
            [
                'name' => 'Magic: The Gathering',
                'slug' => 'magic-the-gathering',
                'description' => 'Torneos de cartas coleccionables Magic con sistema de victorias al mejor de 3 partidas.',
                'is_system' => true,
                'stat_definitions' => [
                    ['name' => 'Victorias de juego', 'key' => 'game_wins', 'type' => 'integer'],
                    ['name' => 'Derrotas de juego', 'key' => 'game_losses', 'type' => 'integer'],
                ],
                'scoring_rules' => [
                    ['name' => 'Victoria', 'points' => 3.0, 'priority' => 5, 'condition' => ['type' => 'result', 'result_value' => 'win', 'stat' => null, 'operator' => null, 'value' => null]],
                    ['name' => 'Empate', 'points' => 1.0, 'priority' => 5, 'condition' => ['type' => 'result', 'result_value' => 'draw', 'stat' => null, 'operator' => null, 'value' => null]],
                    ['name' => 'Derrota', 'points' => 0.0, 'priority' => 5, 'condition' => ['type' => 'result', 'result_value' => 'loss', 'stat' => null, 'operator' => null, 'value' => null]],
                ],
                'tiebreaker_config' => [
                    ['key' => 'owp', 'name' => 'Porcentaje de victorias de oponentes', 'type' => 'owp', 'stat' => null, 'direction' => 'desc', 'min_value' => null],
                    ['key' => 'gwp', 'name' => 'Porcentaje de victorias de partidas', 'type' => 'gwp', 'stat' => null, 'direction' => 'desc', 'min_value' => null],
                    ['key' => 'buchholz', 'name' => 'Buchholz', 'type' => 'buchholz', 'stat' => null, 'direction' => 'desc', 'min_value' => null],
                ],
                'pairing_config' => [
                    'method' => 'swiss',
                    'avoid_rematches' => true,
                    'bye_assignment' => 'lowest_ranked',
                ],
            ],
            [
                'name' => 'Warhammer Age of Sigmar',
                'slug' => 'warhammer-aos',
                'description' => 'Torneos de miniaturas Age of Sigmar con sistema de puntuación por batalla.',
                'is_system' => true,
                'stat_definitions' => [
                    ['name' => 'Battle Points', 'key' => 'bp', 'type' => 'integer'],
                    ['name' => 'Kill Points', 'key' => 'kp', 'type' => 'integer'],
                ],
                'scoring_rules' => [
                    ['name' => 'Victoria', 'points' => 3.0, 'priority' => 5, 'condition' => ['type' => 'result', 'result_value' => 'win', 'stat' => null, 'operator' => null, 'value' => null]],
                    ['name' => 'Empate', 'points' => 1.0, 'priority' => 5, 'condition' => ['type' => 'result', 'result_value' => 'draw', 'stat' => null, 'operator' => null, 'value' => null]],
                    ['name' => 'Derrota', 'points' => 0.0, 'priority' => 5, 'condition' => ['type' => 'result', 'result_value' => 'loss', 'stat' => null, 'operator' => null, 'value' => null]],
                ],
                'tiebreaker_config' => [
                    ['key' => 'buchholz', 'name' => 'Buchholz', 'type' => 'buchholz', 'stat' => null, 'direction' => 'desc', 'min_value' => null],
                    ['key' => 'stat_sum_bp', 'name' => 'Suma de estadísticas (bp)', 'type' => 'stat_sum', 'stat' => 'bp', 'direction' => 'desc', 'min_value' => null],
                    ['key' => 'stat_sum_kp', 'name' => 'Suma de estadísticas (kp)', 'type' => 'stat_sum', 'stat' => 'kp', 'direction' => 'desc', 'min_value' => null],
                ],
                'pairing_config' => [
                    'method' => 'swiss',
                    'avoid_rematches' => true,
                    'bye_assignment' => 'lowest_ranked',
                ],
            ],
            [
                'name' => 'Genérico',
                'slug' => 'generic',
                'description' => 'Perfil genérico para torneos con sistema básico de victorias, empates y derrotas.',
                'is_system' => true,
                'stat_definitions' => [],
                'scoring_rules' => [
                    ['name' => 'Victoria', 'points' => 3.0, 'priority' => 5, 'condition' => ['type' => 'result', 'result_value' => 'win', 'stat' => null, 'operator' => null, 'value' => null]],
                    ['name' => 'Empate', 'points' => 1.0, 'priority' => 5, 'condition' => ['type' => 'result', 'result_value' => 'draw', 'stat' => null, 'operator' => null, 'value' => null]],
                    ['name' => 'Derrota', 'points' => 0.0, 'priority' => 5, 'condition' => ['type' => 'result', 'result_value' => 'loss', 'stat' => null, 'operator' => null, 'value' => null]],
                ],
                'tiebreaker_config' => [
                    ['key' => 'buchholz', 'name' => 'Buchholz', 'type' => 'buchholz', 'stat' => null, 'direction' => 'desc', 'min_value' => null],
                    ['key' => 'owp', 'name' => 'Porcentaje de victorias de oponentes', 'type' => 'owp', 'stat' => null, 'direction' => 'desc', 'min_value' => null],
                    ['key' => 'progressive', 'name' => 'Progresivo', 'type' => 'progressive', 'stat' => null, 'direction' => 'desc', 'min_value' => null],
                ],
                'pairing_config' => [
                    'method' => 'swiss',
                    'avoid_rematches' => true,
                    'bye_assignment' => 'lowest_ranked',
                ],
            ],
        ];
    }
}
