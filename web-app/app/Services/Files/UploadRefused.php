<?php

namespace App\Services\Files;

use App\Arc\ArcCommandException;
use App\Arc\ExitCode;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A refused upload, save or download with the code the page shows
 * (E3 spec §8): E_DISK 422 (quota) or 507 (staging), E_LIMIT 413 and the
 * session errors.
 */
final class UploadRefused extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status,
    ) {
        parent::__construct($message);
    }

    /**
     * Commands that fail on the disk or a size limit get the file manager's
     * statuses; anything else stays an ArcCommandException.
     */
    public static function fromCommand(ArcCommandException $e): ArcCommandException|self
    {
        return match ($e->exit()) {
            ExitCode::Disk => new self('E_DISK', __('Disk quota exceeded.'), 422),
            ExitCode::Limit => new self('E_LIMIT', $e->userMessage(), 413),
            default => $e,
        };
    }

    public function render(): JsonResponse
    {
        return response()->json(['error' => ['code' => $this->errorCode, 'message' => $this->getMessage()]], $this->status);
    }
}
