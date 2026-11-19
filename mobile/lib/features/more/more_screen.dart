import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../app/theme.dart';
import '../../core/format.dart';
import '../../core/session.dart';

/// Account, plan, and the sections that aren't tabs.
class MoreScreen extends ConsumerWidget {
  const MoreScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final session = ref.watch(sessionProvider);
    final landlord = session.landlord;
    final user = session.user;

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
              trailing: session.isAdmin ? const Icon(Icons.chevron_right) : null,
              onTap: session.isAdmin ? () => context.push('/subscription') : null,
            ),
          ]),
        ),
        const SizedBox(height: 12),
        Card(
          child: Column(children: [
            ListTile(leading: const Icon(Icons.apartment_outlined), title: const Text('Properties & units'), trailing: const Icon(Icons.chevron_right), onTap: () => context.push('/properties')),
            ListTile(leading: const Icon(Icons.description_outlined), title: const Text('Leases'), trailing: const Icon(Icons.chevron_right), onTap: () => context.push('/leases')),
            ListTile(leading: const Icon(Icons.receipt_outlined), title: const Text('Expenses'), trailing: const Icon(Icons.chevron_right), onTap: () => context.push('/expenses')),
            ListTile(leading: const Icon(Icons.build_outlined), title: const Text('Issues & repairs'), trailing: const Icon(Icons.chevron_right), onTap: () => context.push('/issues')),
            if (session.isAdmin)
              ListTile(leading: const Icon(Icons.sms_outlined), title: const Text('SMS & bulk SMS'), trailing: const Icon(Icons.chevron_right), onTap: () => context.push('/sms')),
            if (session.isAdmin)
              ListTile(leading: const Icon(Icons.account_balance_wallet_outlined), title: const Text('Payouts'), trailing: const Icon(Icons.chevron_right), onTap: () => context.push('/payouts')),
            if (session.isAdmin && session.features.contains('books'))
              ListTile(leading: const Icon(Icons.account_balance_outlined), title: const Text('Books & taxes'), trailing: const Icon(Icons.chevron_right), onTap: () => context.push('/books')),
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
