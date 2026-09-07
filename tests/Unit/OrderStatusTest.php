<?php

namespace Tests\Unit;

use App\Enums\OrderStatus;
use PHPUnit\Framework\TestCase;

class OrderStatusTest extends TestCase
{
    public function test_pending_can_move_to_confirmed_or_cancelled(): void
    {
        $this->assertTrue(OrderStatus::Pending->canTransitionTo(OrderStatus::Confirmed));
        $this->assertTrue(OrderStatus::Pending->canTransitionTo(OrderStatus::Cancelled));
        $this->assertFalse(OrderStatus::Pending->canTransitionTo(OrderStatus::Shipped));
    }

    public function test_shipped_cannot_be_cancelled(): void
    {
        $this->assertFalse(OrderStatus::Shipped->isCancellable());
        $this->assertTrue(OrderStatus::Packed->isCancellable());
    }
}
