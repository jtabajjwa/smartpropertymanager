import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../app/theme.dart';
import '../../core/format.dart';
import '../../core/paged.dart';
import '../../core/session.dart';

class PaymentsScreen extends ConsumerStatefulWidget {
  const PaymentsScreen({super.key});

  @override
  ConsumerState<PaymentsScreen> createState() => _PaymentsScreenState();
}

class _PaymentsScreenState extends ConsumerState<PaymentsScreen> {
  final _list = GlobalKey<PagedListState>();

  @override
  Widget build(BuildContext context) {
    final readOnly = ref.watch(sessionProvider).readOnly;

    return Scaffold(
      appBar: AppBar(title: const Text('Payments')),
      body: PagedList(
        key: _list,
        path: '/payments',
        searchHint: 'Search number, reference or payer',
        emptyIcon: Icons.payments_outlined,
        emptyMessage: 'No payments recorded yet.',
        itemBuilder: (context, p) => Card(
          child: ListTile(
            leading: const CircleAvatar(backgroundColor: Color(0xFFECFDF5), foregroundColor: Brand.green, child: Icon(Icons.south_west, size: 18)),
            title: Text('${p['payer']}', style: const TextStyle(fontWeight: FontWeight.w600)),
            subtitle: Text('${date(p['payment_date'] as String?)} · ${p['method_label']}${p['status'] == 'cancelled' ? ' · cancelled' : ''}'),
            trailing: Text(amount(toNum(p['amount'])), style: TextStyle(fontWeight: FontWeight.w700, decoration: p['status'] == 'cancelled' ? TextDecoration.lineThrough : null)),
            onTap: () => context.push('/payments/${p['id']}'),
          ),
        ),
      ),
      floatingActionButton: readOnly
          ? null
          : FloatingActionButton.extended(
              backgroundColor: Brand.orange,
              foregroundColor: Colors.white,
              onPressed: () async {
                final created = await context.push<bool>('/payments/new');
                if (created == true) _list.currentState?.reload();
              },
              icon: const Icon(Icons.add),
              label: const Text('Record'),
            ),
    );
  }
}
