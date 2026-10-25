<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Feature;

use App\Application\Cookies\DTOs\DeclaredCookieDTO;
use App\Application\Cookies\Services\CookieDeclarationRegistryInterface;
use App\Domain\Cookies\Enums\CookieType;
use Tests\Support\Modules\ModuleTestCase;

/**
 * The guest cookie is declared in the core's registry, whether or not a cookie consent module
 * is installed to read it.
 */
final class CookieDeclarationTest extends ModuleTestCase
{
    protected ?string $moduleName = 'tournaments';

    protected bool $autoEnableModule = true;

    public function test_the_guest_cookie_is_declared_in_the_preferences_category(): void
    {
        $declared = collect($this->app->make(CookieDeclarationRegistryInterface::class)->all())
            ->first(fn (DeclaredCookieDTO $cookie): bool => $cookie->name === 'tn_guest_registrations');

        $this->assertInstanceOf(DeclaredCookieDTO::class, $declared);
        $this->assertSame('preferences', $declared->categorySlug);
        $this->assertSame(config('app.name'), $declared->provider);
        $this->assertSame(
            'Recuerda en este navegador los torneos a los que te has apuntado como invitado, para avisarte si intentas apuntarte otra vez.',
            $declared->purpose,
        );
        $this->assertSame('90 días', $declared->duration);
        $this->assertSame(CookieType::FirstParty, $declared->type);
    }
}
