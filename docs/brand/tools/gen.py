"""Generate ArcPanel brand directions, the comparison page and the final kit.

Usage: python docs/brand/tools/gen.py [--direction a|b|c] [--apply-web]

SVGs are written with an oversize viewBox; export.cjs (resvg) then tightens
every viewBox to the real ink bounds, renders the PNGs and favicon.ico and,
with --apply-web, copies the panel assets into web/.
Needs Node.js with @resvg/resvg-js reachable through NODE_PATH.
"""
import argparse
import html
import json
import os
import subprocess
import sys
import tempfile

from contrast import cr
from glyphs import wordmark
from palettes import DIRECTIONS, NEUTRAL_STEPS, semantic

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.normpath(os.path.join(HERE, "..", "..", ".."))
ARGS = argparse.ArgumentParser(description=__doc__.splitlines()[0])
ARGS.add_argument("--direction", default="a", choices=["a", "b", "c"])
ARGS.add_argument("--apply-web", action="store_true")
OPTS = ARGS.parse_args()
CHOSEN = OPTS.direction
BRAND = os.path.join(ROOT, "docs", "brand")
DIRS = os.path.join(BRAND, "directions")
CROP_LIST = []


def write(path, text, crop=None):
    os.makedirs(os.path.dirname(path), exist_ok=True)
    with open(path, "w", encoding="utf-8", newline="\n") as fh:
        fh.write(text)
    if crop is not None:
        CROP_LIST.append({"file": path, **crop})


def svg_doc(body, title, vb="-60 -60 2400 400"):
    return (f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="{vb}" role="img">'
            f"<title>{html.escape(title)}</title>{body}</svg>\n")


def word_paths(d_cfg, ink, accent, glyphs=None):
    g = glyphs or d_cfg["glyphs"]
    (d1, d2), width = wordmark(g, split=3)
    join = "round" if d_cfg["linecap"] == "round" else "miter"
    attrs = (f'fill="none" stroke-width="{g.w:g}" stroke-linecap="{d_cfg["linecap"]}" '
             f'stroke-linejoin="{join}"')
    if d_cfg["twotone"]:
        body = (f'<g {attrs}><path stroke="{accent}" d="{d1}"/>'
                f'<path stroke="{ink}" d="{d2}"/></g>')
    else:
        body = f'<g {attrs} stroke="{ink}"><path d="{d1}{d2}"/></g>'
    return body, width


def icon_svg(d_cfg):
    return ('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" role="img">'
            f'<title>ArcPanel</title>{d_cfg["icon"](d_cfg["light"])}</svg>\n')


def lockup(d_cfg, ink, accent):
    """Icon + wordmark, icon height 130 (1.3x cap height), gap 40."""
    icon = d_cfg["icon"](d_cfg["light"])
    word, width = word_paths(d_cfg, ink, accent)
    body = (f'<g transform="translate(0 -15) scale({130/64:.6f})">{icon}</g>'
            f'<g transform="translate(170 0)">{word}</g>')
    return svg_doc(body, "ArcPanel", vb=f"-60 -80 {width + 300} 260")


def header_logo(d_cfg, colour, accent):
    """Two-line lockup 'Arc' / 'Panel' for the 54x29 panel header slot."""
    g = d_cfg["header_glyphs"]
    (a, _), wa = wordmark(g, text="Arc")
    (p, _), wp = wordmark(g, text="Panel")
    join = "round" if d_cfg["linecap"] == "round" else "miter"
    attrs = (f'fill="none" stroke-width="{g.w:g}" stroke-linecap="{d_cfg["linecap"]}" '
             f'stroke-linejoin="{join}"')
    first = accent if d_cfg["twotone"] else colour
    body = (f'<g {attrs}><path stroke="{first}" d="{a}"/>'
            f'<path stroke="{colour}" transform="translate(0 134)" d="{p}"/></g>')
    return svg_doc(body, "ArcPanel", vb=f"-60 -60 {max(wa, wp) + 120} 360")


def tokens_css(key, d_cfg):
    def block(p, theme, indent):
        s = semantic(p, theme)
        lines = [
            f"--ap-color-primary: {p['primary']};",
            f"--ap-color-primary-hover: {p['primary-hover']};",
            f"--ap-color-primary-subtle: {p['primary-subtle']};",
            f"--ap-color-on-primary: {p['on-primary']};",
        ]
        lines += [f"--ap-color-neutral-{st}: {c};" for st, c in zip(NEUTRAL_STEPS, p["neutral"])]
        lines += [
            f"--ap-color-success: {p['success']};",
            f"--ap-color-warning: {p['warning']};",
            f"--ap-color-danger: {p['danger']};",
            f"--ap-color-info: {p['info']};",
            f"--ap-color-bg: {s['bg']};",
            f"--ap-color-surface: {s['surface']};",
            f"--ap-color-border: {s['border']};",
            f"--ap-color-text: {s['text']};",
            f"--ap-color-text-muted: {s['text-muted']};",
            f"--ap-color-top-bar: {p['top-bar']};",
        ]
        return "\n".join(indent + l for l in lines)

    f = d_cfg["fonts"]
    head = (f'/*\n * ArcPanel brand tokens, direction {key.upper()} "{d_cfg["name"]}".\n'
            " * Generated with the brand kit; see docs/brand/brand.md.\n"
            " * Light values are the default. Dark values apply with\n"
            ' * prefers-color-scheme: dark (unless data-theme="light") or data-theme="dark".\n */\n\n')
    root = (":root {\n"
            f'\t--ap-font-ui: "{f["ui"]}", system-ui, -apple-system, "Segoe UI", sans-serif;\n'
            f'\t--ap-font-mono: "{f["mono"]}", ui-monospace, "Cascadia Mono", "Consolas", monospace;\n'
            "\t--ap-radius: 6px;\n"
            f"{block(d_cfg['light'], 'light', chr(9))}\n}}\n\n")
    dark = block(d_cfg["dark"], "dark", "\t\t")
    dark_attr = block(d_cfg["dark"], "dark", "\t")
    return (head + root +
            "@media (prefers-color-scheme: dark) {\n\t:root:not([data-theme=\"light\"]) {\n"
            f"{dark}\n\t}}\n}}\n\n"
            ":root[data-theme=\"dark\"] {\n"
            f"{dark_attr}\n}}\n")


def contrast_pairs(d_cfg, theme):
    p = d_cfg[theme]
    s = semantic(p, theme)
    pairs = [
        ("Body text on background", s["text"], s["bg"]),
        ("Muted text on background", s["text-muted"], s["bg"]),
        ("Primary (links) on background", p["primary"], s["bg"]),
        ("Primary on surface", p["primary"], s["surface"]),
        ("Primary hover on background", p["primary-hover"], s["bg"]),
        ("On-primary on primary (buttons)", p["on-primary"], p["primary"]),
        ("On-primary on primary hover", p["on-primary"], p["primary-hover"]),
        ("White on top bar", "#ffffff", p["top-bar"]),
        ("Success on background", p["success"], s["bg"]),
        ("Warning on background", p["warning"], s["bg"]),
        ("Danger on background", p["danger"], s["bg"]),
        ("Info on background", p["info"], s["bg"]),
    ]
    return [(n, fg, bg, cr(fg, bg)) for n, fg, bg in pairs]


# ---------------------------------------------------------------- directions
def build_direction(key, d_cfg, out):
    lp, dp = d_cfg["light"], d_cfg["dark"]
    ink_l, ink_d = lp["neutral"][9], dp["neutral"][9]
    word_l, _ = word_paths(d_cfg, ink_l, lp["primary"])
    word_d, _ = word_paths(d_cfg, ink_d, dp["primary"])
    write(os.path.join(out, "wordmark.svg"), svg_doc(word_l, "ArcPanel"), crop={})
    write(os.path.join(out, "wordmark-dark.svg"), svg_doc(word_d, "ArcPanel"), crop={})
    write(os.path.join(out, "icon.svg"), icon_svg(d_cfg))
    write(os.path.join(out, "logo.svg"), lockup(d_cfg, ink_l, lp["primary"]), crop={})
    write(os.path.join(out, "logo-dark.svg"), lockup(d_cfg, ink_d, dp["primary"]), crop={})
    accent = lp.get("accent", "#ffffff")
    write(os.path.join(out, "logo-header.svg"), header_logo(d_cfg, "#ffffff", accent),
          crop={"aspect": 54 / 29})
    write(os.path.join(out, "tokens.css"), tokens_css(key, d_cfg))


def swatch(name, colour, against, label_bg):
    ratio = cr(colour, against)
    txt = "#ffffff" if cr("#ffffff", colour) >= cr("#111111", colour) else "#111111"
    return (f'<div class="sw"><div class="chip" style="background:{colour};color:{txt}">Aa</div>'
            f'<div class="meta"><b>{name}</b><code>{colour}</code>'
            f'<span>{ratio:.2f}:1 on {label_bg}</span></div></div>')


def palette_html(d_cfg, theme):
    p = d_cfg[theme]
    s = semantic(p, theme)
    bg = s["bg"]
    main = "".join(swatch(n, p[k], bg, "bg") for n, k in [
        ("Primary", "primary"), ("Primary hover", "primary-hover"),
        ("Success", "success"), ("Warning", "warning"), ("Danger", "danger"), ("Info", "info")])
    neutrals = "".join(
        f'<div class="n" style="background:{c};color:{"#fff" if cr("#fff", c) > cr("#111", c) else "#111"}">'
        f"<span>{st}</span><code>{c}</code></div>"
        for st, c in zip(NEUTRAL_STEPS, p["neutral"]))
    return (f'<div class="palette {theme}" style="background:{bg};color:{s["text"]}">'
            f'<h4>{theme.capitalize()} palette</h4><div class="sws">{main}</div>'
            f'<div class="neutrals">{neutrals}</div></div>')


def contrast_html(d_cfg):
    rows = []
    for theme in ("light", "dark"):
        for n, fg, bg, r in contrast_pairs(d_cfg, theme):
            ok = "pass" if r >= 4.5 else "fail"
            rows.append(f'<tr><td>{theme}</td><td>{n}</td>'
                        f'<td><span class="pair" style="color:{fg};background:{bg}">Aa</span> '
                        f'<code>{fg}</code> / <code>{bg}</code></td>'
                        f'<td class="{ok}">{r:.2f}:1</td></tr>')
    return ('<table class="contrast"><thead><tr><th>Theme</th><th>Pair</th><th>Colours</th>'
            f'<th>Ratio</th></tr></thead><tbody>{"".join(rows)}</tbody></table>')


def mock_html(key, d_cfg, theme):
    p = d_cfg[theme]
    s = semantic(p, theme)
    items = "".join(f"<span>{t}</span>" for t in ("Users", "Web", "DNS", "Mail", "DB"))
    return (f'<div class="mock" style="background:{s["bg"]};color:{s["text"]};font-family:'
            f'{d_cfg["fonts"]["ui"]},system-ui,sans-serif">'
            f'<div class="bar" style="background:{p["top-bar"]}">'
            f'<img src="{key}/logo-header.svg" width="54" height="29" alt="ArcPanel">'
            f'<nav>{items}</nav></div>'
            f'<div class="body"><p style="color:{s["text-muted"]}">example.com &middot; '
            f'<span class="link" style="color:{p["primary"]}">Edit web domain</span></p>'
            f'<span class="btn" style="background:{p["primary"]};color:{p["on-primary"]}">Add domain</span> '
            f'<span class="btn ghost" style="border-color:{s["border"]};background:{s["surface"]};'
            f'color:{s["text"]}">Cancel</span> '
            f'<code style="font-family:{d_cfg["fonts"]["mono"]},ui-monospace,monospace;'
            f'background:{s["surface"]};color:{s["text"]}">v-list-users</code></div></div>')


def column_html(key, d_cfg):
    lp, dp = d_cfg["light"], d_cfg["dark"]
    f = d_cfg["fonts"]
    icons = "".join(f'<img src="{key}/icon.svg" width="{n}" height="{n}" alt="">' for n in (16, 32, 64))
    chosen = ' <span class="badge">Default</span>' if key == CHOSEN else ""
    return f"""
<section class="dir" id="dir-{key}">
  <h2>{key.upper()} &middot; {d_cfg['name']}{chosen}</h2>
  <p class="tag">{html.escape(d_cfg['tagline'])}</p>
  <h3>Logo</h3>
  <div class="stage" style="background:#ffffff"><img src="{key}/logo.svg" alt="ArcPanel logo on light"></div>
  <div class="stage" style="background:{dp['bg']}"><img src="{key}/logo-dark.svg" alt="ArcPanel logo on dark"></div>
  <div class="stage pair2"><div style="background:#ffffff"><img src="{key}/wordmark.svg" alt="Wordmark"></div>
  <div style="background:{dp['bg']}"><img src="{key}/wordmark-dark.svg" alt="Wordmark dark"></div></div>
  <h3>Icon at 16, 32 and 64 px</h3>
  <div class="icons"><div style="background:#ffffff">{icons}</div><div style="background:{dp['bg']}">{icons}</div></div>
  <h3>Top bar mock</h3>
  {mock_html(key, d_cfg, 'light')}
  {mock_html(key, d_cfg, 'dark')}
  <h3>Palettes</h3>
  {palette_html(d_cfg, 'light')}
  {palette_html(d_cfg, 'dark')}
  <h3>Fonts (SIL Open Font License)</h3>
  <p class="fonts">UI: <a href="{f['ui_url']}">{f['ui']}</a> &middot; Monospace: <a href="{f['mono_url']}">{f['mono']}</a></p>
  <p class="fontnote">Shown in the system fallback: this page does not load web fonts.</p>
  <h3>Contrast (WCAG 2.2, AA needs 4.5:1)</h3>
  {contrast_html(d_cfg)}
  <p class="files">Files: <a href="{key}/logo.svg">logo.svg</a>, <a href="{key}/logo-dark.svg">logo-dark.svg</a>,
  <a href="{key}/wordmark.svg">wordmark.svg</a>, <a href="{key}/icon.svg">icon.svg</a>,
  <a href="{key}/logo-header.svg">logo-header.svg</a>, <a href="{key}/tokens.css">tokens.css</a></p>
</section>"""


PAGE_CSS = """
:root{--ink:#1d2027;--muted:#5d6472;--line:#dfe2e8;--page:#f3f4f6;--card:#fff}
*{box-sizing:border-box}
body{margin:0;background:var(--page);color:var(--ink);font:15px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
header.page{padding:32px 16px 8px;max-width:1500px;margin:0 auto}
header.page h1{margin:0 0 4px;font-size:26px}
header.page p{margin:4px 0;color:var(--muted);max-width:75ch}
main{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:20px;padding:16px;max-width:1500px;margin:0 auto}
@media (max-width:1100px){main{grid-template-columns:1fr}}
.dir{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:20px;min-width:0}
.dir h2{margin:0;font-size:20px}
.dir h3{font-size:13px;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);margin:22px 0 8px}
.tag{color:var(--muted);margin:6px 0 0;min-height:4.5em}
.badge{font-size:12px;background:#1d2027;color:#fff;border-radius:99px;padding:2px 10px;vertical-align:middle}
.stage{border:1px solid var(--line);border-radius:8px;padding:28px 20px;margin-bottom:8px;display:flex;justify-content:center}
.stage img{height:52px;max-width:100%}
.stage.pair2{padding:0;overflow:hidden;display:grid;grid-template-columns:1fr 1fr}
.stage.pair2 div{padding:18px 12px;display:flex;justify-content:center}
.stage.pair2 img{height:22px}
.icons{display:grid;grid-template-columns:1fr 1fr;border:1px solid var(--line);border-radius:8px;overflow:hidden}
.icons div{display:flex;gap:18px;align-items:center;justify-content:center;padding:16px}
.mock{border:1px solid var(--line);border-radius:8px;overflow:hidden;margin-bottom:8px}
.mock .bar{height:40px;display:flex;align-items:center;padding:0 14px;gap:18px}
.mock nav{display:flex;gap:12px;color:#fff;font-size:12px;font-weight:600;overflow:hidden}
.mock .body{padding:12px 14px;font-size:13px}
.mock .body p{margin:0 0 10px}
.mock .link{font-weight:600;text-decoration:underline}
.btn{display:inline-block;padding:5px 14px;border-radius:6px;font-weight:600;font-size:13px;border:1px solid transparent}
.mock code{padding:2px 6px;border-radius:4px;font-size:12px}
.palette{border:1px solid var(--line);border-radius:8px;padding:12px;margin-bottom:8px}
.palette h4{margin:0 0 8px;font-size:13px}
.sws{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
.sw{display:flex;gap:8px;align-items:center;min-width:0}
.chip{width:40px;height:40px;border-radius:6px;display:flex;align-items:center;justify-content:center;font-weight:700;flex:none}
.meta{display:flex;flex-direction:column;font-size:12px;line-height:1.3;min-width:0}
.meta code{font-size:12px}
.meta span{opacity:.8}
.neutrals{display:grid;grid-template-columns:repeat(10,minmax(0,1fr));margin-top:10px;border-radius:6px;overflow:hidden}
.n{padding:6px 2px;font-size:9px;text-align:center;display:flex;flex-direction:column}
.n code{font-size:8.5px;word-break:break-all}
.fonts{margin:0}.fontnote{margin:2px 0 0;color:var(--muted);font-size:13px}
table.contrast{width:100%;border-collapse:collapse;font-size:12px}
.contrast th,.contrast td{text-align:left;padding:4px 6px;border-bottom:1px solid var(--line);vertical-align:middle}
.contrast td code{font-size:11px}
.pair{display:inline-block;padding:0 6px;border-radius:4px;font-weight:700;border:1px solid var(--line)}
.pass{color:#1b7f47;font-weight:700}.fail{color:#c42f2f;font-weight:700}
.files{font-size:13px;color:var(--muted)}
a{color:#2f55d4}
"""


def index_html():
    cols = "".join(column_html(k, DIRECTIONS[k]) for k in ("a", "b", "c"))
    return f"""<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ArcPanel Brand Directions</title>
<style>{PAGE_CSS}</style>
</head>
<body>
<header class="page">
<h1>ArcPanel brand directions</h1>
<p>Three directions for the ArcPanel brand kit (spec 2026-10-04-001, WP1). Each has a path-built wordmark, a square icon built on an arc, light and dark palettes and an SIL OFL font pairing. Direction {CHOSEN.upper()} is the current default in <code>docs/brand/</code>; the owner can swap it by re-running the generator with another direction.</p>
<p>Product name: <b>ArcPanel</b> (one word, capital A and P). Based on HestiaCP.</p>
</header>
<main>{cols}</main>
</body>
</html>
"""


def main():
    for k, d_cfg in DIRECTIONS.items():
        build_direction(k, d_cfg, os.path.join(DIRS, k))
    write(os.path.join(DIRS, "index.html"), index_html())

    # final kit from the chosen direction
    d_cfg = DIRECTIONS[CHOSEN]
    lp, dp = d_cfg["light"], d_cfg["dark"]
    write(os.path.join(BRAND, "logo.svg"), lockup(d_cfg, lp["neutral"][9], lp["primary"]), crop={})
    write(os.path.join(BRAND, "logo-dark.svg"), lockup(d_cfg, dp["neutral"][9], dp["primary"]), crop={})
    write(os.path.join(BRAND, "icon.svg"), icon_svg(d_cfg))
    write(os.path.join(BRAND, "logo-header.svg"),
          header_logo(d_cfg, "#ffffff", lp.get("accent", "#ffffff")), crop={"aspect": 54 / 29})
    write(os.path.join(BRAND, "tokens.css"), tokens_css(CHOSEN, d_cfg))

    for t in ("light", "dark"):
        print(f"Contrast, direction {CHOSEN.upper()}, {t}:")
        for n, fg, bg, r in contrast_pairs(d_cfg, t):
            print(f"  {n:34} {fg} on {bg}  {r:5.2f}:1")
    failed = False
    for k, d in DIRECTIONS.items():
        for t in ("light", "dark"):
            bad = [(n, round(r, 2)) for n, fg, bg, r in contrast_pairs(d, t) if r < 4.5]
            if bad:
                failed = True
                print(f"FAIL {k} {t}: {bad}")

    job = {"root": ROOT, "brand": BRAND, "crop": CROP_LIST, "applyWeb": OPTS.apply_web}
    fd, job_path = tempfile.mkstemp(suffix=".json", prefix="arcpanel-brand-")
    with os.fdopen(fd, "w") as fh:
        json.dump(job, fh)
    try:
        subprocess.run(["node", os.path.join(HERE, "export.cjs"), job_path], check=True)
    finally:
        os.remove(job_path)
    sys.exit(1 if failed else 0)


main()
