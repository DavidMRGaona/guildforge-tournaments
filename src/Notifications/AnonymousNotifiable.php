<?php

declare(strict_types=1);

namespace Modules\Tournaments\Notifications;

use Illuminate\Notifications\Notifiable;

/**
 * Simple notifiable for sending emails to non-user recipients.
 */
final class AnonymousNotifiable
{
    use Notifiable;

    public function __construct(
        private readonly string $email,
        private readonly ?string $name = null,
    ) {}

    public function routeNotificationForMail(): string
    {
        return $this->email;
    }

    public function getEmailForNotification(): string
    {
        return $this->email;
    }

    public function getName(): ?string
    {
        return $this->name;
    }
}
