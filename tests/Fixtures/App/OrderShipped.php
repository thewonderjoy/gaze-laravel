<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze\Laravel\Tests\Fixtures\App;

class OrderShipped
{
    public function __construct(public int $orderId, public string $carrier = 'DHL')
    {
    }
}
