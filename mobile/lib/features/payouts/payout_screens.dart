import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/intl.dart';

import '../../app/theme.dart';
import '../../core/api.dart';
import '../../core/format.dart';
import '../../core/widgets.dart';

final payoutsProvider = FutureProvider.autoDispose<Map<String, dynamic>>((ref) => ref.read(apiProvider).get('/payouts'));

String _at(String? iso) {
  final d = DateTime.tryParse(iso ?? '')?.toLocal();
  return d == null ? '' : DateFormat('d MMM yyyy').format(d);
}

Color payoutColor(Object? status) => switch (status) {
      'completed' => Brand.green,
      'failed' || 'rejected' => Brand.red,
      'cancelled' => Brand.navyMuted,
      _ => Brand.amber,
    };

/// Payouts: what Primora holds for you, where it can be paid, and requests to be paid.
class PayoutsScreen extends ConsumerWidget {
  const PayoutsScreen({super.key});

  Future<void> _sheet(BuildContext context, WidgetRef ref, Widget sheet) async {
    await showModalBottomSheet(context: context, isScrollControlled: true, showDragHandle: true, builder: (_) => sheet);
    ref.invalidate(payoutsProvider);
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return Scaffold(
      appBar: AppBar(title: const Text('Payouts')),
      body: AsyncBody(
        value: ref.watch(payoutsProvider),
        onRefresh: () => ref.refresh(payoutsProvider.future),
        builder: (p) {
          if (p['collects'] != true && toNum(p['owed']) <= 0) {
            return ListView(padding: const EdgeInsets.all(24), children: const [
              Icon(Icons.account_balance_wallet_outlined, size: 44, color: Brand.navyMuted),
              SizedBox(height: 12),
              Text('Primora doesn\'t collect rent for you yet, so there\'s nothing to pay out. Ask Primora to switch on mobile money rent collection.',
                  textAlign: TextAlign.center),
            ]);
          }
          final accounts = (p['accounts'] as List).cast<Map<String, dynamic>>();
          final payouts = (p['payouts'] as List).cast<Map<String, dynamic>>();
          final usable = accounts.where((a) => a['usable'] == true).toList();
          final pendingPayout = (p['pending_payout'] as Map?)?.cast<String, dynamic>();
          final pendingAccount = (p['pending_account'] as Map?)?.cast<String, dynamic>();

          return ListView(padding: const EdgeInsets.all(16), children: [
            Card(
              color: Brand.navy,
              child: Padding(
                padding: const EdgeInsets.all(18),
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  const Text('Available to pay out', style: TextStyle(color: Color(0xFFC9CCD4))),
                  const SizedBox(height: 4),
                  Text(ugx(toNum(p['available'])), style: const TextStyle(color: Colors.white, fontSize: 28, fontWeight: FontWeight.w800)),
                  Text('Held for you ${ugx(toNum(p['owed']))}${toNum(p['reserved']) > 0 ? ' · ${ugx(toNum(p['reserved']))} in requests' : ''}',
                      style: const TextStyle(color: Color(0xFF9BA0AE), fontSize: 12)),
                  const SizedBox(height: 12),
                  FilledButton.icon(
                    style: FilledButton.styleFrom(minimumSize: const Size(0, 42)),
                    onPressed: usable.isEmpty || toNum(p['available']) < toNum(p['min'])
                        ? null
                        : () => _sheet(context, ref, RequestPayoutSheet(overview: p, accounts: usable)),
                    icon: const Icon(Icons.call_made),
                    label: const Text('Request a payout'),
                  ),
                  if (usable.isEmpty)
                    const Padding(
                      padding: EdgeInsets.only(top: 6),
                      child: Text('Add a payout account first; Primora verifies it before it can be used.', style: TextStyle(color: Color(0xFFDAA47E), fontSize: 12)),
                    ),
                ]),
              ),
            ),
            if (pendingPayout != null || pendingAccount != null) ...[
              const SizedBox(height: 12),
              Card(
                color: Brand.orangeLight,
                child: ListTile(
                  leading: const Icon(Icons.password, color: Brand.orange),
                  title: Text(pendingPayout != null ? 'Confirm your payout of ${ugx(toNum(pendingPayout['amount']))}' : 'Confirm the new account'),
                  subtitle: Text('Enter the code sent to ${p['code_destinations']}'),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: () => _sheet(context, ref, CodeSheet(
                    kind: pendingPayout != null ? CodeKind.payout : CodeKind.account,
                    what: '${(pendingPayout ?? pendingAccount)!['summary']}',
                    destinations: '${p['code_destinations']}',
                  )),
                ),
              ),
            ],
            const SizedBox(height: 12),
            SectionCard(
              title: 'Payout accounts',
              trailing: TextButton(onPressed: () => _sheet(context, ref, AddAccountSheet(overview: p)), child: const Text('Add')),
              children: [
                if (accounts.isEmpty) const Padding(padding: EdgeInsets.only(bottom: 10), child: Text('None yet.')),
                for (final a in accounts)
                  ListTile(
                    contentPadding: EdgeInsets.zero,
                    leading: Icon(a['type'] == 'bank' ? Icons.account_balance_outlined : Icons.phone_android, color: Brand.navyMuted),
                    title: Text('${a['summary']}'),
                    subtitle: Text('${a['status_label']}'),
                    trailing: IconButton(
                      tooltip: 'Remove',
                      icon: const Icon(Icons.delete_outline),
                      onPressed: () async {
                        final ok = await showDialog<bool>(
                          context: context,
                          builder: (c) => AlertDialog(
                            title: const Text('Remove this account?'),
                            content: Text('${a['summary']} will no longer receive payouts.'),
                            actions: [
                              TextButton(onPressed: () => Navigator.pop(c, false), child: const Text('Cancel')),
                              TextButton(onPressed: () => Navigator.pop(c, true), child: const Text('Remove', style: TextStyle(color: Brand.red))),
                            ],
                          ),
                        );
                        if (ok != true) return;
                        try {
                          await ref.read(apiProvider).delete('/payouts/accounts/${a['id']}');
                          ref.invalidate(payoutsProvider);
                        } on ApiException catch (e) {
                          if (context.mounted) showMessage(context, e.message, error: true);
                        }
                      },
                    ),
                  ),
                Text('Mobile money numbers: ${p['mobile_in_use']} of ${p['mobile_limit']}', style: const TextStyle(fontSize: 12, color: Brand.navyMuted)),
                const SizedBox(height: 8),
              ],
            ),
            const SizedBox(height: 12),
            SectionCard(title: 'Payouts', children: [
              if (payouts.isEmpty) const Padding(padding: EdgeInsets.only(bottom: 10), child: Text('No payouts yet.')),
              for (final l in payouts)
                ListTile(
                  contentPadding: EdgeInsets.zero,
                  title: Text('${ugx(toNum(l['amount']))} → ${l['to']}'),
                  subtitle: Text('${l['reference']} · ${_at(l['requested_at'] as String?)}${toNum(l['fee']) > 0 ? ' · ${amount(toNum(l['net']))} after charges' : ''}'),
                  trailing: l['can_cancel'] == true
                      ? TextButton(
                          onPressed: () async {
                            try {
                              await ref.read(apiProvider).post('/payouts/${l['id']}/cancel');
                              ref.invalidate(payoutsProvider);
                            } on ApiException catch (e) {
                              if (context.mounted) showMessage(context, e.message, error: true);
                            }
                          },
                          child: const Text('Cancel'),
                        )
                      : StatusChip('${l['status_label']}', color: payoutColor(l['status'])),
                ),
            ]),
          ]);
        },
      ),
    );
  }
}

/// Amount and account, with the transfer charge shown as you type; then the password, then the code.
class RequestPayoutSheet extends ConsumerStatefulWidget {
  const RequestPayoutSheet({super.key, required this.overview, required this.accounts});
  final Map<String, dynamic> overview;
  final List<Map<String, dynamic>> accounts;

  @override
  ConsumerState<RequestPayoutSheet> createState() => _RequestPayoutSheetState();
}

class _RequestPayoutSheetState extends ConsumerState<RequestPayoutSheet> {
  late int _accountId = (widget.accounts.first['id'] as num).toInt();
  final _amount = TextEditingController();
  final _note = TextEditingController();
  final _password = TextEditingController();
  Map<String, dynamic>? _quote;
  String? _error;
  bool _busy = false;
  Timer? _debounce;

  @override
  void dispose() {
    _debounce?.cancel();
    super.dispose();
  }

  void _quoteLater() {
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 500), () async {
      final amount = _amount.text.replaceAll(',', '').trim();
      if (amount.isEmpty) return setState(() => _quote = null);
      try {
        final q = await ref.read(apiProvider).post('/payouts/quote', data: {'payout_account_id': _accountId, 'amount': amount});
        if (mounted) setState(() => (_quote = q, _error = null));
      } on ApiException catch (e) {
        if (mounted) setState(() => (_quote = null, _error = e.message));
      }
    });
  }

  Future<void> _submit() async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await ref.read(apiProvider).post('/payouts/request', data: {
        'payout_account_id': _accountId, 'amount': _amount.text.replaceAll(',', '').trim(), 'note': _note.text.trim(), 'password': _password.text,
      });
      if (!mounted) return;
      final account = widget.accounts.firstWhere((a) => a['id'] == _accountId);
      Navigator.of(context).pop();
      await showModalBottomSheet(
        context: context,
        isScrollControlled: true,
        showDragHandle: true,
        builder: (_) => CodeSheet(kind: CodeKind.payout, what: '${account['summary']}', destinations: '${widget.overview['code_destinations']}'),
      );
    } on ApiException catch (e) {
      setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.fromLTRB(16, 0, 16, 16 + MediaQuery.of(context).viewInsets.bottom),
      child: SingleChildScrollView(
        child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          const Text('Request a payout', style: TextStyle(fontSize: 18, fontWeight: FontWeight.w700)),
          Text('Available: ${ugx(toNum(widget.overview['available']))} · smallest ${ugx(toNum(widget.overview['min']))}', style: const TextStyle(color: Brand.navyMuted)),
          const SizedBox(height: 12),
          DropdownButtonFormField<int>(
            initialValue: _accountId,
            isExpanded: true,
            decoration: const InputDecoration(labelText: 'Pay to'),
            items: [for (final a in widget.accounts) DropdownMenuItem(value: (a['id'] as num).toInt(), child: Text('${a['summary']}', overflow: TextOverflow.ellipsis))],
            onChanged: (v) {
              setState(() => _accountId = v!);
              _quoteLater();
            },
          ),
          const SizedBox(height: 12),
          TextField(controller: _amount, keyboardType: TextInputType.number, onChanged: (_) => _quoteLater(), decoration: const InputDecoration(labelText: 'Amount (UGX)')),
          if (_quote != null)
            Padding(
              padding: const EdgeInsets.only(top: 6, left: 4),
              child: Text('Transfer charge ${ugx(toNum(_quote!['fee']))} · you receive ${ugx(toNum(_quote!['net']))}', style: const TextStyle(color: Brand.green, fontWeight: FontWeight.w600)),
            ),
          const SizedBox(height: 12),
          TextField(controller: _note, decoration: const InputDecoration(labelText: 'Note for Primora (optional)')),
          const SizedBox(height: 12),
          TextField(controller: _password, obscureText: true, onChanged: (_) => setState(() {}),
              decoration: const InputDecoration(labelText: 'Your password', helperText: 'Then we send you a code to confirm')),
          if (_error != null) Padding(padding: const EdgeInsets.only(top: 10), child: Text(_error!, style: const TextStyle(color: Brand.red))),
          const SizedBox(height: 16),
          FilledButton(onPressed: _busy || _quote == null || _password.text.isEmpty ? null : _submit, child: const Text('Continue')),
        ]),
      ),
    );
  }
}

/// A new mobile money number or bank account; then the password, then the code. Primora verifies it before use.
class AddAccountSheet extends ConsumerStatefulWidget {
  const AddAccountSheet({super.key, required this.overview});
  final Map<String, dynamic> overview;

  @override
  ConsumerState<AddAccountSheet> createState() => _AddAccountSheetState();
}

class _AddAccountSheetState extends ConsumerState<AddAccountSheet> {
  String _type = 'mobile_money';
  final _c = {for (final f in ['account_name', 'msisdn', 'bank_name', 'bank_branch', 'account_number', 'swift_code', 'password']) f: TextEditingController()};
  Map<String, String> _errors = {};
  String? _error;
  bool _busy = false;

  Future<void> _submit() async {
    setState(() {
      _busy = true;
      _errors = {};
      _error = null;
    });
    try {
      final r = await ref.read(apiProvider).post('/payouts/accounts', data: {'type': _type, for (final e in _c.entries) e.key: e.value.text.trim()});
      if (!mounted) return;
      Navigator.of(context).pop();
      await showModalBottomSheet(
        context: context,
        isScrollControlled: true,
        showDragHandle: true,
        builder: (_) => CodeSheet(kind: CodeKind.account, what: '${(r['pending'] as Map)['summary']}', destinations: '${widget.overview['code_destinations']}'),
      );
    } on ApiException catch (e) {
      setState(() => e.fieldErrors.isEmpty || e.fieldErrors.containsKey('request') ? _error = e.message : _errors = e.fieldErrors);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Widget _field(String key, String label, {TextInputType? keyboard, bool obscure = false}) => Padding(
        padding: const EdgeInsets.only(bottom: 12),
        child: TextField(controller: _c[key], keyboardType: keyboard, obscureText: obscure, decoration: InputDecoration(labelText: label, errorText: _errors[key])),
      );

  @override
  Widget build(BuildContext context) {
    final mobileFull = toNum(widget.overview['mobile_in_use']) >= toNum(widget.overview['mobile_limit']);

    return Padding(
      padding: EdgeInsets.fromLTRB(16, 0, 16, 16 + MediaQuery.of(context).viewInsets.bottom),
      child: SingleChildScrollView(
        child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          const Text('Add a payout account', style: TextStyle(fontSize: 18, fontWeight: FontWeight.w700)),
          const SizedBox(height: 12),
          SegmentedButton<String>(
            segments: const [ButtonSegment(value: 'mobile_money', label: Text('Mobile money')), ButtonSegment(value: 'bank', label: Text('Bank account'))],
            selected: {_type},
            onSelectionChanged: (s) => setState(() => _type = s.first),
          ),
          const SizedBox(height: 12),
          if (_type == 'mobile_money' && mobileFull)
            Padding(
              padding: const EdgeInsets.only(bottom: 12),
              child: Text('You already have ${widget.overview['mobile_limit']} mobile money numbers. Remove one first.', style: const TextStyle(color: Brand.red)),
            ),
          _field('account_name', 'Name on the account'),
          if (_type == 'mobile_money') _field('msisdn', 'MTN or Airtel number', keyboard: TextInputType.phone),
          if (_type == 'bank') ...[
            _field('bank_name', 'Bank'),
            _field('bank_branch', 'Branch (optional)'),
            _field('account_number', 'Account number'),
            _field('swift_code', 'SWIFT code (optional)'),
          ],
          _field('password', 'Your password', obscure: true),
          if (_error != null) Padding(padding: const EdgeInsets.only(bottom: 10), child: Text(_error!, style: const TextStyle(color: Brand.red))),
          FilledButton(onPressed: _busy ? null : _submit, child: const Text('Continue')),
        ]),
      ),
    );
  }
}

enum CodeKind { payout, account }

/// The second step: the code sent to the user's email and phone.
class CodeSheet extends ConsumerStatefulWidget {
  const CodeSheet({super.key, required this.kind, required this.what, required this.destinations});
  final CodeKind kind;
  final String what;
  final String destinations;

  @override
  ConsumerState<CodeSheet> createState() => _CodeSheetState();
}

class _CodeSheetState extends ConsumerState<CodeSheet> {
  final _code = TextEditingController();
  String? _error;
  bool _busy = false;

  String get _base => widget.kind == CodeKind.payout ? '/payouts/request' : '/payouts/accounts';

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

  Future<void> _confirm() => _run(() async {
        final r = await ref.read(apiProvider).post('$_base/confirm', data: {'code': _code.text.trim()});
        ref.invalidate(payoutsProvider);
        if (!mounted) return;
        Navigator.of(context).pop();
        showMessage(context, '${r['message']}');
      });

  Future<void> _resend() => _run(() async {
        final r = await ref.read(apiProvider).post('$_base/resend');
        if (mounted) showMessage(context, '${r['message']}');
      });

  Future<void> _cancel() => _run(() async {
        await ref.read(apiProvider).delete(widget.kind == CodeKind.payout ? '/payouts/request' : '/payouts/accounts/pending');
        ref.invalidate(payoutsProvider);
        if (mounted) Navigator.of(context).pop();
      });

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.fromLTRB(16, 0, 16, 16 + MediaQuery.of(context).viewInsets.bottom),
      child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Text(widget.kind == CodeKind.payout ? 'Confirm your payout' : 'Confirm the new account', style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w700)),
        const SizedBox(height: 4),
        Text('${widget.what}\nWe sent a code to ${widget.destinations}.', style: const TextStyle(color: Brand.navyMuted)),
        const SizedBox(height: 12),
        TextField(
          controller: _code,
          autofocus: true,
          keyboardType: TextInputType.number,
          maxLength: 6,
          textAlign: TextAlign.center,
          style: const TextStyle(fontSize: 24, letterSpacing: 8, fontWeight: FontWeight.w700),
          decoration: const InputDecoration(hintText: '······', counterText: ''),
        ),
        if (_error != null) Padding(padding: const EdgeInsets.only(top: 8), child: Text(_error!, style: const TextStyle(color: Brand.red))),
        const SizedBox(height: 12),
        FilledButton(onPressed: _busy ? null : _confirm, child: const Text('Confirm')),
        Row(children: [
          TextButton(onPressed: _busy ? null : _resend, child: const Text('Send a new code')),
          const Spacer(),
          TextButton(onPressed: _busy ? null : _cancel, child: const Text('Cancel', style: TextStyle(color: Brand.red))),
        ]),
      ]),
    );
  }
}
