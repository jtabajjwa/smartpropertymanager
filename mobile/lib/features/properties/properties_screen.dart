import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../app/theme.dart';
import '../../core/paged.dart';
import '../../core/session.dart';

/// Properties (estates) with how full each one is.
class PropertiesScreen extends ConsumerStatefulWidget {
  const PropertiesScreen({super.key});

  @override
  ConsumerState<PropertiesScreen> createState() => _PropertiesScreenState();
}

class _PropertiesScreenState extends ConsumerState<PropertiesScreen> {
  final _list = GlobalKey<PagedListState>();

  @override
  Widget build(BuildContext context) {
    final readOnly = ref.watch(sessionProvider).readOnly;

    return Scaffold(
      appBar: AppBar(title: const Text('Properties')),
      body: PagedList(
        key: _list,
        path: '/estates',
        searchHint: 'Search properties',
        emptyIcon: Icons.apartment_outlined,
        emptyMessage: 'No properties yet. Add your first one.',
        itemBuilder: (context, e) {
          final total = (e['unit_count'] as num?)?.toInt() ?? 0;
          final occupied = (e['occupied_count'] as num?)?.toInt() ?? 0;

          return Card(
            clipBehavior: Clip.antiAlias,
            child: InkWell(
              onTap: () async {
                await context.push('/properties/${e['id']}');
                _list.currentState?.reload();
              },
              child: Padding(
                padding: const EdgeInsets.all(14),
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Row(children: [
                    const Icon(Icons.apartment, color: Brand.orange),
                    const SizedBox(width: 10),
                    Expanded(child: Text('${e['name']}', style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16))),
                    const Icon(Icons.chevron_right, color: Brand.navyMuted),
                  ]),
                  if ((e['district'] ?? e['address']) != null)
                    Padding(
                      padding: const EdgeInsets.only(top: 4, left: 34),
                      child: Text([e['address'], e['district']].where((v) => v != null && '$v'.isNotEmpty).join(', '),
                          style: const TextStyle(color: Brand.navyMuted, fontSize: 13)),
                    ),
                  const SizedBox(height: 10),
                  ClipRRect(
                    borderRadius: BorderRadius.circular(4),
                    child: LinearProgressIndicator(
                      value: total == 0 ? 0 : occupied / total,
                      minHeight: 6,
                      backgroundColor: const Color(0xFFE5E7EB),
                      color: Brand.green,
                    ),
                  ),
                  const SizedBox(height: 6),
                  Text('$occupied of $total ${total == 1 ? 'unit' : 'units'} occupied', style: const TextStyle(fontSize: 12, color: Brand.navyMuted)),
                ]),
              ),
            ),
          );
        },
      ),
      floatingActionButton: readOnly
          ? null
          : FloatingActionButton(
              backgroundColor: Brand.orange,
              foregroundColor: Colors.white,
              onPressed: () async {
                final saved = await context.push<bool>('/properties/new');
                if (saved == true) _list.currentState?.reload();
              },
              child: const Icon(Icons.add_home_outlined),
            ),
    );
  }
}
