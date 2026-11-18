import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api.dart';
import '../../core/form.dart';

/// Add a tenant: the same fields and checks as the web form (photos come later).
class TenantFormScreen extends ConsumerStatefulWidget {
  const TenantFormScreen({super.key});

  @override
  ConsumerState<TenantFormScreen> createState() => _TenantFormScreenState();
}

class _TenantFormScreenState extends ConsumerState<TenantFormScreen> with ApiForm {
  final _c = {
    for (final f in ['name', 'email', 'primary_phone', 'secondary_phone', 'id_number', 'emergency_contact_name', 'emergency_contact_phone', 'emergency_contact_email', 'notes'])
      f: TextEditingController(),
  };
  String _type = 'individual';
  String _idType = 'national_id';
  String _relationship = 'family';

  Widget _field(String key, String label, {TextInputType? keyboard, int maxLines = 1}) => Padding(
        padding: const EdgeInsets.only(bottom: 12),
        child: TextField(controller: _c[key], keyboardType: keyboard, maxLines: maxLines, decoration: InputDecoration(labelText: label, errorText: errorFor(key))),
      );

  Future<void> _save() => submit(() async {
        await ref.read(apiProvider).post('/tenants', data: {
          for (final e in _c.entries) e.key: e.value.text.trim(),
          'type': _type, 'id_type': _idType, 'emergency_contact_relationship': _relationship,
        });
      }, done: 'Tenant added.');

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('New tenant')),
      body: ListView(padding: const EdgeInsets.all(16), children: [
        SegmentedButton<String>(
          segments: const [ButtonSegment(value: 'individual', label: Text('Individual')), ButtonSegment(value: 'company', label: Text('Company'))],
          selected: {_type},
          onSelectionChanged: (s) => setState(() => _type = s.first),
        ),
        gap,
        _field('name', 'Full name'),
        _field('primary_phone', 'Phone', keyboard: TextInputType.phone),
        _field('email', 'Email', keyboard: TextInputType.emailAddress),
        _field('secondary_phone', 'Other phone (optional)', keyboard: TextInputType.phone),
        DropdownButtonFormField<String>(
          initialValue: _idType,
          decoration: InputDecoration(labelText: 'ID type', errorText: errorFor('id_type')),
          items: const [
            DropdownMenuItem(value: 'national_id', child: Text('National ID')),
            DropdownMenuItem(value: 'passport', child: Text('Passport')),
            DropdownMenuItem(value: 'drivers_license', child: Text("Driver's licence")),
            DropdownMenuItem(value: 'business_registration', child: Text('Business registration')),
            DropdownMenuItem(value: 'other', child: Text('Other')),
          ],
          onChanged: (v) => setState(() => _idType = v!),
        ),
        gap,
        _field('id_number', 'ID number'),
        const Padding(padding: EdgeInsets.fromLTRB(2, 4, 0, 10), child: Text('Emergency contact', style: TextStyle(fontWeight: FontWeight.w700))),
        _field('emergency_contact_name', 'Name'),
        DropdownButtonFormField<String>(
          initialValue: _relationship,
          decoration: const InputDecoration(labelText: 'Relationship'),
          items: const [
            DropdownMenuItem(value: 'family', child: Text('Family')),
            DropdownMenuItem(value: 'friend', child: Text('Friend')),
            DropdownMenuItem(value: 'colleague', child: Text('Colleague')),
            DropdownMenuItem(value: 'other', child: Text('Other')),
          ],
          onChanged: (v) => setState(() => _relationship = v!),
        ),
        gap,
        _field('emergency_contact_phone', 'Phone', keyboard: TextInputType.phone),
        _field('emergency_contact_email', 'Email (optional)', keyboard: TextInputType.emailAddress),
        _field('notes', 'Notes (optional)', maxLines: 3),
        formFooter('Add tenant', _save),
      ]),
    );
  }
}
