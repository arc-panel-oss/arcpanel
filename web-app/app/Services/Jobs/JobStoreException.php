<?php

namespace App\Services\Jobs;

use RuntimeException;

/** A job command failed or answered something that is not a job (E15). */
final class JobStoreException extends RuntimeException {}
