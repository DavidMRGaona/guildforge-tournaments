<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Unit\Domain\Services;

use Modules\Tournaments\Domain\Services\FinalPlacementRule;
use Modules\Tournaments\Domain\ValueObjects\FinalStanding;
use PHPUnit\Framework\TestCase;

final class FinalPlacementRuleTest extends TestCase
{
    public function test_the_stored_order_is_the_placement_when_nobody_ties(): void
    {
        $placements = (new FinalPlacementRule)->placements([
            new FinalStanding('c', 3, 3.0, ['buchholz' => 3.0]),
            new FinalStanding('a', 1, 9.0, ['buchholz' => 6.0]),
            new FinalStanding('b', 2, 6.0, ['buchholz' => 9.0]),
        ]);

        $this->assertSame(['a' => 1, 'b' => 2, 'c' => 3], $placements);
    }

    public function test_equal_points_and_tiebreakers_share_a_rank_and_the_next_one_skips(): void
    {
        $placements = (new FinalPlacementRule)->placements([
            new FinalStanding('a', 1, 9.0, ['buchholz' => 6.0, 'progressive' => 4.0]),
            new FinalStanding('b', 2, 9.0, ['buchholz' => 6.0, 'progressive' => 4.0]),
            new FinalStanding('c', 3, 6.0, ['buchholz' => 6.0, 'progressive' => 4.0]),
        ]);

        $this->assertSame(['a' => 1, 'b' => 1, 'c' => 3], $placements);
    }

    public function test_a_tiebreaker_that_tells_them_apart_is_not_a_tie(): void
    {
        $placements = (new FinalPlacementRule)->placements([
            new FinalStanding('a', 1, 9.0, ['buchholz' => 7.0]),
            new FinalStanding('b', 2, 9.0, ['buchholz' => 6.0]),
        ]);

        $this->assertSame(['a' => 1, 'b' => 2], $placements);
    }

    public function test_decimals_that_differ_only_by_float_noise_tie(): void
    {
        $placements = (new FinalPlacementRule)->placements([
            new FinalStanding('a', 1, 0.1 + 0.2, []),
            new FinalStanding('b', 2, 0.3, []),
        ]);

        $this->assertSame(['a' => 1, 'b' => 1], $placements);
    }

    public function test_a_tie_for_the_win_gives_everybody_in_it_first_place(): void
    {
        $placements = (new FinalPlacementRule)->placements([
            new FinalStanding('a', 1, 3.0, []),
            new FinalStanding('b', 2, 3.0, []),
            new FinalStanding('c', 3, 3.0, []),
            new FinalStanding('d', 4, 0.0, []),
        ]);

        $this->assertSame(['a' => 1, 'b' => 1, 'c' => 1, 'd' => 4], $placements);
    }

    public function test_the_placement_only_counts_the_standings_given(): void
    {
        // The rows of withdrawn or disqualified players are left out by the caller: the next one is first
        $placements = (new FinalPlacementRule)->placements([
            new FinalStanding('b', 2, 6.0, []),
            new FinalStanding('c', 3, 3.0, []),
        ]);

        $this->assertSame(['b' => 1, 'c' => 2], $placements);
    }

    public function test_the_same_rank_is_ordered_by_participant_so_the_result_is_stable(): void
    {
        $placements = (new FinalPlacementRule)->placements([
            new FinalStanding('b', 1, 3.0, ['x' => 1.0]),
            new FinalStanding('a', 1, 2.0, ['x' => 1.0]),
        ]);

        $this->assertSame(['a' => 1, 'b' => 2], $placements);
    }

    public function test_without_standings_there_is_no_placement(): void
    {
        $this->assertSame([], (new FinalPlacementRule)->placements([]));
    }
}
