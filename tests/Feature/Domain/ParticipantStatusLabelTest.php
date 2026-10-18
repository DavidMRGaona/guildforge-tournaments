<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Feature\Domain;

use Modules\Tournaments\Domain\Enums\ParticipantStatus;
use Tests\Support\Modules\ModuleTestCase;

final class ParticipantStatusLabelTest extends ModuleTestCase
{
    protected ?string $moduleName = 'tournaments';

    protected bool $autoEnableModule = true;

    public function test_expired_is_labelled_caducada(): void
    {
        $this->assertSame('Caducada', ParticipantStatus::Expired->label());
    }
}
