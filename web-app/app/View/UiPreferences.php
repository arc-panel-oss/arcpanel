<?php

namespace App\View;

/**
 * Theme and density of the panel app (D8 of the E1-S2 spec): the cookie
 * arc_ui, else derived from the legacy THEME. Rendered on <html> by the
 * server so pages never flash.
 */
final class UiPreferences
{
    public const COOKIE = 'arc_ui';

    public const THEMES = ['nocturne', 'light', 'auto'];

    public const DENSITIES = ['comfortable', 'compact'];

    public function __construct(
        public readonly string $theme,
        public readonly string $density,
        public readonly bool $canChangeTheme,
    ) {}

    /**
     * @param  string|null  $cookie  raw value of the arc_ui cookie (JSON)
     * @param  string|null  $legacyTheme  user THEME, else the server THEME
     */
    public static function resolve(?string $cookie, ?string $legacyTheme, bool $canChangeTheme): self
    {
        [$theme, $density] = self::fromLegacyTheme($legacyTheme);

        $saved = is_string($cookie) ? json_decode($cookie, true) : null;
        if (is_array($saved)) {
            if ($canChangeTheme && in_array($saved['theme'] ?? null, self::THEMES, true)) {
                $theme = $saved['theme'];
            }
            if (in_array($saved['density'] ?? null, self::DENSITIES, true)) {
                $density = $saved['density'];
            }
        }

        return new self($theme, $density, $canChangeTheme);
    }

    /**
     * Legacy themes (web/css/themes): dark → nocturne; default and vestia →
     * light; flat → light and compact; anything else → nocturne.
     *
     * @return array{0: string, 1: string}
     */
    public static function fromLegacyTheme(?string $legacyTheme): array
    {
        return match ($legacyTheme) {
            'default', 'vestia' => ['light', 'comfortable'],
            'flat' => ['light', 'compact'],
            default => ['nocturne', 'comfortable'],
        };
    }
}
