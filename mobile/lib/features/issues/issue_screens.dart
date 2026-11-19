import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../../core/format.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../app/theme.dart';
import '../../core/api.dart';
import '../../core/paged.dart';
import '../../core/session.dart';
import '../../core/widgets.dart';

const issueStatuses = {'open': 'Open', 'in_progress': 'In progress', 'resolved': 'Resolved', 'closed': 'Closed'};

StatusChip issueStatusChip(Object? status) => switch (status) {
      'open' => const StatusChip('Open', color: Brand.orange),
      'in_progress' => const StatusChip('In progress', color: Color(0xFF2563EB)),
      'resolved' => const StatusChip('Resolved', color: Brand.green),
      _ => const StatusChip('Closed'),
    };

IconData issueIcon(Object? category) => switch (category) {
      'repair' => Icons.build_outlined,
      'complaint' => Icons.report_outlined,
      _ => Icons.help_outline,
    };

/// Issues tenants raised in their portal: open ones first.
class IssuesScreen extends ConsumerStatefulWidget {
  const IssuesScreen({super.key});

  @override
  ConsumerState<IssuesScreen> createState() => _IssuesScreenState();
}

class _IssuesScreenState extends ConsumerState<IssuesScreen> {
  String _status = 'active';
  final _list = GlobalKey<PagedListState>();

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Issues & repairs')),
      body: PagedList(
        key: _list,
        path: '/issues',
        query: {'status': _status},
        searchHint: 'Search title, number or tenant',
        emptyIcon: Icons.handyman_outlined,
        emptyMessage: 'No issues here. Tenants report them from their portal.',
        header: SizedBox(
          height: 48,
          child: ListView(scrollDirection: Axis.horizontal, padding: const EdgeInsets.symmetric(horizontal: 16), children: [
            for (final f in const {'active': 'Open & in progress', 'resolved': 'Resolved', 'closed': 'Closed', 'all': 'All'}.entries)
              Padding(
                padding: const EdgeInsets.only(right: 8),
                child: ChoiceChip(label: Text(f.value), selected: _status == f.key, selectedColor: Brand.orangeLight, onSelected: (_) => setState(() => _status = f.key)),
              ),
          ]),
        ),
        itemBuilder: (context, i) => Card(
          child: ListTile(
            leading: CircleAvatar(backgroundColor: Brand.orangeLight, foregroundColor: Brand.orange, child: Icon(issueIcon(i['category']), size: 20)),
            title: Text('${i['title']}', style: const TextStyle(fontWeight: FontWeight.w600)),
            subtitle: Text('${i['tenant'] ?? ''}${i['unit'] != null ? ' · ${i['unit']}' : ''}\n${i['number']} · ${when(i['created_at'] as String?)}'),
            isThreeLine: true,
            trailing: issueStatusChip(i['status']),
            onTap: () async {
              await context.push('/issues/${i['id']}');
              _list.currentState?.reload();
            },
          ),
        ),
      ),
    );
  }
}

final issueProvider = FutureProvider.autoDispose.family<Map<String, dynamic>, int>((ref, id) => ref.read(apiProvider).get('/issues/$id'));

/// One issue: what the tenant reported, the conversation, and answering it.
class IssueDetailScreen extends ConsumerStatefulWidget {
  const IssueDetailScreen({super.key, required this.id});
  final int id;

  @override
  ConsumerState<IssueDetailScreen> createState() => _IssueDetailScreenState();
}

class _IssueDetailScreenState extends ConsumerState<IssueDetailScreen> {
  final _note = TextEditingController();
  String? _status;
  bool _busy = false;

  Future<void> _respond(Map<String, dynamic> issue) async {
    setState(() => _busy = true);
    try {
      await ref.read(apiProvider).post('/issues/${widget.id}/respond', data: {'status': _status ?? issue['status'], 'note': _note.text.trim()});
      _note.clear();
      ref.invalidate(issueProvider(widget.id));
      if (mounted) showMessage(context, 'Sent. The tenant sees it in their portal.');
    } on ApiException catch (e) {
      if (mounted) showMessage(context, e.message, error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final readOnly = ref.watch(sessionProvider).readOnly;

    return Scaffold(
      appBar: AppBar(title: const Text('Issue')),
      body: AsyncBody(
        value: ref.watch(issueProvider(widget.id)),
        onRefresh: () => ref.refresh(issueProvider(widget.id).future),
        builder: (i) {
          final updates = (i['updates'] as List).cast<Map<String, dynamic>>();
          _status ??= i['status'] as String?;

          return ListView(padding: const EdgeInsets.all(16), children: [
            Row(children: [
              Expanded(child: Text('${i['title']}', style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800))),
              issueStatusChip(i['status']),
            ]),
            Text('${i['number']} · ${i['category_label']} · ${when(i['created_at'] as String?)}', style: const TextStyle(color: Brand.navyMuted)),
            const SizedBox(height: 12),
            SectionCard(children: [
              InfoRow('Tenant', i['tenant'] as String?),
              InfoRow('Unit', i['unit'] as String?),
              if (i['description'] != null) Padding(padding: const EdgeInsets.symmetric(vertical: 8), child: Text('${i['description']}')),
              if (i['photo_url'] != null)
                Padding(
                  padding: const EdgeInsets.only(bottom: 10),
                  child: ClipRRect(borderRadius: BorderRadius.circular(12), child: Image.network('${i['photo_url']}', height: 200, fit: BoxFit.cover,
                      errorBuilder: (_, __, ___) => const Text('Photo could not be loaded.'))),
                ),
              Row(children: [
                if (i['tenant_phone'] != null) TextButton.icon(onPressed: () => launchUrl(Uri.parse('tel:${i['tenant_phone']}')), icon: const Icon(Icons.call, size: 18), label: const Text('Call')),
                if (i['tenant_id'] != null) TextButton(onPressed: () => context.push('/tenants/${i['tenant_id']}'), child: const Text('Tenant')),
              ]),
            ]),
            const SizedBox(height: 12),
            SectionCard(title: 'Updates', children: [
              for (final u in updates)
                Padding(
                  padding: const EdgeInsets.symmetric(vertical: 6),
                  child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Icon(u['author'] == 'tenant' ? Icons.person_outline : Icons.home_work_outlined, size: 18, color: Brand.navyMuted),
                    const SizedBox(width: 8),
                    Expanded(
                      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        Text('${u['by']} · ${when(u['at'] as String?)}', style: const TextStyle(fontSize: 12, color: Brand.navyMuted)),
                        if (u['status_label'] != null) Text('Status: ${u['status_label']}', style: const TextStyle(fontWeight: FontWeight.w600)),
                        if (u['note'] != null) Text('${u['note']}'),
                      ]),
                    ),
                  ]),
                ),
              if (updates.isEmpty) const Padding(padding: EdgeInsets.only(bottom: 10), child: Text('No updates yet.')),
            ]),
            if (!readOnly) ...[
              const SizedBox(height: 12),
              SectionCard(title: 'Reply to the tenant', children: [
                DropdownButtonFormField<String>(
                  initialValue: _status,
                  decoration: const InputDecoration(labelText: 'Status'),
                  items: [for (final s in issueStatuses.entries) DropdownMenuItem(value: s.key, child: Text(s.value))],
                  onChanged: (v) => setState(() => _status = v),
                ),
                const SizedBox(height: 12),
                TextField(controller: _note, maxLines: 3, decoration: const InputDecoration(labelText: 'Note (optional)', hintText: 'e.g. A plumber is coming on Friday')),
                const SizedBox(height: 12),
                FilledButton(onPressed: _busy ? null : () => _respond(i), child: const Text('Send update')),
                const SizedBox(height: 10),
              ]),
            ],
            const SizedBox(height: 8),
            const Text('The tenant sees each update in their portal.', style: TextStyle(fontSize: 12, color: Brand.navyMuted)),
          ]);
        },
      ),
    );
  }
}
