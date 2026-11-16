<?php

namespace App\Erp;

final readonly class ErpArticle
{
    public function __construct(
        public string $number,
        public string $description = '',
        public ?string $ean = null,
        public bool $active = true,
    ) {}
}
