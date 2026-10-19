<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Feature;

use Modules\CookieConsent\Application\DTOs\DeclaredCookieDTO;
use Modules\CookieConsent\Application\Services\CookieDeclarationRegistryInterface;
use Tests\Support\Modules\ModuleTestCase;

final class CookieDeclarationTest extends ModuleTestCase
{
    protected ?string $moduleName = 'tournaments';

    protected bool $autoEnableModule = true;

    protected function setUp(): void
    {
        parent::setUp();

        if (! interface_exists(CookieDeclarationRegistryInterface::class)) {
            $this->markTestSkipped('The cookie-consent module is not installed.');
        }

        $this->enableModule('cookie-consent');
    }

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
    }
}
