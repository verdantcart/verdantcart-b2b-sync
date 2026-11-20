<?php

namespace App\Events;

use App\Erp\Fortnox\Events\FortnoxEvent;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Something changed in Fortnox. Dispatched by `php artisan fortnox:listen` for every event;
 * listeners react to the topics they care about (stock, new articles, …).
 */
class FortnoxEventReceived
{
    use Dispatchable;

    public function __construct(public readonly FortnoxEvent $event) {}
}
