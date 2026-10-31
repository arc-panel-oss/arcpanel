<?php

namespace App\View;

/**
 * Theme, density and sidebar layout of the panel app (D8 of the E1-S2 spec):
 * the cookie arc_ui, else derived from THEME (the user's, else the server's).
 * Rendered on <html> by the server so pages never flash. The same cookie
 * remembers a hidden Community card of the admin dashboard
 * (docs/features/specs/2026-11-01-001-community-touchpoints-design.md §4).
 */
final class UiPreferences
{
    public const COOKIE = 'arc_ui';

    public const THEMES = ['nocturne', 'light', 'auto'];

    public const DENSITIES = ['comfortable', 'compact'];

    /** full: labels; rail: icons only (from 768 px, the phone drawer is unchanged) */
    public const SIDEBARS = ['full', 'rail'];

    public function __construct(
        public readonly string $theme,
        public readonly string $density,
        public readonly bool $canChangeTheme,
        public readonly string $sidebar = 'full',
        public readonly bool $communityHidden = false,
    ) {}

    /**
     * @param  string|null  $cookie  raw value of the arc_ui cookie (JSON)
     * @param  string|null  $legacyTheme  user THEME, else the server THEME
     */
    public static function resolve(?string $cookie, ?string $legacyTheme, bool $canChangeTheme): self
    {
        [$theme, $density] = self::fromLegacyTheme($legacyTheme);
        $sidebar = 'full';
        $communityHidden = false;

        $saved = is_string($cookie) ? json_decode($cookie, true) : null;
        if (is_array($saved)) {
            if ($canChangeTheme && in_array($saved['theme'] ?? null, self::THEMES, true)) {
                $theme = $saved['theme'];
            }
            if (in_array($saved['density'] ?? null, self::DENSITIES, true)) {
                $density = $saved['density'];
            }
            if (in_array($saved['sidebar'] ?? null, self::SIDEBARS, true)) {
                $sidebar = $saved['sidebar'];
            }
            $communityHidden = ($saved['community'] ?? null) === 'hidden';
        }

        return new self($theme, $density, $canChangeTheme, $sidebar, $communityHidden);
    }

    /**
     * THEME holds a panel theme since 2.0 (E8 spec D11). Values of 1.x
     * servers and users not yet upgraded keep their mapping: dark → nocturne;
     * default and vestia → light; flat → light and compact; anything else →
     * nocturne.
     *
     * @return array{0: string, 1: string}
     */
    public static function fromLegacyTheme(?string $legacyTheme): array
    {
        if (in_array($legacyTheme, self::THEMES, true)) {
            return [$legacyTheme, 'comfortable'];
        }

        return match ($legacyTheme) {
            'default', 'vestia' => ['light', 'comfortable'],
            'flat' => ['light', 'compact'],
            default => ['nocturne', 'comfortable'],
        };
    }
}
