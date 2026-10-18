<?php

declare(strict_types=1);

namespace Modules\Tournaments\Application\DTOs\Response;

enum GuestConfirmationOutcome: string
{
    /** The email is confirmed now: the usual registration emails went out. */
    case Confirmed = 'confirmed';

    /** The link had been used before: nothing changed. */
    case AlreadyConfirmed = 'already_confirmed';

    /** Unknown link, or its registration ended (cancelled, expired, or overdue and expired now). */
    case Invalid = 'invalid';

    /** Registration closed before the confirmation: the registration expired. */
    case RegistrationClosed = 'registration_closed';

    /** A registration waiting without a seat found the tournament full: it expired. */
    case Full = 'full';
}
