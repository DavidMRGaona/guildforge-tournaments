<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\ValueObjects;

/**
 * What one player may do now with the result of their match.
 */
final readonly class MatchReportActions
{
    public function __construct(
        public bool $canReport,
        public bool $canCorrect,
        public bool $canConfirm,
        public bool $canDispute,
    ) {}

    /**
     * @return array{can_report: bool, can_correct: bool, can_confirm: bool, can_dispute: bool}
     */
    public function toArray(): array
    {
        return [
            'can_report' => $this->canReport,
            'can_correct' => $this->canCorrect,
            'can_confirm' => $this->canConfirm,
            'can_dispute' => $this->canDispute,
        ];
    }
}
