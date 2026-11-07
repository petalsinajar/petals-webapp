<?php

namespace Tests\Unit;

use App\Support\Money;
use App\Support\PhoneNumber;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_percent_rounds_to_whole_minor_units(): void
    {
        $this->assertSame(63345, Money::percent(211150, 30));
        $this->assertSame(1, Money::percent(3, 33.4));
    }

    public function test_format_shows_cedis_and_drops_zero_pesewas(): void
    {
        $this->assertSame('GH₵2,500', Money::format(250000));
        $this->assertSame('GH₵1,200.50', Money::format(120050));
        $this->assertSame('GH₵0.15', Money::format(15));
        $this->assertSame('-GH₵15', Money::format(-1500));
        $this->assertSame('EUR 10', Money::format(1000, 'EUR'));
    }

    public function test_allocation_always_adds_up(): void
    {
        $parts = Money::allocate(1000, ['a' => 1, 'b' => 1, 'c' => 1]);

        $this->assertSame(1000, array_sum($parts));
        $this->assertSame(['a' => 334, 'b' => 333, 'c' => 333], $parts);
    }

    public function test_phone_numbers_normalise_to_e164(): void
    {
        $this->assertSame('+233241234567', PhoneNumber::normalize('024 123 4567'));
        $this->assertSame('+233241234567', PhoneNumber::normalize('+233 24 123 4567'));
        $this->assertSame('+2348031234567', PhoneNumber::normalize('002348031234567'));
        $this->assertNull(PhoneNumber::normalize('12'));
    }
}
