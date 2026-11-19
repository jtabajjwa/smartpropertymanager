import 'package:flutter/material.dart';

import '../app/theme.dart';

/// Primora's mark (roof, terracotta chevron, door), drawn from the same
/// 120×120 geometry as the web favicon, so it's sharp at any size.
/// On dark backgrounds pass `dark: true`: the navy parts turn white.
class PrimoraMark extends StatelessWidget {
  const PrimoraMark({super.key, this.size = 40, this.dark = false});

  final double size;
  final bool dark;

  @override
  Widget build(BuildContext context) =>
      SizedBox.square(dimension: size, child: CustomPaint(painter: _MarkPainter(dark ? Colors.white : Brand.navy)));
}

class _MarkPainter extends CustomPainter {
  _MarkPainter(this.ink);
  final Color ink;

  @override
  void paint(Canvas canvas, Size size) {
    final u = size.width / 120;
    Paint stroke(Color c, double w) => Paint()
      ..color = c
      ..style = PaintingStyle.stroke
      ..strokeWidth = w * u
      ..strokeCap = StrokeCap.square
      ..strokeJoin = StrokeJoin.miter;
    Path line(List<double> p) => Path()
      ..moveTo(p[0] * u, p[1] * u)
      ..lineTo(p[2] * u, p[3] * u)
      ..lineTo(p[4] * u, p[5] * u);

    canvas.drawPath(line([15, 55, 60, 15, 105, 55]), stroke(ink, 11));
    canvas.drawPath(line([35, 72, 60, 50, 85, 72]), stroke(Brand.orange, 10));
    canvas.drawRRect(RRect.fromLTRBR(54 * u, 83 * u, 66 * u, 100 * u, Radius.circular(u)), Paint()..color = ink);
  }

  @override
  bool shouldRepaint(_MarkPainter old) => old.ink != ink;
}

/// The lockup: mark + "Primora" + PROPTECH, as on the website.
class PrimoraLogo extends StatelessWidget {
  const PrimoraLogo({super.key, this.size = 40, this.dark = false});

  final double size;
  final bool dark;

  @override
  Widget build(BuildContext context) {
    return Row(mainAxisSize: MainAxisSize.min, children: [
      PrimoraMark(size: size, dark: dark),
      SizedBox(width: size * 0.28),
      Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text('Primora',
            style: TextStyle(fontSize: size * 0.62, height: 1, fontWeight: FontWeight.w700, letterSpacing: -0.4, color: dark ? Colors.white : Brand.navy)),
        SizedBox(height: size * 0.08),
        Text('PROPTECH',
            style: TextStyle(fontSize: size * 0.24, height: 1, fontWeight: FontWeight.w600, letterSpacing: size * 0.07,
                color: dark ? const Color(0xFFDAA47E) : Brand.orange)),
      ]),
    ]);
  }
}
