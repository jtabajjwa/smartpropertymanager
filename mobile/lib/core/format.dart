import 'package:intl/intl.dart';

final _money = NumberFormat.decimalPattern('en_US');
final _date = DateFormat('d MMM yyyy');

/// "UGX 500,000".
String ugx(num? value) => 'UGX ${_money.format((value ?? 0).round())}';

/// "500,000".
String amount(num? value) => _money.format((value ?? 0).round());

/// "5 Nov 2026" from "2026-11-05".
String date(String? iso) {
  if (iso == null || iso.isEmpty) return '—';
  final parsed = DateTime.tryParse(iso);
  return parsed == null ? iso : _date.format(parsed);
}

String isoDate(DateTime d) => DateFormat('yyyy-MM-dd').format(d);

num toNum(Object? v) => v is num ? v : num.tryParse('$v') ?? 0;
