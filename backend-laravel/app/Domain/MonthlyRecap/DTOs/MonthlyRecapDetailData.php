<?php

namespace App\Domain\MonthlyRecap\DTOs;

final readonly class MonthlyRecapDetailData
{
    public function __construct(
        public string $detailType,
        public ?string $category = null,
        public ?float $value = null,
        public ?int $quantity = null,
        public array $metadata = [],
    ) {}
}
