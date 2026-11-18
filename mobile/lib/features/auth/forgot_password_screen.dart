import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../app/theme.dart';
import '../../core/api.dart';
import '../../core/widgets.dart';

/// Email → 6-digit code → new password (the same codes as the web).
class ForgotPasswordScreen extends ConsumerStatefulWidget {
  const ForgotPasswordScreen({super.key});

  @override
  ConsumerState<ForgotPasswordScreen> createState() => _ForgotPasswordScreenState();
}

class _ForgotPasswordScreenState extends ConsumerState<ForgotPasswordScreen> {
  final _email = TextEditingController();
  final _code = TextEditingController();
  final _password = TextEditingController();
  final _confirm = TextEditingController();
  bool _codeSent = false;
  bool _busy = false;
  String? _error;

  Future<void> _run(Future<void> Function() action) async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await action();
    } on ApiException catch (e) {
      setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _sendCode() => _run(() async {
        final data = await ref.read(apiProvider).post('/auth/forgot-password', data: {'email': _email.text.trim()});
        setState(() => _codeSent = true);
        if (mounted) showMessage(context, data['message'] as String);
      });

  Future<void> _reset() => _run(() async {
        final data = await ref.read(apiProvider).post('/auth/reset-password', data: {
          'email': _email.text.trim(), 'code': _code.text.trim(), 'password': _password.text, 'password_confirmation': _confirm.text,
        });
        if (!mounted) return;
        showMessage(context, data['message'] as String);
        context.go('/login');
      });

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Reset password')),
      body: ListView(padding: const EdgeInsets.all(20), children: [
        TextField(controller: _email, enabled: !_codeSent, keyboardType: TextInputType.emailAddress, decoration: const InputDecoration(labelText: 'Your email')),
        if (_codeSent) ...[
          const SizedBox(height: 12),
          TextField(controller: _code, keyboardType: TextInputType.number, maxLength: 6, decoration: const InputDecoration(labelText: '6-digit code from the email')),
          TextField(controller: _password, obscureText: true, decoration: const InputDecoration(labelText: 'New password')),
          const SizedBox(height: 12),
          TextField(controller: _confirm, obscureText: true, decoration: const InputDecoration(labelText: 'Confirm new password')),
        ],
        if (_error != null) Padding(padding: const EdgeInsets.only(top: 12), child: Text(_error!, style: const TextStyle(color: Brand.red))),
        const SizedBox(height: 20),
        FilledButton(onPressed: _busy ? null : (_codeSent ? _reset : _sendCode), child: Text(_codeSent ? 'Set new password' : 'Send me a code')),
      ]),
    );
  }
}
