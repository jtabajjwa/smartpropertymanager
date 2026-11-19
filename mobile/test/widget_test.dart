import 'package:flutter_test/flutter_test.dart';
import 'package:primora/core/api.dart';
import 'package:primora/core/format.dart';
import 'package:primora/main.dart';

void main() {
  test('money and dates are shown the Primora way', () {
    expect(ugx(500000), 'UGX 500,000');
    expect(amount(1234.6), '1,235');
    expect(date('2026-11-05'), '5 Nov 2026');
    expect(date(null), '—');
    expect(toNum('42'), 42);
  });

  test('only network failures are retried, and only twice; server errors show at once', () {
    final offline = ApiException('Could not reach Primora.');
    final server = ApiException('Server error', status: 500);
    expect(retryOnlyNetworkErrors(0, offline), isNotNull);
    expect(retryOnlyNetworkErrors(1, offline), isNotNull);
    expect(retryOnlyNetworkErrors(2, offline), isNull);
    expect(retryOnlyNetworkErrors(0, server), isNull);
    expect(retryOnlyNetworkErrors(0, StateError('x')), isNull);
  });
}
