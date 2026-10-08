<?php

namespace App\Services\Server;

use App\Policies\ServerPolicy;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The rules of the services page that the page and REST API v2 share
 * (E34 batch 6 spec D61, D66): which services exist, which actions a
 * protected service refuses (the panel's own service is restarted, never
 * stopped), and the host name that confirms a server restart (E7 batch 3
 * spec D5).
 */
final class ServiceActions
{
    public function __construct(private readonly SystemServices $services) {}

    /**
     * 404 unless the server runs every one of these services.
     *
     * @param  list<string>  $services
     */
    public function assertKnown(array $services): void
    {
        foreach ($services as $service) {
            if (! $this->services->knows($service)) {
                throw new NotFoundHttpException;
            }
        }
    }

    /** Whether $action may run on $service: a protected service is never stopped. */
    public static function allows(string $action, string $service): bool
    {
        return $action !== 'stop' || ! ServerPolicy::isProtectedService($service);
    }

    /**
     * Split a selection into the services the action may run on and those
     * it refuses, both in the order given.
     *
     * @param  list<string>  $services
     * @return array{0: list<string>, 1: list<string>} [allowed, refused]
     */
    public static function partition(string $action, array $services): array
    {
        $allowed = [];
        $refused = [];
        foreach ($services as $service) {
            if (self::allows($action, $service)) {
                $allowed[] = $service;
            } else {
                $refused[] = $service;
            }
        }

        return [$allowed, $refused];
    }

    /** The reason a refused service gives (bulk results, API errors). */
    public static function refusal(): string
    {
        return __('This service is required by the panel.');
    }

    /**
     * A server restart needs the host name of the server typed in; anything
     * else is a validation error on $field.
     *
     * @throws ValidationException
     */
    public function confirmRestart(mixed $typed, string $field = 'hostname'): void
    {
        $hostname = $this->services->info()['hostname'];
        if (! is_string($typed) || $typed === '' || $hostname === '' || $typed !== $hostname) {
            throw ValidationException::withMessages([$field => __('Type the host name of the server to confirm.')]);
        }
    }
}
