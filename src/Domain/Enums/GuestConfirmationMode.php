<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Enums;

enum GuestConfirmationMode: string
{
    case Hold = 'hold';
    case Wait = 'wait';
}
