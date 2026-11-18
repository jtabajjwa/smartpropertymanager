import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api.dart';
import '../../core/form.dart';
import '../../core/format.dart';
import '../../core/picker.dart';

/// Raise an invoice on a lease (rent, deposit or utility), as on the web.
class InvoiceFormScreen extends ConsumerStatefulWidget {
  const InvoiceFormScreen({super.key, this.leaseId});
  final int? leaseId;

  @override
  ConsumerState<InvoiceFormScreen> createState() => _InvoiceFormScreenState();
}

class _InvoiceFormScreenState extends ConsumerState<InvoiceFormScreen> with ApiForm {
  Map<String, dynamic>? _lease;
  String _type = 'utility';
  final _title = TextEditingController();
  final _amount = TextEditingController();
  final _notes = TextEditingController();
  DateTime _invoiceDate = DateTime.now();
  DateTime _dueDate = DateTime.now().add(const Duration(days: 7));
  bool _sms = true;

  @override
  void initState() {
    super.initState();
    if (widget.leaseId != null) {
      ref.read(apiProvider).get('/leases/${widget.leaseId}').then((l) => setState(() => _lease = l)).catchError((_) {});
    }
  }

  Future<void> _pickLease() async {
    final lease = await pickRecord(context,
        title: 'Choose a lease', path: '/leases', query: const {'status': 'active'},
        label: (l) => '${l['tenant']}', subtitle: (l) => '${l['unit']} · ${l['number']}');
    if (lease != null) setState(() => _lease = lease);
  }

  Future<void> _save() => submit(() async {
        await ref.read(apiProvider).post('/invoices', data: {
          'lease_id': _lease?['id'],
          'type': _type,
          'title': _title.text.trim(),
          'amount': _amount.text.replaceAll(',', '').trim(),
          'invoice_date': isoDate(_invoiceDate),
          'due_date': isoDate(_dueDate),
          'notes': _notes.text.trim(),
          'send_sms': _sms,
        });
      }, done: 'Invoice raised.');

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('New invoice')),
      body: ListView(padding: const EdgeInsets.all(16), children: [
        InkWell(
          onTap: _pickLease,
          child: InputDecorator(
            decoration: InputDecoration(labelText: 'Tenant and lease', errorText: errorFor('lease_id'), suffixIcon: const Icon(Icons.search)),
            child: Text(_lease == null ? 'Choose…' : '${_lease!['tenant']} · ${_lease!['unit']}'),
          ),
        ),
        gap,
        DropdownButtonFormField<String>(
          initialValue: _type,
          decoration: InputDecoration(labelText: 'Type', errorText: errorFor('type')),
          items: const [
            DropdownMenuItem(value: 'utility', child: Text('Utility (water, power, service charge…)')),
            DropdownMenuItem(value: 'rent', child: Text('Rent')),
            DropdownMenuItem(value: 'security_deposit', child: Text('Security deposit')),
          ],
          onChanged: (v) => setState(() => _type = v!),
        ),
        gap,
        TextField(controller: _title, maxLength: 100, decoration: InputDecoration(labelText: 'Title', hintText: 'e.g. Water, November', errorText: errorFor('title'))),
        TextField(controller: _amount, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: 'Amount (UGX)', errorText: errorFor('amount'))),
        gap,
        DateField(label: 'Invoice date', value: _invoiceDate, error: errorFor('invoice_date'), onChanged: (d) => setState(() => _invoiceDate = d)),
        gap,
        DateField(label: 'Due date', value: _dueDate, error: errorFor('due_date'), onChanged: (d) => setState(() => _dueDate = d)),
        gap,
        TextField(controller: _notes, maxLines: 2, decoration: const InputDecoration(labelText: 'Notes (optional)')),
        SwitchListTile(value: _sms, onChanged: (v) => setState(() => _sms = v), contentPadding: EdgeInsets.zero, title: const Text('SMS the tenant')),
        formFooter('Raise invoice', _save),
      ]),
    );
  }
}
