<?php

namespace App\Erp;

final readonly class ErpCustomer
{
    public function __construct(
        public string $customerNumber,
        public string $name,
        public ?string $organisationNumber = null,
        public ?string $email = null,
        public ?string $vatNumber = null,
        public ?string $countryCode = null,
        public ?string $currency = null,
        public bool $active = true,
    ) {}
}
