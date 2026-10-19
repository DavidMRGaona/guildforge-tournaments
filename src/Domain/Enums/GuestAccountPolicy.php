<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Enums;

enum GuestAccountPolicy: string
{
    case RequireLogin = 'require_login';
    case AllowGuest = 'allow_guest';
}
