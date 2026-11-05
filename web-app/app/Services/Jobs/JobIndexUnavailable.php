<?php

namespace App\Services\Jobs;

use RuntimeException;

/** The panel database is missing or cannot be written (E15 spec §8): pages show a banner with Rebuild. */
final class JobIndexUnavailable extends RuntimeException {}
