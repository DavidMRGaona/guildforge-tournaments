<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Feature\Filament;

use DomainException;
use Modules\Tournaments\Domain\Exceptions\InvalidMatchResultException;
use Modules\Tournaments\Filament\InvalidMatchResultMessage;
use Tests\Support\Modules\ModuleTestCase;

/**
 * The panel explains a rejected result: scores that contradict it, or a generic notice.
 */
final class InvalidMatchResultMessageTest extends ModuleTestCase
{
    protected ?string $moduleName = 'tournaments';

    protected bool $autoEnableModule = true;

    public function test_scores_that_contradict_the_result_get_their_own_message(): void
    {
        $this->assertSame(
            __('tournaments::messages.errors.scores_contradict_result'),
            InvalidMatchResultMessage::for(InvalidMatchResultException::scoresContradictResult('match-1'))
        );
    }

    public function test_a_bye_mismatch_gets_the_generic_message(): void
    {
        $this->assertSame(
            __('tournaments::messages.errors.invalid_match_result'),
            InvalidMatchResultMessage::for(InvalidMatchResultException::byeOnlyForByeMatches('match-1'))
        );
        $this->assertSame(
            __('tournaments::messages.errors.invalid_match_result'),
            InvalidMatchResultMessage::for(InvalidMatchResultException::byeMatchOnlyAcceptsBye('match-1'))
        );
    }

    public function test_any_other_domain_exception_gets_the_generic_message(): void
    {
        $this->assertSame(
            __('tournaments::messages.errors.invalid_match_result'),
            InvalidMatchResultMessage::for(new DomainException('Other'))
        );
    }
}
