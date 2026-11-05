<?php

namespace App\Services\Alerts;

use RuntimeException;

/** The alert history table cannot be read or written. */
final class AlertIndexUnavailable extends RuntimeException {}
