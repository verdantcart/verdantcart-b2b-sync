<?php

namespace App\Erp\Fortnox\Events;

use RuntimeException;

/** The connection could not be opened, or it ended. The listener reconnects. */
class ConnectionClosed extends RuntimeException {}
