import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';

import '../../app/theme.dart';
import '../../core/api.dart';
import '../../core/format.dart';
import '../../core/paged.dart';
import '../../core/session.dart';
import '../../core/widgets.dart';

final smsOverviewProvider = FutureProvider.autoDispose<Map<String, dynamic>>((ref) => ref.read(apiProvider).get('/sms'));

String _at(String? iso) {
  final d = DateTime.tryParse(iso ?? '')?.toLocal();
  return d == null ? '' : DateFormat('d MMM, HH:mm').format(d);
}

StatusChip smsStatusChip(Object? status, {bool resent = false}) {
  if (resent) return const StatusChip('Resent');
  return switch (status) {
    'sent' => const StatusChip('Sent', color: Brand.green),
    'no_credit' => const StatusChip('No credit', color: Brand.amber),
    'read_only' => const StatusChip('Not sent', color: Brand.amber),
    _ => const StatusChip('Failed', color: Brand.red),
  };
}

/// SMS: credit balance, buying more, resending failures, the log, and bulk SMS.
class SmsScreen extends ConsumerWidget {
  const SmsScreen({super.key});

  Future<void> _resendAll(BuildContext context, WidgetRef ref) async {
    try {
      final r = await ref.read(apiProvider).post('/sms/logs/resend-failed');
      ref.invalidate(smsOverviewProvider);
      if (context.mounted) showMessage(context, '${r['message']}');
    } on ApiException catch (e) {
      if (context.mounted) showMessage(context, e.message, error: true);
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final readOnly = ref.watch(sessionProvider).readOnly;

    return Scaffold(
      appBar: AppBar(title: const Text('SMS')),
      body: AsyncBody(
        value: ref.watch(smsOverviewProvider),
        onRefresh: () => ref.refresh(smsOverviewProvider.future),
        builder: (o) {
          final balance = toNum(o['balance']).toInt();
          final low = balance <= toNum(o['low_threshold']);
          final resendable = (o['resendable'] as Map).cast<String, dynamic>();

          return ListView(padding: const EdgeInsets.all(16), children: [
            Card(
              color: Brand.navy,
              child: Padding(
                padding: const EdgeInsets.all(18),
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  const Text('SMS credit', style: TextStyle(color: Color(0xFFC9CCD4))),
                  const SizedBox(height: 4),
                  Text(NumberFormat.decimalPattern().format(balance),
                      style: TextStyle(color: low ? const Color(0xFFFCA5A5) : Colors.white, fontSize: 32, fontWeight: FontWeight.w800)),
                  Text('${o['used_this_month']} used this month · ${o['sent_today']} sent today', style: const TextStyle(color: Color(0xFF9BA0AE), fontSize: 12)),
                  if (o['can_buy'] == true) ...[
                    const SizedBox(height: 12),
                    FilledButton.icon(
                      style: FilledButton.styleFrom(minimumSize: const Size(0, 42)),
                      onPressed: () async {
                        await showModalBottomSheet(
                          context: context,
                          isScrollControlled: true,
                          showDragHandle: true,
                          builder: (_) => BuySmsSheet(overview: o),
                        );
                        ref.invalidate(smsOverviewProvider);
                      },
                      icon: const Icon(Icons.add_shopping_cart),
                      label: const Text('Buy SMS'),
                    ),
                  ],
                ]),
              ),
            ),
            if (toNum(resendable['count']) > 0) ...[
              const SizedBox(height: 12),
              Card(
                color: const Color(0xFFFEF2F2),
                child: ListTile(
                  leading: const Icon(Icons.sms_failed_outlined, color: Brand.red),
                  title: Text('${resendable['count']} SMS not sent'),
                  subtitle: Text('From the last ${resendable['days']} days · needs ${resendable['credits']} credits to resend'),
                  trailing: readOnly ? null : TextButton(onPressed: () => _resendAll(context, ref), child: const Text('Resend all')),
                ),
              ),
            ],
            const SizedBox(height: 12),
            Card(
              child: Column(children: [
                ListTile(
                  leading: const Icon(Icons.campaign_outlined, color: Brand.orange),
                  title: const Text('Bulk SMS'),
                  subtitle: const Text('One message to all your tenants, personalised'),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: () => context.push('/sms/broadcasts'),
                ),
                ListTile(
                  leading: const Icon(Icons.list_alt, color: Brand.orange),
                  title: const Text('SMS log'),
                  subtitle: const Text('Every SMS sent to tenants'),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: () async {
                    await context.push('/sms/logs');
                    ref.invalidate(smsOverviewProvider);
                  },
                ),
              ]),
            ),
          ]);
        },
      ),
    );
  }
}

/// Choose a bundle and a mobile money number; the payment prompt goes to the
/// phone, and the sheet waits for the result.
class BuySmsSheet extends ConsumerStatefulWidget {
  const BuySmsSheet({super.key, required this.overview});
  final Map<String, dynamic> overview;

  @override
  ConsumerState<BuySmsSheet> createState() => _BuySmsSheetState();
}

class _BuySmsSheetState extends ConsumerState<BuySmsSheet> {
  int? _bundleId;
  late final _phone = TextEditingController(text: '${widget.overview['default_phone'] ?? ''}');
  String? _error;
  String? _waiting;
  bool _busy = false;
  Timer? _poll;

  @override
  void dispose() {
    _poll?.cancel();
    super.dispose();
  }

  Future<void> _buy() async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final r = await ref.read(apiProvider).post('/sms/buy', data: {'bundle_id': _bundleId, 'phone': _phone.text.trim()});
      setState(() => _waiting = '${r['message']}');
      _watch('${r['reference']}');
    } on ApiException catch (e) {
      setState(() {
        _error = e.message;
        _busy = false;
      });
    }
  }

  /// Ask every 4 seconds, for up to 3 minutes.
  void _watch(String reference) {
    var tries = 0;
    _poll = Timer.periodic(const Duration(seconds: 4), (timer) async {
      tries++;
      try {
        final s = await ref.read(apiProvider).get('/payments/status/$reference');
        if (s['open'] != true || tries > 45) {
          timer.cancel();
          if (!mounted) return;
          final ok = s['status'] == 'succeeded';
          Navigator.of(context).pop();
          showMessage(context, ok ? 'Payment received. Your SMS credit is now ${s['sms_balance']}.' : '${s['message'] ?? 'The payment did not go through.'}', error: !ok);
        }
      } on ApiException catch (_) {
        // Keep trying; a slow connection shouldn't end the wait.
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    final bundles = (widget.overview['bundles'] as List).cast<Map<String, dynamic>>();

    return Padding(
      padding: EdgeInsets.fromLTRB(16, 0, 16, 16 + MediaQuery.of(context).viewInsets.bottom),
      child: _waiting != null
          ? Column(mainAxisSize: MainAxisSize.min, children: [
              const SizedBox(height: 16),
              const CircularProgressIndicator(),
              const SizedBox(height: 16),
              Text(_waiting!, textAlign: TextAlign.center, style: const TextStyle(fontWeight: FontWeight.w600)),
              const SizedBox(height: 6),
              const Text('Enter your mobile money PIN on the phone. This updates by itself.', textAlign: TextAlign.center, style: TextStyle(color: Brand.navyMuted)),
              const SizedBox(height: 24),
            ])
          : Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              const Text('Buy SMS', style: TextStyle(fontSize: 18, fontWeight: FontWeight.w700)),
              const SizedBox(height: 8),
              RadioGroup<int>(
                groupValue: _bundleId,
                onChanged: (v) => setState(() => _bundleId = v),
                child: Column(children: [
                  for (final b in bundles)
                    RadioListTile<int>(
                      value: (b['id'] as num).toInt(),
                      contentPadding: EdgeInsets.zero,
                      title: Text('${b['name']} · ${amount(toNum(b['quantity']))} SMS'),
                      subtitle: Text(ugx(toNum(b['price']))),
                    ),
                ]),
              ),
              if (bundles.isEmpty) const Text('No bundles are on sale right now. Contact Primora.'),
              const SizedBox(height: 8),
              TextField(controller: _phone, keyboardType: TextInputType.phone, decoration: const InputDecoration(labelText: 'Pay from (MTN or Airtel number)')),
              if (_error != null) Padding(padding: const EdgeInsets.only(top: 10), child: Text(_error!, style: const TextStyle(color: Brand.red))),
              const SizedBox(height: 16),
              FilledButton(onPressed: _busy || _bundleId == null ? null : _buy, child: const Text('Send payment prompt')),
            ]),
    );
  }
}

/// Every SMS sent to tenants; failed ones can be resent.
class SmsLogScreen extends ConsumerStatefulWidget {
  const SmsLogScreen({super.key});

  @override
  ConsumerState<SmsLogScreen> createState() => _SmsLogScreenState();
}

class _SmsLogScreenState extends ConsumerState<SmsLogScreen> {
  String _status = '';
  final _list = GlobalKey<PagedListState>();

  Future<void> _resend(Map<String, dynamic> log) async {
    try {
      final r = await ref.read(apiProvider).post('/sms/logs/${log['id']}/resend');
      _list.currentState?.reload();
      if (mounted) showMessage(context, '${r['message']}');
    } on ApiException catch (e) {
      if (mounted) showMessage(context, e.message, error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final readOnly = ref.watch(sessionProvider).readOnly;

    return Scaffold(
      appBar: AppBar(title: const Text('SMS log')),
      body: PagedList(
        key: _list,
        path: '/sms/logs',
        query: {'status': _status},
        searchHint: 'Search tenant, phone or message',
        emptyIcon: Icons.sms_outlined,
        emptyMessage: 'No SMS here.',
        header: SizedBox(
          height: 48,
          child: ListView(scrollDirection: Axis.horizontal, padding: const EdgeInsets.symmetric(horizontal: 16), children: [
            for (final f in const {'': 'All', 'failed': 'Not sent', 'sent': 'Sent'}.entries)
              Padding(
                padding: const EdgeInsets.only(right: 8),
                child: ChoiceChip(label: Text(f.value), selected: _status == f.key, selectedColor: Brand.orangeLight, onSelected: (_) => setState(() => _status = f.key)),
              ),
          ]),
        ),
        itemBuilder: (context, l) => Card(
          child: Padding(
            padding: const EdgeInsets.all(12),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Row(children: [
                Expanded(child: Text('${l['tenant'] ?? l['phone']}', style: const TextStyle(fontWeight: FontWeight.w600))),
                smsStatusChip(l['status'], resent: l['resent'] == true),
              ]),
              Text('${l['phone']} · ${_at(l['at'] as String?)}', style: const TextStyle(fontSize: 12, color: Brand.navyMuted)),
              const SizedBox(height: 6),
              Text('${l['message']}'),
              if (l['error'] != null && l['status'] != 'sent')
                Padding(padding: const EdgeInsets.only(top: 4), child: Text('${l['error']}', style: const TextStyle(fontSize: 12, color: Brand.red))),
              if (l['can_resend'] == true && !readOnly)
                Align(alignment: Alignment.centerRight, child: TextButton.icon(onPressed: () => _resend(l), icon: const Icon(Icons.refresh, size: 18), label: const Text('Resend'))),
            ]),
          ),
        ),
      ),
    );
  }
}

/// Bulk SMS sent so far.
class BroadcastsScreen extends ConsumerStatefulWidget {
  const BroadcastsScreen({super.key});

  @override
  ConsumerState<BroadcastsScreen> createState() => _BroadcastsScreenState();
}

class _BroadcastsScreenState extends ConsumerState<BroadcastsScreen> {
  final _list = GlobalKey<PagedListState>();

  @override
  Widget build(BuildContext context) {
    final readOnly = ref.watch(sessionProvider).readOnly;

    return Scaffold(
      appBar: AppBar(title: const Text('Bulk SMS')),
      body: PagedList(
        key: _list,
        path: '/sms/broadcasts',
        emptyIcon: Icons.campaign_outlined,
        emptyMessage: 'No bulk SMS yet. Send your tenants a message.',
        itemBuilder: (context, b) => Card(
          child: ListTile(
            title: Text('${b['message']}', maxLines: 2, overflow: TextOverflow.ellipsis),
            subtitle: Text('${b['audience']} · ${_at(b['at'] as String?)}\n${b['sent']} of ${b['recipient_count']} sent${toNum(b['failed']) > 0 ? ' · ${b['failed']} failed' : ''}'),
            isThreeLine: true,
            onTap: () => context.push('/sms/broadcasts/${b['id']}'),
          ),
        ),
      ),
      floatingActionButton: readOnly
          ? null
          : FloatingActionButton.extended(
              backgroundColor: Brand.orange,
              foregroundColor: Colors.white,
              onPressed: () async {
                final sent = await context.push<bool>('/sms/broadcasts/new');
                if (sent == true) _list.currentState?.reload();
              },
              icon: const Icon(Icons.edit_outlined),
              label: const Text('New message'),
            ),
    );
  }
}

/// Write one message for many tenants, with placeholders filled in per
/// tenant; a live preview shows who it reaches and what it costs.
class BroadcastFormScreen extends ConsumerStatefulWidget {
  const BroadcastFormScreen({super.key});

  @override
  ConsumerState<BroadcastFormScreen> createState() => _BroadcastFormScreenState();
}

class _BroadcastFormScreenState extends ConsumerState<BroadcastFormScreen> {
  final _message = TextEditingController();
  final Set<int> _estates = {};
  bool _owingOnly = false;
  Map<String, dynamic>? _preview;
  List<Map<String, dynamic>> _allEstates = [];
  String? _error;
  bool _busy = false;
  Timer? _debounce;

  @override
  void initState() {
    super.initState();
    ref.read(apiProvider).get('/estates').then((d) => setState(() => _allEstates = ((d['data'] as List?) ?? []).cast<Map<String, dynamic>>())).catchError((_) {});
    _refreshPreview();
  }

  @override
  void dispose() {
    _debounce?.cancel();
    super.dispose();
  }

  Map<String, dynamic> get _payload => {'message': _message.text, 'estates': _estates.toList(), 'owing_only': _owingOnly};

  void _changed() {
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 500), _refreshPreview);
  }

  Future<void> _refreshPreview() async {
    try {
      final p = await ref.read(apiProvider).post('/sms/broadcasts/preview', data: _payload);
      if (mounted) setState(() => _preview = p);
    } on ApiException catch (_) {}
  }

  void _insert(String placeholder) {
    final text = _message.text;
    final sel = _message.selection;
    final at = sel.isValid ? sel.start : text.length;
    final end = sel.isValid ? sel.end : text.length;
    _message.value = TextEditingValue(text: text.replaceRange(at, end, placeholder), selection: TextSelection.collapsed(offset: at + placeholder.length));
    _changed();
  }

  Future<void> _send() async {
    final p = _preview;
    final ok = await showDialog<bool>(
      context: context,
      builder: (c) => AlertDialog(
        title: const Text('Send this message?'),
        content: Text('It goes to ${p?['recipients'] ?? 0} tenants and uses ${p?['credits'] ?? 0} SMS credits.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(c, false), child: const Text('Cancel')),
          FilledButton(style: FilledButton.styleFrom(minimumSize: const Size(0, 40)), onPressed: () => Navigator.pop(c, true), child: const Text('Send')),
        ],
      ),
    );
    if (ok != true) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final b = await ref.read(apiProvider).post('/sms/broadcasts', data: _payload);
      if (!mounted) return;
      showMessage(context, 'Sending to ${b['recipient_count']} tenants.');
      context.pop(true);
    } on ApiException catch (e) {
      setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final overview = ref.watch(smsOverviewProvider);
    final placeholders = overview.hasValue ? (overview.requireValue['placeholders'] as List).cast<Map<String, dynamic>>() : const <Map<String, dynamic>>[];
    final maxLength = overview.hasValue ? toNum(overview.requireValue['max_length']).toInt() : 320;
    final p = _preview;

    return Scaffold(
      appBar: AppBar(title: const Text('New bulk SMS')),
      body: ListView(padding: const EdgeInsets.all(16), children: [
        TextField(
          controller: _message,
          maxLines: 5,
          maxLength: maxLength,
          onChanged: (_) => _changed(),
          decoration: const InputDecoration(labelText: 'Message', hintText: 'e.g. Hi {first_name}, rent for {unit} is due on the 5th. {landlord}', alignLabelWithHint: true),
        ),
        Wrap(spacing: 6, runSpacing: 6, children: [
          for (final ph in placeholders)
            ActionChip(label: Text('${ph['key']}'), tooltip: '${ph['label']}', onPressed: () => _insert('${ph['key']}')),
        ]),
        const SizedBox(height: 16),
        const Text('Send to', style: TextStyle(fontWeight: FontWeight.w700)),
        const SizedBox(height: 6),
        Wrap(spacing: 6, runSpacing: 6, children: [
          FilterChip(
            label: const Text('All properties'),
            selected: _estates.isEmpty,
            selectedColor: Brand.orangeLight,
            onSelected: (_) {
              setState(_estates.clear);
              _changed();
            },
          ),
          for (final e in _allEstates)
            FilterChip(
              label: Text('${e['name']}'),
              selected: _estates.contains(e['id']),
              selectedColor: Brand.orangeLight,
              onSelected: (on) {
                setState(() => on ? _estates.add((e['id'] as num).toInt()) : _estates.remove(e['id']));
                _changed();
              },
            ),
        ]),
        SwitchListTile(
          value: _owingOnly,
          contentPadding: EdgeInsets.zero,
          title: const Text('Only tenants who owe'),
          onChanged: (v) {
            setState(() => _owingOnly = v);
            _changed();
          },
        ),
        if (p != null)
          Card(
            color: p['enough'] == true ? Brand.orangeLight : const Color(0xFFFEF2F2),
            child: Padding(
              padding: const EdgeInsets.all(14),
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text('${p['recipients']} tenants · ${p['credits']} SMS credits (you have ${p['balance']})', style: const TextStyle(fontWeight: FontWeight.w700)),
                if (toNum(p['without_phone']) > 0) Text('${p['without_phone']} tenants have no phone number and are left out.', style: const TextStyle(fontSize: 12)),
                if (p['enough'] != true) const Text('Not enough SMS credit. Buy more SMS first.', style: TextStyle(color: Brand.red)),
                if (p['sample'] != null) ...[
                  const SizedBox(height: 10),
                  Text('${p['sample_to']} will get:', style: const TextStyle(fontSize: 12, color: Brand.navyMuted)),
                  const SizedBox(height: 4),
                  Container(
                    padding: const EdgeInsets.all(10),
                    decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(10)),
                    child: Text('${p['sample']}'),
                  ),
                ],
              ]),
            ),
          ),
        if (_error != null) Padding(padding: const EdgeInsets.only(top: 12), child: Text(_error!, style: const TextStyle(color: Brand.red))),
        const SizedBox(height: 20),
        FilledButton(
          onPressed: _busy || p == null || p['enough'] != true || toNum(p['recipients']) == 0 || _message.text.trim().length < 5 ? null : _send,
          child: const Text('Send'),
        ),
        const SizedBox(height: 24),
      ]),
    );
  }
}

final broadcastProvider = FutureProvider.autoDispose.family<Map<String, dynamic>, int>((ref, id) => ref.read(apiProvider).get('/sms/broadcasts/$id'));

/// One bulk SMS: how far it got, and each tenant's result.
class BroadcastDetailScreen extends ConsumerWidget {
  const BroadcastDetailScreen({super.key, required this.id});
  final int id;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return Scaffold(
      appBar: AppBar(title: const Text('Bulk SMS')),
      body: AsyncBody(
        value: ref.watch(broadcastProvider(id)),
        onRefresh: () => ref.refresh(broadcastProvider(id).future),
        builder: (b) {
          final recipients = (b['recipients'] as List).cast<Map<String, dynamic>>();

          return ListView(padding: const EdgeInsets.all(16), children: [
            SectionCard(children: [
              Padding(padding: const EdgeInsets.symmetric(vertical: 6), child: Text('${b['message']}')),
              const Divider(),
              InfoRow('Sent to', '${b['audience']}'),
              InfoRow('Status', '${b['status']}'),
              InfoRow('Sent', '${b['sent']} of ${b['recipient_count']}'),
              if (toNum(b['failed']) > 0) InfoRow('Failed', '${b['failed']}'),
              InfoRow('Credits used', '${b['credits_used']}'),
              InfoRow('By', '${b['by'] ?? ''} · ${_at(b['at'] as String?)}'),
            ]),
            const SizedBox(height: 12),
            SectionCard(title: 'Tenants', children: [
              for (final r in recipients)
                ListTile(
                  contentPadding: EdgeInsets.zero,
                  title: Text('${r['tenant'] ?? r['phone']}'),
                  subtitle: Text('${r['phone']}${r['error'] != null && r['status'] != 'sent' ? ' · ${r['error']}' : ''}'),
                  trailing: smsStatusChip(r['status']),
                ),
              if (recipients.isEmpty) const Padding(padding: EdgeInsets.only(bottom: 10), child: Text('Being sent… pull down to refresh.')),
            ]),
          ]);
        },
      ),
    );
  }
}
