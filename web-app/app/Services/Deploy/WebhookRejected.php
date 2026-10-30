<?php

namespace App\Services\Deploy;

use RuntimeException;

/**
 * A webhook request whose signature is missing or wrong, or that comes from
 * no known provider (E20 spec §6.2): the endpoint answers 401 and counts it
 * for the rate limit. The message never carries the secret or the body.
 */
final class WebhookRejected extends RuntimeException {}
