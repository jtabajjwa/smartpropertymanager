import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:open_filex/open_filex.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../app/theme.dart';
import '../../core/api.dart';
import '../../core/format.dart';
import '../../core/session.dart';
import '../../core/widgets.dart';

final tenantDashboardProvider = FutureProvider.autoDispose<Map<String, dynamic>>((ref) => ref.read(apiProvider).get('/tenant/dashboard'));

/// Downloads one of the tenant's documents (invoice, receipt, agreement) and opens it.
Future<void> openTenantDocument(BuildContext context, WidgetRef ref, String path, String filename) async {
  showMessage(context, 'Opening $filename…');
  try {
    final file = await ref.read(apiProvider).download(path, filename);
    await OpenFilex.open(file.path);
  } on ApiException catch (e) {
    if (context.mounted) showMessage(context, e.message, error: true);
  }
}

/// Opens a receipt or photo the landlord attached, in the browser.
Future<void> launchAttachment(BuildContext context, String url) async {
  if (!await launchUrl(Uri.parse(url), mode: LaunchMode.externalApplication) && context.mounted) {
    showMessage(context, "Couldn't open the file.", error: true);
  }
}

/// The bottom tabs for tenants: Home, Bills, Payments, Issues, Account.
class TenantShell extends StatelessWidget {
  const TenantShell({super.key, required this.shell});
  final StatefulNavigationShell shell;

  @override
  Widget build(BuildContext context) => Scaffold(
        body: shell,
        bottomNavigationBar: NavigationBar(
          selectedIndex: shell.currentIndex,
          onDestinationSelected: (i) => shell.goBranch(i, initialLocation: i == shell.currentIndex),
          destinations: const [
            NavigationDestination(icon: Icon(Icons.home_outlined), selectedIcon: Icon(Icons.home), label: 'Home'),
            NavigationDestination(icon: Icon(Icons.receipt_long_outlined), selectedIcon: Icon(Icons.receipt_long), label: 'Bills'),
            NavigationDestination(icon: Icon(Icons.payments_outlined), selectedIcon: Icon(Icons.payments), label: 'Payments'),
            NavigationDestination(icon: Icon(Icons.build_outlined), selectedIcon: Icon(Icons.build), label: 'Issues'),
            NavigationDestination(icon: Icon(Icons.person_outline), selectedIcon: Icon(Icons.person), label: 'Account'),
          ],
        ),
      );
}

/// What the tenant owes and when, paying, their home(s), recent payments and issues.
class TenantHomeScreen extends ConsumerWidget {
  const TenantHomeScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final session = ref.watch(sessionProvider);
    final firstName = '${session.tenant['name'] ?? ''}'.split(' ').first;
    final canPay = session.profile['can_pay'] == true;

    return Scaffold(
      appBar: AppBar(title: Text(firstName.isEmpty ? 'Home' : 'Hello, $firstName')),
      body: AsyncBody(
        value: ref.watch(tenantDashboardProvider),
        onRefresh: () => ref.refresh(tenantDashboardProvider.future),
        builder: (d) {
          final outstanding = toNum(d['outstanding']);
          final overdue = toNum(d['overdue']);
          final next = (d['next_due'] as Map?)?.cast<String, dynamic>();
          final awaiting = (d['awaiting'] as Map?)?.cast<String, dynamic>();
          final leases = (d['leases'] as List).cast<Map<String, dynamic>>();
          final payments = (d['payments'] as List).cast<Map<String, dynamic>>();
          final issues = (d['issues'] as List).cast<Map<String, dynamic>>();

          return ListView(padding: const EdgeInsets.fromLTRB(16, 8, 16, 32), children: spaced([
            Card(
              color: Brand.navy,
              child: Padding(
                padding: const EdgeInsets.all(18),
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text('You owe · ${session.landlord['name'] ?? ''}', style: const TextStyle(color: Color(0xFFC9CCD4), fontSize: 13)),
                  const SizedBox(height: 6),
                  Text(ugx(outstanding), style: const TextStyle(color: Colors.white, fontSize: 28, fontWeight: FontWeight.w700)),
                  const SizedBox(height: 4),
                  if (overdue > 0)
                    Text('${ugx(overdue)} is overdue', style: const TextStyle(color: Color(0xFFFCA5A5)))
                  else if (next != null)
                    Text('Next: ${ugx(toNum(next['balance']))} due ${date(next['due_date'] as String?)}', style: const TextStyle(color: Color(0xFFC9CCD4)))
                  else if (outstanding <= 0)
                    const Text("You're all paid up. Thank you!", style: TextStyle(color: Color(0xFFC9CCD4))),
                  if (toNum(d['credit']) > 0) Text('Credit: ${ugx(toNum(d['credit']))} paid in advance', style: const TextStyle(color: Color(0xFFC9CCD4))),
                  if (canPay) ...[
                    const SizedBox(height: 14),
                    Row(children: [
                      Expanded(
                        child: FilledButton(
                          onPressed: awaiting != null ? null : () => context.push('/t/pay'),
                          style: FilledButton.styleFrom(backgroundColor: Brand.orange),
                          child: Text(outstanding > 0 ? 'Pay now' : 'Pay in advance'),
                        ),
                      ),
                    ]),
                  ],
                ]),
              ),
            ),
            const UnitIdCard(),
            if (awaiting != null)
              Card(
                color: Brand.amber.withValues(alpha: 0.08),
                child: ListTile(
                  leading: const Icon(Icons.hourglass_top, color: Brand.amber),
                  title: Text('${ugx(toNum(awaiting['amount']))} waiting for approval', style: const TextStyle(color: Brand.amber, fontWeight: FontWeight.w600)),
                  subtitle: Text('Approve it on ${awaiting['phone']} with your mobile money PIN.'),
                  trailing: IconButton(icon: const Icon(Icons.refresh), onPressed: () => ref.invalidate(tenantDashboardProvider)),
                ),
              ),
            for (final l in leases)
              SectionCard(
                title: '${l['unit'] ?? 'Your home'}',
                trailing: l['status'] == 'active' ? const StatusChip('Active', color: Brand.green) : StatusChip('${l['status']}'),
                children: [
                  InfoRow('Rent', '${ugx(toNum(l['rent_amount']))} ${'${l['billing_cycle'] ?? ''}'.replaceAll('_', ' ')}'),
                  InfoRow('Started', date(l['start_date'] as String?)),
                  if (l['end_date'] != null) InfoRow('Ends', date(l['end_date'] as String?)),
                  if (l['unit_code'] != null) InfoRow('Unit ID', '${l['unit_code']}'),
                  Align(
                    alignment: Alignment.centerLeft,
                    child: TextButton.icon(
                      onPressed: () => openTenantDocument(context, ref, '/tenant/leases/${l['id']}/agreement', 'agreement-${l['number']}.pdf'),
                      icon: const Icon(Icons.description_outlined, size: 18),
                      label: const Text('Tenancy agreement'),
                    ),
                  ),
                ],
              ),
            Card(
              child: ListTile(
                leading: const Icon(Icons.savings_outlined),
                title: const Text('Security deposit'),
                subtitle: Text('${ugx(toNum(d['deposit']))} held'),
                trailing: const Icon(Icons.chevron_right),
                onTap: () => context.push('/t/deposit'),
              ),
            ),
            if (payments.isNotEmpty)
              SectionCard(
                title: 'Recent payments',
                trailing: _HeaderLink('All', () => context.go('/t/payments')),
                children: [
                  for (final p in payments)
                    Padding(
                      padding: const EdgeInsets.symmetric(vertical: 7),
                      child: Row(children: [
                        Expanded(
                          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                            Text(date(p['payment_date'] as String?), style: const TextStyle(fontWeight: FontWeight.w500)),
                            if (p['for'] != null) Text('For ${p['for']}', style: const TextStyle(color: Brand.navyMuted, fontSize: 12)),
                          ]),
                        ),
                        Text(ugx(toNum(p['amount'])), style: const TextStyle(fontWeight: FontWeight.w600)),
                      ]),
                    ),
                ],
              ),
            SectionCard(
              title: 'Issues',
              trailing: _HeaderLink('Report', () => context.push('/t/issues/new')),
              children: [
                if (issues.isEmpty) const Padding(padding: EdgeInsets.symmetric(vertical: 6), child: Text('Something broken? Report it here and your landlord is told straight away.', style: TextStyle(color: Brand.navyMuted))),
                for (final i in issues)
                  ListTile(
                    contentPadding: EdgeInsets.zero,
                    title: Text('${i['title']}'),
                    subtitle: Text('${i['number'] ?? ''} · ${date(i['created_at'] as String?)}'),
                    trailing: tenantIssueChip(i),
                    onTap: () => context.push('/t/issues/${i['id']}'),
                  ),
              ],
            ),
          ]));
        },
      ),
    );
  }
}

/// [children] with a 12px gap between each (cards have no margin of their own).
List<Widget> spaced(List<Widget> children, {double gap = 12}) => [
      for (final (i, w) in children.indexed) ...[if (i > 0) SizedBox(height: gap), w],
    ];

/// A small text link in a section's title row (no extra height).
class _HeaderLink extends StatelessWidget {
  const _HeaderLink(this.label, this.onPressed);
  final String label;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) => TextButton(
        onPressed: onPressed,
        style: TextButton.styleFrom(visualDensity: VisualDensity.compact, tapTargetSize: MaterialTapTargetSize.shrinkWrap, padding: const EdgeInsets.symmetric(horizontal: 8)),
        child: Text(label),
      );
}

StatusChip tenantIssueChip(Map<String, dynamic> issue) => switch (issue['status']) {
      'open' => StatusChip('${issue['status_label']}', color: Brand.orange),
      'in_progress' => StatusChip('${issue['status_label']}', color: const Color(0xFF2563EB)),
      'resolved' => StatusChip('${issue['status_label']}', color: Brand.green),
      _ => StatusChip('${issue['status_label']}'),
    };

/// The tenant's unit ID(s): what they key in when paying rent by USSD (and can
/// sign in with), with a button to copy it.
class UnitIdCard extends ConsumerWidget {
  const UnitIdCard({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final units = ((ref.watch(sessionProvider).profile['units'] as List?) ?? const []).cast<Map>();
    if (units.isEmpty) return const SizedBox.shrink();

    return Card(
      child: Column(children: [
        for (final u in units)
          ListTile(
            leading: const Icon(Icons.dialpad, color: Brand.orange),
            title: Text.rich(TextSpan(children: [
              const TextSpan(text: 'Unit ID  '),
              TextSpan(text: '${u['code']}', style: const TextStyle(fontWeight: FontWeight.w700, letterSpacing: 1.2)),
            ])),
            subtitle: Text('${units.length > 1 && u['unit'] != null ? '${u['unit']} · ' : ''}Enter it when paying rent by USSD'),
            trailing: IconButton(
              tooltip: 'Copy unit ID',
              icon: const Icon(Icons.copy, size: 20),
              onPressed: () async {
                await Clipboard.setData(ClipboardData(text: '${u['code']}'));
                if (context.mounted) {
                  ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Unit ID copied')));
                }
              },
            ),
          ),
      ]),
    );
  }
}
