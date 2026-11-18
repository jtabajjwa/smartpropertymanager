import 'package:flutter_test/flutter_test.dart';
import 'package:primora/core/format.dart';

void main() {
  test('money and dates are shown the Primora way', () {
    expect(ugx(500000), 'UGX 500,000');
    expect(amount(1234.6), '1,235');
    expect(date('2026-11-05'), '5 Nov 2026');
    expect(date(null), '—');
    expect(toNum('42'), 42);
  });
}
