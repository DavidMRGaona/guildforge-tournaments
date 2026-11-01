<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Enums;

/**
 * Why a player cannot report the result of their match yet, or at all.
 */
enum ReportingClosedReason: string
{
    // The tournament leaves every result to the organization
    case AdminOnly = 'admin_only';
    // The player withdrew or was disqualified
    case NotPlaying = 'not_playing';
    // The round has not started
    case RoundPending = 'round_pending';
}
