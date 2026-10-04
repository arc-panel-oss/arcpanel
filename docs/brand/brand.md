# ArcPanel brand kit

**Status:** Draft default, waiting for owner sign-off · **Epic:** E9, WP1 · **Spec:** [2026-10-04-001 ArcPanel rebranding](../features/specs/2026-10-04-001-arcpanel-rebranding-design.md)

ArcPanel is a Linux web hosting control panel based on HestiaCP. This kit sets the logo, icon, colours and fonts for the panel, the documentation site and `arcpanel.top`.

## 1. Choosing a direction

There are three directions in [`directions/index.html`](directions/index.html). Open the page in a browser to compare them side by side.

| Direction             | Idea                                                                                                                  | Palette                                 | Fonts                         |
| --------------------- | --------------------------------------------------------------------------------------------------------------------- | --------------------------------------- | ----------------------------- |
| **A, Arch** (default) | Monoline wordmark. The "A" is drawn as an arch, and the icon uses the same arch "A".                                  | Indigo `#2f55d4` on cool slate neutrals | Inter + JetBrains Mono        |
| B, Hearth             | Heavy two-tone wordmark ("Arc" in copper) with a gauge-arc icon. A nod to the hearth goddess HestiaCP is named after. | Copper `#c2410c` on warm stone neutrals | IBM Plex Sans + IBM Plex Mono |
| C, Horizon            | Light open wordmark whose "A" has an arc for a crossbar. The icon is an arc rising over a panel line.                 | Teal `#0d6e66` on grey-green neutrals   | Manrope + Fira Code           |

### Why A is the default

1. **One idea in both marks.** The arch is both the "A" of ArcPanel and the arc in the name. The icon and the wordmark repeat the same shape, so the icon still says "ArcPanel" when it appears alone (favicon, app tile, login page).
2. **Best at 16 px.** The A icon is one bold white shape on a solid fill and stays readable as a favicon. B's gauge loses its centre dot at 16 px, and its dark tile disappears on dark backgrounds. C's arc-over-line reads as a generic dome or sunrise.
3. **Lowest-risk change to the current theme.** The HestiaCP theme is built on blues (gradients, focus rings, chart colours). Indigo replaces them without layout work and still looks clearly different from HestiaCP.
4. **Most contrast headroom.** Every primary pair is at least 5:1 in both themes (see §6).

The owner can switch to B or C at any time. See §9.

## 2. Files

| File                                                         | Use                                                                                                                  |
| ------------------------------------------------------------ | -------------------------------------------------------------------------------------------------------------------- |
| `logo.svg`                                                   | Main lockup (icon and wordmark) for light backgrounds                                                                |
| `logo-dark.svg`                                              | Main lockup for dark backgrounds (wordmark `#f1f3f6`)                                                                |
| `icon.svg`                                                   | Square app icon, 64-unit grid                                                                                        |
| `logo-header.svg`                                            | Two-line white "Arc / Panel" lockup for the panel top bar slot (54 × 29 px, viewBox in the same ratio, left aligned) |
| `logo.png`                                                   | `logo.svg` exported 1200 px wide, transparent background                                                             |
| `icon-16.png`, `icon-32.png`, `icon-192.png`, `icon-512.png` | Icon exports with rounded corners and transparent corners                                                            |
| `icon-180.png`                                               | Apple touch icon, full-bleed square (iOS applies its own mask)                                                       |
| `favicon.ico`                                                | 16, 32 and 48 px PNG frames                                                                                          |
| `tokens.css`                                                 | CSS custom properties (`--ap-*`) for light and dark                                                                  |
| `directions/`                                                | The three candidate directions and the comparison page                                                               |
| `tools/`                                                     | Generator for every file above (see §9)                                                                              |

The wordmark is built from geometric stroked paths. It contains no `<text>` element and does not depend on an installed font, so it looks the same in every renderer. Never retype the name in a font to imitate the logo. Always use the SVG.

Panel copies (`web/`):

| Panel file                   | Source                                                   |
| ---------------------------- | -------------------------------------------------------- |
| `web/images/logo.svg`        | `icon.svg` (login page at 100 × 120 and the SVG favicon) |
| `web/images/logo.png`        | Icon at 128 px                                           |
| `web/images/logo-header.svg` | `logo-header.svg`                                        |
| `web/images/favicon.png`     | `icon-32.png`                                            |
| `web/favicon.ico`            | `favicon.ico`                                            |

## 3. Clear space and minimum size

- **Unit:** the wordmark cap height, written _C_. In the lockup the icon is 1.3 _C_ high, and the gap between icon and wordmark is 0.4 _C_.
- **Clear space:** keep at least 0.5 _C_ free on every side of the lockup, the wordmark or the icon. Text, edges and other logos must stay outside it.
- **Minimum size:**

| Asset                  | Minimum                                                                      |
| ---------------------- | ---------------------------------------------------------------------------- |
| Lockup (`logo.svg`)    | 100 px wide on screen, 25 mm in print                                        |
| Wordmark alone         | 80 px wide                                                                   |
| Icon                   | 16 px. Below 24 px use the PNG/ICO exports, which are rendered for that size |
| Two-line header lockup | Only in the 54 × 29 px panel header slot                                     |

## 4. Colour

### Light theme

| Token          | Hex       | Use                                                   |
| -------------- | --------- | ----------------------------------------------------- |
| Primary        | `#2f55d4` | Links, primary buttons, focus, chart line 1           |
| Primary hover  | `#2443b0` | Hover and pressed states, top bar base                |
| Primary subtle | `#eef2fe` | Selected rows, focused inputs                         |
| On primary     | `#ffffff` | Text and icons on primary                             |
| Success        | `#1b7f47` |                                                       |
| Warning        | `#975a00` | Text-safe amber. For fills, use a tint with dark text |
| Danger         | `#c42f2f` |                                                       |
| Info           | `#0b6da6` |                                                       |

Neutrals (cool slate): 50 `#f6f7f9`, 100 `#eceef2`, 200 `#d9dde5`, 300 `#bcc3cf`, 400 `#8f99aa`, 500 `#6b7587`, 600 `#525b6b`, 700 `#3d4451`, 800 `#282d36`, 900 `#171a20`.

Semantic: background `#ffffff`, surface neutral-50, border neutral-200, text neutral-900, muted text neutral-600.

### Dark theme

| Token          | Hex       | Use                                            |
| -------------- | --------- | ---------------------------------------------- |
| Primary        | `#819eff` | Links, primary buttons, focus, chart line 1    |
| Primary hover  | `#a6bbff` | Hover state                                    |
| Primary subtle | `#1e2742` | Selected rows, focused inputs                  |
| On primary     | `#111317` | Text on primary buttons (dark text, not white) |
| Success        | `#4cc38a` |                                                |
| Warning        | `#f2b451` |                                                |
| Danger         | `#f27b76` |                                                |
| Info           | `#5cb6ee` |                                                |

Neutrals, from darkest (50) to lightest (900), so that the same step number plays the same role in both themes: 50 `#111317`, 100 `#181b21`, 200 `#22262e`, 300 `#2e333d`, 400 `#464c58`, 500 `#636b79`, 600 `#8a93a3`, 700 `#b0b8c5`, 800 `#d5dae2`, 900 `#f1f3f6`.

Semantic: background neutral-100, surface neutral-200, border neutral-300, text neutral-900, muted text neutral-600, top bar neutral-200.

## 5. Typography

Both fonts are licensed under the SIL Open Font License 1.1.

| Role                              | Font           | Source                                       | Fallback stack                                         |
| --------------------------------- | -------------- | -------------------------------------------- | ------------------------------------------------------ |
| UI                                | Inter          | <https://github.com/rsms/inter>              | `system-ui, -apple-system, "Segoe UI", sans-serif`     |
| Monospace (commands, paths, logs) | JetBrains Mono | <https://github.com/JetBrains/JetBrainsMono> | `ui-monospace, "Cascadia Mono", "Consolas", monospace` |

The panel still loads Exo and Inconsolata. Bundling and loading the new fonts belongs to E2 (redesign), not to this work package. `tokens.css` declares `--ap-font-ui` and `--ap-font-mono` ready for that change.

## 6. Contrast (WCAG 2.2 AA, 4.5:1 for normal text)

Brand palette pairs, computed with the WCAG 2.x relative-luminance formula:

| Pair                            | Light                 | Ratio   | Dark                  | Ratio   |
| ------------------------------- | --------------------- | ------- | --------------------- | ------- |
| Body text on background         | `#171a20` / `#ffffff` | 17.43:1 | `#f1f3f6` / `#181b21` | 15.52:1 |
| Muted text on background        | `#525b6b` / `#ffffff` | 6.85:1  | `#8a93a3` / `#181b21` | 5.57:1  |
| Primary (links) on background   | `#2f55d4` / `#ffffff` | 6.23:1  | `#819eff` / `#181b21` | 6.79:1  |
| Primary on surface              | `#2f55d4` / `#f6f7f9` | 5.81:1  | `#819eff` / `#22262e` | 5.97:1  |
| Primary hover on background     | `#2443b0` / `#ffffff` | 8.38:1  | `#a6bbff` / `#181b21` | 9.18:1  |
| On-primary on primary (buttons) | `#ffffff` / `#2f55d4` | 6.23:1  | `#111317` / `#819eff` | 7.32:1  |
| On-primary on primary hover     | `#ffffff` / `#2443b0` | 8.38:1  | `#111317` / `#a6bbff` | 9.89:1  |
| White on top bar                | `#ffffff` / `#2443b0` | 8.38:1  | `#ffffff` / `#22262e` | 15.17:1 |
| Success on background           | `#1b7f47` / `#ffffff` | 5.03:1  | `#4cc38a` / `#181b21` | 7.79:1  |
| Warning on background           | `#975a00` / `#ffffff` | 5.56:1  | `#f2b451` / `#181b21` | 9.38:1  |
| Danger on background            | `#c42f2f` / `#ffffff` | 5.53:1  | `#f27b76` / `#181b21` | 6.46:1  |
| Info on background              | `#0b6da6` / `#ffffff` | 5.60:1  | `#5cb6ee` / `#181b21` | 7.70:1  |

Pairs as applied in the current panel themes:

| Pair                                                                               | Ratio  |
| ---------------------------------------------------------------------------------- | ------ |
| Default theme: link `#2f55d4` on page `#ffffff`                                    | 6.23:1 |
| Default theme: button text `#2f55d4` on the darkest button gradient stop `#d5defb` | 4.65:1 |
| Default theme: hover button text `#2443b0` on `#dce4fd`                            | 6.61:1 |
| Default theme: active button text `#1f3a99` on `#c9d5fa`                           | 6.77:1 |
| Default theme: top bar menu text `#f7f7f7` on the lightest top bar stop `#3a5fdc`  | 5.09:1 |
| Default theme: white login labels on the lightest login background stop `#3f63db`  | 5.23:1 |
| Dark theme: link `#819eff` on page `#282828`                                       | 5.80:1 |
| Dark theme: link hover `#a6bbff` on page `#282828`                                 | 7.84:1 |

All pairs pass AA. The icon was checked at 16 px (`icon-16.png`): the white arch "A" stays distinct from the tile.

## 7. Usage

Do:

- Use `logo.svg` on white or light neutral backgrounds, and `logo-dark.svg` on neutral-100 or darker.
- Use the icon alone where space is square or very small: favicon, app tile, avatar, login page.
- Keep the clear space and minimum sizes in §3.
- Take colours from `tokens.css` and no other source.

Don't:

- Stretch, skew, rotate or outline the logo, or add shadows, gradients or glows.
- Recolour the wordmark in colours outside the palette, or put it on a busy photo or a mid-tone background with low contrast.
- Change the spacing between icon and wordmark, or rebuild the lockup with a font.
- Use the two-line header lockup anywhere except the panel top bar.
- Use HestiaCP's logo or flame next to the ArcPanel logo.
- Use white as text on dark-theme primary `#819eff` (2.54:1). Use `--ap-color-on-primary`.

## 8. Naming

From spec §5:

| Context                                     | Name                                                                                                                    |
| ------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------- |
| Product name in UI, emails, docs, installer | **ArcPanel**: one word, capital A and P. Never "Arc Panel", "Arcpanel", "ARCPANEL" in running text, or "ArcPanel CP"    |
| Attribution                                 | "Based on HestiaCP" (About page, README, installer summary). "Based on HestiaCP 1.10.5" where a version is shown        |
| Debian packages                             | `arcpanel`, `arcpanel-nginx`, `arcpanel-php`, `arcpanel-web-terminal`                                                   |
| Service                                     | `arcpanel`                                                                                                              |
| Server path alias                           | `/usr/local/arcpanel`                                                                                                   |
| Environment variable alias                  | `ARCPANEL`                                                                                                              |
| Command aliases                             | `v-*-hestia-*` → `v-*-arcpanel-*`                                                                                       |
| Hosts                                       | `arcpanel.top` (site and installer), `docs.arcpanel.top`, `apt.arcpanel.top`, `ip.arcpanel.top`, `storage.arcpanel.top` |
| Repository                                  | <https://github.com/arc-panel-oss/arcpanel>                                                                             |

The name is not translated. Lowercase `arcpanel` is used only in identifiers: packages, services, paths and hosts.

**Attribution:** ArcPanel is based on HestiaCP. Keep "Based on HestiaCP" wherever the brand is presented as a product: About page, README, installer summary and the website footer.

## 9. Changing or regenerating the kit

All SVGs, PNGs, `favicon.ico`, `tokens.css` and the comparison page are produced by `tools/gen.py`. Glyphs live in `tools/glyphs.py`, and palettes and icons in `tools/palettes.py`. The rasterizer is [resvg](https://github.com/linebender/resvg) through `@resvg/resvg-js`. Install it outside the repository. It is not a project dependency.

```sh
npm install --prefix /tmp/arcpanel-brand @resvg/resvg-js
NODE_PATH=/tmp/arcpanel-brand/node_modules python docs/brand/tools/gen.py --direction a --apply-web
npx prettier --write docs/brand/directions/index.html
```

`--direction b` or `--direction c` switches the default kit. `--apply-web` also copies the panel assets into `web/`. The script prints the contrast table and exits non-zero if any pair is below 4.5:1.

The panel stylesheets are not generated. To switch directions, edit the brand block at the top of `:root` in `web/css/src/themes/default.css` and `dark.css`:

| Panel property                                                       | Brand value                                                                           |
| -------------------------------------------------------------------- | ------------------------------------------------------------------------------------- |
| `--color-primary`                                                    | primary                                                                               |
| `--color-primary-hover`                                              | primary hover                                                                         |
| `--color-primary-active`                                             | pressed state, one step darker than hover (light) or between primary and hover (dark) |
| `--color-primary-subtle`                                             | primary subtle                                                                        |
| `--color-primary-border`                                             | light tint of primary for hover borders                                               |
| `--top-bar-background`, `--button-background*`, `--login-background` | gradients built from primary, hover and pale primary tints (default theme only)       |

Links, link hover, `--icon-color-blue` and `--chart-line-1-color` point at these properties. In `flat.css`, the top bar and login background use them as well.
