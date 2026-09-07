<?php

namespace App\Listeners;

use App\Events\OrderCreated;
use App\Notifications\OrderCreatedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendOrderCreatedNotification implements ShouldQueue
{
    public function handle(OrderCreated $event): void
    {
        $order = $event->order->loadMissing('user');

        $order->user?->notify(new OrderCreatedNotification($order));
    }
}
