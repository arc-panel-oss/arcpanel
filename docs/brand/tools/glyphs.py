"""Geometric monoline glyphs for the ArcPanel wordmark.

Units: cap height 100 (y=0 cap top, y=100 baseline), x-height 72 (top at y=28).
Glyphs are stroked centre-lines; coordinates are inset by half the stroke so the
ink (with round/square caps) touches exactly y=0 / y=28 / y=100.
"""
import math


def f(v):
    s = f"{v:.2f}".rstrip("0").rstrip(".")
    return "0" if s == "-0" else s


class Glyphs:
    def __init__(self, w, a_style="arch", gap=None, bar_y=66, p_bowl=60, n_width=64, r_ext=6):
        self.w = w
        self.h = w / 2
        self.a_style = a_style
        self.gap = gap if gap is not None else 10 + w * 0.45
        self.bar_y = bar_y
        self.p_bowl = p_bowl
        self.n_width = n_width
        self.r_ext = r_ext

    # each returns (d, ink_width)
    def A(self, x):
        h, w = self.h, self.w
        W = 76
        yb = 100 - h
        if self.a_style == "arch":
            Ra = (W - w) / 2
            d = (f"M{f(x+h)} {f(yb)}V{f(h+Ra)}A{f(Ra)} {f(Ra)} 0 0 1 {f(x+W-h)} {f(h+Ra)}V{f(yb)}"
                 f"M{f(x+h)} {f(self.bar_y)}H{f(x+W-h)}")
        else:  # pointed with an arc crossbar
            W = 80
            ax, ay = x + W / 2, h
            lx, rx = x + h, x + W - h
            # crossbar as a shallow arc between the legs at bar_y
            t = (self.bar_y - ay) / (yb - ay)
            bl = ax + (lx - ax) * t
            br = ax + (rx - ax) * t
            r = (br - bl) * 0.75
            d = (f"M{f(lx)} {f(yb)}L{f(ax)} {f(ay)}L{f(rx)} {f(yb)}"
                 f"M{f(bl)} {f(self.bar_y)}A{f(r)} {f(r)} 0 0 0 {f(br)} {f(self.bar_y)}")
        return d, W

    def r(self, x):
        h = self.h
        yx = 28 + h
        Rr = 22
        d = (f"M{f(x+h)} {f(100-h)}V{f(yx)}"
             f"M{f(x+h)} {f(yx+Rr)}A{f(Rr)} {f(Rr)} 0 0 1 {f(x+h+Rr)} {f(yx)}H{f(x+h+Rr+self.r_ext)}")
        return d, h + Rr + self.r_ext + h

    def _R(self):
        return 36 - self.h

    def c(self, x):
        R = self._R()
        cx, cy = x + 36, 64
        a = math.radians(42)
        sx, sy = cx + R * math.cos(-a), cy + R * math.sin(-a)
        ex, ey = cx + R * math.cos(a), cy + R * math.sin(a)
        d = f"M{f(sx)} {f(sy)}A{f(R)} {f(R)} 0 1 0 {f(ex)} {f(ey)}"
        return d, 36 + R * math.cos(a) + self.h

    def a(self, x):
        R = self._R()
        cx, cy = x + 36, 64
        h = self.h
        d = (f"M{f(cx-R)} {f(cy)}a{f(R)} {f(R)} 0 1 0 {f(2*R)} 0a{f(R)} {f(R)} 0 1 0 {f(-2*R)} 0"
             f"M{f(x+72-h)} {f(28+h)}V{f(100-h)}")
        return d, 72

    def e(self, x):
        R = self._R()
        cx, cy = x + 36, 64
        a = math.radians(40)
        ex, ey = cx + R * math.cos(a), cy + R * math.sin(a)
        d = f"M{f(cx-R)} {f(cy)}H{f(cx+R)}A{f(R)} {f(R)} 0 1 0 {f(ex)} {f(ey)}"
        return d, 72

    def n(self, x):
        h = self.h
        yx = 28 + h
        Rn = (self.n_width - self.w) / 2
        d = (f"M{f(x+h)} {f(100-h)}V{f(yx)}"
             f"M{f(x+h)} {f(yx+Rn)}A{f(Rn)} {f(Rn)} 0 0 1 {f(x+h+2*Rn)} {f(yx+Rn)}V{f(100-h)}")
        return d, self.n_width

    def l(self, x):
        h = self.h
        return f"M{f(x+h)} {f(h)}V{f(100-h)}", self.w

    def P(self, x):
        h = self.h
        rb = (self.p_bowl - self.w) / 2
        bx = 12
        d = (f"M{f(x+h)} {f(100-h)}V{f(h)}H{f(x+h+bx)}"
             f"A{f(rb)} {f(rb)} 0 0 1 {f(x+h+bx)} {f(h+2*rb)}H{f(x+h)}")
        return d, h + bx + rb + h


# optical kerning adjustments (added to the default gap), per pair
KERN = {"rc": -6, "Ar": 0, "cP": 2, "Pa": -2, "an": 0, "ne": 0, "el": 0}


def wordmark(g, text="ArcPanel", split=None):
    """Return (list of (segment_text, d)), total_width.
    split: index where to split into two groups (e.g. 3 => 'Arc' | 'Panel')."""
    x = 0.0
    groups = [[], []]
    prev = None
    for i, ch in enumerate(text):
        if prev is not None:
            x += g.gap + KERN.get(prev + ch, 0)
        d, wid = getattr(g, ch)(x)
        groups[0 if (split is None or i < split) else 1].append(d)
        x += wid
        prev = ch
    return ["".join(gr) for gr in groups], x


def wordmark_lines(g, lines):
    """Lay out several lines, return list of (d, width) per line."""
    out = []
    for text in lines:
        (d, _), wid = wordmark(g, text)
        out.append((d, wid))
    return out
