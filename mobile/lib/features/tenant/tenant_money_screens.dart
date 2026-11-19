import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../app/theme.dart';
import '../../core/api.dart';
import '../../core/format.dart';
import '../../core/paged.dart';
import '../../core/payment_wait.dart';
import '../../core/session.dart';
import '../../core/widgets.dart';
import 'tenant_home_screen.dart';

StatusChip _invoiceChip(Map<String, dynamic> i) {
  if (i['overdue'] == true) return const StatusChip('Overdue', color: Brand.red);
  return switch (i['status']) {
    'paid' => const StatusChip('Paid', color: Brand.green),
    'partial' => const StatusChip('Part paid', color: Brand.amber),
    _ => const StatusChip('Unpaid', color: Brand.orange),
  };
}

/// Every bill from the landlord; tap for the PDF or to pay it.
class TenantInvoicesScreen extends ConsumerWidget {
  const TenantInvoicesScreen({super.key});

  void _open(BuildContext context, WidgetRef ref, Map<String, dynamic> i) {
    final canPay = ref.read(sessionProvider).profile['can_pay'] == true && toNum(i['balance']) > 0;
    showModalBottomSheet<void>(
      context: context,
      builder: (sheet) => SafeArea(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(16, 16, 16, 8),
          child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            Text('${i['title']}', style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w700)),
            Text('${i['number']}${i['unit'] != null ? ' · ${i['unit']}' : ''}', style: const TextStyle(color: Brand.navyMuted)),
            const SizedBox(height: 12),
            InfoRow('Amount', ugx(toNum(i['amount']))),
            InfoRow('Paid', ugx(toNum(i['paid']))),
            InfoRow('Still due', ugx(toNum(i['balance'])), bold: true),
            InfoRow('Due date', date(i['due_date'] as String?)),
            const SizedBox(height: 16),
            if (canPay)
              FilledButton(
                onPressed: () {
                  Navigator.of(sheet).pop();
                  context.push('/t/pay?invoice=${i['id']}');
                },
                child: Text('Pay ${ugx(toNum(i['balance']))}'),
              ),
            TextButton.icon(
              onPressed: () {
                Navigator.of(sheet).pop();
                openTenantDocument(context, ref, '/tenant/invoices/${i['id']}/pdf', 'invoice-${i['number']}.pdf');
              },
              icon: const Icon(Icons.picture_as_pdf_outlined),
              label: const Text('Invoice (PDF)'),
            ),
          ]),
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) => Scaffold(
        appBar: AppBar(title: const Text('Bills')),
        body: PagedList(
          path: '/tenant/invoices',
          emptyIcon: Icons.receipt_long_outlined,
          emptyMessage: 'No bills yet.',
          itemBuilder: (context, i) => Card(
            child: ListTile(
              title: Text('${i['title']}'),
              subtitle: Text('${i['number']} · due ${date(i['due_date'] as String?)}${i['unit'] != null ? '\n${i['unit']}' : ''}'),
              isThreeLine: i['unit'] != null,
              trailing: Column(mainAxisAlignment: MainAxisAlignment.center, crossAxisAlignment: CrossAxisAlignment.end, children: [
                Text(ugx(toNum(i['amount'])), style: const TextStyle(fontWeight: FontWeight.w600)),
                const SizedBox(height: 4),
                _invoiceChip(i),
              ]),
              onTap: () => _open(context, ref, i),
            ),
          ),
        ),
      );
}

final _tenantPaymentsHeaderProvider = FutureProvider.autoDispose<Map<String, dynamic>>((ref) => ref.read(apiProvider).get('/tenant/payments'));

/// Payments recorded by the landlord (with receipts), credit, and mobile money payments still going through.
class TenantPaymentsScreen extends ConsumerWidget {
  const TenantPaymentsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final header = ref.watch(_tenantPaymentsHeaderProvider).value;
    final pending = ((header?['pending'] as List?) ?? const []).cast<Map<String, dynamic>>();
    final credit = toNum(header?['credit']);

    return Scaffold(
      appBar: AppBar(title: const Text('Payments')),
      body: PagedList(
        path: '/tenant/payments',
        emptyIcon: Icons.payments_outlined,
        emptyMessage: 'No payments yet.',
        header: credit > 0 || pending.isNotEmpty
            ? Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: spaced([
                if (credit > 0)
                  Card(child: ListTile(leading: const Icon(Icons.savings_outlined, color: Brand.green), title: Text('${ugx(credit)} credit'), subtitle: const Text('Paid in advance; it goes towards your next bills.'))),
                for (final t in pending)
                  Card(
                    child: ListTile(
                      leading: Icon(t['open'] == true ? Icons.hourglass_top : Icons.error_outline, color: t['open'] == true ? Brand.amber : Brand.red),
                      title: Text('${ugx(toNum(t['amount']))} by mobile money'),
                      subtitle: Text('${t['phone']} · ${t['open'] == true ? 'waiting for approval' : (t['message'] ?? 'did not go through')}'),
                    ),
                  ),
                const SizedBox.shrink(),
              ]))
            : null,
        itemBuilder: (context, p) => Card(
          child: ListTile(
            title: Text(ugx(toNum(p['amount'])), style: const TextStyle(fontWeight: FontWeight.w600)),
            subtitle: Text('${date(p['payment_date'] as String?)} · ${p['method']}${p['for'] != null ? '\nFor ${p['for']}' : ''}'),
            isThreeLine: p['for'] != null,
            trailing: IconButton(
              tooltip: 'Receipt',
              icon: const Icon(Icons.picture_as_pdf_outlined),
              onPressed: () => openTenantDocument(context, ref, '/tenant/payments/${p['id']}/receipt', 'receipt-${p['number'] ?? p['id']}.pdf'),
            ),
          ),
        ),
      ),
    );
  }
}

final _payOptionsProvider = FutureProvider.autoDispose<Map<String, dynamic>>((ref) => ref.read(apiProvider).get('/tenant/pay'));

/// Pay a bill (all or part of it) or in advance by mobile money; shows the collection fee when the landlord passes it on.
class TenantPayScreen extends ConsumerStatefulWidget {
  const TenantPayScreen({super.key, this.invoiceId});
  final int? invoiceId;

  @override
  ConsumerState<TenantPayScreen> createState() => _TenantPayScreenState();
}

class _TenantPayScreenState extends ConsumerState<TenantPayScreen> {
  static const _advance = -1;
  int? _target;
  final _amount = TextEditingController();
  TextEditingController? _phone;
  Map<String, dynamic>? _quote;
  Timer? _debounce;
  String? _error;
  bool _busy = false;

  @override
  void dispose() {
    _debounce?.cancel();
    _amount.dispose();
    _phone?.dispose();
    super.dispose();
  }

  void _init(Map<String, dynamic> o) {
    if (_phone != null) return;
    _phone = TextEditingController(text: '${o['default_phone'] ?? ''}');
    final open = (o['open_invoices'] as List).cast<Map<String, dynamic>>();
    final chosen = open.where((i) => i['id'] == widget.invoiceId).firstOrNull ?? open.firstOrNull;
    _pick(chosen, open.isEmpty);
  }

  void _pick(Map<String, dynamic>? invoice, bool advance) {
    _target = advance || invoice == null ? _advance : (invoice['id'] as num).toInt();
    _amount.text = invoice == null || advance ? '' : '${toNum(invoice['balance']).round()}';
    _requote();
  }

  void _requote() {
    _debounce?.cancel();
    final amount = num.tryParse(_amount.text.replaceAll(',', '').trim());
    if (amount == null || amount <= 0) {
      setState(() => _quote = null);
      return;
    }
    _debounce = Timer(const Duration(milliseconds: 400), () async {
      try {
        final q = await ref.read(apiProvider).get('/tenant/pay/quote', query: {'amount': amount});
        if (mounted) setState(() => _quote = q);
      } on ApiException catch (_) {
        // The pay button still works; the server checks the amount.
      }
    });
  }

  Future<void> _pay() async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final r = await ref.read(apiProvider).post('/tenant/pay', data: {
        'invoice_id': _target == _advance ? null : _target,
        'amount': _amount.text.replaceAll(',', '').trim(),
        'phone': _phone!.text.trim(),
      });
      if (!mounted) return;
      final ok = await showModalBottomSheet<bool>(
        context: context,
        isDismissible: false,
        enableDrag: false,
        builder: (_) => PaymentWaitSheet(
          statusPath: '/tenant/pay/${r['reference']}',
          message: '${r['message']}',
          success: (s) => 'Thank you! ${ugx(toNum(s['principal']))} has been paid to your landlord${s['receipt'] != null ? ' (ref ${s['receipt']})' : ''}.',
        ),
      );
      ref.invalidate(tenantDashboardProvider);
      if (mounted && ok == true) context.pop();
    } on ApiException catch (e) {
      setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Pay')),
      body: AsyncBody(
        value: ref.watch(_payOptionsProvider),
        onRefresh: () => ref.refresh(_payOptionsProvider.future),
        builder: (o) {
          if (o['enabled'] != true) {
            return ListView(padding: const EdgeInsets.all(24), children: [
              const Icon(Icons.phonelink_erase_outlined, size: 44, color: Brand.navyMuted),
              const SizedBox(height: 12),
              Text('${o['reason'] ?? 'Mobile money payments are unavailable right now. Please try again later.'}', textAlign: TextAlign.center),
            ]);
          }
          _init(o);
          final open = (o['open_invoices'] as List).cast<Map<String, dynamic>>();
          final fee = toNum(_quote?['fee']);
          final passFee = o['pass_fee'] == true;

          return ListView(padding: const EdgeInsets.all(16), children: [
            const Text('What are you paying for?', style: TextStyle(fontWeight: FontWeight.w700)),
            RadioGroup<int>(
              groupValue: _target,
              onChanged: (v) => setState(() => _pick(open.where((i) => i['id'] == v).firstOrNull, v == _advance)),
              child: Column(children: [
                for (final i in open)
                  RadioListTile<int>(
                    value: (i['id'] as num).toInt(),
                    contentPadding: EdgeInsets.zero,
                    title: Text('${i['title']} · ${ugx(toNum(i['balance']))}'),
                    subtitle: Text('${i['number']} · due ${date(i['due_date'] as String?)}${i['overdue'] == true ? ' (overdue)' : ''}'),
                  ),
                const RadioListTile<int>(
                  value: _advance,
                  contentPadding: EdgeInsets.zero,
                  title: Text('Pay in advance'),
                  subtitle: Text('Kept as credit towards your next bills.'),
                ),
              ]),
            ),
            const SizedBox(height: 8),
            TextField(
              controller: _amount,
              keyboardType: TextInputType.number,
              onChanged: (_) => _requote(),
              decoration: InputDecoration(labelText: 'Amount (UGX)', helperText: 'From ${amount(toNum(o['min']))} up to ${amount(toNum(o['max']))}'),
            ),
            const SizedBox(height: 12),
            TextField(controller: _phone, keyboardType: TextInputType.phone, decoration: const InputDecoration(labelText: 'Pay from (MTN or Airtel number)')),
            if (_quote != null && passFee && fee > 0) ...[
              const SizedBox(height: 12),
              SectionCard(children: [
                InfoRow('Towards rent', ugx(toNum(_quote!['principal']))),
                InfoRow('Mobile money fee', ugx(fee)),
                InfoRow('You pay', ugx(toNum(_quote!['charge'])), bold: true),
              ]),
            ],
            if (_error != null) Padding(padding: const EdgeInsets.only(top: 10), child: Text(_error!, style: const TextStyle(color: Brand.red))),
            const SizedBox(height: 16),
            FilledButton(
              onPressed: _busy ? null : _pay,
              style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(50)),
              child: Text(_quote == null ? 'Send payment prompt' : 'Pay ${ugx(toNum(_quote!['charge']))}'),
            ),
            const SizedBox(height: 6),
            const Text('A prompt goes to the phone; approve it with your mobile money PIN.', textAlign: TextAlign.center, style: TextStyle(color: Brand.navyMuted, fontSize: 12)),
          ]);
        },
      ),
    );
  }
}

final _depositProvider = FutureProvider.autoDispose<Map<String, dynamic>>((ref) => ref.read(apiProvider).get('/tenant/deposit'));

/// The security deposit: what was paid, each time it was used (with receipts), refunds, and what is left.
class TenantDepositScreen extends ConsumerWidget {
  const TenantDepositScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) => Scaffold(
        appBar: AppBar(title: const Text('Security deposit')),
        body: AsyncBody(
          value: ref.watch(_depositProvider),
          onRefresh: () => ref.refresh(_depositProvider.future),
          builder: (d) {
            final deposits = (d['deposits'] as List).cast<Map<String, dynamic>>();
            if (deposits.isEmpty) return const EmptyState(icon: Icons.savings_outlined, message: 'No security deposit on your tenancy.');

            return ListView(padding: const EdgeInsets.all(16), children: spaced(gap: 8, [
              for (final dep in deposits) ...[
                SectionCard(title: '${(dep['lease'] as Map)['unit'] ?? 'Deposit'}', children: [
                  InfoRow('Agreed', ugx(toNum(dep['agreed']))),
                  InfoRow('Paid', ugx(toNum(dep['paid']))),
                  if (toNum(dep['used']) > 0) InfoRow('Used', ugx(toNum(dep['used']))),
                  if (toNum(dep['refunded']) > 0) InfoRow('Refunded to you', ugx(toNum(dep['refunded']))),
                  if (toNum(dep['kept']) > 0) InfoRow('Kept by landlord', ugx(toNum(dep['kept']))),
                  InfoRow('Left', ugx(toNum(dep['balance'])), bold: true),
                ]),
                for (final e in (dep['entries'] as List).cast<Map<String, dynamic>>())
                  Card(
                    child: ListTile(
                      title: Text('${e['label']}'),
                      subtitle: Text([date(e['date'] as String?), if (e['notes'] != null && e['notes'] != e['label']) '${e['notes']}'].join(' · ')),
                      onTap: e['attachment_url'] == null ? null : () => launchAttachment(context, '${e['attachment_url']}'),
                      trailing: Column(mainAxisAlignment: MainAxisAlignment.center, crossAxisAlignment: CrossAxisAlignment.end, children: [
                        Text('${e['in'] == true ? '+' : '−'}${amount(toNum(e['amount']))}',
                            style: TextStyle(fontWeight: FontWeight.w600, color: e['in'] == true ? Brand.green : Brand.red)),
                        Text('Left ${amount(toNum(e['balance']))}', style: const TextStyle(fontSize: 11, color: Brand.navyMuted)),
                      ]),
                    ),
                  ),
                const SizedBox(height: 4),
              ],
            ]));
          },
        ),
      );
}
