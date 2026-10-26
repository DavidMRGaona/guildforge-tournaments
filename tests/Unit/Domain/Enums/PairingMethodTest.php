<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Unit\Domain\Enums;

use Modules\Tournaments\Domain\Enums\PairingMethod;
use Tests\TestCase;

final class PairingMethodTest extends TestCase
{
    public function test_swiss_has_correct_value(): void
    {
        $this->assertEquals('swiss', PairingMethod::Swiss->value);
    }

    public function test_random_has_correct_value(): void
    {
        $this->assertEquals('random', PairingMethod::Random->value);
    }

    public function test_accelerated_is_no_longer_offered(): void
    {
        // It had no pairing rules of its own
        $this->assertNotContains('accelerated', PairingMethod::values());
        $this->assertArrayNotHasKey('accelerated', PairingMethod::options());
    }

    public function test_values_returns_all_case_values(): void
    {
        $values = PairingMethod::values();

        $this->assertCount(2, $values);
        $this->assertContains('swiss', $values);
        $this->assertContains('random', $values);
    }

    public function test_options_returns_value_label_pairs(): void
    {
        $options = PairingMethod::options();

        $this->assertCount(2, $options);
        $this->assertArrayHasKey('swiss', $options);
        $this->assertArrayHasKey('random', $options);

        $this->assertEquals(
            __('tournaments::messages.pairing_method.swiss'),
            $options['swiss']
        );
        $this->assertEquals(
            __('tournaments::messages.pairing_method.random'),
            $options['random']
        );
    }

    public function test_label_returns_translated_label_for_swiss(): void
    {
        $this->assertEquals(
            __('tournaments::messages.pairing_method.swiss'),
            PairingMethod::Swiss->label()
        );
    }

    public function test_label_returns_translated_label_for_random(): void
    {
        $this->assertEquals(
            __('tournaments::messages.pairing_method.random'),
            PairingMethod::Random->label()
        );
    }
}
