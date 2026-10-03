<?php

namespace Tests\Unit;

use App\Enums\OrderStatus;
use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MarketplaceUnitTest extends TestCase
{
    public function test_money_parsing_and_formatting(): void
    {
        $this->assertSame(10050000, Money::fromNaira('100.50k'));
        $this->assertSame(125000, Money::fromNaira('₦1,250'));
        $this->assertSame('₦100,500.00', Money::naira(10050000));
        $this->assertSame('₦100.50k', Money::short(10050000));
        $this->assertSame(502500, Money::commission(10050000, 500));
    }

    public function test_try_from_naira_returns_null_for_bad_input(): void
    {
        $this->assertNull(Money::tryFromNaira('abc'));
        $this->assertNull(Money::tryFromNaira(['1']));
        $this->assertSame(100000, Money::tryFromNaira('1000'));
    }

    public function test_money_rejects_more_than_two_decimals(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::fromNaira('12.345');
    }

    public function test_order_status_transitions(): void
    {
        $this->assertTrue(OrderStatus::Paid->canTransitionTo(OrderStatus::Shipped));
        $this->assertFalse(OrderStatus::Paid->canTransitionTo(OrderStatus::Completed));
        $this->assertTrue(OrderStatus::Completed->isFinal());
        $this->assertTrue(OrderStatus::Disputed->canTransitionTo(OrderStatus::Refunded));
    }
}
