import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api.dart';
import '../../core/form.dart';
import '../../core/format.dart';
import '../../core/picker.dart';

/// Record money a tenant paid (cash, bank, mobile money), as on the web:
/// optionally put it on their unpaid invoices, and SMS them a receipt.
class PaymentFormScreen extends ConsumerStatefulWidget {
  const PaymentFormScreen({super.key, this.tenantId});
  final int? tenantId;

  @override
  ConsumerState<PaymentFormScreen> createState() => _PaymentFormScreenState();
}

class _PaymentFormScreenState extends ConsumerState<PaymentFormScreen> with ApiForm {
  static const _methods = {
    'cash': 'Cash',
    'mtn_mobile_money': 'MTN Mobile Money',
    'airtel_mobile_money': 'Airtel Money',
    'bank_transfer': 'Bank transfer',
    'flexipay': 'FlexiPay',
  };

  Map<String, dynamic>? _tenant;
  num? _owes;
  final _amount = TextEditingController();
  final _reference = TextEditingController();
  final _notes = TextEditingController();
  DateTime _date = DateTime.now();
  String _method = 'cash';
  bool _apply = true;
  bool _sms = true;

  @override
  void initState() {
    super.initState();
    if (widget.tenantId != null) _loadTenant(widget.tenantId!);
  }

  Future<void> _loadTenant(int id) async {
    try {
      final t = await ref.read(apiProvider).get('/tenants/$id');
      setState(() {
        _tenant = t;
        _owes = toNum(t['balance']);
        if (_amount.text.isEmpty && _owes! > 0) _amount.text = '${_owes!.round()}';
      });
    } on ApiException catch (_) {}
  }

  Future<void> _pickTenant() async {
    final t = await pickRecord(context, title: 'Who paid?', path: '/tenants',
        label: (t) => '${t['name']}', subtitle: (t) => '${t['unit'] ?? 'No active lease'} · ${t['phone']}');
    if (t != null) {
      setState(() => _tenant = t);
      _loadTenant(t['id'] as int);
    }
  }

  Future<void> _save() => submit(() async {
        await ref.read(apiProvider).post('/payments', data: {
          'tenant_id': _tenant?['id'],
          'amount': _amount.text.replaceAll(',', '').trim(),
          'payment_date': isoDate(_date),
          'payment_method': _method,
          'reference': _reference.text.trim(),
          'notes': _notes.text.trim(),
          'apply_to_invoices': _apply,
          'send_sms': _sms,
        });
      }, done: 'Payment recorded.');

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Record a payment')),
      body: ListView(padding: const EdgeInsets.all(16), children: [
        InkWell(
          onTap: _pickTenant,
          child: InputDecorator(
            decoration: InputDecoration(labelText: 'Tenant', errorText: errorFor('tenant_id'), suffixIcon: const Icon(Icons.search)),
            child: Text(_tenant == null ? 'Choose…' : '${_tenant!['name']}'),
          ),
        ),
        if (_owes != null)
          Padding(padding: const EdgeInsets.only(top: 6, left: 4), child: Text(_owes! > 0 ? 'Owes ${ugx(_owes)}' : 'Nothing owed right now')),
        gap,
        TextField(controller: _amount, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: 'Amount (UGX)', errorText: errorFor('amount'))),
        gap,
        DropdownButtonFormField<String>(
          initialValue: _method,
          decoration: InputDecoration(labelText: 'How they paid', errorText: errorFor('payment_method')),
          items: [for (final m in _methods.entries) DropdownMenuItem(value: m.key, child: Text(m.value))],
          onChanged: (v) => setState(() => _method = v!),
        ),
        gap,
        DateField(label: 'Date received', value: _date, error: errorFor('payment_date'), onChanged: (d) => setState(() => _date = d)),
        gap,
        TextField(controller: _reference, decoration: InputDecoration(labelText: 'Reference (optional)', hintText: 'e.g. mobile money transaction ID', errorText: errorFor('reference'))),
        gap,
        TextField(controller: _notes, maxLines: 2, decoration: const InputDecoration(labelText: 'Notes (optional)')),
        SwitchListTile(value: _apply, onChanged: (v) => setState(() => _apply = v), contentPadding: EdgeInsets.zero,
            title: const Text('Pay their unpaid invoices'), subtitle: const Text('Oldest first; anything extra is kept as advance payment')),
        SwitchListTile(value: _sms, onChanged: (v) => setState(() => _sms = v), contentPadding: EdgeInsets.zero, title: const Text('SMS the tenant a receipt')),
        formFooter('Record payment', _save),
      ]),
    );
  }
}
