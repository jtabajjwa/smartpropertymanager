import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../app/theme.dart';
import '../../core/api.dart';
import '../../core/format.dart';
import '../../core/session.dart';
import '../../core/widgets.dart';

final tenantProvider = FutureProvider.autoDispose.family<Map<String, dynamic>, int>((ref, id) => ref.read(apiProvider).get('/tenants/$id'));

class TenantDetailScreen extends ConsumerWidget {
  const TenantDetailScreen({super.key, required this.id});
  final int id;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final readOnly = ref.watch(sessionProvider).readOnly;

    return Scaffold(
      appBar: AppBar(title: const Text('Tenant')),
      body: AsyncBody(
        value: ref.watch(tenantProvider(id)),
        onRefresh: () => ref.refresh(tenantProvider(id).future),
        builder: (t) {
          final leases = (t['leases'] as List).cast<Map<String, dynamic>>();
          final invoices = (t['invoices'] as List).cast<Map<String, dynamic>>();
          final payments = (t['payments'] as List).cast<Map<String, dynamic>>();
          final contact = (t['emergency_contact'] as Map).cast<String, dynamic>();
          final activeLease = leases.where((l) => l['status'] == 'active').firstOrNull;

          return ListView(padding: const EdgeInsets.all(16), children: [
            Text('${t['name']}', style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w800)),
            Text('${t['number']} · ${t['unit'] ?? 'No active lease'}', style: const TextStyle(color: Brand.navyMuted)),
            const SizedBox(height: 12),
            Row(children: [
              Expanded(child: _Figure('Owes', ugx(toNum(t['balance'])), toNum(t['balance']) > 0 ? Brand.red : Brand.green)),
              const SizedBox(width: 12),
              Expanded(child: _Figure('Deposit held', ugx(toNum(t['deposit_balance'])), Brand.navy)),
            ]),
            const SizedBox(height: 12),
            Row(children: [
              Expanded(child: OutlinedButton.icon(onPressed: () => launchUrl(Uri.parse('tel:${t['phone']}')), icon: const Icon(Icons.call), label: const Text('Call'))),
              const SizedBox(width: 8),
              Expanded(child: OutlinedButton.icon(onPressed: () => launchUrl(Uri.parse('sms:${t['phone']}')), icon: const Icon(Icons.sms_outlined), label: const Text('SMS'))),
              if (!readOnly) ...[
                const SizedBox(width: 8),
                Expanded(
                  child: FilledButton.icon(
                    style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(40)),
                    onPressed: () async {
                      final saved = await context.push<bool>('/payments/new?tenant=${t['id']}');
                      if (saved == true) ref.invalidate(tenantProvider(id));
                    },
                    icon: const Icon(Icons.add),
                    label: const Text('Payment'),
                  ),
                ),
              ],
            ]),
            const SizedBox(height: 12),
            SectionCard(title: 'Contact', children: [
              InfoRow('Phone', t['phone'] as String?),
              InfoRow('Email', t['email'] as String?),
              InfoRow('ID', [t['id_type'], t['id_number']].where((e) => e != null).join(' ').replaceAll('_', ' ')),
              InfoRow('Emergency contact', [contact['name'], contact['relationship'], contact['phone']].where((e) => e != null).join(' · ')),
            ]),
            if (activeLease == null && !readOnly) ...[
              const SizedBox(height: 12),
              OutlinedButton.icon(
                onPressed: () async {
                  final saved = await context.push<bool>('/leases/new?tenant=${t['id']}');
                  if (saved == true) ref.invalidate(tenantProvider(id));
                },
                icon: const Icon(Icons.key_outlined),
                label: const Text('Start a lease for this tenant'),
              ),
            ],
            if (activeLease != null) ...[
              const SizedBox(height: 12),
              SectionCard(
                title: 'Lease ${activeLease['number']}',
                trailing: readOnly
                    ? null
                    : TextButton(
                        onPressed: () async {
                          final saved = await context.push<bool>('/invoices/new?lease=${activeLease['id']}');
                          if (saved == true) ref.invalidate(tenantProvider(id));
                        },
                        child: const Text('New invoice'),
                      ),
                children: [
                  InfoRow('Unit', activeLease['unit'] as String?),
                  InfoRow('Rent', '${ugx(toNum(activeLease['rent_amount']))} ${activeLease['billing_cycle']}'),
                  InfoRow('Started', date(activeLease['start_date'] as String?)),
                  InfoRow('Next invoice', date(activeLease['next_invoice_date'] as String?)),
                  Align(alignment: Alignment.centerLeft, child: TextButton(onPressed: () => context.push('/leases/${activeLease['id']}'), child: const Text('Open lease'))),
                ],
              ),
            ],
            const SizedBox(height: 12),
            SectionCard(title: 'Invoices', children: [
              if (invoices.isEmpty) const Padding(padding: EdgeInsets.only(bottom: 10), child: Text('None.')),
              for (final i in invoices)
                ListTile(
                  contentPadding: EdgeInsets.zero,
                  title: Text('${i['title']}'),
                  subtitle: Row(children: [Text('${i['number']} · due ${date(i['due_date'] as String?)}  '), StatusChip.invoice(i)]),
                  trailing: Text(amount(toNum(i['balance']) > 0 ? toNum(i['balance']) : toNum(i['amount'])), style: const TextStyle(fontWeight: FontWeight.w700)),
                  onTap: () => context.push('/invoices/${i['id']}'),
                ),
            ]),
            const SizedBox(height: 12),
            SectionCard(title: 'Payments', children: [
              if (payments.isEmpty) const Padding(padding: EdgeInsets.only(bottom: 10), child: Text('None.')),
              for (final p in payments)
                ListTile(
                  contentPadding: EdgeInsets.zero,
                  title: Text(ugx(toNum(p['amount']))),
                  subtitle: Text('${date(p['payment_date'] as String?)} · ${p['method_label']}'),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: () => context.push('/payments/${p['id']}'),
                ),
            ]),
          ]);
        },
      ),
    );
  }
}

class _Figure extends StatelessWidget {
  const _Figure(this.label, this.value, this.color);
  final String label;
  final String value;
  final Color color;

  @override
  Widget build(BuildContext context) => Card(
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(label, style: const TextStyle(color: Brand.navyMuted, fontSize: 12)),
            const SizedBox(height: 4),
            FittedBox(fit: BoxFit.scaleDown, child: Text(value, style: TextStyle(fontSize: 18, fontWeight: FontWeight.w800, color: color))),
          ]),
        ),
      );
}
