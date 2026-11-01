<?php

declare(strict_types=1);

namespace Modules\Tournaments\Application\DTOs;

/**
 * What became of a player's report.
 */
enum MatchReportOutcome: string
{
    case Recorded = 'recorded';
    case AwaitingConfirmation = 'awaiting_confirmation';
}
