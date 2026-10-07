<?php

namespace App\Api;

use App\Arc\SysConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Headers of every API v2 answer (E34 spec D13): ArcPanel-Version and
 * X-Request-Id, the client's own id or a new one, which is also written to
 * the log lines of the request.
 */
final class ApiHeaders
{
    public const REQUEST_ID = 'X-Request-Id';

    public const VERSION = 'ArcPanel-Version';

    private const ATTRIBUTE = 'arc_request_id';

    /** The id of the request, chosen once. */
    public static function requestId(Request $request): string
    {
        $id = $request->attributes->get(self::ATTRIBUTE);
        if (! is_string($id)) {
            $given = (string) $request->headers->get(self::REQUEST_ID, '');
            $id = preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $given) === 1 ? $given : (string) Str::uuid();
            $request->attributes->set(self::ATTRIBUTE, $id);
            Log::shareContext(['request_id' => $id]);
        }

        return $id;
    }

    /**
     * @template T of Response
     *
     * @param  T  $response
     * @return T
     */
    public static function apply(Response $response, Request $request): Response
    {
        $response->headers->set(self::REQUEST_ID, self::requestId($request));
        if (! $response->headers->has(self::VERSION) && ($version = self::panelVersion()) !== '') {
            $response->headers->set(self::VERSION, $version);
        }

        return $response;
    }

    private static function panelVersion(): string
    {
        try {
            return app(SysConfig::class)->get('VERSION');
        } catch (Throwable) {
            return '';
        }
    }
}
