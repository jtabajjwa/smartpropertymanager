import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../app/theme.dart';
import '../../core/api.dart';
import '../../core/logo.dart';
import '../../core/session.dart';

enum _Step { identify, choose, code, account }

/// Tenant sign-in, as on the web tenant portal: phone number, email or unit
/// ID → a 6-digit code by SMS or email → (pick a tenancy if the phone is on
/// several) → signed in. No password.
class TenantLoginScreen extends ConsumerStatefulWidget {
  const TenantLoginScreen({super.key});

  @override
  ConsumerState<TenantLoginScreen> createState() => _TenantLoginScreenState();
}

class _TenantLoginScreenState extends ConsumerState<TenantLoginScreen> {
  final _identifier = TextEditingController();
  final _code = TextEditingController();
  _Step _step = _Step.identify;
  bool _busy = false;
  String? _error;
  String? _notice;

  // Tickets from the server for the next step.
  String? _choiceTicket;
  String? _loginTicket;
  String? _accountTicket;
  List<String> _choices = [];
  List<Map<String, dynamic>> _accounts = [];
  String _sentTo = '';

  @override
  void dispose() {
    _identifier.dispose();
    _code.dispose();
    super.dispose();
  }

  ApiClient get _api => ref.read(apiProvider);

  Future<void> _run(Future<void> Function() action) async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await action();
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  void _codeSent(Map<String, dynamic> r) => setState(() {
        _loginTicket = '${r['login_ticket']}';
        _sentTo = '${r['sent_to'] ?? ''}';
        _notice = '${r['message'] ?? ''}';
        _code.clear();
        _step = _Step.code;
      });

  Future<void> _lookup() => _run(() async {
        if (_identifier.text.trim().isEmpty) throw ApiException('Enter your phone number, email address or unit ID.');
        final r = await _api.post('/tenant/auth/lookup', data: {'identifier': _identifier.text.trim()});
        if (r['choices'] != null) {
          setState(() {
            _choiceTicket = '${r['choice_ticket']}';
            _choices = (r['choices'] as List).map((e) => '$e').toList();
            _step = _Step.choose;
          });
        } else {
          _codeSent(r);
        }
      });

  Future<void> _choose(int i) => _run(() async => _codeSent(await _api.post('/tenant/auth/choose', data: {'choice_ticket': _choiceTicket, 'choice': i})));

  Future<void> _resend() => _run(() async {
        final r = await _api.post('/tenant/auth/resend', data: {'login_ticket': _loginTicket});
        setState(() => _notice = '${r['message']}');
      });

  Future<void> _verify() => _run(() async {
        final session = ref.read(sessionProvider.notifier);
        final r = await _api.post('/tenant/auth/verify', data: {'login_ticket': _loginTicket, 'code': _code.text.trim(), 'device_name': session.deviceName});
        if (r['token'] != null) {
          await session.tenantSignedIn(r);
        } else {
          setState(() {
            _accountTicket = '${r['account_ticket']}';
            _accounts = (r['accounts'] as List).cast<Map<String, dynamic>>();
            _step = _Step.account;
          });
        }
      });

  Future<void> _select(int tenantId) => _run(() async {
        final session = ref.read(sessionProvider.notifier);
        await session.tenantSignedIn(await _api.post('/tenant/auth/select', data: {'account_ticket': _accountTicket, 'tenant_id': tenantId, 'device_name': session.deviceName}));
      });

  void _restart() => setState(() {
        _step = _Step.identify;
        _error = null;
        _notice = null;
      });

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: Brand.navy,
      appBar: AppBar(
        backgroundColor: Brand.navy,
        foregroundColor: Colors.white,
        leading: BackButton(onPressed: () => _step == _Step.identify ? context.go('/login') : _restart()),
      ),
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.fromLTRB(24, 0, 24, 24),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 420),
              child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                const Center(child: PrimoraLogo(size: 44, dark: true)),
                const SizedBox(height: 24),
                Card(
                  child: Padding(
                    padding: const EdgeInsets.all(20),
                    child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                      ..._body(),
                      if (_error != null) ...[
                        const SizedBox(height: 12),
                        Text(_error!, style: const TextStyle(color: Brand.red)),
                      ],
                    ]),
                  ),
                ),
              ]),
            ),
          ),
        ),
      ),
    );
  }

  Widget _button(String label, VoidCallback onPressed) => FilledButton(
        onPressed: _busy ? null : onPressed,
        child: _busy ? const SizedBox(height: 20, width: 20, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white)) : Text(label),
      );

  List<Widget> _body() => switch (_step) {
        _Step.identify => [
            const Text('Tenant sign in', style: TextStyle(fontSize: 18, fontWeight: FontWeight.w700)),
            const SizedBox(height: 6),
            const Text("We'll send you a 6-digit code. No password needed.", style: TextStyle(color: Brand.navyMuted)),
            const SizedBox(height: 16),
            TextField(
              controller: _identifier,
              keyboardType: TextInputType.emailAddress,
              autofillHints: const [AutofillHints.telephoneNumber, AutofillHints.email],
              onSubmitted: (_) => _lookup(),
              decoration: const InputDecoration(labelText: 'Phone number, email or unit ID', helperText: 'Your unit ID is on your invoices.'),
            ),
            const SizedBox(height: 20),
            _button('Send code', _lookup),
          ],
        _Step.choose => [
            const Text('Which is yours?', style: TextStyle(fontSize: 18, fontWeight: FontWeight.w700)),
            const SizedBox(height: 6),
            const Text('More than one tenant lives in this unit. Choose where to send your code.', style: TextStyle(color: Brand.navyMuted)),
            const SizedBox(height: 12),
            for (final (i, c) in _choices.indexed)
              ListTile(
                contentPadding: EdgeInsets.zero,
                leading: Icon(c.contains('@') ? Icons.email_outlined : Icons.sms_outlined),
                title: Text(c),
                trailing: const Icon(Icons.chevron_right),
                enabled: !_busy,
                onTap: () => _choose(i),
              ),
          ],
        _Step.code => [
            const Text('Enter your code', style: TextStyle(fontSize: 18, fontWeight: FontWeight.w700)),
            const SizedBox(height: 6),
            Text(_notice?.isNotEmpty == true ? _notice! : 'We sent a code to $_sentTo.', style: const TextStyle(color: Brand.navyMuted)),
            const SizedBox(height: 16),
            TextField(
              controller: _code,
              autofocus: true,
              keyboardType: TextInputType.number,
              autofillHints: const [AutofillHints.oneTimeCode],
              maxLength: 6,
              textAlign: TextAlign.center,
              style: const TextStyle(fontSize: 24, letterSpacing: 8, fontWeight: FontWeight.w600),
              onChanged: (v) {
                if (v.trim().length == 6 && !_busy) _verify();
              },
              decoration: const InputDecoration(counterText: '', hintText: '••••••'),
            ),
            const SizedBox(height: 16),
            _button('Sign in', _verify),
            TextButton(onPressed: _busy ? null : _resend, child: const Text('Send a new code')),
          ],
        _Step.account => [
            const Text('Choose an account', style: TextStyle(fontSize: 18, fontWeight: FontWeight.w700)),
            const SizedBox(height: 6),
            const Text('Your number is on more than one tenancy. You can switch later.', style: TextStyle(color: Brand.navyMuted)),
            const SizedBox(height: 12),
            for (final a in _accounts)
              ListTile(
                contentPadding: EdgeInsets.zero,
                leading: const Icon(Icons.home_work_outlined),
                title: Text('${a['landlord'] ?? a['name']}'),
                subtitle: Text(((a['units'] as List?) ?? const []).join(', ')),
                trailing: const Icon(Icons.chevron_right),
                enabled: !_busy,
                onTap: () => _select((a['id'] as num).toInt()),
              ),
          ],
      };
}
