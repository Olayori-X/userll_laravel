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

    public function test_money_rejects_amounts_too_large_to_store(): void
    {
        $this->assertNull(Money::tryFromNaira('99999999999999999999'));
        $this->assertNull(Money::tryFromNaira('999999999999999k'));
        $this->assertNull(Money::tryFromNaira('99999999999m'));

        // The largest accepted input still fits in a 64-bit integer.
        $this->assertSame(999999999900000000, Money::fromNaira('9999999999m'));
    }

    public function test_money_huge_amount_throws_the_expected_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::fromNaira('99999999999999999999');
    }

    public function test_short_format_does_not_round_up_into_the_next_unit_wrongly(): void
    {
        $this->assertSame('₦999.99', Money::short(99999));
        $this->assertSame('₦999.50k', Money::short(99950000));
        $this->assertSame('₦1.00M', Money::short(99999999));   // ₦999,999.99 used to show as ₦1,000.00k
        $this->assertSame('₦1.00M', Money::short(99999900));
        $this->assertSame('₦1.00M', Money::short(100000000));
        $this->assertSame('₦1.50M', Money::short(150000000));
    }

    public function test_order_status_transitions(): void
    {
        $this->assertTrue(OrderStatus::Paid->canTransitionTo(OrderStatus::Shipped));
        $this->assertFalse(OrderStatus::Paid->canTransitionTo(OrderStatus::Completed));
        $this->assertTrue(OrderStatus::Completed->isFinal());
        $this->assertTrue(OrderStatus::Disputed->canTransitionTo(OrderStatus::Refunded));
    }
}
