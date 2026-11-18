import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../app/theme.dart';
import '../../core/api.dart';
import '../../core/format.dart';
import '../../core/session.dart';
import '../../core/widgets.dart';

final invoiceProvider = FutureProvider.autoDispose.family<Map<String, dynamic>, int>((ref, id) => ref.read(apiProvider).get('/invoices/$id'));

class InvoiceDetailScreen extends ConsumerWidget {
  const InvoiceDetailScreen({super.key, required this.id});
  final int id;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final readOnly = ref.watch(sessionProvider).readOnly;

    return Scaffold(
      appBar: AppBar(title: const Text('Invoice')),
      body: AsyncBody(
        value: ref.watch(invoiceProvider(id)),
        onRefresh: () => ref.refresh(invoiceProvider(id).future),
        builder: (i) {
          final payments = (i['payments'] as List).cast<Map<String, dynamic>>();
          final lease = (i['lease'] as Map?)?.cast<String, dynamic>();
          final balance = toNum(i['balance']);

          return ListView(padding: const EdgeInsets.all(16), children: [
            Row(children: [
              Expanded(child: Text('${i['number']}', style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w800))),
              StatusChip.invoice(i),
            ]),
            Text('${i['title']}', style: const TextStyle(color: Brand.navyMuted)),
            const SizedBox(height: 12),
            SectionCard(children: [
              InfoRow('Billed to', i['payer'] as String?),
              if (i['unit'] != null) InfoRow('Unit', i['unit'] as String?),
              InfoRow('Invoice date', date(i['invoice_date'] as String?)),
              InfoRow('Due', date(i['due_date'] as String?)),
              const Divider(),
              InfoRow('Amount', ugx(toNum(i['amount']))),
              InfoRow('Paid', ugx(toNum(i['paid']))),
              InfoRow('Balance', ugx(balance), bold: true),
            ]),
            if (i['notes'] != null && '${i['notes']}'.isNotEmpty) ...[
              const SizedBox(height: 12),
              SectionCard(title: 'Notes', children: [Padding(padding: const EdgeInsets.only(bottom: 8), child: Text('${i['notes']}'))]),
            ],
            const SizedBox(height: 12),
            SectionCard(title: 'Payments', children: [
              if (payments.isEmpty) const Padding(padding: EdgeInsets.only(bottom: 10), child: Text('Nothing paid yet.')),
              for (final p in payments)
                ListTile(
                  contentPadding: EdgeInsets.zero,
                  title: Text('${ugx(toNum(p['applied']))} applied'),
                  subtitle: Text('${p['number']} · ${p['date']} · ${p['method']}'),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: () => context.push('/payments/${p['id']}'),
                ),
            ]),
            if (!readOnly && balance > 0 && lease != null) ...[
              const SizedBox(height: 16),
              FilledButton.icon(
                onPressed: () async {
                  final saved = await context.push<bool>('/payments/new?tenant=${lease['tenant_id']}');
                  if (saved == true) ref.invalidate(invoiceProvider(id));
                },
                icon: const Icon(Icons.add),
                label: const Text('Record a payment'),
              ),
            ],
          ]);
        },
      ),
    );
  }
}
