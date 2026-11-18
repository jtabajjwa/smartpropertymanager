import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../app/theme.dart';
import '../../core/config.dart';
import '../../core/format.dart';
import '../../core/session.dart';

/// Account, subscription, and the web portal for what the app doesn't do yet.
class MoreScreen extends ConsumerWidget {
  const MoreScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final session = ref.watch(sessionProvider);
    final landlord = session.landlord;
    final user = session.user;

    Future<void> openWeb(String path) => launchUrl(Uri.parse('${AppConfig.webBaseUrl}$path'), mode: LaunchMode.externalApplication);

    return Scaffold(
      appBar: AppBar(title: const Text('More')),
      body: ListView(padding: const EdgeInsets.all(16), children: [
        Card(
          child: ListTile(
            leading: CircleAvatar(backgroundColor: Brand.orange, foregroundColor: Colors.white, child: Text(('${user['name'] ?? '?'}').substring(0, 1).toUpperCase())),
            title: Text('${user['name'] ?? ''}'),
            subtitle: Text('${user['email'] ?? ''}\n${landlord['name'] ?? ''}${session.isAdmin ? ' · admin' : ''}'),
            isThreeLine: true,
          ),
        ),
        const SizedBox(height: 12),
        Card(
          child: Column(children: [
            ListTile(
              leading: const Icon(Icons.workspace_premium_outlined),
              title: Text('Plan: ${landlord['plan'] ?? 'Free trial'}'),
              subtitle: Text(landlord['current_period_end'] != null
                  ? '${landlord['subscription_state'] == 'trial' ? 'Trial ends' : 'Paid until'} ${date(landlord['current_period_end'] as String?)}'
                  : '${landlord['subscription_state'] ?? ''}'),
              trailing: const Icon(Icons.open_in_new, size: 18),
              onTap: () => openWeb('/subscription'),
            ),
          ]),
        ),
        const SizedBox(height: 12),
        const Padding(
          padding: EdgeInsets.fromLTRB(4, 4, 4, 8),
          child: Text('In the web portal (coming to the app)', style: TextStyle(color: Brand.navyMuted, fontWeight: FontWeight.w600)),
        ),
        Card(
          child: Column(children: [
            for (final (icon, label, path) in [
              (Icons.apartment_outlined, 'Properties & units', '/estates'),
              (Icons.description_outlined, 'Leases', '/leases'),
              (Icons.receipt_outlined, 'Expenses', '/expenses'),
              (Icons.build_outlined, 'Issues & repairs', '/issues'),
              (Icons.sms_outlined, 'SMS & bulk SMS', '/sms-logs'),
              (Icons.account_balance_outlined, 'Books & taxes', '/books'),
              (Icons.account_balance_wallet_outlined, 'Payouts', '/payouts'),
            ])
              ListTile(leading: Icon(icon), title: Text(label), trailing: const Icon(Icons.open_in_new, size: 18), onTap: () => openWeb(path)),
          ]),
        ),
        const SizedBox(height: 20),
        OutlinedButton.icon(
          onPressed: () => ref.read(sessionProvider.notifier).logout(),
          icon: const Icon(Icons.logout),
          label: const Text('Sign out'),
          style: OutlinedButton.styleFrom(foregroundColor: Brand.red, minimumSize: const Size.fromHeight(48)),
        ),
      ]),
    );
  }
}
