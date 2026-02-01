<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Unit\Domain\Enums;

use Modules\Tournaments\Domain\Enums\PairingMethod;
use Tests\TestCase;

final class PairingMethodTest extends TestCase
{
    public function test_it_has_expected_cases(): void
    {
        $cases = PairingMethod::cases();

        $this->assertCount(3, $cases);
        $this->assertContains(PairingMethod::Swiss, $cases);
        $this->assertContains(PairingMethod::Random, $cases);
        $this->assertContains(PairingMethod::Accelerated, $cases);
    }

    public function test_swiss_has_correct_value(): void
    {
        $this->assertEquals('swiss', PairingMethod::Swiss->value);
    }

    public function test_random_has_correct_value(): void
    {
        $this->assertEquals('random', PairingMethod::Random->value);
    }

    public function test_accelerated_has_correct_value(): void
    {
        $this->assertEquals('accelerated', PairingMethod::Accelerated->value);
    }

    public function test_values_returns_all_case_values(): void
    {
        $values = PairingMethod::values();

        $this->assertIsArray($values);
        $this->assertCount(3, $values);
        $this->assertContains('swiss', $values);
        $this->assertContains('random', $values);
        $this->assertContains('accelerated', $values);
    }

    public function test_options_returns_value_label_pairs(): void
    {
        $options = PairingMethod::options();

        $this->assertIsArray($options);
        $this->assertCount(3, $options);
        $this->assertArrayHasKey('swiss', $options);
        $this->assertArrayHasKey('random', $options);
        $this->assertArrayHasKey('accelerated', $options);

        $this->assertEquals(
            __('tournaments::messages.pairing_method.swiss'),
            $options['swiss']
        );
        $this->assertEquals(
            __('tournaments::messages.pairing_method.random'),
            $options['random']
        );
        $this->assertEquals(
            __('tournaments::messages.pairing_method.accelerated'),
            $options['accelerated']
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

    public function test_label_returns_translated_label_for_accelerated(): void
    {
        $this->assertEquals(
            __('tournaments::messages.pairing_method.accelerated'),
            PairingMethod::Accelerated->label()
        );
    }
}
