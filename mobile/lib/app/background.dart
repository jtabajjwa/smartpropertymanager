import 'dart:math' as math;

import 'package:flutter/material.dart';

/// The web portal's background (`.app-bg` in resources/css/app.css): warm
/// off-white with four soft glows (terracotta top right, blue left, lavender
/// bottom, pink right) drifting slowly.
///
/// [PrimoraBackground] (around the whole app) runs one shared drift; every
/// page paints the glows itself through [GlowBackdrop] (added by the page
/// transitions in the theme), so pages stay solid while they slide over each
/// other. Stays still when the phone asks for less motion.
class PrimoraBackground extends StatefulWidget {
  const PrimoraBackground({super.key, required this.child});
  final Widget child;

  static const base = Color(0xFFFBFAF9);

  @override
  State<PrimoraBackground> createState() => _PrimoraBackgroundState();
}

class _PrimoraBackgroundState extends State<PrimoraBackground> with TickerProviderStateMixin {
  // Same periods as the web: 28 s and 34 s, back and forth.
  late final AnimationController _a = AnimationController(vsync: this, duration: const Duration(seconds: 28));
  late final AnimationController _b = AnimationController(vsync: this, duration: const Duration(seconds: 34));
  late final _drift = _Drift(_a, _b);

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    if (MediaQuery.maybeDisableAnimationsOf(context) ?? false) {
      _a.stop();
      _b.stop();
    } else if (!_a.isAnimating) {
      _a.repeat(reverse: true);
      _b.repeat(reverse: true);
    }
  }

  @override
  void dispose() {
    _a.dispose();
    _b.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => _DriftScope(drift: _drift, child: widget.child);
}

class _Drift {
  _Drift(this.a, this.b);
  final AnimationController a;
  final AnimationController b;
  Listenable get listenable => Listenable.merge([a, b]);
}

class _DriftScope extends InheritedWidget {
  const _DriftScope({required this.drift, required super.child});
  final _Drift drift;

  @override
  bool updateShouldNotify(_DriftScope old) => old.drift != drift;
}

/// The gradient behind one page (or anything that should sit on it).
class GlowBackdrop extends StatelessWidget {
  const GlowBackdrop({super.key, required this.child});
  final Widget child;

  @override
  Widget build(BuildContext context) {
    final drift = context.dependOnInheritedWidgetOfExactType<_DriftScope>()?.drift;

    return Stack(fit: StackFit.expand, children: [
      const ColoredBox(color: PrimoraBackground.base),
      RepaintBoundary(
        child: drift == null
            ? CustomPaint(painter: _GlowPainter(0, 0))
            : AnimatedBuilder(
                animation: drift.listenable,
                builder: (_, __) => CustomPaint(painter: _GlowPainter(Curves.easeInOut.transform(drift.a.value), Curves.easeInOut.transform(drift.b.value))),
              ),
      ),
      child,
    ]);
  }
}

/// Page transitions that put the gradient under each page, then use the platform's usual animation.
class GlowPageTransitionsBuilder extends PageTransitionsBuilder {
  const GlowPageTransitionsBuilder(this.inner);
  final PageTransitionsBuilder inner;

  @override
  Widget buildTransitions<T>(PageRoute<T> route, BuildContext context, Animation<double> animation, Animation<double> secondaryAnimation, Widget child) =>
      inner.buildTransitions(route, context, animation, secondaryAnimation, GlowBackdrop(child: child));
}

class _GlowPainter extends CustomPainter {
  _GlowPainter(this.a, this.b);
  final double a;
  final double b;

  @override
  void paint(Canvas canvas, Size size) {
    final vmax = math.max(size.width, size.height) / 100;

    void glow(double x, double y, double rx, double ry, Color c, double inner, double middle, double stop, Offset drift) {
      final center = Offset(size.width * x + drift.dx * vmax, size.height * y + drift.dy * vmax);
      final rect = Rect.fromCenter(center: center, width: rx * 2 * vmax, height: ry * 2 * vmax);
      canvas.drawOval(
        rect,
        Paint()
          ..shader = RadialGradient(
            colors: [c.withValues(alpha: inner), c.withValues(alpha: middle), c.withValues(alpha: 0)],
            stops: [0, stop, 0.72],
          ).createShader(rect),
      );
    }

    // As on the web: the first pair drifts left and down by up to 3 / 2.5 vmax, the second right and up.
    final da = Offset(-3 * a, 2.5 * a);
    final db = Offset(3 * b, -2.5 * b);

    glow(0.82, 0.08, 42, 34, const Color(0xFFD68C68), 0.30, 0.12, 0.45, da); // terracotta, top right
    glow(0.06, 0.42, 40, 36, const Color(0xFF6E92E2), 0.24, 0.09, 0.48, da); // blue, left
    glow(0.62, 1.00, 44, 32, const Color(0xFFAA92E8), 0.24, 0.08, 0.48, db); // lavender, bottom
    glow(0.98, 0.62, 30, 26, const Color(0xFFECAABE), 0.18, 0.09, 0.40, db); // pink, right
  }

  @override
  bool shouldRepaint(_GlowPainter old) => old.a != a || old.b != b;
}
