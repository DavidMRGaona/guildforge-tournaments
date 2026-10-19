<?php

declare(strict_types=1);

namespace Modules\Tournaments\Application\DTOs\Response;

enum GuestRegistrationOutcome: string
{
    /** New (or reactivated) registration: the confirmation email went out. */
    case ConfirmationSent = 'confirmation_sent';

    /** The guest already had a registration awaiting confirmation: the email went out again. */
    case ConfirmationResent = 'confirmation_resent';

    /** The guest already had a registration awaiting confirmation, emailed a moment ago: nothing sent. */
    case ConfirmationPending = 'confirmation_pending';
}
