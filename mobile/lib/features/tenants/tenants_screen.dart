import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../app/theme.dart';
import '../../core/paged.dart';
import '../../core/session.dart';

class TenantsScreen extends ConsumerStatefulWidget {
  const TenantsScreen({super.key});

  @override
  ConsumerState<TenantsScreen> createState() => _TenantsScreenState();
}

class _TenantsScreenState extends ConsumerState<TenantsScreen> {
  final _list = GlobalKey<PagedListState>();

  @override
  Widget build(BuildContext context) {
    final readOnly = ref.watch(sessionProvider).readOnly;

    return Scaffold(
      appBar: AppBar(title: const Text('Tenants')),
      body: PagedList(
        key: _list,
        path: '/tenants',
        searchHint: 'Search name, phone, email',
        emptyIcon: Icons.people_outline,
        emptyMessage: 'No tenants yet.',
        itemBuilder: (context, t) => Card(
          child: ListTile(
            leading: CircleAvatar(
              backgroundColor: Brand.orangeLight,
              foregroundColor: Brand.orange,
              child: Text('${t['name']}'.isNotEmpty ? '${t['name']}'[0].toUpperCase() : '?'),
            ),
            title: Text('${t['name']}', style: const TextStyle(fontWeight: FontWeight.w600)),
            subtitle: Text([t['unit'] ?? 'No active lease', t['phone']].where((e) => e != null).join(' · ')),
            trailing: const Icon(Icons.chevron_right),
            onTap: () => context.push('/tenants/${t['id']}'),
          ),
        ),
      ),
      floatingActionButton: readOnly
          ? null
          : FloatingActionButton(
              backgroundColor: Brand.orange,
              foregroundColor: Colors.white,
              onPressed: () async {
                final created = await context.push<bool>('/tenants/new');
                if (created == true) _list.currentState?.reload();
              },
              child: const Icon(Icons.person_add_alt_1),
            ),
    );
  }
}
