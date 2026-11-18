import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../app/theme.dart';
import '../../core/api.dart';
import '../../core/session.dart';
import '../../core/widgets.dart';

/// Steps the server asks for before the app opens: verify the email, choose a
/// new password, accept the current Terms of Service.
class SetupScreen extends ConsumerStatefulWidget {
  const SetupScreen({super.key});

  @override
  ConsumerState<SetupScreen> createState() => _SetupScreenState();
}

class _SetupScreenState extends ConsumerState<SetupScreen> {
  final _current = TextEditingController();
  final _password = TextEditingController();
  final _confirm = TextEditingController();
  bool _agreed = false;
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

  @override
  Widget build(BuildContext context) {
    final session = ref.watch(sessionProvider);
    final notifier = ref.read(sessionProvider.notifier);

    final Widget body;
    if (session.needsEmailVerification) {
      body = Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        const Text('Verify your email', style: TextStyle(fontSize: 20, fontWeight: FontWeight.w700)),
        const SizedBox(height: 8),
        Text('We sent a link to ${session.user['email']}. Open it, then tap "I\'ve verified".'),
        const SizedBox(height: 20),
        FilledButton(onPressed: _busy ? null : () => _run(notifier.refresh), child: const Text("I've verified")),
        TextButton(
          onPressed: _busy ? null : () => _run(() async {
            final message = await notifier.resendVerification();
            if (context.mounted) showMessage(context, message);
          }),
          child: const Text('Send the link again'),
        ),
      ]);
    } else if (session.needsPasswordChange) {
      body = Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        const Text('Choose a new password', style: TextStyle(fontSize: 20, fontWeight: FontWeight.w700)),
        const SizedBox(height: 8),
        const Text('Your account was set up with a temporary password.'),
        const SizedBox(height: 16),
        TextField(controller: _current, obscureText: true, decoration: const InputDecoration(labelText: 'Current (temporary) password')),
        const SizedBox(height: 12),
        TextField(controller: _password, obscureText: true, decoration: const InputDecoration(labelText: 'New password')),
        const SizedBox(height: 12),
        TextField(controller: _confirm, obscureText: true, decoration: const InputDecoration(labelText: 'Confirm new password')),
        const SizedBox(height: 20),
        FilledButton(onPressed: _busy ? null : () => _run(() => notifier.changePassword(_current.text, _password.text, _confirm.text)), child: const Text('Save password')),
      ]);
    } else {
      body = Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        const Text('Updated Terms of Service', style: TextStyle(fontSize: 20, fontWeight: FontWeight.w700)),
        const SizedBox(height: 8),
        const Text('Please read and accept them to continue.'),
        const SizedBox(height: 12),
        Wrap(spacing: 8, children: [
          OutlinedButton(onPressed: () => launchUrl(Uri.parse('${session.setup['terms_url']}')), child: const Text('Terms of Service')),
          OutlinedButton(onPressed: () => launchUrl(Uri.parse('${session.setup['privacy_url']}')), child: const Text('Privacy Policy')),
        ]),
        CheckboxListTile(
          value: _agreed,
          onChanged: (v) => setState(() => _agreed = v ?? false),
          contentPadding: EdgeInsets.zero,
          controlAffinity: ListTileControlAffinity.leading,
          title: const Text('I agree to the Terms of Service and Privacy Policy'),
        ),
        FilledButton(onPressed: _busy || !_agreed ? null : () => _run(notifier.acceptTerms), child: const Text('Accept and continue')),
      ]);
    }

    return Scaffold(
      appBar: AppBar(title: const Text('Primora'), actions: [
        TextButton(onPressed: notifier.logout, child: const Text('Sign out', style: TextStyle(color: Colors.white))),
      ]),
      body: ListView(padding: const EdgeInsets.all(20), children: [
        body,
        if (_error != null) Padding(padding: const EdgeInsets.only(top: 12), child: Text(_error!, style: const TextStyle(color: Brand.red))),
      ]),
    );
  }
}
