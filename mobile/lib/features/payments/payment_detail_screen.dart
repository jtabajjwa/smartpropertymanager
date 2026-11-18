import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:open_filex/open_filex.dart';

import '../../app/theme.dart';
import '../../core/api.dart';
import '../../core/format.dart';
import '../../core/widgets.dart';

final paymentProvider = FutureProvider.autoDispose.family<Map<String, dynamic>, int>((ref, id) => ref.read(apiProvider).get('/payments/$id'));

class PaymentDetailScreen extends ConsumerStatefulWidget {
  const PaymentDetailScreen({super.key, required this.id});
  final int id;

  @override
  ConsumerState<PaymentDetailScreen> createState() => _PaymentDetailScreenState();
}

class _PaymentDetailScreenState extends ConsumerState<PaymentDetailScreen> {
  bool _downloading = false;

  /// Downloads the PDF receipt and opens it (from there it can be shared on WhatsApp, printed, …).
  Future<void> _receipt(Map<String, dynamic> p) async {
    setState(() => _downloading = true);
    try {
      final file = await ref.read(apiProvider).download('/payments/${p['id']}/receipt', 'receipt-${p['number'] ?? p['id']}.pdf');
      await OpenFilex.open(file.path, type: 'application/pdf');
    } on ApiException catch (e) {
      if (mounted) showMessage(context, e.message, error: true);
    } finally {
      if (mounted) setState(() => _downloading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Payment')),
      body: AsyncBody(
        value: ref.watch(paymentProvider(widget.id)),
        onRefresh: () => ref.refresh(paymentProvider(widget.id).future),
        builder: (p) {
          final invoices = (p['invoices'] as List).cast<Map<String, dynamic>>();

          return ListView(padding: const EdgeInsets.all(16), children: [
            Text(ugx(toNum(p['amount'])), style: const TextStyle(fontSize: 28, fontWeight: FontWeight.w800)),
            Text('${p['number']} · from ${p['payer']}', style: const TextStyle(color: Brand.navyMuted)),
            const SizedBox(height: 12),
            SectionCard(children: [
              InfoRow('Date', date(p['payment_date'] as String?)),
              InfoRow('Method', p['method_label'] as String?),
              InfoRow('Reference', p['reference'] as String?),
              InfoRow('Status', '${p['status']}'),
              if (toNum(p['unallocated']) > 0) InfoRow('Kept as advance payment', ugx(toNum(p['unallocated'])), bold: true),
            ]),
            const SizedBox(height: 12),
            SectionCard(title: 'Paid towards', children: [
              if (invoices.isEmpty) const Padding(padding: EdgeInsets.only(bottom: 10), child: Text('Not applied to any invoice yet.')),
              for (final i in invoices)
                ListTile(
                  contentPadding: EdgeInsets.zero,
                  title: Text('${i['title']}'),
                  subtitle: Text('${i['number']}'),
                  trailing: Text(amount(toNum(i['applied'])), style: const TextStyle(fontWeight: FontWeight.w700)),
                  onTap: () => context.push('/invoices/${i['invoice_id']}'),
                ),
            ]),
            const SizedBox(height: 16),
            FilledButton.icon(
              onPressed: _downloading ? null : () => _receipt(p),
              icon: _downloading
                  ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                  : const Icon(Icons.picture_as_pdf_outlined),
              label: const Text('Receipt (PDF)'),
            ),
          ]);
        },
      ),
    );
  }
}
