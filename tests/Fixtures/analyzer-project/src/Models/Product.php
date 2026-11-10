<?php

namespace Shop\Models;

use Shop\Support\Money;

class Product
{
    public function __construct(public int $priceInCents) {}

    public function price(): string
    {
        return Money::format($this->priceInCents);
    }
}
