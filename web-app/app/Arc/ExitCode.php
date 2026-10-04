<?php

namespace App\Arc;

/**
 * Exit codes of the v-* commands (func/main.sh).
 */
enum ExitCode: int
{
    case Args = 1;
    case Invalid = 2;
    case NotExist = 3;
    case Exists = 4;
    case Suspended = 5;
    case Unsuspended = 6;
    case InUse = 7;
    case Limit = 8;
    case Password = 9;
    case Forbidden = 10;
    case Disabled = 11;
    case Parsing = 12;
    case Disk = 13;
    case LoadAverage = 14;
    case Connect = 15;
    case Ftp = 16;
    case Db = 17;
    case Rrd = 18;
    case Update = 19;
    case Restart = 20;

    /** Name used in JSON error responses, e.g. E_LIMIT. */
    public function errorCode(): string
    {
        return match ($this) {
            self::Args => 'E_ARGS',
            self::Invalid => 'E_INVALID',
            self::NotExist => 'E_NOTEXIST',
            self::Exists => 'E_EXISTS',
            self::Suspended => 'E_SUSPENDED',
            self::Unsuspended => 'E_UNSUSPENDED',
            self::InUse => 'E_INUSE',
            self::Limit => 'E_LIMIT',
            self::Password => 'E_PASSWORD',
            self::Forbidden => 'E_FORBIDEN',
            self::Disabled => 'E_DISABLED',
            self::Parsing => 'E_PARSING',
            self::Disk => 'E_DISK',
            self::LoadAverage => 'E_LA',
            self::Connect => 'E_CONNECT',
            self::Ftp => 'E_FTP',
            self::Db => 'E_DB',
            self::Rrd => 'E_RRD',
            self::Update => 'E_UPDATE',
            self::Restart => 'E_RESTART',
        };
    }

    public function httpStatus(): int
    {
        return match ($this) {
            self::Args, self::Invalid => 422,
            self::NotExist => 404,
            self::Exists, self::InUse => 409,
            self::Forbidden, self::Password, self::Suspended => 403,
            self::Limit => 429,
            self::Disabled, self::LoadAverage => 503,
            default => 500,
        };
    }
}
