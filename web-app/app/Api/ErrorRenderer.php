<?php

namespace App\Api;

use App\Arc\ArcCommandException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * The error body of every API v2 answer (E34 spec D10):
 * `{"error": {"code": "E_…", "message": "…", …}}`.
 */
final class ErrorRenderer
{
    public static function handles(Request $request): bool
    {
        return $request->is('api/v2', 'api/v2/*');
    }

    public static function render(Throwable $e, Request $request): JsonResponse
    {
        $error = self::toApiException($e);

        return ApiHeaders::apply(
            response()->json($error->body(), $error->status, $error->headers),
            $request,
        );
    }

    public static function toApiException(Throwable $e): ApiException
    {
        return match (true) {
            $e instanceof ApiException => $e,
            $e instanceof ArcCommandException => new ApiException(
                ErrorCode::fromExit($e->exit()),
                $e->result !== null ? $e->userMessage() : 'The command could not run.',
                ErrorCode::statusOfExit($e->exit()),
            ),
            $e instanceof ValidationException => new ApiException(
                ErrorCode::Validation,
                'The given data was invalid.',
                422,
                ['fields' => $e->errors()],
            ),
            $e instanceof AuthorizationException => new ApiException(ErrorCode::Forbidden, 'This action is not allowed.', 403),
            $e instanceof HttpExceptionInterface => self::fromHttp($e),
            default => new ApiException(ErrorCode::Server, 'Server error.', 500),
        };
    }

    private static function fromHttp(HttpExceptionInterface $e): ApiException
    {
        $status = $e->getStatusCode();
        $headers = array_map('strval', $e->getHeaders());

        return match ($status) {
            401 => new ApiException(ErrorCode::Unauthenticated, 'Authentication failed.', 401, headers: $headers),
            403 => new ApiException(ErrorCode::Forbidden, 'This action is not allowed.', 403, headers: $headers),
            404 => new ApiException(ErrorCode::NotExist, 'Not found.', 404, headers: $headers),
            405 => new ApiException(ErrorCode::MethodNotAllowed, 'Method not allowed.', 405, headers: $headers),
            429 => new ApiException(ErrorCode::RateLimit, 'Too many requests.', 429, headers: $headers),
            default => new ApiException($status < 500 ? ErrorCode::Invalid : ErrorCode::Server, $status < 500 ? 'Invalid request.' : 'Server error.', $status, headers: $headers),
        };
    }
}
