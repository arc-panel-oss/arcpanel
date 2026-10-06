<?php

namespace App\View;

/**
 * The custom logo of the White Label settings (E7 batch 3 spec D6): the
 * files administrators put into $HESTIA/web/images/custom/, which nginx
 * serves under /images/custom/.
 */
final class WhiteLabel
{
    public const LOGO_URL = '/images/custom/logo-header.svg';

    private static ?bool $customLogo = null;

    public static function customLogo(): bool
    {
        return self::$customLogo ??= is_file(config('arc.install_dir').'/web/images/custom/logo-header.svg');
    }

    /** Tests: forget the cached answer. */
    public static function forget(): void
    {
        self::$customLogo = null;
    }
}
