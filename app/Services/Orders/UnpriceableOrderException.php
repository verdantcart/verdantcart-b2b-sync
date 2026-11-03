<?php

namespace App\Services\Orders;

use RuntimeException;

/**
 * The order cannot be priced with the data we have (e.g. a SKU we do not know).
 * Retrying will not help until someone fixes the catalogue, so this is not retried.
 */
class UnpriceableOrderException extends RuntimeException {}
