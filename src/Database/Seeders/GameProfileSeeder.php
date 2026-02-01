<?php

declare(strict_types=1);

namespace Modules\Tournaments\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\GameProfileModel;

final class GameProfileSeeder extends Seeder
{
    /**
     * Seed predefined game profiles for common tournament formats.
     */
    public function run(): void
    {
        $this->createGenericSwissProfile();
        $this->createMtgProfile();
        $this->createBloodBowlNafProfile();
        $this->createWarhammer40kWtcProfile();
        $this->createChessFideProfile();
        $this->createWarhammerAgeOfSigmarProfile();
        $this->createWarhammerOldWorldProfile();
        $this->createKillTeamProfile();
        $this->createPokemonTcgProfile();
    }

    /**
     * Generic Swiss tournament profile with standard scoring.
     */
    private function createGenericSwissProfile(): void
    {
        GameProfileModel::updateOrCreate(
            ['slug' => 'generic-swiss'],
            [
                'name' => 'Torneo suizo generico',
                'description' => 'Formato de torneo suizo estandar con puntuacion clasica de 3/1/0.',
                'stat_definitions' => [],
                'scoring_rules' => [
                    [
                        'name' => 'Victoria',
                        'condition' => [
                            'type' => 'result',
                            'result_value' => 'win',
                            'stat' => null,
                            'operator' => null,
                            'value' => null,
                        ],
                        'points' => 3.0,
                        'priority' => 0,
                    ],
                    [
                        'name' => 'Empate',
                        'condition' => [
                            'type' => 'result',
                            'result_value' => 'draw',
                            'stat' => null,
                            'operator' => null,
                            'value' => null,
                        ],
                        'points' => 1.0,
                        'priority' => 0,
                    ],
                    [
                        'name' => 'Derrota',
                        'condition' => [
                            'type' => 'result',
                            'result_value' => 'loss',
                            'stat' => null,
                            'operator' => null,
                            'value' => null,
                        ],
                        'points' => 0.0,
                        'priority' => 0,
                    ],
                ],
                'tiebreaker_config' => [
                    [
                        'key' => 'buchholz',
                        'name' => 'Buchholz',
                        'type' => 'buchholz',
                        'stat' => null,
                        'direction' => 'desc',
                        'min_value' => null,
                    ],
                    [
                        'key' => 'progressive',
                        'name' => 'Puntuacion progresiva',
                        'type' => 'progressive',
                        'stat' => null,
                        'direction' => 'desc',
                        'min_value' => null,
                    ],
                    [
                        'key' => 'owp',
                        'name' => 'Porcentaje de victorias de oponentes',
                        'type' => 'owp',
                        'stat' => null,
                        'direction' => 'desc',
                        'min_value' => null,
                    ],
                ],
                'pairing_config' => [
                    'method' => 'swiss',
                    'sort_by' => 'points',
                    'sort_by_stat' => null,
                    'avoid_rematches' => true,
                    'max_byes_per_player' => 1,
                    'bye_assignment' => 'lowest_ranked',
                ],
                'is_system' => true,
            ],
        );
    }

    /**
     * Magic: The Gathering tournament profile.
     */
    private function createMtgProfile(): void
    {
        GameProfileModel::updateOrCreate(
            ['slug' => 'mtg'],
            [
                'name' => 'Magic: The Gathering',
                'description' => 'Formato oficial de torneos de Magic: The Gathering con puntuacion 3/1/0 y desempates OWP%, GWP%, OGWP%.',
                'stat_definitions' => [
                    [
                        'key' => 'games_won',
                        'name' => 'Partidas ganadas',
                        'type' => 'integer',
                        'min_value' => 0,
                        'max_value' => 3,
                        'per_player' => true,
                        'required' => true,
                        'description' => 'Numero de partidas ganadas en el match (mejor de 3)',
                    ],
                    [
                        'key' => 'games_lost',
                        'name' => 'Partidas perdidas',
                        'type' => 'integer',
                        'min_value' => 0,
                        'max_value' => 3,
                        'per_player' => true,
                        'required' => true,
                        'description' => 'Numero de partidas perdidas en el match (mejor de 3)',
                    ],
                ],
                'scoring_rules' => [
                    [
                        'name' => 'Victoria',
                        'condition' => [
                            'type' => 'result',
                            'result_value' => 'win',
                            'stat' => null,
                            'operator' => null,
                            'value' => null,
                        ],
                        'points' => 3.0,
                        'priority' => 0,
                    ],
                    [
                        'name' => 'Empate',
                        'condition' => [
                            'type' => 'result',
                            'result_value' => 'draw',
                            'stat' => null,
                            'operator' => null,
                            'value' => null,
                        ],
                        'points' => 1.0,
                        'priority' => 0,
                    ],
                    [
                        'name' => 'Derrota',
                        'condition' => [
                            'type' => 'result',
                            'result_value' => 'loss',
                            'stat' => null,
                            'operator' => null,
                            'value' => null,
                        ],
                        'points' => 0.0,
                        'priority' => 0,
                    ],
                ],
                'tiebreaker_config' => [
                    [
                        'key' => 'owp',
                        'name' => 'OWP% (porcentaje de victorias de oponentes)',
                        'type' => 'owp',
                        'stat' => null,
                        'direction' => 'desc',
                        'min_value' => 0.33,
                    ],
                    [
                        'key' => 'gwp',
                        'name' => 'GWP% (porcentaje de victorias de partidas)',
                        'type' => 'gwp',
                        'stat' => null,
                        'direction' => 'desc',
                        'min_value' => 0.33,
                    ],
                    [
                        'key' => 'ogwp',
                        'name' => 'OGWP% (porcentaje de victorias de partidas de oponentes)',
                        'type' => 'ogwp',
                        'stat' => null,
                        'direction' => 'desc',
                        'min_value' => 0.33,
                    ],
                ],
                'pairing_config' => [
                    'method' => 'swiss',
                    'sort_by' => 'points',
                    'sort_by_stat' => null,
                    'avoid_rematches' => true,
                    'max_byes_per_player' => 1,
                    'bye_assignment' => 'lowest_ranked',
                ],
                'is_system' => true,
            ],
        );
    }

    /**
     * Blood Bowl NAF standard tournament profile.
     */
    private function createBloodBowlNafProfile(): void
    {
        GameProfileModel::updateOrCreate(
            ['slug' => 'blood-bowl-naf'],
            [
                'name' => 'Blood Bowl (NAF)',
                'description' => 'Formato estandar NAF para torneos de Blood Bowl con puntuacion por margen de touchdowns y desempates por diferencia de estadisticas.',
                'stat_definitions' => [
                    [
                        'key' => 'touchdowns',
                        'name' => 'Touchdowns',
                        'type' => 'integer',
                        'min_value' => 0,
                        'max_value' => null,
                        'per_player' => true,
                        'required' => true,
                        'description' => 'Touchdowns anotados en el partido',
                    ],
                    [
                        'key' => 'casualties',
                        'name' => 'Casualties',
                        'type' => 'integer',
                        'min_value' => 0,
                        'max_value' => null,
                        'per_player' => true,
                        'required' => true,
                        'description' => 'Casualties infligidas en el partido',
                    ],
                ],
                'scoring_rules' => [
                    // Crushing victory (TD margin >= 3) - highest priority
                    [
                        'name' => 'Victoria aplastante',
                        'condition' => [
                            'type' => 'margin_diff',
                            'result_value' => null,
                            'stat' => 'touchdowns',
                            'operator' => '>=',
                            'value' => 3.0,
                        ],
                        'points' => 3.0,
                        'priority' => 10,
                    ],
                    // Regular win
                    [
                        'name' => 'Victoria',
                        'condition' => [
                            'type' => 'result',
                            'result_value' => 'win',
                            'stat' => null,
                            'operator' => null,
                            'value' => null,
                        ],
                        'points' => 2.0,
                        'priority' => 0,
                    ],
                    // Draw
                    [
                        'name' => 'Empate',
                        'condition' => [
                            'type' => 'result',
                            'result_value' => 'draw',
                            'stat' => null,
                            'operator' => null,
                            'value' => null,
                        ],
                        'points' => 1.0,
                        'priority' => 0,
                    ],
                    // Loss
                    [
                        'name' => 'Derrota',
                        'condition' => [
                            'type' => 'result',
                            'result_value' => 'loss',
                            'stat' => null,
                            'operator' => null,
                            'value' => null,
                        ],
                        'points' => 0.0,
                        'priority' => 0,
                    ],
                    // Bye
                    [
                        'name' => 'Bye',
                        'condition' => [
                            'type' => 'result',
                            'result_value' => 'bye',
                            'stat' => null,
                            'operator' => null,
                            'value' => null,
                        ],
                        'points' => 2.0,
                        'priority' => 0,
                    ],
                ],
                'tiebreaker_config' => [
                    [
                        'key' => 'td_diff',
                        'name' => 'Diferencia de touchdowns',
                        'type' => 'stat_diff',
                        'stat' => 'touchdowns',
                        'direction' => 'desc',
                        'min_value' => null,
                    ],
                    [
                        'key' => 'cas_diff',
                        'name' => 'Diferencia de casualties',
                        'type' => 'stat_diff',
                        'stat' => 'casualties',
                        'direction' => 'desc',
                        'min_value' => null,
                    ],
                    [
                        'key' => 'td_sum',
                        'name' => 'Total de touchdowns',
                        'type' => 'stat_sum',
                        'stat' => 'touchdowns',
                        'direction' => 'desc',
                        'min_value' => null,
                    ],
                    [
                        'key' => 'sos',
                        'name' => 'Fortaleza del calendario',
                        'type' => 'sos',
                        'stat' => null,
                        'direction' => 'desc',
                        'min_value' => null,
                    ],
                ],
                'pairing_config' => [
                    'method' => 'swiss',
                    'sort_by' => 'points',
                    'sort_by_stat' => null,
                    'avoid_rematches' => true,
                    'max_byes_per_player' => 1,
                    'bye_assignment' => 'lowest_ranked',
                ],
                'is_system' => true,
            ],
        );
    }

    /**
     * Warhammer 40K WTC format tournament profile.
     */
    private function createWarhammer40kWtcProfile(): void
    {
        GameProfileModel::updateOrCreate(
            ['slug' => 'warhammer-40k-wtc'],
            [
                'name' => 'Warhammer 40K (WTC)',
                'description' => 'Formato WTC (World Team Championship) para torneos de Warhammer 40K con escala de puntuacion 20-0 basada en margen de puntos de victoria.',
                'stat_definitions' => [
                    [
                        'key' => 'victory_points',
                        'name' => 'Puntos de victoria',
                        'type' => 'integer',
                        'min_value' => 0,
                        'max_value' => 100,
                        'per_player' => true,
                        'required' => true,
                        'description' => 'Puntos de victoria obtenidos en la partida',
                    ],
                ],
                'scoring_rules' => [
                    // 20-0 scale based on VP margin
                    // VP margin >= 61: 20-0
                    [
                        'name' => '20-0 (margen >= 61)',
                        'condition' => [
                            'type' => 'margin_diff',
                            'result_value' => null,
                            'stat' => 'victory_points',
                            'operator' => '>=',
                            'value' => 61.0,
                        ],
                        'points' => 20.0,
                        'priority' => 100,
                    ],
                    // VP margin 51-60: 19-1
                    [
                        'name' => '19-1 (margen 51-60)',
                        'condition' => [
                            'type' => 'margin_diff',
                            'result_value' => null,
                            'stat' => 'victory_points',
                            'operator' => '>=',
                            'value' => 51.0,
                        ],
                        'points' => 19.0,
                        'priority' => 90,
                    ],
                    // VP margin 41-50: 18-2
                    [
                        'name' => '18-2 (margen 41-50)',
                        'condition' => [
                            'type' => 'margin_diff',
                            'result_value' => null,
                            'stat' => 'victory_points',
                            'operator' => '>=',
                            'value' => 41.0,
                        ],
                        'points' => 18.0,
                        'priority' => 80,
                    ],
                    // VP margin 31-40: 17-3
                    [
                        'name' => '17-3 (margen 31-40)',
                        'condition' => [
                            'type' => 'margin_diff',
                            'result_value' => null,
                            'stat' => 'victory_points',
                            'operator' => '>=',
                            'value' => 31.0,
                        ],
                        'points' => 17.0,
                        'priority' => 70,
                    ],
                    // VP margin 21-30: 16-4
                    [
                        'name' => '16-4 (margen 21-30)',
                        'condition' => [
                            'type' => 'margin_diff',
                            'result_value' => null,
                            'stat' => 'victory_points',
                            'operator' => '>=',
                            'value' => 21.0,
                        ],
                        'points' => 16.0,
                        'priority' => 60,
                    ],
                    // VP margin 11-20: 15-5
                    [
                        'name' => '15-5 (margen 11-20)',
                        'condition' => [
                            'type' => 'margin_diff',
                            'result_value' => null,
                            'stat' => 'victory_points',
                            'operator' => '>=',
                            'value' => 11.0,
                        ],
                        'points' => 15.0,
                        'priority' => 50,
                    ],
                    // VP margin 6-10: 14-6
                    [
                        'name' => '14-6 (margen 6-10)',
                        'condition' => [
                            'type' => 'margin_diff',
                            'result_value' => null,
                            'stat' => 'victory_points',
                            'operator' => '>=',
                            'value' => 6.0,
                        ],
                        'points' => 14.0,
                        'priority' => 40,
                    ],
                    // VP margin 1-5: 13-7
                    [
                        'name' => '13-7 (margen 1-5)',
                        'condition' => [
                            'type' => 'margin_diff',
                            'result_value' => null,
                            'stat' => 'victory_points',
                            'operator' => '>=',
                            'value' => 1.0,
                        ],
                        'points' => 13.0,
                        'priority' => 30,
                    ],
                    // VP margin 0 (draw): 10-10
                    [
                        'name' => '10-10 (empate)',
                        'condition' => [
                            'type' => 'result',
                            'result_value' => 'draw',
                            'stat' => null,
                            'operator' => null,
                            'value' => null,
                        ],
                        'points' => 10.0,
                        'priority' => 20,
                    ],
                    // Loss (loser gets points based on inverse margin, but we model it as base 0)
                    [
                        'name' => 'Derrota',
                        'condition' => [
                            'type' => 'result',
                            'result_value' => 'loss',
                            'stat' => null,
                            'operator' => null,
                            'value' => null,
                        ],
                        'points' => 0.0,
                        'priority' => 0,
                    ],
                ],
                'tiebreaker_config' => [
                    [
                        'key' => 'vp_diff',
                        'name' => 'Diferencia de puntos de victoria',
                        'type' => 'stat_diff',
                        'stat' => 'victory_points',
                        'direction' => 'desc',
                        'min_value' => null,
                    ],
                    [
                        'key' => 'vp_sum',
                        'name' => 'Total de puntos de victoria',
                        'type' => 'stat_sum',
                        'stat' => 'victory_points',
                        'direction' => 'desc',
                        'min_value' => null,
                    ],
                    [
                        'key' => 'sos',
                        'name' => 'Fortaleza del calendario',
                        'type' => 'sos',
                        'stat' => null,
                        'direction' => 'desc',
                        'min_value' => null,
                    ],
                ],
                'pairing_config' => [
                    'method' => 'swiss',
                    'sort_by' => 'points',
                    'sort_by_stat' => null,
                    'avoid_rematches' => true,
                    'max_byes_per_player' => 1,
                    'bye_assignment' => 'lowest_ranked',
                ],
                'is_system' => true,
            ],
        );
    }

    /**
     * Chess FIDE standard tournament profile.
     */
    private function createChessFideProfile(): void
    {
        GameProfileModel::updateOrCreate(
            ['slug' => 'chess-fide'],
            [
                'name' => 'Ajedrez (FIDE)',
                'description' => 'Formato estandar FIDE para torneos de ajedrez con puntuacion 1/0.5/0 y desempates Buchholz, Sonneborn-Berger y progresivo.',
                'stat_definitions' => [],
                'scoring_rules' => [
                    [
                        'name' => 'Victoria',
                        'condition' => [
                            'type' => 'result',
                            'result_value' => 'win',
                            'stat' => null,
                            'operator' => null,
                            'value' => null,
                        ],
                        'points' => 1.0,
                        'priority' => 0,
                    ],
                    [
                        'name' => 'Tablas',
                        'condition' => [
                            'type' => 'result',
                            'result_value' => 'draw',
                            'stat' => null,
                            'operator' => null,
                            'value' => null,
                        ],
                        'points' => 0.5,
                        'priority' => 0,
                    ],
                    [
                        'name' => 'Derrota',
                        'condition' => [
                            'type' => 'result',
                            'result_value' => 'loss',
                            'stat' => null,
                            'operator' => null,
                            'value' => null,
                        ],
                        'points' => 0.0,
                        'priority' => 0,
                    ],
                ],
                'tiebreaker_config' => [
                    [
                        'key' => 'buchholz',
                        'name' => 'Buchholz',
                        'type' => 'buchholz',
                        'stat' => null,
                        'direction' => 'desc',
                        'min_value' => null,
                    ],
                    [
                        'key' => 'sonneborn_berger',
                        'name' => 'Sonneborn-Berger',
                        'type' => 'sonneborn_berger',
                        'stat' => null,
                        'direction' => 'desc',
                        'min_value' => null,
                    ],
                    [
                        'key' => 'progressive',
                        'name' => 'Puntuacion progresiva',
                        'type' => 'progressive',
                        'stat' => null,
                        'direction' => 'desc',
                        'min_value' => null,
                    ],
                ],
                'pairing_config' => [
                    'method' => 'swiss',
                    'sort_by' => 'points',
                    'sort_by_stat' => null,
                    'avoid_rematches' => true,
                    'max_byes_per_player' => 1,
                    'bye_assignment' => 'lowest_ranked',
                ],
                'is_system' => true,
            ],
        );
    }

    /**
     * Warhammer Age of Sigmar tournament profile.
     */
    private function createWarhammerAgeOfSigmarProfile(): void
    {
        GameProfileModel::updateOrCreate(
            ['slug' => 'warhammer-aos'],
            [
                'name' => 'Warhammer Age of Sigmar',
                'description' => 'Formato estandar para torneos de Age of Sigmar con puntuacion 3/1/0 y seguimiento de puntos de batalla y tacticas de batalla.',
                'stat_definitions' => [
                    [
                        'key' => 'battle_points',
                        'name' => 'Puntos de batalla',
                        'type' => 'integer',
                        'min_value' => 0,
                        'max_value' => 100,
                        'per_player' => true,
                        'required' => true,
                        'description' => 'Puntos de batalla obtenidos en la partida',
                    ],
                    [
                        'key' => 'battle_tactics',
                        'name' => 'Tacticas de batalla',
                        'type' => 'integer',
                        'min_value' => 0,
                        'max_value' => 4,
                        'per_player' => true,
                        'required' => true,
                        'description' => 'Tacticas de batalla completadas (0-4)',
                    ],
                ],
                'scoring_rules' => [
                    [
                        'name' => 'Victoria',
                        'condition' => [
                            'type' => 'result',
                            'result_value' => 'win',
                            'stat' => null,
                            'operator' => null,
                            'value' => null,
                        ],
                        'points' => 3.0,
                        'priority' => 0,
                    ],
                    [
                        'name' => 'Empate',
                        'condition' => [
                            'type' => 'result',
                            'result_value' => 'draw',
                            'stat' => null,
                            'operator' => null,
                            'value' => null,
                        ],
                        'points' => 1.0,
                        'priority' => 0,
                    ],
                    [
                        'name' => 'Derrota',
                        'condition' => [
                            'type' => 'result',
                            'result_value' => 'loss',
                            'stat' => null,
                            'operator' => null,
                            'value' => null,
                        ],
                        'points' => 0.0,
                        'priority' => 0,
                    ],
                ],
                'tiebreaker_config' => [
                    [
                        'key' => 'sos',
                        'name' => 'Fortaleza del calendario',
                        'type' => 'sos',
                        'stat' => null,
                        'direction' => 'desc',
                        'min_value' => null,
                    ],
                    [
                        'key' => 'bp_sum',
                        'name' => 'Total de puntos de batalla',
                        'type' => 'stat_sum',
                        'stat' => 'battle_points',
                        'direction' => 'desc',
                        'min_value' => null,
                    ],
                    [
                        'key' => 'bt_sum',
                        'name' => 'Total de tacticas de batalla',
                        'type' => 'stat_sum',
                        'stat' => 'battle_tactics',
                        'direction' => 'desc',
                        'min_value' => null,
                    ],
                ],
                'pairing_config' => [
                    'method' => 'swiss',
                    'sort_by' => 'points',
                    'sort_by_stat' => null,
                    'avoid_rematches' => true,
                    'max_byes_per_player' => 1,
                    'bye_assignment' => 'lowest_ranked',
                ],
                'is_system' => true,
            ],
        );
    }

    /**
     * Warhammer: The Old World tournament profile with graduated scoring.
     */
    private function createWarhammerOldWorldProfile(): void
    {
        GameProfileModel::updateOrCreate(
            ['slug' => 'warhammer-tow'],
            [
                'name' => 'Warhammer: The Old World',
                'description' => 'Formato para torneos de The Old World con escala graduada de puntuacion (0-6) basada en diferencia de puntos de victoria.',
                'stat_definitions' => [
                    [
                        'key' => 'victory_points',
                        'name' => 'Puntos de victoria',
                        'type' => 'integer',
                        'min_value' => 0,
                        'max_value' => null,
                        'per_player' => true,
                        'required' => true,
                        'description' => 'Puntos de victoria obtenidos en la partida',
                    ],
                    [
                        'key' => 'general_killed',
                        'name' => 'General eliminado',
                        'type' => 'boolean',
                        'min_value' => null,
                        'max_value' => null,
                        'per_player' => true,
                        'required' => false,
                        'description' => 'Si el general enemigo fue eliminado',
                    ],
                ],
                'scoring_rules' => [
                    // Crushing victory (VP margin >= 1001): 6-0
                    [
                        'name' => '6-0 (margen >= 1001)',
                        'condition' => [
                            'type' => 'margin_diff',
                            'result_value' => null,
                            'stat' => 'victory_points',
                            'operator' => '>=',
                            'value' => 1001.0,
                        ],
                        'points' => 6.0,
                        'priority' => 100,
                    ],
                    // Major victory (VP margin 501-1000): 5-1
                    [
                        'name' => '5-1 (margen 501-1000)',
                        'condition' => [
                            'type' => 'margin_diff',
                            'result_value' => null,
                            'stat' => 'victory_points',
                            'operator' => '>=',
                            'value' => 501.0,
                        ],
                        'points' => 5.0,
                        'priority' => 90,
                    ],
                    // Solid victory (VP margin 301-500): 4-2
                    [
                        'name' => '4-2 (margen 301-500)',
                        'condition' => [
                            'type' => 'margin_diff',
                            'result_value' => null,
                            'stat' => 'victory_points',
                            'operator' => '>=',
                            'value' => 301.0,
                        ],
                        'points' => 4.0,
                        'priority' => 80,
                    ],
                    // Minor victory (VP margin 151-300): 3-3 (draw with tiebreak)
                    [
                        'name' => '3-3 (margen 151-300)',
                        'condition' => [
                            'type' => 'margin_diff',
                            'result_value' => null,
                            'stat' => 'victory_points',
                            'operator' => '>=',
                            'value' => 151.0,
                        ],
                        'points' => 3.0,
                        'priority' => 70,
                    ],
                    // Draw (VP margin 0-150): 3-3
                    [
                        'name' => '3-3 (empate, margen <= 150)',
                        'condition' => [
                            'type' => 'result',
                            'result_value' => 'draw',
                            'stat' => null,
                            'operator' => null,
                            'value' => null,
                        ],
                        'points' => 3.0,
                        'priority' => 60,
                    ],
                    // Loss (loser gets inverse points)
                    [
                        'name' => 'Derrota',
                        'condition' => [
                            'type' => 'result',
                            'result_value' => 'loss',
                            'stat' => null,
                            'operator' => null,
                            'value' => null,
                        ],
                        'points' => 0.0,
                        'priority' => 0,
                    ],
                ],
                'tiebreaker_config' => [
                    [
                        'key' => 'vp_sum',
                        'name' => 'Total de puntos de victoria',
                        'type' => 'stat_sum',
                        'stat' => 'victory_points',
                        'direction' => 'desc',
                        'min_value' => null,
                    ],
                    [
                        'key' => 'general_killed_sum',
                        'name' => 'Generales eliminados',
                        'type' => 'stat_sum',
                        'stat' => 'general_killed',
                        'direction' => 'desc',
                        'min_value' => null,
                    ],
                    [
                        'key' => 'sos',
                        'name' => 'Fortaleza del calendario',
                        'type' => 'sos',
                        'stat' => null,
                        'direction' => 'desc',
                        'min_value' => null,
                    ],
                ],
                'pairing_config' => [
                    'method' => 'swiss',
                    'sort_by' => 'points',
                    'sort_by_stat' => null,
                    'avoid_rematches' => true,
                    'max_byes_per_player' => 1,
                    'bye_assignment' => 'lowest_ranked',
                ],
                'is_system' => true,
            ],
        );
    }

    /**
     * Kill Team tournament profile.
     */
    private function createKillTeamProfile(): void
    {
        GameProfileModel::updateOrCreate(
            ['slug' => 'kill-team'],
            [
                'name' => 'Kill Team',
                'description' => 'Formato estandar para torneos de Kill Team con puntuacion 3/1/0 y seguimiento de puntos de victoria y operaciones tacticas.',
                'stat_definitions' => [
                    [
                        'key' => 'victory_points',
                        'name' => 'Puntos de victoria',
                        'type' => 'integer',
                        'min_value' => 0,
                        'max_value' => 12,
                        'per_player' => true,
                        'required' => true,
                        'description' => 'Puntos de victoria obtenidos (0-12)',
                    ],
                    [
                        'key' => 'tac_ops',
                        'name' => 'Operaciones tacticas',
                        'type' => 'integer',
                        'min_value' => 0,
                        'max_value' => 6,
                        'per_player' => true,
                        'required' => true,
                        'description' => 'Puntos de operaciones tacticas (0-6)',
                    ],
                ],
                'scoring_rules' => [
                    [
                        'name' => 'Victoria',
                        'condition' => [
                            'type' => 'result',
                            'result_value' => 'win',
                            'stat' => null,
                            'operator' => null,
                            'value' => null,
                        ],
                        'points' => 3.0,
                        'priority' => 0,
                    ],
                    [
                        'name' => 'Empate',
                        'condition' => [
                            'type' => 'result',
                            'result_value' => 'draw',
                            'stat' => null,
                            'operator' => null,
                            'value' => null,
                        ],
                        'points' => 1.0,
                        'priority' => 0,
                    ],
                    [
                        'name' => 'Derrota',
                        'condition' => [
                            'type' => 'result',
                            'result_value' => 'loss',
                            'stat' => null,
                            'operator' => null,
                            'value' => null,
                        ],
                        'points' => 0.0,
                        'priority' => 0,
                    ],
                ],
                'tiebreaker_config' => [
                    [
                        'key' => 'tac_ops_sum',
                        'name' => 'Total de operaciones tacticas',
                        'type' => 'stat_sum',
                        'stat' => 'tac_ops',
                        'direction' => 'desc',
                        'min_value' => null,
                    ],
                    [
                        'key' => 'vp_sum',
                        'name' => 'Total de puntos de victoria',
                        'type' => 'stat_sum',
                        'stat' => 'victory_points',
                        'direction' => 'desc',
                        'min_value' => null,
                    ],
                    [
                        'key' => 'sos',
                        'name' => 'Fortaleza del calendario',
                        'type' => 'sos',
                        'stat' => null,
                        'direction' => 'desc',
                        'min_value' => null,
                    ],
                ],
                'pairing_config' => [
                    'method' => 'swiss',
                    'sort_by' => 'points',
                    'sort_by_stat' => null,
                    'avoid_rematches' => true,
                    'max_byes_per_player' => 1,
                    'bye_assignment' => 'lowest_ranked',
                ],
                'is_system' => true,
            ],
        );
    }

    /**
     * Pokemon TCG tournament profile.
     */
    private function createPokemonTcgProfile(): void
    {
        GameProfileModel::updateOrCreate(
            ['slug' => 'pokemon-tcg'],
            [
                'name' => 'Pokemon TCG',
                'description' => 'Formato oficial para torneos de Pokemon TCG con puntuacion 3/1/0 y desempates OWP%, OOWP% y enfrentamiento directo.',
                'stat_definitions' => [],
                'scoring_rules' => [
                    [
                        'name' => 'Victoria',
                        'condition' => [
                            'type' => 'result',
                            'result_value' => 'win',
                            'stat' => null,
                            'operator' => null,
                            'value' => null,
                        ],
                        'points' => 3.0,
                        'priority' => 0,
                    ],
                    [
                        'name' => 'Empate',
                        'condition' => [
                            'type' => 'result',
                            'result_value' => 'draw',
                            'stat' => null,
                            'operator' => null,
                            'value' => null,
                        ],
                        'points' => 1.0,
                        'priority' => 0,
                    ],
                    [
                        'name' => 'Derrota',
                        'condition' => [
                            'type' => 'result',
                            'result_value' => 'loss',
                            'stat' => null,
                            'operator' => null,
                            'value' => null,
                        ],
                        'points' => 0.0,
                        'priority' => 0,
                    ],
                ],
                'tiebreaker_config' => [
                    [
                        'key' => 'owp',
                        'name' => 'OWP% (porcentaje de victorias de oponentes)',
                        'type' => 'owp',
                        'stat' => null,
                        'direction' => 'desc',
                        'min_value' => 0.25,
                    ],
                    [
                        'key' => 'oowp',
                        'name' => 'OOWP% (porcentaje de victorias de oponentes de oponentes)',
                        'type' => 'oowp',
                        'stat' => null,
                        'direction' => 'desc',
                        'min_value' => 0.25,
                    ],
                    [
                        'key' => 'head_to_head',
                        'name' => 'Enfrentamiento directo',
                        'type' => 'head_to_head',
                        'stat' => null,
                        'direction' => 'desc',
                        'min_value' => null,
                    ],
                ],
                'pairing_config' => [
                    'method' => 'swiss',
                    'sort_by' => 'points',
                    'sort_by_stat' => null,
                    'avoid_rematches' => true,
                    'max_byes_per_player' => 1,
                    'bye_assignment' => 'lowest_ranked',
                ],
                'is_system' => true,
            ],
        );
    }
}
