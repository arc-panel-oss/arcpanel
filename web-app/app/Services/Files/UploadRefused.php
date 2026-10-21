<?php

namespace App\Services\Files;

use App\Arc\ArcCommandException;
use App\Arc\ExitCode;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A refused upload, save or download with the code the page shows
 * (E3 spec §8): E_DISK 422 (quota) or 507 (staging), E_LIMIT 413 or 507
 * (the account's staging limits, E39 W5 §7), the session errors, and
 * E_FORBIDEN 403 naming a read-only folder.
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
     * Commands that fail on the disk, a size limit or a read-only folder get
     * the file manager's statuses; anything else stays an ArcCommandException.
     * $home turns the folder into a path below "~".
     */
    public static function fromCommand(ArcCommandException $e, ?string $home = null): ArcCommandException|self
    {
        return match ($e->exit()) {
            ExitCode::Disk => new self('E_DISK', __('Disk quota exceeded.'), 422),
            ExitCode::Limit => new self('E_LIMIT', $e->userMessage(), 413),
            ExitCode::Forbidden => self::readOnly($e, $home) ?? $e,
            default => $e,
        };
    }

    /** "Error: /home/bob/web/x is read-only" (fs_check_writable_parent) as "~/web/x is read-only." */
    private static function readOnly(ArcCommandException $e, ?string $home): ?self
    {
        if (preg_match('#^(?:Error: )?(/.*) is read-only$#', $e->userMessage(), $match) !== 1) {
            return null;
        }
        $folder = $match[1];
        if ($home !== null && $home !== '' && ($folder === $home || str_starts_with($folder, $home.'/'))) {
            $folder = '~'.substr($folder, strlen($home));
        }

        return new self('E_FORBIDEN', sprintf(__('%s is read-only.'), $folder), 403);
    }

    public function render(): JsonResponse
    {
        return response()->json(['error' => ['code' => $this->errorCode, 'message' => $this->getMessage()]], $this->status);
    }
}
