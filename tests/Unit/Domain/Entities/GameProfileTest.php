<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Unit\Domain\Entities;

use DateTimeImmutable;
use Modules\Tournaments\Domain\Entities\GameProfile;
use Modules\Tournaments\Domain\Enums\ByeAssignment;
use Modules\Tournaments\Domain\Enums\ConditionType;
use Modules\Tournaments\Domain\Enums\PairingMethod;
use Modules\Tournaments\Domain\Enums\PairingSortCriteria;
use Modules\Tournaments\Domain\Enums\SortDirection;
use Modules\Tournaments\Domain\Enums\StatType;
use Modules\Tournaments\Domain\Enums\TiebreakerType;
use Modules\Tournaments\Domain\ValueObjects\GameProfileId;
use Modules\Tournaments\Domain\ValueObjects\PairingConfig;
use Modules\Tournaments\Domain\ValueObjects\ScoringCondition;
use Modules\Tournaments\Domain\ValueObjects\ScoringRule;
use Modules\Tournaments\Domain\ValueObjects\StatDefinition;
use Modules\Tournaments\Domain\ValueObjects\TiebreakerDefinition;
use PHPUnit\Framework\TestCase;

final class GameProfileTest extends TestCase
{
    /**
     * @return array<StatDefinition>
     */
    private function getDefaultStatDefinitions(): array
    {
        return [
            new StatDefinition(
                key: 'victory_points',
                name: 'Victory points',
                type: StatType::Integer,
                minValue: 0,
                maxValue: 100,
            ),
            new StatDefinition(
                key: 'army_points',
                name: 'Army points',
                type: StatType::Integer,
                minValue: 0,
                maxValue: 2000,
            ),
        ];
    }

    /**
     * @return array<ScoringRule>
     */
    private function getDefaultScoringRules(): array
    {
        return [
            new ScoringRule(
                name: 'Victory',
                condition: new ScoringCondition(
                    type: ConditionType::Result,
                    resultValue: 'win',
                    stat: null,
                    operator: null,
                    value: null,
                ),
                points: 3.0,
            ),
            new ScoringRule(
                name: 'Draw',
                condition: new ScoringCondition(
                    type: ConditionType::Result,
                    resultValue: 'draw',
                    stat: null,
                    operator: null,
                    value: null,
                ),
                points: 1.0,
            ),
        ];
    }

    /**
     * @return array<TiebreakerDefinition>
     */
    private function getDefaultTiebreakerConfig(): array
    {
        return [
            new TiebreakerDefinition(
                key: 'buchholz',
                name: 'Buchholz',
                type: TiebreakerType::Buchholz,
            ),
            new TiebreakerDefinition(
                key: 'vp_sum',
                name: 'Victory points total',
                type: TiebreakerType::StatSum,
                stat: 'victory_points',
            ),
        ];
    }

    private function createGameProfile(bool $isSystem = false): GameProfile
    {
        return new GameProfile(
            id: GameProfileId::generate(),
            name: 'Warhammer 40K',
            slug: 'warhammer-40k',
            description: 'Standard Warhammer 40K tournament profile',
            statDefinitions: $this->getDefaultStatDefinitions(),
            scoringRules: $this->getDefaultScoringRules(),
            tiebreakerConfig: $this->getDefaultTiebreakerConfig(),
            pairingConfig: new PairingConfig(),
            isSystem: $isSystem,
        );
    }

    public function test_it_creates_game_profile_with_all_properties(): void
    {
        $id = GameProfileId::generate();
        $statDefinitions = $this->getDefaultStatDefinitions();
        $scoringRules = $this->getDefaultScoringRules();
        $tiebreakerConfig = $this->getDefaultTiebreakerConfig();
        $pairingConfig = new PairingConfig();

        $profile = new GameProfile(
            id: $id,
            name: 'Warhammer 40K',
            slug: 'warhammer-40k',
            description: 'Standard Warhammer 40K tournament profile',
            statDefinitions: $statDefinitions,
            scoringRules: $scoringRules,
            tiebreakerConfig: $tiebreakerConfig,
            pairingConfig: $pairingConfig,
            isSystem: true,
        );

        $this->assertInstanceOf(GameProfile::class, $profile);
        $this->assertEquals($id, $profile->id());
        $this->assertEquals('Warhammer 40K', $profile->name());
        $this->assertEquals('warhammer-40k', $profile->slug());
        $this->assertEquals('Standard Warhammer 40K tournament profile', $profile->description());
        $this->assertEquals($statDefinitions, $profile->statDefinitions());
        $this->assertEquals($scoringRules, $profile->scoringRules());
        $this->assertEquals($tiebreakerConfig, $profile->tiebreakerConfig());
        $this->assertEquals($pairingConfig, $profile->pairingConfig());
        $this->assertTrue($profile->isSystem());
    }

    public function test_id_getter_returns_correct_value(): void
    {
        $profile = $this->createGameProfile();

        $this->assertInstanceOf(GameProfileId::class, $profile->id());
    }

    public function test_name_getter_returns_correct_value(): void
    {
        $profile = $this->createGameProfile();

        $this->assertEquals('Warhammer 40K', $profile->name());
    }

    public function test_slug_getter_returns_correct_value(): void
    {
        $profile = $this->createGameProfile();

        $this->assertEquals('warhammer-40k', $profile->slug());
    }

    public function test_description_getter_returns_correct_value(): void
    {
        $profile = $this->createGameProfile();

        $this->assertEquals('Standard Warhammer 40K tournament profile', $profile->description());
    }

    public function test_stat_definitions_getter_returns_correct_value(): void
    {
        $profile = $this->createGameProfile();

        $this->assertIsArray($profile->statDefinitions());
        $this->assertCount(2, $profile->statDefinitions());
        $this->assertContainsOnlyInstancesOf(StatDefinition::class, $profile->statDefinitions());
    }

    public function test_scoring_rules_getter_returns_correct_value(): void
    {
        $profile = $this->createGameProfile();

        $this->assertIsArray($profile->scoringRules());
        $this->assertCount(2, $profile->scoringRules());
        $this->assertContainsOnlyInstancesOf(ScoringRule::class, $profile->scoringRules());
    }

    public function test_tiebreaker_config_getter_returns_correct_value(): void
    {
        $profile = $this->createGameProfile();

        $this->assertIsArray($profile->tiebreakerConfig());
        $this->assertCount(2, $profile->tiebreakerConfig());
        $this->assertContainsOnlyInstancesOf(TiebreakerDefinition::class, $profile->tiebreakerConfig());
    }

    public function test_pairing_config_getter_returns_correct_value(): void
    {
        $profile = $this->createGameProfile();

        $this->assertInstanceOf(PairingConfig::class, $profile->pairingConfig());
    }

    public function test_is_system_getter_returns_correct_value(): void
    {
        $systemProfile = $this->createGameProfile(isSystem: true);
        $customProfile = $this->createGameProfile(isSystem: false);

        $this->assertTrue($systemProfile->isSystem());
        $this->assertFalse($customProfile->isSystem());
    }

    public function test_created_at_getter_returns_null_by_default(): void
    {
        $profile = $this->createGameProfile();

        $this->assertNull($profile->createdAt());
    }

    public function test_updated_at_getter_returns_null_by_default(): void
    {
        $profile = $this->createGameProfile();

        $this->assertNull($profile->updatedAt());
    }

    public function test_created_at_and_updated_at_can_be_set(): void
    {
        $now = new DateTimeImmutable();

        $profile = new GameProfile(
            id: GameProfileId::generate(),
            name: 'Test',
            slug: 'test',
            description: null,
            statDefinitions: [],
            scoringRules: [],
            tiebreakerConfig: [],
            pairingConfig: new PairingConfig(),
            isSystem: false,
            createdAt: $now,
            updatedAt: $now,
        );

        $this->assertEquals($now, $profile->createdAt());
        $this->assertEquals($now, $profile->updatedAt());
    }

    public function test_update_name_changes_name(): void
    {
        $profile = $this->createGameProfile();

        $profile->updateName('Age of Sigmar');

        $this->assertEquals('Age of Sigmar', $profile->name());
    }

    public function test_update_slug_changes_slug(): void
    {
        $profile = $this->createGameProfile();

        $profile->updateSlug('age-of-sigmar');

        $this->assertEquals('age-of-sigmar', $profile->slug());
    }

    public function test_update_description_changes_description(): void
    {
        $profile = $this->createGameProfile();

        $profile->updateDescription('Updated description');

        $this->assertEquals('Updated description', $profile->description());
    }

    public function test_update_description_accepts_null(): void
    {
        $profile = $this->createGameProfile();

        $profile->updateDescription(null);

        $this->assertNull($profile->description());
    }

    public function test_update_stat_definitions_changes_stat_definitions(): void
    {
        $profile = $this->createGameProfile();

        $newStats = [
            new StatDefinition(
                key: 'new_stat',
                name: 'New stat',
                type: StatType::Integer,
            ),
        ];

        $profile->updateStatDefinitions($newStats);

        $this->assertEquals($newStats, $profile->statDefinitions());
        $this->assertCount(1, $profile->statDefinitions());
    }

    public function test_update_scoring_rules_changes_scoring_rules(): void
    {
        $profile = $this->createGameProfile();

        $newRules = [
            new ScoringRule(
                name: 'New rule',
                condition: new ScoringCondition(
                    type: ConditionType::Result,
                    resultValue: 'win',
                    stat: null,
                    operator: null,
                    value: null,
                ),
                points: 5.0,
            ),
        ];

        $profile->updateScoringRules($newRules);

        $this->assertEquals($newRules, $profile->scoringRules());
        $this->assertCount(1, $profile->scoringRules());
    }

    public function test_update_tiebreaker_config_changes_tiebreaker_config(): void
    {
        $profile = $this->createGameProfile();

        $newTiebreakers = [
            new TiebreakerDefinition(
                key: 'progressive',
                name: 'Progressive',
                type: TiebreakerType::Progressive,
            ),
        ];

        $profile->updateTiebreakerConfig($newTiebreakers);

        $this->assertEquals($newTiebreakers, $profile->tiebreakerConfig());
        $this->assertCount(1, $profile->tiebreakerConfig());
    }

    public function test_update_pairing_config_changes_pairing_config(): void
    {
        $profile = $this->createGameProfile();

        $newPairingConfig = new PairingConfig(
            method: PairingMethod::Swiss,
            sortBy: PairingSortCriteria::Stat,
            sortByStat: 'victory_points',
            avoidRematches: false,
            maxByesPerPlayer: 2,
            byeAssignment: ByeAssignment::Random,
        );

        $profile->updatePairingConfig($newPairingConfig);

        $this->assertEquals($newPairingConfig, $profile->pairingConfig());
    }

    public function test_can_be_deleted_returns_false_when_is_system_is_true(): void
    {
        $profile = $this->createGameProfile(isSystem: true);

        $this->assertFalse($profile->canBeDeleted());
    }

    public function test_can_be_deleted_returns_true_when_is_system_is_false(): void
    {
        $profile = $this->createGameProfile(isSystem: false);

        $this->assertTrue($profile->canBeDeleted());
    }

    public function test_can_be_modified_returns_false_when_is_system_is_true(): void
    {
        $profile = $this->createGameProfile(isSystem: true);

        $this->assertFalse($profile->canBeModified());
    }

    public function test_can_be_modified_returns_true_when_is_system_is_false(): void
    {
        $profile = $this->createGameProfile(isSystem: false);

        $this->assertTrue($profile->canBeModified());
    }

    public function test_get_stat_definition_returns_correct_stat(): void
    {
        $profile = $this->createGameProfile();

        $stat = $profile->getStatDefinition('victory_points');

        $this->assertInstanceOf(StatDefinition::class, $stat);
        $this->assertEquals('victory_points', $stat->key);
        $this->assertEquals('Victory points', $stat->name);
    }

    public function test_get_stat_definition_returns_null_for_non_existent_key(): void
    {
        $profile = $this->createGameProfile();

        $stat = $profile->getStatDefinition('non_existent');

        $this->assertNull($stat);
    }

    public function test_has_stat_definition_returns_true_for_existing_key(): void
    {
        $profile = $this->createGameProfile();

        $this->assertTrue($profile->hasStatDefinition('victory_points'));
        $this->assertTrue($profile->hasStatDefinition('army_points'));
    }

    public function test_has_stat_definition_returns_false_for_non_existent_key(): void
    {
        $profile = $this->createGameProfile();

        $this->assertFalse($profile->hasStatDefinition('non_existent'));
    }

    public function test_get_tiebreaker_definition_returns_correct_tiebreaker(): void
    {
        $profile = $this->createGameProfile();

        $tiebreaker = $profile->getTiebreakerDefinition('buchholz');

        $this->assertInstanceOf(TiebreakerDefinition::class, $tiebreaker);
        $this->assertEquals('buchholz', $tiebreaker->key);
        $this->assertEquals('Buchholz', $tiebreaker->name);
        $this->assertEquals(TiebreakerType::Buchholz, $tiebreaker->type);
    }

    public function test_get_tiebreaker_definition_returns_null_for_non_existent_key(): void
    {
        $profile = $this->createGameProfile();

        $tiebreaker = $profile->getTiebreakerDefinition('non_existent');

        $this->assertNull($tiebreaker);
    }

    public function test_description_can_be_null(): void
    {
        $profile = new GameProfile(
            id: GameProfileId::generate(),
            name: 'Test',
            slug: 'test',
            description: null,
            statDefinitions: [],
            scoringRules: [],
            tiebreakerConfig: [],
            pairingConfig: new PairingConfig(),
        );

        $this->assertNull($profile->description());
    }

    public function test_empty_arrays_are_allowed_for_collections(): void
    {
        $profile = new GameProfile(
            id: GameProfileId::generate(),
            name: 'Test',
            slug: 'test',
            description: null,
            statDefinitions: [],
            scoringRules: [],
            tiebreakerConfig: [],
            pairingConfig: new PairingConfig(),
        );

        $this->assertIsArray($profile->statDefinitions());
        $this->assertEmpty($profile->statDefinitions());
        $this->assertIsArray($profile->scoringRules());
        $this->assertEmpty($profile->scoringRules());
        $this->assertIsArray($profile->tiebreakerConfig());
        $this->assertEmpty($profile->tiebreakerConfig());
    }
}
