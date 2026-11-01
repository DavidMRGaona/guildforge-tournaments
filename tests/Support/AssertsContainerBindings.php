<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Support;

/**
 * Checks which class the container builds for an interface. Larastan boots the application
 * while analysing, so with the module enabled in the local database it already knows what
 * app(SomeInterface::class) returns and reports the assertion as always true; with no
 * database, as in CI, it does not. Taking the classes as parameters keeps the analysis the
 * same in both places, and the test still fails when the interface is bound to another class.
 */
trait AssertsContainerBindings
{
    /**
     * @param  class-string  $abstract
     * @param  class-string  $concrete
     */
    protected function assertContainerBinds(string $abstract, string $concrete): void
    {
        $this->assertInstanceOf($concrete, $this->app->make($abstract));
    }
}
