import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/intl.dart';

import '../../app/theme.dart';
import '../../core/api.dart';
import '../../core/format.dart';
import '../../core/payment_wait.dart';
import '../../core/session.dart';
import '../../core/widgets.dart';

final subscriptionProvider = FutureProvider.autoDispose<Map<String, dynamic>>((ref) => ref.read(apiProvider).get('/subscription'));

const _stateText = {
  'trial': 'Free trial',
  'trial_ended': 'Trial ended',
  'active': 'Active',
  'expiring': 'Ending soon',
  'expired': 'Expired',
  'past_due': 'Payment overdue',
  'cancelled': 'Cancelled',
};

/// The landlord's Primora plan: what they have now, choosing a plan, unit
/// tier and billing cycle (with any promotion's discount), and paying by
/// mobile money, as on the web's Subscription page.
class SubscriptionScreen extends ConsumerStatefulWidget {
  const SubscriptionScreen({super.key});

  @override
  ConsumerState<SubscriptionScreen> createState() => _SubscriptionScreenState();
}

class _SubscriptionScreenState extends ConsumerState<SubscriptionScreen> {
  String? _cycle;
  int? _planId;
  final Map<int, int?> _tiers = {};
  TextEditingController? _phone;
  String? _error;
  bool _busy = false;

  @override
  void dispose() {
    _phone?.dispose();
    super.dispose();
  }

  /// First load: start from the landlord's current plan and cycle.
  void _init(Map<String, dynamic> s) {
    if (_phone != null) return;
    final current = s['current'] as Map;
    final plans = (s['plans'] as List).cast<Map<String, dynamic>>();
    _phone = TextEditingController(text: '${s['default_phone'] ?? ''}');
    _cycle = '${current['cycle'] ?? 'monthly'}';
    _planId = plans.any((p) => p['id'] == current['plan_id']) ? (current['plan_id'] as num).toInt() : (plans.isEmpty ? null : (plans.first['id'] as num).toInt());
    for (final p in plans) {
      _tiers[(p['id'] as num).toInt()] = (p['default_tier'] as num?)?.toInt();
    }
  }

  /// What the chosen plan + tier costs for the chosen cycle: {list, amount, discount}, or null if not offered.
  Map<String, dynamic>? _price(Map<String, dynamic> plan, String? cycle) {
    final tierId = _tiers[(plan['id'] as num).toInt()];
    final tiers = (plan['tiers'] as List).cast<Map<String, dynamic>>();
    final tier = tiers.firstWhere((t) => t['id'] == tierId, orElse: () => tiers.first);
    if (tier['eligible'] != true || tier['pay'] is! Map) return null;
    return ((tier['pay'] as Map)[cycle] as Map?)?.cast<String, dynamic>();
  }

  Future<void> _pay(Map<String, dynamic> plan, Map<String, dynamic> price) async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final r = await ref.read(apiProvider).post('/subscription/pay', data: {
        'plan_id': plan['id'],
        'tier_id': _tiers[(plan['id'] as num).toInt()],
        'cycle': _cycle,
        'phone': _phone!.text.trim(),
      });
      if (!mounted) return;
      final ok = await showModalBottomSheet<bool>(
        context: context,
        isDismissible: false,
        enableDrag: false,
        builder: (_) => PaymentWaitSheet(
          statusPath: '/payments/status/${r['reference']}',
          message: '${r['message']}',
          success: (s) => s['period_end'] != null ? 'Your plan is paid until ${date(s['period_end'] as String?)}.' : 'Your subscription is active.',
        ),
      );
      await _paid(ok);
    } on ApiException catch (e) {
      setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _paid(bool? ok) async {
    ref.invalidate(subscriptionProvider);
    if (ok == true) await ref.read(sessionProvider.notifier).refresh();
  }

  Future<void> _addCode() async {
    final added = await showModalBottomSheet<String>(context: context, isScrollControlled: true, builder: (_) => const _CodeSheet());
    if (added == null || !mounted) return;
    ref.invalidate(subscriptionProvider);
    showMessage(context, added);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Plan')),
      body: AsyncBody(
        value: ref.watch(subscriptionProvider),
        onRefresh: () => ref.refresh(subscriptionProvider.future),
        builder: (s) {
          _init(s);
          final plans = (s['plans'] as List).cast<Map<String, dynamic>>();
          final cycles = (s['cycles'] as List).cast<Map<String, dynamic>>();
          final payments = (s['payments'] as List).cast<Map<String, dynamic>>();
          final promo = (s['promo'] as Map?)?.cast<String, dynamic>();
          final awaiting = (s['awaiting'] as Map?)?.cast<String, dynamic>();
          final chosen = plans.where((p) => p['id'] == _planId).firstOrNull;
          final price = chosen == null ? null : _price(chosen, _cycle);

          return ListView(padding: const EdgeInsets.fromLTRB(16, 16, 16, 32), children: [
            _CurrentPlan(current: (s['current'] as Map).cast<String, dynamic>()),
            if (awaiting != null) _Awaiting(payment: awaiting, onDone: _paid),
            if (promo != null)
              _Note(
                icon: Icons.local_offer_outlined,
                color: Brand.green,
                text: '${promo['name']}: ${promo['summary']}${(promo['left'] as num? ?? 0) > 0 ? ' (${promo['left']} discounted payment${promo['left'] == 1 ? '' : 's'} left)' : ''}.',
              )
            else if (s['can_take_code'] == true)
              Align(
                alignment: Alignment.centerLeft,
                child: TextButton.icon(onPressed: _addCode, icon: const Icon(Icons.local_offer_outlined, size: 18), label: const Text('Have a partner or promo code?')),
              ),
            const SizedBox(height: 8),
            const Text('Choose a plan', style: TextStyle(fontSize: 17, fontWeight: FontWeight.w700)),
            const SizedBox(height: 10),
            SegmentedButton<String>(
              segments: [for (final c in cycles) ButtonSegment(value: '${c['key']}', label: Text('${c['label']}'))],
              selected: {_cycle ?? 'monthly'},
              showSelectedIcon: false,
              onSelectionChanged: (v) => setState(() => _cycle = v.first),
            ),
            const SizedBox(height: 12),
            if (plans.isEmpty) const Text('No plan can be paid for your number of units here. Please contact Primora.'),
            for (final p in plans)
              _PlanCard(
                plan: p,
                selected: p['id'] == _planId,
                current: p['id'] == (s['current'] as Map)['plan_id'],
                tierId: _tiers[(p['id'] as num).toInt()],
                price: _price(p, _cycle),
                cycleLabel: '${cycles.firstWhere((c) => c['key'] == _cycle, orElse: () => cycles.first)['label']}'.toLowerCase(),
                onSelect: () => setState(() => _planId = (p['id'] as num).toInt()),
                onTier: (t) => setState(() {
                  _planId = (p['id'] as num).toInt();
                  _tiers[_planId!] = t;
                }),
              ),
            const SizedBox(height: 8),
            if (s['available'] != true)
              const _Note(icon: Icons.info_outline, color: Brand.amber, text: 'Mobile money payments are unavailable right now. Please try again later or contact Primora.')
            else if (chosen != null) ...[
              TextField(controller: _phone, keyboardType: TextInputType.phone, decoration: const InputDecoration(labelText: 'Pay from (MTN or Airtel number)')),
              if (_error != null) Padding(padding: const EdgeInsets.only(top: 10), child: Text(_error!, style: const TextStyle(color: Brand.red))),
              const SizedBox(height: 14),
              FilledButton(
                onPressed: _busy || price == null || awaiting != null ? null : () => _pay(chosen, price),
                style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(50)),
                child: Text(price == null ? 'Not offered for this cycle' : 'Pay ${ugx(toNum(price['amount']))} for ${chosen['name']}'),
              ),
              const SizedBox(height: 6),
              const Text('A prompt goes to the phone; approve it with your mobile money PIN.', textAlign: TextAlign.center, style: TextStyle(color: Brand.navyMuted, fontSize: 12)),
            ],
            if (payments.isNotEmpty) ...[
              const SizedBox(height: 24),
              SectionCard(title: 'Payments', children: [for (final p in payments) _PaymentRow(payment: p)]),
            ],
          ]);
        },
      ),
    );
  }
}

class _CurrentPlan extends StatelessWidget {
  const _CurrentPlan({required this.current});
  final Map<String, dynamic> current;

  @override
  Widget build(BuildContext context) {
    final state = '${current['state']}';
    final end = current['period_end'] as String?;
    final bad = ['trial_ended', 'expired', 'past_due', 'cancelled'].contains(state);
    final until = end == null ? null : (state.startsWith('trial') ? (bad ? 'Trial ended' : 'Trial ends') : (bad ? 'Ended' : 'Paid until'));
    final remaining = current['remaining'] as num?;

    return Card(
      color: Brand.navy,
      child: Padding(
        padding: const EdgeInsets.all(18),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            Expanded(child: Text('${current['plan'] ?? 'Free trial'}', style: const TextStyle(color: Colors.white, fontSize: 20, fontWeight: FontWeight.w700))),
            StatusChip(_stateText[state] ?? state, color: bad ? Brand.red : (state == 'expiring' ? Brand.amber : Brand.green)),
          ]),
          const SizedBox(height: 6),
          if (current['cycle_label'] != null) Text('Billed ${'${current['cycle_label']}'.toLowerCase()}', style: const TextStyle(color: Color(0xFFC9CCD4))),
          if (until != null) Text('$until ${date(end)}', style: const TextStyle(color: Color(0xFFC9CCD4))),
          const SizedBox(height: 6),
          Text(
            '${current['units']} unit${current['units'] == 1 ? '' : 's'}${current['max_units'] != null ? ' of ${current['max_units']} on your tier${remaining != null ? ' · $remaining left' : ''}' : ''}',
            style: const TextStyle(color: Color(0xFFC9CCD4)),
          ),
          if (current['read_only'] == true) ...[
            const SizedBox(height: 10),
            const Text('Your account is read-only until you pay. Renew below to carry on adding and changing records.', style: TextStyle(color: Color(0xFFFCA5A5))),
          ],
        ]),
      ),
    );
  }
}

class _PlanCard extends StatelessWidget {
  const _PlanCard({
    required this.plan,
    required this.selected,
    required this.current,
    required this.tierId,
    required this.price,
    required this.cycleLabel,
    required this.onSelect,
    required this.onTier,
  });

  final Map<String, dynamic> plan;
  final bool selected;
  final bool current;
  final int? tierId;
  final Map<String, dynamic>? price;
  final String cycleLabel;
  final VoidCallback onSelect;
  final ValueChanged<int?> onTier;

  @override
  Widget build(BuildContext context) {
    final tiers = (plan['tiers'] as List).cast<Map<String, dynamic>>();
    final features = (plan['features'] as List).cast<String>();
    final missing = (plan['missing'] as List).cast<String>();
    final discount = toNum(price?['discount']);

    return Card(
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14), side: BorderSide(color: selected ? Brand.orange : Colors.transparent, width: 2)),
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: onSelect,
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [
              Icon(selected ? Icons.radio_button_checked : Icons.radio_button_off, color: selected ? Brand.orange : Brand.navyMuted, size: 20),
              const SizedBox(width: 8),
              Expanded(child: Text('${plan['name']}', style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w700))),
              if (current) const StatusChip('Current', color: Brand.orange),
            ]),
            const SizedBox(height: 8),
            if (price == null)
              const Text('Not offered for this cycle', style: TextStyle(color: Brand.navyMuted))
            else
              Wrap(crossAxisAlignment: WrapCrossAlignment.end, spacing: 8, children: [
                Text(ugx(toNum(price!['amount'])), style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w700)),
                if (discount > 0) Text(ugx(toNum(price!['list'])), style: const TextStyle(color: Brand.navyMuted, decoration: TextDecoration.lineThrough)),
                Text(cycleLabel, style: const TextStyle(color: Brand.navyMuted)),
              ]),
            if (plan['tiered'] == true) ...[
              const SizedBox(height: 10),
              DropdownButtonFormField<int?>(
                initialValue: tierId,
                isExpanded: true,
                decoration: const InputDecoration(labelText: 'Units', isDense: true),
                items: [
                  for (final t in tiers)
                    DropdownMenuItem<int?>(
                      value: (t['id'] as num?)?.toInt(),
                      enabled: t['eligible'] == true,
                      child: Text('${t['label']}${t['eligible'] == true ? '' : ' (too small)'}', style: TextStyle(color: t['eligible'] == true ? null : Brand.navyMuted)),
                    ),
                ],
                onChanged: onTier,
              ),
            ],
            const SizedBox(height: 10),
            for (final f in features) _FeatureLine(f, included: true),
            for (final f in missing) _FeatureLine(f, included: false),
          ]),
        ),
      ),
    );
  }
}

class _FeatureLine extends StatelessWidget {
  const _FeatureLine(this.name, {required this.included});
  final String name;
  final bool included;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 2),
        child: Row(children: [
          Icon(included ? Icons.check : Icons.remove, size: 16, color: included ? Brand.green : Brand.navyMuted),
          const SizedBox(width: 8),
          Expanded(child: Text(name, style: TextStyle(fontSize: 13, color: included ? null : Brand.navyMuted))),
        ]),
      );
}

class _Note extends StatelessWidget {
  const _Note({required this.icon, required this.color, required this.text, this.action});
  final IconData icon;
  final Color color;
  final String text;
  final Widget? action;

  @override
  Widget build(BuildContext context) => Card(
        color: color.withValues(alpha: 0.08),
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Icon(icon, color: color, size: 20),
            const SizedBox(width: 10),
            Expanded(child: Text(text, style: TextStyle(color: color))),
            if (action != null) action!,
          ]),
        ),
      );
}

/// A payment started earlier (possibly on the web) that is still waiting for approval.
class _Awaiting extends ConsumerStatefulWidget {
  const _Awaiting({required this.payment, required this.onDone});
  final Map<String, dynamic> payment;
  final Future<void> Function(bool?) onDone;

  @override
  ConsumerState<_Awaiting> createState() => _AwaitingState();
}

class _AwaitingState extends ConsumerState<_Awaiting> {
  bool _checking = false;

  Future<void> _check() async {
    setState(() => _checking = true);
    try {
      final s = await ref.read(apiProvider).get('/payments/status/${widget.payment['reference']}');
      if (s['open'] == true) {
        if (mounted) showMessage(context, 'Still waiting for approval on ${widget.payment['paid_with']}.');
      } else {
        await widget.onDone(s['status'] == 'succeeded');
      }
    } on ApiException catch (e) {
      if (mounted) showMessage(context, e.message, error: true);
    } finally {
      if (mounted) setState(() => _checking = false);
    }
  }

  @override
  Widget build(BuildContext context) => _Note(
        icon: Icons.hourglass_top,
        color: Brand.amber,
        text: 'A payment of ${ugx(toNum(widget.payment['amount']))} for ${widget.payment['plan'] ?? 'your plan'} is waiting for approval on ${widget.payment['paid_with']}.',
        action: TextButton(onPressed: _checking ? null : _check, child: const Text('Check')),
      );
}

class _PaymentRow extends StatelessWidget {
  const _PaymentRow({required this.payment});
  final Map<String, dynamic> payment;

  @override
  Widget build(BuildContext context) {
    final at = DateTime.tryParse('${payment['date']}')?.toLocal();
    final chip = switch (payment['status']) {
      'succeeded' => const StatusChip('Paid', color: Brand.green),
      'failed' => const StatusChip('Failed', color: Brand.red),
      _ => const StatusChip('Waiting', color: Brand.amber),
    };

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 8),
      child: Row(children: [
        Expanded(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text('${payment['plan'] ?? '—'} · ${payment['cycle'] ?? ''}', style: const TextStyle(fontWeight: FontWeight.w600)),
            Text(
              [if (at != null) DateFormat('d MMM y, HH:mm').format(at), '${payment['paid_with']}', if (payment['period_end'] != null) 'covers to ${date(payment['period_end'] as String?)}'].join(' · '),
              style: const TextStyle(color: Brand.navyMuted, fontSize: 12),
            ),
          ]),
        ),
        const SizedBox(width: 8),
        Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
          Text(ugx(toNum(payment['amount'])), style: const TextStyle(fontWeight: FontWeight.w600)),
          const SizedBox(height: 4),
          chip,
        ]),
      ]),
    );
  }
}

class _CodeSheet extends ConsumerStatefulWidget {
  const _CodeSheet();

  @override
  ConsumerState<_CodeSheet> createState() => _CodeSheetState();
}

class _CodeSheetState extends ConsumerState<_CodeSheet> {
  final _code = TextEditingController();
  String? _error;
  bool _busy = false;

  @override
  void dispose() {
    _code.dispose();
    super.dispose();
  }

  Future<void> _apply() async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final r = await ref.read(apiProvider).post('/subscription/code', data: {'code': _code.text.trim()});
      if (mounted) Navigator.of(context).pop('${r['message']}');
    } on ApiException catch (e) {
      setState(() {
        _error = e.message;
        _busy = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) => Padding(
        padding: EdgeInsets.fromLTRB(16, 20, 16, 16 + MediaQuery.of(context).viewInsets.bottom),
        child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          const Text('Add a code', style: TextStyle(fontSize: 18, fontWeight: FontWeight.w700)),
          const SizedBox(height: 6),
          const Text('From a Primora partner or a promotion. A code can be added once, before your first payment.', style: TextStyle(color: Brand.navyMuted)),
          const SizedBox(height: 12),
          TextField(
            controller: _code,
            autofocus: true,
            textCapitalization: TextCapitalization.characters,
            decoration: InputDecoration(labelText: 'Code', errorText: _error),
            onSubmitted: (_) => _apply(),
          ),
          const SizedBox(height: 16),
          FilledButton(onPressed: _busy ? null : _apply, child: const Text('Add code')),
        ]),
      );
}
