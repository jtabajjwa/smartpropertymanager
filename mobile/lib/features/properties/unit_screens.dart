import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../app/theme.dart';
import '../../core/api.dart';
import '../../core/form.dart';
import '../../core/format.dart';
import '../../core/session.dart';
import '../../core/widgets.dart';
import 'property_detail_screen.dart';

final unitProvider = FutureProvider.autoDispose.family<Map<String, dynamic>, int>((ref, id) => ref.read(apiProvider).get('/units/$id'));

/// One unit: who's in it, what they owe, its lease history; start a lease if it's vacant.
class UnitDetailScreen extends ConsumerWidget {
  const UnitDetailScreen({super.key, required this.id});
  final int id;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final readOnly = ref.watch(sessionProvider).readOnly;

    return Scaffold(
      appBar: AppBar(title: const Text('Unit'), actions: [
        if (!readOnly)
          IconButton(
            tooltip: 'Edit',
            icon: const Icon(Icons.edit_outlined),
            onPressed: () async {
              final saved = await context.push<bool>('/units/$id/edit');
              if (saved == true) ref.invalidate(unitProvider(id));
            },
          ),
      ]),
      body: AsyncBody(
        value: ref.watch(unitProvider(id)),
        onRefresh: () => ref.refresh(unitProvider(id).future),
        builder: (u) {
          final occupant = (u['occupant'] as Map?)?.cast<String, dynamic>();
          final leases = (u['leases'] as List).cast<Map<String, dynamic>>();

          return ListView(padding: const EdgeInsets.all(16), children: [
            Row(children: [
              Expanded(child: Text('${u['label']}', style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w800))),
              StatusChip(unitStatusLabel(u['status']), color: unitStatusColor(u['status'])),
            ]),
            if (u['code'] != null) Text('Unit ID ${u['code']} (tenants pay with this)', style: const TextStyle(color: Brand.navyMuted)),
            const SizedBox(height: 12),
            if (occupant != null)
              SectionCard(title: 'Tenant', children: [
                InfoRow('Name', '${occupant['tenant']}'),
                InfoRow('Lease', '${occupant['lease']}'),
                InfoRow('Rent', '${ugx(toNum(occupant['rent_amount']))} ${occupant['billing_cycle']}'),
                InfoRow('Owes', ugx(toNum(occupant['owed'])), bold: true),
                if (toNum(occupant['overdue']) > 0) InfoRow('Overdue', ugx(toNum(occupant['overdue']))),
                Row(children: [
                  TextButton(onPressed: () => context.push('/tenants/${occupant['tenant_id']}'), child: const Text('Tenant')),
                  TextButton(onPressed: () => context.push('/leases/${occupant['lease_id']}'), child: const Text('Lease')),
                ]),
              ])
            else if (!readOnly && u['status'] == 'vacant')
              FilledButton.icon(
                onPressed: () async {
                  final saved = await context.push<bool>('/leases/new?unit=${u['id']}');
                  if (saved == true) ref.invalidate(unitProvider(id));
                },
                icon: const Icon(Icons.key_outlined),
                label: const Text('Let this unit (start a lease)'),
              ),
            if (u['description'] != null && '${u['description']}'.isNotEmpty) ...[
              const SizedBox(height: 12),
              SectionCard(title: 'Description', children: [Padding(padding: const EdgeInsets.only(bottom: 8), child: Text('${u['description']}'))]),
            ],
            const SizedBox(height: 12),
            SectionCard(title: 'Lease history', children: [
              if (leases.isEmpty) const Padding(padding: EdgeInsets.only(bottom: 10), child: Text('Never let yet.')),
              for (final l in leases)
                ListTile(
                  contentPadding: EdgeInsets.zero,
                  title: Text('${l['tenant']}'),
                  subtitle: Text('${l['number']} · ${date(l['start_date'] as String?)}${l['end_date'] != null ? ' to ${date(l['end_date'] as String?)}' : ''}'),
                  trailing: StatusChip('${l['status']}', color: l['status'] == 'active' ? Brand.green : Brand.navyMuted),
                  onTap: () => context.push('/leases/${l['id']}'),
                ),
            ]),
          ]);
        },
      ),
    );
  }
}

/// Add a unit to a property, or edit one (name, status, description).
class UnitFormScreen extends ConsumerStatefulWidget {
  const UnitFormScreen({super.key, this.id, this.estateId});
  final int? id;
  final int? estateId;

  @override
  ConsumerState<UnitFormScreen> createState() => _UnitFormScreenState();
}

class _UnitFormScreenState extends ConsumerState<UnitFormScreen> with ApiForm {
  final _name = TextEditingController();
  final _description = TextEditingController();
  String _status = 'vacant';
  int? _estateId;
  bool _loading = false;

  @override
  void initState() {
    super.initState();
    _estateId = widget.estateId;
    if (widget.id != null) _load();
  }

  Future<void> _load() async {
    setState(() => _loading = true);
    try {
      final u = await ref.read(apiProvider).get('/units/${widget.id}');
      _name.text = '${u['name'] ?? ''}';
      _description.text = '${u['description'] ?? ''}';
      _status = '${u['status']}';
      _estateId = (u['estate_id'] as num?)?.toInt();
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _save() => submit(() async {
        await ref.read(apiProvider).post(widget.id == null ? '/units' : '/units/${widget.id}', data: {
          'name': _name.text.trim(),
          'estate_id': _estateId,
          'status': _status,
          'description': _description.text.trim(),
        });
      }, done: widget.id == null ? 'Unit added.' : 'Unit saved.');

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(widget.id == null ? 'New unit' : 'Edit unit')),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : ListView(padding: const EdgeInsets.all(16), children: [
              TextField(controller: _name, decoration: InputDecoration(labelText: 'Unit name', hintText: 'e.g. A4, Shop 2', errorText: errorFor('name'))),
              gap,
              DropdownButtonFormField<String>(
                initialValue: _status,
                decoration: InputDecoration(labelText: 'Status', errorText: errorFor('status')),
                items: const [
                  DropdownMenuItem(value: 'vacant', child: Text('Vacant')),
                  DropdownMenuItem(value: 'occupied', child: Text('Occupied')),
                  DropdownMenuItem(value: 'maintenance', child: Text('Under maintenance')),
                ],
                onChanged: (v) => setState(() => _status = v!),
              ),
              gap,
              TextField(controller: _description, maxLines: 3, decoration: const InputDecoration(labelText: 'Description (optional)', hintText: 'e.g. 2 bedrooms, self-contained')),
              if (errorFor('units') != null) Padding(padding: const EdgeInsets.only(top: 12), child: Text(errorFor('units')!, style: const TextStyle(color: Brand.red))),
              const SizedBox(height: 20),
              formFooter(widget.id == null ? 'Add unit' : 'Save', _save),
            ]),
    );
  }
}
