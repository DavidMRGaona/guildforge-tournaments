<?php

declare(strict_types=1);

namespace Modules\Tournaments\Application\DTOs\Response;

/**
 * What following a guest confirmation link did; the tournament is null for an unknown link.
 */
final readonly class GuestConfirmationResultDTO
{
    public function __construct(
        public GuestConfirmationOutcome $outcome,
        public ?string $tournamentId,
        public ?string $tournamentSlug,
    ) {
    }
}
