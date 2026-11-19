import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../app/theme.dart';
import '../../core/api.dart';
import '../../core/format.dart';
import '../../core/logo.dart';
import '../../core/session.dart';
import '../../core/widgets.dart';

final dashboardProvider = FutureProvider.autoDispose<Map<String, dynamic>>((ref) => ref.read(apiProvider).get('/dashboard'));

class DashboardScreen extends ConsumerWidget {
  const DashboardScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final session = ref.watch(sessionProvider);

    return Scaffold(
      appBar: AppBar(
        titleSpacing: 12,
        title: Row(
          children: [
            const PrimoraMark(size: 30, dark: true),
            const SizedBox(width: 10),
            Expanded(child: Text('${session.landlord['name'] ?? 'Primora'}', overflow: TextOverflow.ellipsis)),
          ],
        ),
      ),
      body: AsyncBody(
        value: ref.watch(dashboardProvider),
        onRefresh: () => ref.refresh(dashboardProvider.future),
        builder: (d) {
          final units = d['units'] as Map;
          final net = d['net_income'] as Map;
          final pending = d['pending_invoices'] as Map;
          final overdue = d['overdue_invoices'] as Map;

          return ListView(
            padding: const EdgeInsets.fromLTRB(16, 16, 16, 96),
            children: [
              if (session.readOnly)
                Padding(
                  padding: const EdgeInsets.only(bottom: 12),
                  child: Card(
                    color: const Color(0xFFFEF2F2),
                    clipBehavior: Clip.antiAlias,
                    child: InkWell(
                      onTap: session.isAdmin ? () => context.push('/subscription') : null,
                      child: Padding(
                        padding: const EdgeInsets.all(14),
                        child: Row(children: [
                          Expanded(
                            child: Text(
                              'Your subscription has lapsed: you can look, but not add or change anything. '
                              '${session.isAdmin ? 'Tap to renew.' : 'Ask an admin to renew it.'}',
                              style: const TextStyle(color: Brand.red),
                            ),
                          ),
                          if (session.isAdmin) const Icon(Icons.chevron_right, color: Brand.red),
                        ]),
                      ),
                    ),
                  ),
                ),
              Card(
                color: Brand.navy,
                clipBehavior: Clip.antiAlias,
                child: InkWell(
                  onTap: session.isAdmin && session.features.contains('books') ? () => context.push('/books') : null,
                  child: Padding(
                    padding: const EdgeInsets.all(18),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text('Net income · ${net['period']}', style: const TextStyle(color: Color(0xFFC9CCD4), fontSize: 13)),
                        const SizedBox(height: 6),
                        Text(
                          ugx(toNum(net['net'])),
                          style: const TextStyle(color: Colors.white, fontSize: 28, fontWeight: FontWeight.w800),
                        ),
                        const SizedBox(height: 10),
                        Row(
                          children: [
                            _Mini('Income', ugx(toNum(net['income'])), Colors.white),
                            const SizedBox(width: 24),
                            _Mini('Expenses', ugx(toNum(net['expenses'])), Colors.white),
                          ],
                        ),
                      ],
                    ),
                  ),
                ),
              ),
              const SizedBox(height: 12),
              GridView.count(
                crossAxisCount: 2,
                shrinkWrap: true,
                physics: const NeverScrollableScrollPhysics(),
                mainAxisSpacing: 12,
                crossAxisSpacing: 12,
                childAspectRatio: 1.55,
                children: [
                  _Stat('Unpaid invoices', ugx(toNum(pending['amount'])), '${pending['count']} open', onTap: () => context.go('/invoices')),
                  _Stat(
                    'Overdue',
                    ugx(toNum(overdue['amount'])),
                    '${overdue['count']} invoices',
                    color: toNum(overdue['count']) > 0 ? Brand.red : null,
                    onTap: () => context.go('/invoices'),
                  ),
                  _Stat(
                    'Occupancy',
                    '${units['occupancy_rate']}%',
                    '${units['occupied']} of ${units['total']} units · ${units['vacant']} vacant',
                    onTap: () => context.push('/properties'),
                  ),
                  _Stat('Tenants', '${d['tenants']}', '${d['active_leases']} active leases', onTap: () => context.go('/tenants')),
                ],
              ),
              const SizedBox(height: 12),
              _MonthlyChart(months: (d['monthly'] as List).cast<Map<String, dynamic>>()),
              const SizedBox(height: 12),
              SectionCard(
                title: 'Recent payments',
                trailing: TextButton(onPressed: () => context.go('/payments'), child: const Text('All')),
                children: [
                  for (final p in (d['recent_payments'] as List).cast<Map<String, dynamic>>())
                    ListTile(
                      contentPadding: EdgeInsets.zero,
                      title: Text('${p['payer']}'),
                      subtitle: Text('${date(p['payment_date'] as String?)} · ${p['method_label']}'),
                      trailing: Text(amount(toNum(p['amount'])), style: const TextStyle(fontWeight: FontWeight.w700)),
                      onTap: () => context.push('/payments/${p['id']}'),
                    ),
                  if ((d['recent_payments'] as List).isEmpty) const Padding(padding: EdgeInsets.only(bottom: 10), child: Text('No payments yet.')),
                ],
              ),
              if ((d['expiring_leases'] as List).isNotEmpty) ...[
                const SizedBox(height: 12),
                SectionCard(
                  title: 'Leases ending within 30 days',
                  children: [
                    for (final l in (d['expiring_leases'] as List).cast<Map<String, dynamic>>())
                      ListTile(
                        contentPadding: EdgeInsets.zero,
                        title: Text('${l['tenant']}'),
                        subtitle: Text('${l['unit']}'),
                        trailing: Text(date(l['end_date'] as String?)),
                      ),
                  ],
                ),
              ],
              const SizedBox(height: 12),
              Text('Security deposits held: ${ugx(toNum(d['deposits_held']))}', style: const TextStyle(color: Brand.navyMuted)),
            ],
          );
        },
      ),
      floatingActionButton: session.readOnly
          ? null
          : FloatingActionButton.extended(
              onPressed: () => context.push('/payments/new'),
              backgroundColor: Brand.orange,
              foregroundColor: Colors.white,
              icon: const Icon(Icons.add),
              label: const Text('Record payment'),
            ),
    );
  }
}

class _Mini extends StatelessWidget {
  const _Mini(this.label, this.value, this.color);
  final String label;
  final String value;
  final Color color;

  @override
  Widget build(BuildContext context) => Column(
    crossAxisAlignment: CrossAxisAlignment.start,
    children: [
      Text(label, style: const TextStyle(color: Color(0xFF9BA0AE), fontSize: 12)),
      Text(
        value,
        style: TextStyle(color: color, fontWeight: FontWeight.w600),
      ),
    ],
  );
}

class _Stat extends StatelessWidget {
  const _Stat(this.label, this.value, this.hint, {this.color, this.onTap});
  final String label;
  final String value;
  final String hint;
  final Color? color;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    return Card(
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Text(label, style: const TextStyle(color: Brand.navyMuted, fontSize: 12)),
              FittedBox(
                fit: BoxFit.scaleDown,
                child: Text(
                  value,
                  style: TextStyle(fontSize: 20, fontWeight: FontWeight.w800, color: color ?? Brand.navy),
                ),
              ),
              Text(
                hint,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: const TextStyle(color: Brand.navyMuted, fontSize: 11),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// Income and expenses for the last six months, as simple paired bars.
class _MonthlyChart extends StatelessWidget {
  const _MonthlyChart({required this.months});
  final List<Map<String, dynamic>> months;

  @override
  Widget build(BuildContext context) {
    final top = months.fold<num>(1, (m, e) => [m, toNum(e['income']), toNum(e['expense'])].reduce((a, b) => a > b ? a : b));

    return SectionCard(
      title: 'Last 6 months',
      children: [
        SizedBox(
          height: 130,
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              for (final m in months)
                Expanded(
                  child: Column(
                    mainAxisAlignment: MainAxisAlignment.end,
                    children: [
                      Row(
                        crossAxisAlignment: CrossAxisAlignment.end,
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: [
                          _Bar(height: 100 * toNum(m['income']) / top, color: Brand.orange),
                          const SizedBox(width: 3),
                          _Bar(height: 100 * toNum(m['expense']) / top, color: const Color(0xFFC9CCD4)),
                        ],
                      ),
                      const SizedBox(height: 6),
                      Text('${m['label']}', style: const TextStyle(fontSize: 11, color: Brand.navyMuted)),
                    ],
                  ),
                ),
            ],
          ),
        ),
        const Padding(
          padding: EdgeInsets.symmetric(vertical: 8),
          child: Row(
            children: [
              _Legend(color: Brand.orange, label: 'Income'),
              SizedBox(width: 16),
              _Legend(color: Color(0xFFC9CCD4), label: 'Expenses'),
            ],
          ),
        ),
      ],
    );
  }
}

class _Bar extends StatelessWidget {
  const _Bar({required this.height, required this.color});
  final num height;
  final Color color;

  @override
  Widget build(BuildContext context) => Container(
    width: 10,
    height: height.toDouble().clamp(2, 100),
    decoration: BoxDecoration(
      color: color,
      borderRadius: const BorderRadius.vertical(top: Radius.circular(4)),
    ),
  );
}

class _Legend extends StatelessWidget {
  const _Legend({required this.color, required this.label});
  final Color color;
  final String label;

  @override
  Widget build(BuildContext context) => Row(
    children: [
      Container(
        width: 10,
        height: 10,
        decoration: BoxDecoration(color: color, borderRadius: BorderRadius.circular(2)),
      ),
      const SizedBox(width: 6),
      Text(label, style: const TextStyle(fontSize: 12, color: Brand.navyMuted)),
    ],
  );
}
