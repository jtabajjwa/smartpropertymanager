import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:image_picker/image_picker.dart';

import '../../app/theme.dart';
import '../../core/api.dart';
import '../../core/format.dart';
import '../../core/paged.dart';
import '../../core/widgets.dart';
import 'tenant_home_screen.dart';

/// The tenant's repairs, requests and complaints, open ones first.
class TenantIssuesScreen extends StatefulWidget {
  const TenantIssuesScreen({super.key});

  @override
  State<TenantIssuesScreen> createState() => _TenantIssuesScreenState();
}

class _TenantIssuesScreenState extends State<TenantIssuesScreen> {
  final _list = GlobalKey<PagedListState>();

  Future<void> _new() async {
    await context.push('/t/issues/new');
    _list.currentState?.reload();
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: const Text('Issues')),
        floatingActionButton: FloatingActionButton.extended(onPressed: _new, icon: const Icon(Icons.add), label: const Text('Report')),
        body: PagedList(
          key: _list,
          path: '/tenant/issues',
          emptyIcon: Icons.build_outlined,
          emptyMessage: 'Something broken, or need something? Report it and your landlord is told straight away.',
          itemBuilder: (context, i) => Card(
            child: ListTile(
              title: Text('${i['title']}'),
              subtitle: Text('${i['number'] ?? ''} · ${i['category_label']} · ${date(i['created_at'] as String?)}${i['unit'] != null ? '\n${i['unit']}' : ''}'),
              isThreeLine: i['unit'] != null,
              trailing: tenantIssueChip(i),
              onTap: () async {
                await context.push('/t/issues/${i['id']}');
                _list.currentState?.reload();
              },
            ),
          ),
        ),
      );
}

final _issueOptionsProvider = FutureProvider.autoDispose<Map<String, dynamic>>((ref) => ref.read(apiProvider).get('/tenant/issues/options'));

/// Report an issue, with an optional photo.
class TenantIssueFormScreen extends ConsumerStatefulWidget {
  const TenantIssueFormScreen({super.key});

  @override
  ConsumerState<TenantIssueFormScreen> createState() => _TenantIssueFormScreenState();
}

class _TenantIssueFormScreenState extends ConsumerState<TenantIssueFormScreen> {
  String _category = 'repair';
  int? _leaseId;
  final _title = TextEditingController();
  final _description = TextEditingController();
  XFile? _photo;
  bool _busy = false;
  Map<String, String> _errors = {};
  String? _error;

  @override
  void dispose() {
    _title.dispose();
    _description.dispose();
    super.dispose();
  }

  Future<void> _pickPhoto(ImageSource source) async {
    try {
      final photo = await ImagePicker().pickImage(source: source, maxWidth: 2000, imageQuality: 80);
      if (photo != null) setState(() => _photo = photo);
    } catch (_) {
      if (mounted) showMessage(context, "Couldn't open the ${source == ImageSource.camera ? 'camera' : 'gallery'}.", error: true);
    }
  }

  Future<void> _submit() async {
    setState(() {
      _busy = true;
      _errors = {};
      _error = null;
    });
    try {
      final r = await ref.read(apiProvider).post('/tenant/issues', data: FormData.fromMap({
        'category': _category,
        'title': _title.text.trim(),
        'description': _description.text.trim(),
        'lease_id': ?_leaseId,
        if (_photo != null) 'photo': await MultipartFile.fromFile(_photo!.path, filename: _photo!.name),
      }));
      ref.invalidate(tenantDashboardProvider);
      if (!mounted) return;
      showMessage(context, '${r['message']}');
      context.pushReplacement('/t/issues/${r['id']}');
    } on ApiException catch (e) {
      setState(() {
        _errors = e.fieldErrors;
        _error = e.fieldErrors.isEmpty ? e.message : null;
      });
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: const Text('Report an issue')),
        body: AsyncBody(
          value: ref.watch(_issueOptionsProvider),
          onRefresh: () => ref.refresh(_issueOptionsProvider.future),
          builder: (o) {
            final categories = (o['categories'] as List).cast<Map<String, dynamic>>();
            final leases = (o['leases'] as List).cast<Map<String, dynamic>>();

            return ListView(padding: const EdgeInsets.all(16), children: [
              SegmentedButton<String>(
                segments: [for (final c in categories) ButtonSegment(value: '${c['key']}', label: Text('${c['label']}'.split(' /').first))],
                selected: {_category},
                showSelectedIcon: false,
                onSelectionChanged: (v) => setState(() => _category = v.first),
              ),
              const SizedBox(height: 16),
              if (leases.length > 1) ...[
                DropdownButtonFormField<int>(
                  initialValue: _leaseId ?? (leases.first['id'] as num).toInt(),
                  decoration: const InputDecoration(labelText: 'Unit'),
                  items: [for (final l in leases) DropdownMenuItem(value: (l['id'] as num).toInt(), child: Text('${l['unit']}'))],
                  onChanged: (v) => setState(() => _leaseId = v),
                ),
                const SizedBox(height: 12),
              ],
              TextField(controller: _title, decoration: InputDecoration(labelText: 'Title', hintText: 'e.g. Leaking kitchen pipe', errorText: _errors['title'])),
              const SizedBox(height: 12),
              TextField(
                controller: _description,
                maxLines: 5,
                decoration: InputDecoration(labelText: 'What is wrong?', alignLabelWithHint: true, errorText: _errors['description']),
              ),
              const SizedBox(height: 12),
              if (_photo != null)
                ListTile(
                  contentPadding: EdgeInsets.zero,
                  leading: const Icon(Icons.image_outlined),
                  title: Text(_photo!.name, overflow: TextOverflow.ellipsis),
                  trailing: IconButton(icon: const Icon(Icons.close), onPressed: () => setState(() => _photo = null)),
                )
              else
                Row(children: [
                  OutlinedButton.icon(onPressed: () => _pickPhoto(ImageSource.camera), icon: const Icon(Icons.photo_camera_outlined), label: const Text('Take photo')),
                  const SizedBox(width: 8),
                  TextButton.icon(onPressed: () => _pickPhoto(ImageSource.gallery), icon: const Icon(Icons.photo_library_outlined), label: const Text('Gallery')),
                ]),
              if (_errors['photo'] != null) Text(_errors['photo']!, style: const TextStyle(color: Brand.red)),
              if (_error != null) Padding(padding: const EdgeInsets.only(top: 10), child: Text(_error!, style: const TextStyle(color: Brand.red))),
              const SizedBox(height: 20),
              FilledButton(onPressed: _busy ? null : _submit, style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(50)), child: const Text('Send to landlord')),
            ]);
          },
        ),
      );
}

final _tenantIssueProvider = FutureProvider.autoDispose.family<Map<String, dynamic>, int>((ref, id) => ref.read(apiProvider).get('/tenant/issues/$id'));

/// One issue: the report, every update from the landlord, and adding a note.
class TenantIssueDetailScreen extends ConsumerStatefulWidget {
  const TenantIssueDetailScreen({super.key, required this.id});
  final int id;

  @override
  ConsumerState<TenantIssueDetailScreen> createState() => _TenantIssueDetailScreenState();
}

class _TenantIssueDetailScreenState extends ConsumerState<TenantIssueDetailScreen> {
  final _note = TextEditingController();
  bool _busy = false;

  @override
  void dispose() {
    _note.dispose();
    super.dispose();
  }

  Future<void> _send() async {
    if (_note.text.trim().isEmpty) return;
    setState(() => _busy = true);
    try {
      final r = await ref.read(apiProvider).post('/tenant/issues/${widget.id}/notes', data: {'note': _note.text.trim()});
      _note.clear();
      ref.invalidate(_tenantIssueProvider(widget.id));
      if (mounted) showMessage(context, '${r['message']}');
    } on ApiException catch (e) {
      if (mounted) showMessage(context, e.message, error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: const Text('Issue')),
        body: AsyncBody(
          value: ref.watch(_tenantIssueProvider(widget.id)),
          onRefresh: () => ref.refresh(_tenantIssueProvider(widget.id).future),
          builder: (i) {
            final updates = (i['updates'] as List).cast<Map<String, dynamic>>();

            return ListView(padding: const EdgeInsets.all(16), children: [
              Row(children: [
                Expanded(child: Text('${i['title']}', style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800))),
                tenantIssueChip(i),
              ]),
              Text('${i['number'] ?? ''} · ${i['category_label']} · ${when(i['created_at'] as String?)}', style: const TextStyle(color: Brand.navyMuted)),
              const SizedBox(height: 12),
              SectionCard(children: [
                if (i['unit'] != null) InfoRow('Unit', '${i['unit']}'),
                Padding(padding: const EdgeInsets.symmetric(vertical: 8), child: Text('${i['description'] ?? ''}')),
                if (i['photo_url'] != null)
                  Padding(
                    padding: const EdgeInsets.only(bottom: 10),
                    child: ClipRRect(
                      borderRadius: BorderRadius.circular(12),
                      child: Image.network('${i['photo_url']}', height: 200, fit: BoxFit.cover, errorBuilder: (_, __, ___) => const Text('Photo could not be loaded.')),
                    ),
                  ),
              ]),
              const SizedBox(height: 12),
              SectionCard(title: 'Updates', children: [
                for (final u in updates)
                  Padding(
                    padding: const EdgeInsets.symmetric(vertical: 6),
                    child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Icon(u['mine'] == true ? Icons.person_outline : Icons.home_work_outlined, size: 18, color: Brand.navyMuted),
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
              ]),
              const SizedBox(height: 12),
              if (i['can_comment'] == true)
                SectionCard(title: 'Add a note', children: [
                  TextField(
                    controller: _note,
                    maxLines: 3,
                    decoration: InputDecoration(hintText: i['status'] == 'resolved' ? 'Not fixed? Say so and the issue re-opens.' : 'e.g. Still leaking'),
                  ),
                  const SizedBox(height: 12),
                  FilledButton(onPressed: _busy ? null : _send, child: const Text('Send')),
                  const SizedBox(height: 10),
                ])
              else
                const Text('This issue is closed. If something is still wrong, please report a new one.', style: TextStyle(color: Brand.navyMuted)),
            ]);
          },
        ),
      );
}
