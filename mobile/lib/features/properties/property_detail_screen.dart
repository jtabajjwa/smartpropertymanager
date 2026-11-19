import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../app/theme.dart';
import '../../core/api.dart';
import '../../core/format.dart';
import '../../core/session.dart';
import '../../core/widgets.dart';

final propertyProvider = FutureProvider.autoDispose.family<Map<String, dynamic>, int>((ref, id) => ref.read(apiProvider).get('/estates/$id'));

/// One property: its units, who's in each, and what they owe.
class PropertyDetailScreen extends ConsumerWidget {
  const PropertyDetailScreen({super.key, required this.id});
  final int id;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final readOnly = ref.watch(sessionProvider).readOnly;

    return Scaffold(
      appBar: AppBar(title: const Text('Property'), actions: [
        if (!readOnly)
          IconButton(
            tooltip: 'Edit',
            icon: const Icon(Icons.edit_outlined),
            onPressed: () async {
              final saved = await context.push<bool>('/properties/$id/edit');
              if (saved == true) ref.invalidate(propertyProvider(id));
            },
          ),
      ]),
      body: AsyncBody(
        value: ref.watch(propertyProvider(id)),
        onRefresh: () => ref.refresh(propertyProvider(id).future),
        builder: (e) {
          final units = (e['units'] as List).cast<Map<String, dynamic>>();

          return ListView(padding: const EdgeInsets.fromLTRB(16, 16, 16, 96), children: [
            Text('${e['name']}', style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w800)),
            Text([e['address'], e['district'], e['country']].where((v) => v != null && '$v'.isNotEmpty).join(', '),
                style: const TextStyle(color: Brand.navyMuted)),
            const SizedBox(height: 4),
            Text('${e['occupied_count']} of ${e['unit_count']} units occupied', style: const TextStyle(color: Brand.navyMuted, fontSize: 13)),
            const SizedBox(height: 14),
            if (units.isEmpty) const Card(child: Padding(padding: EdgeInsets.all(16), child: Text('No units yet. Add the first one.'))),
            for (final u in units) ...[
              _UnitTile(unit: u, onChanged: () => ref.invalidate(propertyProvider(id))),
              const SizedBox(height: 8),
            ],
          ]);
        },
      ),
      floatingActionButton: readOnly
          ? null
          : FloatingActionButton.extended(
              backgroundColor: Brand.orange,
              foregroundColor: Colors.white,
              onPressed: () async {
                final saved = await context.push<bool>('/units/new?estate=$id');
                if (saved == true) ref.invalidate(propertyProvider(id));
              },
              icon: const Icon(Icons.add),
              label: const Text('Add unit'),
            ),
    );
  }
}

class _UnitTile extends StatelessWidget {
  const _UnitTile({required this.unit, required this.onChanged});
  final Map<String, dynamic> unit;
  final VoidCallback onChanged;

  @override
  Widget build(BuildContext context) {
    final occupant = (unit['occupant'] as Map?)?.cast<String, dynamic>();
    final owed = toNum(occupant?['owed']);

    return Card(
      child: ListTile(
        leading: CircleAvatar(
          backgroundColor: unitStatusColor(unit['status']).withValues(alpha: 0.12),
          foregroundColor: unitStatusColor(unit['status']),
          child: const Icon(Icons.door_front_door_outlined),
        ),
        title: Text('${unit['name']}${unit['code'] != null ? '  #${unit['code']}' : ''}', style: const TextStyle(fontWeight: FontWeight.w600)),
        subtitle: Text(occupant == null ? unitStatusLabel(unit['status']) : '${occupant['tenant']} · ${ugx(toNum(occupant['rent_amount']))} ${occupant['billing_cycle']}'),
        trailing: occupant == null
            ? const Icon(Icons.chevron_right)
            : Text(owed > 0 ? 'Owes ${amount(owed)}' : 'Paid up', style: TextStyle(fontSize: 12, fontWeight: FontWeight.w600, color: owed > 0 ? Brand.red : Brand.green)),
        onTap: () async {
          await context.push('/units/${unit['id']}');
          onChanged();
        },
      ),
    );
  }
}

Color unitStatusColor(Object? status) => switch (status) {
      'occupied' => Brand.green,
      'maintenance' => Brand.amber,
      _ => Brand.orange,
    };

String unitStatusLabel(Object? status) => switch (status) {
      'occupied' => 'Occupied',
      'maintenance' => 'Under maintenance',
      _ => 'Vacant',
    };
