import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../app/theme.dart';
import '../../core/api.dart';
import '../../core/form.dart';
import '../../core/format.dart';
import '../../core/paged.dart';
import '../../core/picker.dart';
import '../../core/session.dart';
import '../../core/widgets.dart';

const billingCycles = {
  'monthly': 'Monthly',
  'quarterly': 'Every 3 months',
  'yearly': 'Yearly',
  'weekly': 'Weekly',
  'fortnightly': 'Every 2 weeks',
  'daily': 'Daily',
};

StatusChip leaseStatusChip(Object? status) => switch (status) {
      'active' => const StatusChip('Active', color: Brand.green),
      'evicted' => const StatusChip('Evicted', color: Brand.red),
      _ => const StatusChip('Ended'),
    };

/// Leases, active by default.
class LeasesScreen extends ConsumerStatefulWidget {
  const LeasesScreen({super.key});

  @override
  ConsumerState<LeasesScreen> createState() => _LeasesScreenState();
}

class _LeasesScreenState extends ConsumerState<LeasesScreen> {
  String _status = 'active';
  final _list = GlobalKey<PagedListState>();

  @override
  Widget build(BuildContext context) {
    final readOnly = ref.watch(sessionProvider).readOnly;

    return Scaffold(
      appBar: AppBar(title: const Text('Leases')),
      body: PagedList(
        key: _list,
        path: '/leases',
        query: {'status': _status},
        searchHint: 'Search tenant, unit or lease number',
        emptyIcon: Icons.description_outlined,
        emptyMessage: 'No leases here.',
        header: SizedBox(
          height: 48,
          child: ListView(scrollDirection: Axis.horizontal, padding: const EdgeInsets.symmetric(horizontal: 16), children: [
            for (final f in const {'active': 'Active', 'terminated': 'Ended', 'all': 'All'}.entries)
              Padding(
                padding: const EdgeInsets.only(right: 8),
                child: ChoiceChip(label: Text(f.value), selected: _status == f.key, selectedColor: Brand.orangeLight, onSelected: (_) => setState(() => _status = f.key)),
              ),
          ]),
        ),
        itemBuilder: (context, l) => Card(
          child: ListTile(
            title: Text('${l['tenant']}', style: const TextStyle(fontWeight: FontWeight.w600)),
            subtitle: Text('${l['unit']} · ${ugx(toNum(l['rent_amount']))} ${billingCycles[l['billing_cycle']]?.toLowerCase() ?? ''}'),
            trailing: leaseStatusChip(l['status']),
            onTap: () async {
              await context.push('/leases/${l['id']}');
              _list.currentState?.reload();
            },
          ),
        ),
      ),
      floatingActionButton: readOnly
          ? null
          : FloatingActionButton(
              backgroundColor: Brand.orange,
              foregroundColor: Colors.white,
              onPressed: () async {
                final saved = await context.push<bool>('/leases/new');
                if (saved == true) _list.currentState?.reload();
              },
              child: const Icon(Icons.add),
            ),
    );
  }
}

final leaseProvider = FutureProvider.autoDispose.family<Map<String, dynamic>, int>((ref, id) => ref.read(apiProvider).get('/leases/$id'));

/// One lease: its terms, invoices, and ending it.
class LeaseDetailScreen extends ConsumerWidget {
  const LeaseDetailScreen({super.key, required this.id});
  final int id;

  Future<void> _end(BuildContext context, WidgetRef ref, String status) async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (c) => AlertDialog(
        title: Text(status == 'evicted' ? 'Record an eviction?' : 'End this lease?'),
        content: const Text('The unit becomes vacant and no more rent invoices are raised. An ended lease can\'t be changed.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(c, false), child: const Text('Cancel')),
          FilledButton(style: FilledButton.styleFrom(minimumSize: const Size(0, 40)), onPressed: () => Navigator.pop(c, true), child: const Text('Yes')),
        ],
      ),
    );
    if (ok != true || !context.mounted) return;
    try {
      await ref.read(apiProvider).post('/leases/$id', data: {'status': status, 'end_date': isoDate(DateTime.now())});
      ref.invalidate(leaseProvider(id));
      if (context.mounted) showMessage(context, 'Lease ended.');
    } on ApiException catch (e) {
      if (context.mounted) showMessage(context, e.message, error: true);
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final readOnly = ref.watch(sessionProvider).readOnly;

    return Scaffold(
      appBar: AppBar(title: const Text('Lease')),
      body: AsyncBody(
        value: ref.watch(leaseProvider(id)),
        onRefresh: () => ref.refresh(leaseProvider(id).future),
        builder: (l) {
          final invoices = (l['invoices'] as List).cast<Map<String, dynamic>>();
          final active = l['status'] == 'active';

          return ListView(padding: const EdgeInsets.all(16), children: [
            Row(children: [
              Expanded(child: Text('${l['number']}', style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w800))),
              leaseStatusChip(l['status']),
            ]),
            Text('${l['tenant']} · ${l['unit']}', style: const TextStyle(color: Brand.navyMuted)),
            const SizedBox(height: 12),
            SectionCard(title: 'Terms', children: [
              InfoRow('Rent', '${ugx(toNum(l['rent_amount']))} ${billingCycles[l['billing_cycle']]?.toLowerCase() ?? ''}'),
              InfoRow('Security deposit', ugx(toNum(l['security_deposit']))),
              InfoRow('Paid upfront', '${l['required_upfront_periods']} ${l['required_upfront_periods'] == 1 ? 'period' : 'periods'}'),
              InfoRow('Started', date(l['start_date'] as String?)),
              InfoRow('Ends', l['end_date'] == null ? 'Open-ended' : date(l['end_date'] as String?)),
              if (active) InfoRow('Next invoice', date(l['next_invoice_date'] as String?)),
              if (l['has_invoices'] == true)
                const Padding(
                  padding: EdgeInsets.only(top: 4, bottom: 8),
                  child: Text('Rent, deposit, tenant and unit are fixed now that invoices exist.', style: TextStyle(fontSize: 12, color: Brand.navyMuted)),
                ),
            ]),
            const SizedBox(height: 12),
            Row(children: [
              Expanded(child: OutlinedButton(onPressed: () => context.push('/tenants/${l['tenant_id']}'), child: const Text('Tenant'))),
              const SizedBox(width: 8),
              Expanded(child: OutlinedButton(onPressed: () => context.push('/units/${l['unit_id']}'), child: const Text('Unit'))),
            ]),
            if (active && !readOnly) ...[
              const SizedBox(height: 8),
              Row(children: [
                Expanded(
                  child: FilledButton(
                    style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(44)),
                    onPressed: () async {
                      final saved = await context.push<bool>('/invoices/new?lease=$id');
                      if (saved == true) ref.invalidate(leaseProvider(id));
                    },
                    child: const Text('New invoice'),
                  ),
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: FilledButton(
                    style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(44), backgroundColor: Brand.navy),
                    onPressed: () async {
                      final saved = await context.push<bool>('/payments/new?tenant=${l['tenant_id']}');
                      if (saved == true) ref.invalidate(leaseProvider(id));
                    },
                    child: const Text('Record payment'),
                  ),
                ),
              ]),
            ],
            const SizedBox(height: 12),
            SectionCard(title: 'Invoices', children: [
              if (invoices.isEmpty) const Padding(padding: EdgeInsets.only(bottom: 10), child: Text('None yet.')),
              for (final i in invoices)
                ListTile(
                  contentPadding: EdgeInsets.zero,
                  title: Text('${i['title']}'),
                  subtitle: Row(children: [Text('Due ${date(i['due_date'] as String?)}  '), StatusChip.invoice(i)]),
                  trailing: Text(amount(toNum(i['amount'])), style: const TextStyle(fontWeight: FontWeight.w700)),
                  onTap: () => context.push('/invoices/${i['id']}'),
                ),
            ]),
            if (active && !readOnly) ...[
              const SizedBox(height: 16),
              OutlinedButton.icon(
                onPressed: () => _end(context, ref, 'terminated'),
                icon: const Icon(Icons.logout),
                label: const Text('End lease (tenant moved out)'),
                style: OutlinedButton.styleFrom(foregroundColor: Brand.red, minimumSize: const Size.fromHeight(44)),
              ),
              TextButton(onPressed: () => _end(context, ref, 'evicted'), child: const Text('Record an eviction', style: TextStyle(color: Brand.red))),
            ],
          ]);
        },
      ),
    );
  }
}

/// Start a lease: tenant, vacant unit, rent and terms. The first invoices
/// (deposit, and rent for the upfront periods) are raised straight away.
class LeaseFormScreen extends ConsumerStatefulWidget {
  const LeaseFormScreen({super.key, this.unitId, this.tenantId});
  final int? unitId;
  final int? tenantId;

  @override
  ConsumerState<LeaseFormScreen> createState() => _LeaseFormScreenState();
}

class _LeaseFormScreenState extends ConsumerState<LeaseFormScreen> with ApiForm {
  Map<String, dynamic>? _tenant;
  Map<String, dynamic>? _unit;
  final _rent = TextEditingController();
  final _deposit = TextEditingController();
  final _upfront = TextEditingController(text: '1');
  String _cycle = 'monthly';
  DateTime _start = DateTime.now();
  DateTime? _end;

  @override
  void initState() {
    super.initState();
    final api = ref.read(apiProvider);
    if (widget.unitId != null) api.get('/units/${widget.unitId}').then((u) => setState(() => _unit = u)).catchError((_) {});
    if (widget.tenantId != null) api.get('/tenants/${widget.tenantId}').then((t) => setState(() => _tenant = t)).catchError((_) {});
  }

  Future<void> _save() => submit(() async {
        await ref.read(apiProvider).post('/leases', data: {
          'tenant_id': _tenant?['id'],
          'unit_id': _unit?['id'],
          'start_date': isoDate(_start),
          if (_end != null) 'end_date': isoDate(_end!),
          'rent_amount': _rent.text.replaceAll(',', '').trim(),
          'billing_cycle': _cycle,
          'security_deposit': _deposit.text.replaceAll(',', '').trim(),
          'required_upfront_periods': _upfront.text.trim(),
        });
      }, done: 'Lease started. Its first invoices are raised.');

  @override
  Widget build(BuildContext context) {
    final rent = num.tryParse(_rent.text.replaceAll(',', '')) ?? 0;
    final upfront = int.tryParse(_upfront.text) ?? 1;
    final deposit = num.tryParse(_deposit.text.replaceAll(',', '')) ?? 0;

    return Scaffold(
      appBar: AppBar(title: const Text('New lease')),
      body: ListView(padding: const EdgeInsets.all(16), children: [
        InkWell(
          onTap: () async {
            final t = await pickRecord(context, title: 'Tenant', path: '/tenants', label: (t) => '${t['name']}', subtitle: (t) => '${t['phone']}');
            if (t != null) setState(() => _tenant = t);
          },
          child: InputDecorator(
            decoration: InputDecoration(labelText: 'Tenant', errorText: errorFor('tenant_id'), suffixIcon: const Icon(Icons.search)),
            child: Text(_tenant == null ? 'Choose…' : '${_tenant!['name']}'),
          ),
        ),
        gap,
        InkWell(
          onTap: () async {
            final u = await pickRecord(context, title: 'Vacant unit', path: '/units', query: const {'status': 'vacant'}, label: (u) => '${u['label']}');
            if (u != null) setState(() => _unit = u);
          },
          child: InputDecorator(
            decoration: InputDecoration(labelText: 'Unit', errorText: errorFor('unit_id'), suffixIcon: const Icon(Icons.search)),
            child: Text(_unit == null ? 'Choose a vacant unit…' : '${_unit!['label']}'),
          ),
        ),
        gap,
        Row(children: [
          Expanded(
            child: TextField(controller: _rent, keyboardType: TextInputType.number, onChanged: (_) => setState(() {}),
                decoration: InputDecoration(labelText: 'Rent (UGX)', errorText: errorFor('rent_amount'))),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: DropdownButtonFormField<String>(
              initialValue: _cycle,
              isExpanded: true,
              decoration: InputDecoration(labelText: 'Every', errorText: errorFor('billing_cycle')),
              items: [for (final c in billingCycles.entries) DropdownMenuItem(value: c.key, child: Text(c.value))],
              onChanged: (v) => setState(() => _cycle = v!),
            ),
          ),
        ]),
        gap,
        Row(children: [
          Expanded(
            child: TextField(controller: _deposit, keyboardType: TextInputType.number, onChanged: (_) => setState(() {}),
                decoration: InputDecoration(labelText: 'Deposit (UGX)', hintText: '0', errorText: errorFor('security_deposit'))),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: TextField(controller: _upfront, keyboardType: TextInputType.number, onChanged: (_) => setState(() {}),
                decoration: InputDecoration(labelText: 'Periods paid upfront', errorText: errorFor('required_upfront_periods'))),
          ),
        ]),
        gap,
        DateField(label: 'Starts', value: _start, error: errorFor('start_date'), onChanged: (d) => setState(() => _start = d)),
        gap,
        InkWell(
          onTap: () async {
            final d = await showDatePicker(context: context, initialDate: _end ?? _start.add(const Duration(days: 365)), firstDate: _start, lastDate: DateTime(2100));
            setState(() => _end = d);
          },
          child: InputDecorator(
            decoration: InputDecoration(labelText: 'Ends (optional)', errorText: errorFor('end_date'), suffixIcon: const Icon(Icons.calendar_today_outlined, size: 18)),
            child: Text(_end == null ? 'Open-ended' : date(isoDate(_end!))),
          ),
        ),
        if (rent > 0) ...[
          const SizedBox(height: 16),
          Card(
            color: Brand.orangeLight,
            child: Padding(
              padding: const EdgeInsets.all(14),
              child: Text(
                'First invoices: rent ${ugx(rent * upfront)} ($upfront × ${amount(rent)})${deposit > 0 ? ' and deposit ${ugx(deposit)}' : ''}, due ${date(isoDate(_start))}.',
              ),
            ),
          ),
        ],
        const SizedBox(height: 20),
        formFooter('Start lease', _save),
      ]),
    );
  }
}
