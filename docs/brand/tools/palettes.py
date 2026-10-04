"""Brand directions: palettes, fonts, glyph settings, icon builders."""
from glyphs import Glyphs

NEUTRAL_STEPS = ["50", "100", "200", "300", "400", "500", "600", "700", "800", "900"]


def icon_a(p):
    # Arch: an arch-shaped "A" (the arc) in a rounded square.
    return (
        f'<rect width="64" height="64" rx="14" fill="{p["primary"]}"/>'
        '<path d="M20 47.5V28.5a12 12 0 0 1 24 0v19M20 36h24" fill="none" stroke="#fff" '
        'stroke-width="8" stroke-linecap="round" stroke-linejoin="round"/>'
    )


def icon_b(p):
    # Hearth: a 270-degree gauge arc around a hearth dot.
    return (
        f'<rect width="64" height="64" rx="14" fill="{p["ink"]}"/>'
        f'<path d="M20.33 45.67A16.5 16.5 0 1 1 43.67 45.67" fill="none" stroke="{p["accent"]}" '
        'stroke-width="8" stroke-linecap="round"/>'
        '<circle cx="32" cy="34" r="5.5" fill="#fff"/>'
    )


def icon_c(p):
    # Horizon: an arc rising over a panel line.
    return (
        f'<rect width="64" height="64" rx="14" fill="{p["primary"]}"/>'
        '<path d="M17 33a15 15 0 0 1 30 0M14 46h36" fill="none" stroke="#fff" '
        'stroke-width="7" stroke-linecap="round"/>'
    )


DIRECTIONS = {
    "a": {
        "name": "Arch",
        "tagline": "Calm indigo, a monoline wordmark whose A is an arch. Quiet, technical, trustworthy.",
        "glyphs": Glyphs(12),
        "header_glyphs": Glyphs(15),
        "twotone": False,
        "linecap": "round",
        "icon": icon_a,
        "fonts": {"ui": "Inter", "mono": "JetBrains Mono",
                  "ui_url": "https://github.com/rsms/inter",
                  "mono_url": "https://github.com/JetBrains/JetBrainsMono"},
        "light": {
            "primary": "#2f55d4", "primary-hover": "#2443b0", "primary-subtle": "#eef2fe",
            "on-primary": "#ffffff",
            "neutral": ["#f6f7f9", "#eceef2", "#d9dde5", "#bcc3cf", "#8f99aa",
                        "#6b7587", "#525b6b", "#3d4451", "#282d36", "#171a20"],
            "success": "#1b7f47", "warning": "#975a00", "danger": "#c42f2f", "info": "#0b6da6",
            "bg": "#ffffff", "top-bar": "#2443b0",
        },
        "dark": {
            "primary": "#819eff", "primary-hover": "#a6bbff", "primary-subtle": "#1e2742",
            "on-primary": "#111317",
            "neutral": ["#111317", "#181b21", "#22262e", "#2e333d", "#464c58",
                        "#636b79", "#8a93a3", "#b0b8c5", "#d5dae2", "#f1f3f6"],
            "success": "#4cc38a", "warning": "#f2b451", "danger": "#f27b76", "info": "#5cb6ee",
            "bg": "#181b21", "top-bar": "#22262e",
        },
    },
    "b": {
        "name": "Hearth",
        "tagline": "Warm copper on stone, a heavy two-tone wordmark and a gauge icon. A nod to the hearth goddess HestiaCP is named after.",
        "glyphs": Glyphs(17, gap=12),
        "header_glyphs": Glyphs(18, gap=12),
        "twotone": True,
        "linecap": "square",
        "icon": icon_b,
        "fonts": {"ui": "IBM Plex Sans", "mono": "IBM Plex Mono",
                  "ui_url": "https://github.com/IBM/plex",
                  "mono_url": "https://github.com/IBM/plex"},
        "light": {
            "primary": "#c2410c", "primary-hover": "#9a3412", "primary-subtle": "#fff3eb",
            "on-primary": "#ffffff",
            "neutral": ["#faf8f5", "#f3efea", "#e5dfd7", "#cfc6bb", "#a89d90",
                        "#807568", "#625950", "#4a433c", "#312c27", "#1c1917"],
            "success": "#2d7a36", "warning": "#8a5a00", "danger": "#b42318", "info": "#1d6fa3",
            "bg": "#ffffff", "top-bar": "#1c1917",
            "ink": "#1c1917", "accent": "#fb923c",
        },
        "dark": {
            "primary": "#fb923c", "primary-hover": "#fdba74", "primary-subtle": "#3a2416",
            "on-primary": "#1c1917",
            "neutral": ["#141210", "#1c1917", "#262220", "#332e2a", "#4d4640",
                        "#6e655c", "#968b80", "#bab0a5", "#ddd6ce", "#f6f3ef"],
            "success": "#5cc97b", "warning": "#f5c04e", "danger": "#f87166", "info": "#6cb8eb",
            "bg": "#1c1917", "top-bar": "#262220",
            "ink": "#1c1917", "accent": "#fb923c",
        },
    },
    "c": {
        "name": "Horizon",
        "tagline": "Deep teal, a light open wordmark whose A carries an arc for a crossbar, and an arc rising over a panel.",
        "glyphs": Glyphs(9, a_style="pointed", bar_y=70),
        "header_glyphs": Glyphs(13, a_style="pointed", bar_y=70),
        "twotone": False,
        "linecap": "round",
        "icon": icon_c,
        "fonts": {"ui": "Manrope", "mono": "Fira Code",
                  "ui_url": "https://github.com/davelab6/manrope",
                  "mono_url": "https://github.com/tonsky/FiraCode"},
        "light": {
            "primary": "#0d6e66", "primary-hover": "#0a5650", "primary-subtle": "#e9f6f4",
            "on-primary": "#ffffff",
            "neutral": ["#f5f8f8", "#eaf0f0", "#d5dede", "#b6c3c3", "#8d9c9c",
                        "#677777", "#4f5d5d", "#3a4545", "#252e2e", "#141a1a"],
            "success": "#1d7a3e", "warning": "#91570a", "danger": "#be3131", "info": "#1f67b1",
            "bg": "#ffffff", "top-bar": "#0a5650",
        },
        "dark": {
            "primary": "#2dd4bf", "primary-hover": "#5eead4", "primary-subtle": "#123532",
            "on-primary": "#0d1414",
            "neutral": ["#0d1414", "#141a1a", "#1d2525", "#283232", "#3f4b4b",
                        "#5f6e6e", "#879696", "#adbbbb", "#d3dddd", "#f0f5f5"],
            "success": "#57c98a", "warning": "#edb54e", "danger": "#f07872", "info": "#6aaef0",
            "bg": "#141a1a", "top-bar": "#1d2525",
        },
    },
}


def semantic(p, theme):
    n = p["neutral"]
    if theme == "light":
        return {"bg": p["bg"], "surface": n[0], "border": n[2], "text": n[9], "text-muted": n[6]}
    return {"bg": p["bg"], "surface": n[2], "border": n[3], "text": n[9], "text-muted": n[6]}
