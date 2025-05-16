<?php

namespace App\Models;

use Carbon\Carbon;

/**
 * @method isCompleted
 */
class ProductionMetric
{
    public function __construct(
        public readonly int $orderId,
        public readonly int $productionId,
        public readonly string $product,
        public readonly string $appointmentDate,
        public readonly ?string $deliveryDate,
        public readonly bool $isCompleted = false,
    )
    {

    }
}
