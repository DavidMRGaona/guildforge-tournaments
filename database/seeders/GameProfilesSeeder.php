<?php

declare(strict_types=1);

namespace Modules\Tournaments\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\GameProfileModel;

/**
 * Seeds system game profiles for tournaments.
 * Safe to run in production - creates foundational data.
 */
final class GameProfilesSeeder extends Seeder
{
    public function run(): void
    {
        $profiles = $this->getProfiles();

        foreach ($profiles as $profile) {
            $existing = GameProfileModel::query()->where('slug', $profile['slug'])->first();
            if ($existing) {
                $existing->update($profile);
            } else {
                GameProfileModel::query()->create(
                    array_merge($profile, ['id' => Str::uuid()->toString()]),
                );
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
                    ['result' => 'win', 'points' => 3.0],
                    ['result' => 'draw', 'points' => 1.0],
                    ['result' => 'loss', 'points' => 0.0],
                ],
                'tiebreaker_config' => [
                    ['type' => 'buchholz', 'priority' => 1],
                    ['type' => 'stat_differential', 'priority' => 2, 'stat' => 'vp'],
                    ['type' => 'opponent_win_percentage', 'priority' => 3],
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
                    ['result' => 'win', 'points' => 3.0],
                    ['result' => 'draw', 'points' => 1.0],
                    ['result' => 'loss', 'points' => 0.0],
                ],
                'tiebreaker_config' => [
                    ['type' => 'opponent_win_percentage', 'priority' => 1],
                    ['type' => 'stat_percentage', 'priority' => 2, 'stat' => 'game_wins', 'against' => 'game_losses'],
                    ['type' => 'buchholz', 'priority' => 3],
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
                    ['result' => 'win', 'points' => 3.0],
                    ['result' => 'draw', 'points' => 1.0],
                    ['result' => 'loss', 'points' => 0.0],
                ],
                'tiebreaker_config' => [
                    ['type' => 'buchholz', 'priority' => 1],
                    ['type' => 'stat_sum', 'priority' => 2, 'stat' => 'bp'],
                    ['type' => 'stat_sum', 'priority' => 3, 'stat' => 'kp'],
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
                    ['result' => 'win', 'points' => 3.0],
                    ['result' => 'draw', 'points' => 1.0],
                    ['result' => 'loss', 'points' => 0.0],
                ],
                'tiebreaker_config' => [
                    ['type' => 'buchholz', 'priority' => 1],
                    ['type' => 'opponent_win_percentage', 'priority' => 2],
                    ['type' => 'progressive', 'priority' => 3],
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