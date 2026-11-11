<?php

namespace Tests\Unit;

use App\Support\Money;
use PHPUnit\Framework\TestCase;

/** What people type as a price, into whole cents and back. */
class MoneyTest extends TestCase
{
    public function test_prices_people_type_become_exact_cents(): void
    {
        $this->assertSame(123400, Money::toCents('1234'));
        $this->assertSame(123450, Money::toCents('1,234.5'));
        $this->assertSame(123456, Money::toCents('$1,234.56'));
        $this->assertSame(5, Money::toCents('0.05'));
        $this->assertSame(0, Money::toCents('0'));
        // 0.1 + 0.2 style float errors can't happen: 19.99 is exactly 1999.
        $this->assertSame(1999, Money::toCents('19.99'));
    }

    public function test_anything_else_is_refused(): void
    {
        foreach (['', 'abc', '-5', '12.345', '1.2.3', '1e5', '10000001', '99999999.99'] as $bad) {
            $this->assertNull(Money::toCents($bad), "\"$bad\" should be refused");
        }
    }

    public function test_cents_back_to_text(): void
    {
        $this->assertSame('1234.50', Money::fromCents(123450));
        $this->assertSame('0.05', Money::fromCents(5));
        $this->assertSame('10000000.00', Money::fromCents(Money::MAX_CENTS));
    }
}
