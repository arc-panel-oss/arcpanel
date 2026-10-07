<?php

namespace App\Api;

use App\Arc\ExitCode;

/**
 * The `error.code` of an API v2 error (E34 spec D10): the error names of the
 * command exit codes, and the codes of the API layer.
 */
enum ErrorCode: string
{
    // API layer
    case Unauthenticated = 'E_UNAUTHENTICATED';
    case ApiDisabled = 'E_API_DISABLED';
    case Forbidden = 'E_FORBIDDEN';
    case NotExist = 'E_NOTEXIST';
    case Validation = 'E_VALIDATION';
    case RateLimit = 'E_RATE_LIMIT';
    case MethodNotAllowed = 'E_METHOD_NOT_ALLOWED';
    case Server = 'E_SERVER';

    // Exit codes of the v-* commands (func/main.sh)
    case Args = 'E_ARGS';
    case Invalid = 'E_INVALID';
    case Exists = 'E_EXISTS';
    case Suspended = 'E_SUSPENDED';
    case Unsuspended = 'E_UNSUSPENDED';
    case InUse = 'E_INUSE';
    case Limit = 'E_LIMIT';
    case Password = 'E_PASSWORD';
    case Disabled = 'E_DISABLED';
    case Parsing = 'E_PARSING';
    case Disk = 'E_DISK';
    case LoadAverage = 'E_LA';
    case Connect = 'E_CONNECT';
    case Ftp = 'E_FTP';
    case Db = 'E_DB';
    case Rrd = 'E_RRD';
    case Update = 'E_UPDATE';
    case Restart = 'E_RESTART';

    /** The code of a command's exit code; E_FORBIDEN of func/main.sh is spelled E_FORBIDDEN here. */
    public static function fromExit(?ExitCode $exit): self
    {
        return match ($exit) {
            null => self::Server,
            ExitCode::Forbidden => self::Forbidden,
            default => self::from($exit->errorCode()),
        };
    }

    /** HTTP status of a command's exit code (ExitCode::httpStatus(), 500 without one). */
    public static function statusOfExit(?ExitCode $exit): int
    {
        return $exit?->httpStatus() ?? 500;
    }
}
