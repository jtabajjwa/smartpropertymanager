import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../app/theme.dart';
import '../../core/api.dart';
import '../../core/session.dart';
import '../../core/widgets.dart';

/// The tenant's details, their landlord's contacts, switching tenancy (one phone, several landlords), and signing out.
class TenantAccountScreen extends ConsumerWidget {
  const TenantAccountScreen({super.key});

  Future<void> _switch(BuildContext context, WidgetRef ref) async {
    final List<Map<String, dynamic>> accounts;
    final int current;
    try {
      final r = await ref.read(apiProvider).get('/tenant/accounts');
      accounts = (r['accounts'] as List).cast<Map<String, dynamic>>();
      current = (r['current'] as num).toInt();
    } on ApiException catch (e) {
      if (context.mounted) showMessage(context, e.message, error: true);
      return;
    }
    if (!context.mounted) return;

    final chosen = await showModalBottomSheet<int>(
      context: context,
      builder: (sheet) => SafeArea(
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          const Padding(padding: EdgeInsets.all(16), child: Text('Switch account', style: TextStyle(fontSize: 18, fontWeight: FontWeight.w700))),
          for (final a in accounts)
            ListTile(
              leading: Icon(a['id'] == current ? Icons.check_circle : Icons.home_work_outlined, color: a['id'] == current ? Brand.green : null),
              title: Text('${a['landlord'] ?? a['name']}'),
              subtitle: Text(((a['units'] as List?) ?? const []).join(', ')),
              onTap: () => Navigator.of(sheet).pop((a['id'] as num).toInt()),
            ),
          const SizedBox(height: 8),
        ]),
      ),
    );
    if (chosen == null || chosen == current) return;
    try {
      await ref.read(sessionProvider.notifier).switchTenancy(chosen);
      if (context.mounted) context.go('/t/home');
    } on ApiException catch (e) {
      if (context.mounted) showMessage(context, e.message, error: true);
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final session = ref.watch(sessionProvider);
    final tenant = session.tenant;
    final landlord = session.landlord;
    final name = '${tenant['name'] ?? '?'}';

    return Scaffold(
      appBar: AppBar(title: const Text('Account')),
      body: ListView(padding: const EdgeInsets.all(16), children: [
        Card(
          child: ListTile(
            leading: CircleAvatar(backgroundColor: Brand.orange, foregroundColor: Colors.white, child: Text(name.isEmpty ? '?' : name.substring(0, 1).toUpperCase())),
            title: Text(name),
            subtitle: Text([tenant['number'], tenant['phone'], tenant['email']].where((v) => v != null && '$v'.isNotEmpty).join('\n')),
            isThreeLine: true,
          ),
        ),
        const SizedBox(height: 12),
        SectionCard(title: 'Your landlord', children: [
          InfoRow('Name', landlord['name'] as String?),
          Row(children: [
            if (landlord['phone'] != null) ...[
              TextButton.icon(onPressed: () => launchUrl(Uri.parse('tel:${landlord['phone']}')), icon: const Icon(Icons.call, size: 18), label: const Text('Call')),
              TextButton.icon(onPressed: () => launchUrl(Uri.parse('sms:${landlord['phone']}')), icon: const Icon(Icons.sms_outlined, size: 18), label: const Text('SMS')),
            ],
            if (landlord['email'] != null)
              TextButton.icon(onPressed: () => launchUrl(Uri.parse('mailto:${landlord['email']}')), icon: const Icon(Icons.email_outlined, size: 18), label: const Text('Email')),
          ]),
        ]),
        const SizedBox(height: 12),
        Card(
          child: Column(children: [
            ListTile(leading: const Icon(Icons.savings_outlined), title: const Text('Security deposit'), trailing: const Icon(Icons.chevron_right), onTap: () => context.push('/t/deposit')),
            if ((session.profile['account_count'] as num? ?? 1) > 1)
              ListTile(leading: const Icon(Icons.swap_horiz), title: const Text('Switch account'), subtitle: const Text('Your number is on more than one tenancy'), onTap: () => _switch(context, ref)),
          ]),
        ),
        const SizedBox(height: 8),
        if (session.profile['signed_in_with'] != null)
          Text('Signed in with ${session.profile['signed_in_with']}', textAlign: TextAlign.center, style: const TextStyle(color: Brand.navyMuted, fontSize: 12)),
        const SizedBox(height: 16),
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
