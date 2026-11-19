"""
Draws Primora's mark (the same geometry as public/favicon.svg) as PNGs for
the launcher icon and splash screen. Run from mobile/:  python tool/make_brand_images.py
then:  dart run flutter_launcher_icons  &&  dart run flutter_native_splash:create
"""
import math
import os

from PIL import Image, ImageDraw

NAVY = (28, 34, 48, 255)
ORANGE = (193, 112, 74, 255)
CREAM = (250, 243, 238, 255)
WHITE = (255, 255, 255, 255)
SS = 4  # supersampling for smooth edges


def stroke_polygon(p0, p1, p2, width):
    """Outline of a 2-segment polyline with square caps and a miter join."""
    h = width / 2

    def unit(a, b):
        dx, dy = b[0] - a[0], b[1] - a[1]
        length = math.hypot(dx, dy)
        return dx / length, dy / length

    d1, d2 = unit(p0, p1), unit(p1, p2)
    n1, n2 = (-d1[1], d1[0]), (-d2[1], d2[0])
    start = (p0[0] - d1[0] * h, p0[1] - d1[1] * h)
    end = (p2[0] + d2[0] * h, p2[1] + d2[1] * h)

    def miter(sign):
        # Where the two offset edges meet: a1 + t*d1 = a2 + s*d2.
        a1 = (p1[0] + sign * n1[0] * h, p1[1] + sign * n1[1] * h)
        a2 = (p1[0] + sign * n2[0] * h, p1[1] + sign * n2[1] * h)
        det = d1[0] * (-d2[1]) - d1[1] * (-d2[0])
        t = ((a2[0] - a1[0]) * (-d2[1]) - (a2[1] - a1[1]) * (-d2[0])) / det
        return (a1[0] + t * d1[0], a1[1] + t * d1[1])

    off = lambda p, n, s: (p[0] + s * n[0] * h, p[1] + s * n[1] * h)
    return [off(start, n1, 1), miter(1), off(end, n2, 1), off(end, n2, -1), miter(-1), off(start, n1, -1)]


def draw_mark(size, ink, background=None, scale=1.0, radius=0):
    """The mark on a size×size canvas; scale < 1 leaves padding (adaptive icons, splash)."""
    big = size * SS
    img = Image.new('RGBA', (big, big), (0, 0, 0, 0))
    d = ImageDraw.Draw(img)
    if background:
        if radius:
            d.rounded_rectangle([0, 0, big - 1, big - 1], radius=radius * SS, fill=background)
        else:
            d.rectangle([0, 0, big, big], fill=background)

    unit = big / 120 * scale
    offset = (big - 120 * unit) / 2
    pt = lambda x, y: (offset + x * unit, offset + y * unit)
    # Same shapes as the favicon: roof (navy), chevron (terracotta), door (navy).
    d.polygon(stroke_polygon(pt(15, 55), pt(60, 15), pt(105, 55), 13 * unit), fill=ink)
    d.polygon(stroke_polygon(pt(35, 72), pt(60, 50), pt(85, 72), 12 * unit), fill=ORANGE)
    d.rounded_rectangle([*pt(53, 82), *pt(67, 102)], radius=1 * unit, fill=ink)

    return img.resize((size, size), Image.LANCZOS)


out = os.path.join(os.path.dirname(__file__), '..', 'assets', 'branding')
os.makedirs(out, exist_ok=True)

# Launcher icon (iOS and older Android): cream square with the mark, like the favicon.
draw_mark(1024, NAVY, background=CREAM, scale=0.82).save(os.path.join(out, 'icon.png'))
# Android adaptive icon: the mark alone; the background colour is set separately (cream).
draw_mark(1024, NAVY, scale=0.52).save(os.path.join(out, 'icon_foreground.png'))
# Splash: the mark in white and terracotta, on navy (set in flutter_native_splash).
draw_mark(768, WHITE, scale=0.9).save(os.path.join(out, 'splash.png'))
# Android 12+ shows the splash image inside a circle (two thirds of it): pad the mark to fit.
draw_mark(1152, WHITE, scale=0.5).save(os.path.join(out, 'splash_android12.png'))
print('written to', os.path.abspath(out))
