import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../app/theme.dart';
import '../../core/format.dart';
import '../../core/paged.dart';
import '../../core/session.dart';
import '../../core/widgets.dart';

class InvoicesScreen extends ConsumerStatefulWidget {
  const InvoicesScreen({super.key});

  @override
  ConsumerState<InvoicesScreen> createState() => _InvoicesScreenState();
}

class _InvoicesScreenState extends ConsumerState<InvoicesScreen> {
  static const _filters = {'open': 'Unpaid', 'overdue': 'Overdue', 'paid': 'Paid', '': 'All'};
  String _status = 'open';
  final _list = GlobalKey<PagedListState>();

  @override
  Widget build(BuildContext context) {
    final readOnly = ref.watch(sessionProvider).readOnly;

    return Scaffold(
      appBar: AppBar(title: const Text('Invoices')),
      body: PagedList(
        key: _list,
        path: '/invoices',
        query: {'status': _status},
        searchHint: 'Search number, title or tenant',
        emptyIcon: Icons.receipt_long_outlined,
        emptyMessage: 'No invoices here.',
        header: SizedBox(
          height: 48,
          child: ListView(scrollDirection: Axis.horizontal, padding: const EdgeInsets.symmetric(horizontal: 16), children: [
            for (final f in _filters.entries)
              Padding(
                padding: const EdgeInsets.only(right: 8),
                child: ChoiceChip(
                  label: Text(f.value),
                  selected: _status == f.key,
                  selectedColor: Brand.orangeLight,
                  onSelected: (_) => setState(() => _status = f.key),
                ),
              ),
          ]),
        ),
        itemBuilder: (context, i) => Card(
          child: ListTile(
            title: Text('${i['payer']}', style: const TextStyle(fontWeight: FontWeight.w600)),
            subtitle: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text('${i['title']}'),
              const SizedBox(height: 4),
              Row(children: [Text('Due ${date(i['due_date'] as String?)}  ', style: const TextStyle(fontSize: 12)), StatusChip.invoice(i)]),
            ]),
            trailing: Column(mainAxisAlignment: MainAxisAlignment.center, crossAxisAlignment: CrossAxisAlignment.end, children: [
              Text(amount(toNum(i['amount'])), style: const TextStyle(fontWeight: FontWeight.w700)),
              if (toNum(i['balance']) > 0 && toNum(i['balance']) < toNum(i['amount']))
                Text('${amount(toNum(i['balance']))} left', style: const TextStyle(fontSize: 11, color: Brand.navyMuted)),
            ]),
            onTap: () => context.push('/invoices/${i['id']}'),
          ),
        ),
      ),
      floatingActionButton: readOnly
          ? null
          : FloatingActionButton(
              backgroundColor: Brand.orange,
              foregroundColor: Colors.white,
              onPressed: () async {
                final created = await context.push<bool>('/invoices/new');
                if (created == true) _list.currentState?.reload();
              },
              child: const Icon(Icons.add),
            ),
    );
  }
}
