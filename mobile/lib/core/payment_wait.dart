import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../app/theme.dart';
import 'api.dart';

/// Waits for the payment on the phone (asks every 4 seconds, for up to 3 minutes); pops true if it went through.
class PaymentWaitSheet extends ConsumerStatefulWidget {
  const PaymentWaitSheet({super.key, required this.statusPath, required this.message, required this.success});

  /// Where the payment's status is polled, e.g. /payments/status/{reference}.
  final String statusPath;
  final String message;

  /// What to say when it went through, from the status response.
  final String Function(Map<String, dynamic> status) success;

  @override
  ConsumerState<PaymentWaitSheet> createState() => _PaymentWaitSheetState();
}

class _PaymentWaitSheetState extends ConsumerState<PaymentWaitSheet> {
  Timer? _poll;
  Map<String, dynamic>? _result;
  bool _gaveUp = false;

  @override
  void initState() {
    super.initState();
    var tries = 0;
    _poll = Timer.periodic(const Duration(seconds: 4), (timer) async {
      tries++;
      try {
        final s = await ref.read(apiProvider).get(widget.statusPath);
        if (s['open'] != true) {
          timer.cancel();
          if (mounted) setState(() => _result = s);
        } else if (tries > 45) {
          timer.cancel();
          if (mounted) setState(() => _gaveUp = true);
        }
      } on ApiException catch (_) {
        // Keep trying; a slow connection shouldn't end the wait.
      }
    });
  }

  @override
  void dispose() {
    _poll?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final r = _result;
    final ok = r?['status'] == 'succeeded';

    final (IconData? icon, Color color, String title, String body) = switch ((r, _gaveUp)) {
      (null, false) => (null, Brand.navy, widget.message, 'Enter your mobile money PIN on the phone. This updates by itself.'),
      (null, true) => (Icons.hourglass_bottom, Brand.amber, 'Still waiting', "We haven't heard back yet. If you approved it, it will show here as soon as the payment arrives."),
      _ when ok => (Icons.check_circle, Brand.green, 'Payment received', widget.success(r!)),
      _ => (Icons.error_outline, Brand.red, 'Payment not completed', '${r!['message'] ?? 'The payment did not go through. You can try again.'}'),
    };

    return Padding(
      padding: const EdgeInsets.fromLTRB(20, 24, 20, 24),
      child: Column(mainAxisSize: MainAxisSize.min, children: [
        if (icon == null) const CircularProgressIndicator() else Icon(icon, color: color, size: 48),
        const SizedBox(height: 16),
        Text(title, textAlign: TextAlign.center, style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w700)),
        const SizedBox(height: 6),
        Text(body, textAlign: TextAlign.center, style: const TextStyle(color: Brand.navyMuted)),
        const SizedBox(height: 20),
        if (icon != null) FilledButton(onPressed: () => Navigator.of(context).pop(ok), style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(46)), child: const Text('Done')),
      ]),
    );
  }
}

