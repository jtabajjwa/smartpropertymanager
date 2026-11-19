import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../app/theme.dart';
import '../../core/api.dart';
import '../../core/form.dart';
import '../../core/format.dart';
import '../../core/paged.dart';
import '../../core/session.dart';
import '../../core/widgets.dart';

final expenseOptionsProvider = FutureProvider.autoDispose<Map<String, dynamic>>((ref) => ref.read(apiProvider).get('/expenses/options'));

/// Expenses, newest first, with the total for what's shown.
class ExpensesScreen extends ConsumerStatefulWidget {
  const ExpensesScreen({super.key});

  @override
  ConsumerState<ExpensesScreen> createState() => _ExpensesScreenState();
}

class _ExpensesScreenState extends ConsumerState<ExpensesScreen> {
  final _list = GlobalKey<PagedListState>();
  String _range = 'month';

  Map<String, dynamic> get _query {
    final now = DateTime.now();
    return switch (_range) {
      'month' => {'date_from': isoDate(DateTime(now.year, now.month, 1))},
      'last' => {'date_from': isoDate(DateTime(now.year, now.month - 1, 1)), 'date_to': isoDate(DateTime(now.year, now.month, 0))},
      'year' => {'date_from': isoDate(DateTime(now.year, 1, 1))},
      _ => <String, dynamic>{},
    };
  }

  @override
  Widget build(BuildContext context) {
    final readOnly = ref.watch(sessionProvider).readOnly;

    return Scaffold(
      appBar: AppBar(title: const Text('Expenses')),
      body: PagedList(
        key: _list,
        path: '/expenses',
        query: _query,
        searchHint: 'Search type, number or notes',
        emptyIcon: Icons.receipt_outlined,
        emptyMessage: 'No expenses in this period.',
        header: SizedBox(
          height: 48,
          child: ListView(scrollDirection: Axis.horizontal, padding: const EdgeInsets.symmetric(horizontal: 16), children: [
            for (final f in const {'month': 'This month', 'last': 'Last month', 'year': 'This year', 'all': 'All'}.entries)
              Padding(
                padding: const EdgeInsets.only(right: 8),
                child: ChoiceChip(label: Text(f.value), selected: _range == f.key, selectedColor: Brand.orangeLight, onSelected: (_) => setState(() => _range = f.key)),
              ),
          ]),
        ),
        itemBuilder: (context, e) {
          final fromDeposit = e['from_deposit'] as Map?;
          return Card(
            child: ListTile(
              title: Text('${e['item'] ?? 'Expense'}', style: const TextStyle(fontWeight: FontWeight.w600)),
              subtitle: Text([
                date(e['date'] as String?),
                [e['estate'], e['unit']].where((v) => v != null).join(' · '),
                if (fromDeposit != null) 'from ${fromDeposit['tenant']}\'s deposit',
              ].where((v) => v.isNotEmpty).join(' · ')),
              trailing: Text(amount(toNum(e['amount'])), style: const TextStyle(fontWeight: FontWeight.w700)),
              onTap: () async {
                final changed = await context.push<bool>('/expenses/${e['id']}');
                if (changed == true) _list.currentState?.reload();
              },
            ),
          );
        },
      ),
      floatingActionButton: readOnly
          ? null
          : FloatingActionButton.extended(
              backgroundColor: Brand.orange,
              foregroundColor: Colors.white,
              onPressed: () async {
                final saved = await context.push<bool>('/expenses/new');
                if (saved == true) _list.currentState?.reload();
              },
              icon: const Icon(Icons.add),
              label: const Text('Expense'),
            ),
    );
  }
}

/// Record an expense, or change one (amount, date, type, property, notes).
/// A new one can be paid from a tenant's security deposit.
class ExpenseFormScreen extends ConsumerStatefulWidget {
  const ExpenseFormScreen({super.key, this.id});
  final int? id;

  @override
  ConsumerState<ExpenseFormScreen> createState() => _ExpenseFormScreenState();
}

class _ExpenseFormScreenState extends ConsumerState<ExpenseFormScreen> with ApiForm {
  final _amount = TextEditingController();
  final _notes = TextEditingController();
  DateTime _date = DateTime.now();
  int? _itemId;
  int? _estateId;
  int? _unitId;
  List<Map<String, dynamic>> _units = [];
  bool _fromDeposit = false;
  int? _depositLeaseId;
  String _depositCategory = 'damage_repair';
  Map<String, dynamic>? _existing;
  bool _loading = false;

  @override
  void initState() {
    super.initState();
    if (widget.id != null) _load();
  }

  Future<void> _load() async {
    setState(() => _loading = true);
    try {
      final e = await ref.read(apiProvider).get('/expenses/${widget.id}');
      _existing = e;
      _amount.text = '${toNum(e['amount']).round()}';
      _notes.text = '${e['notes'] ?? ''}';
      _date = DateTime.tryParse('${e['date']}') ?? DateTime.now();
      _itemId = (e['item_id'] as num?)?.toInt();
      _estateId = (e['estate_id'] as num?)?.toInt();
      _unitId = (e['unit_id'] as num?)?.toInt();
      await _loadUnits();
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _loadUnits() async {
    if (_estateId == null) return;
    final data = await ref.read(apiProvider).get('/units', query: {'estate_id': _estateId});
    if (mounted) setState(() => _units = ((data['data'] as List?) ?? []).cast<Map<String, dynamic>>());
  }

  Future<void> _save() => submit(() async {
        final data = {
          'date': isoDate(_date),
          'expense_item_id': _itemId,
          'estate_id': _estateId,
          'unit_id': _unitId,
          'amount': _amount.text.replaceAll(',', '').trim(),
          'notes': _notes.text.trim(),
          if (widget.id == null && _fromDeposit) ...{'paid_from_deposit': true, 'deposit_lease_id': _depositLeaseId, 'deposit_category': _depositCategory},
        };
        final api = ref.read(apiProvider);
        widget.id == null ? await api.post('/expenses', data: data) : await api.put('/expenses/${widget.id}', data: data);
      }, done: widget.id == null ? 'Expense recorded.' : 'Expense saved.');

  Future<void> _delete() async {
    final fromDeposit = _existing?['from_deposit'] != null;
    final ok = await showDialog<bool>(
      context: context,
      builder: (c) => AlertDialog(
        title: const Text('Delete this expense?'),
        content: Text(fromDeposit ? 'The amount goes back into the tenant\'s security deposit.' : 'This can\'t be undone.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(c, false), child: const Text('Cancel')),
          TextButton(onPressed: () => Navigator.pop(c, true), child: const Text('Delete', style: TextStyle(color: Brand.red))),
        ],
      ),
    );
    if (ok != true) return;
    await submit(() => ref.read(apiProvider).delete('/expenses/${widget.id}'), done: 'Expense deleted.');
  }

  @override
  Widget build(BuildContext context) {
    final options = ref.watch(expenseOptionsProvider);
    final readOnly = ref.watch(sessionProvider).readOnly;

    return Scaffold(
      appBar: AppBar(title: Text(widget.id == null ? 'New expense' : 'Expense ${_existing?['number'] ?? ''}'), actions: [
        if (widget.id != null && !readOnly) IconButton(tooltip: 'Delete', icon: const Icon(Icons.delete_outline), onPressed: busy ? null : _delete),
      ]),
      body: _loading || !options.hasValue
          ? (options.hasError ? ErrorView(message: '${options.error}') : const Center(child: CircularProgressIndicator()))
          : _form(options.requireValue, readOnly),
    );
  }

  Widget _form(Map<String, dynamic> o, bool readOnly) {
    final items = (o['items'] as List).cast<Map<String, dynamic>>();
    final estates = (o['estates'] as List).cast<Map<String, dynamic>>();
    final depositLeases = (o['deposit_leases'] as List).cast<Map<String, dynamic>>();
    final categories = (o['deposit_categories'] as List).cast<Map<String, dynamic>>();
    final fromDeposit = _existing?['from_deposit'] as Map?;

    return ListView(padding: const EdgeInsets.all(16), children: [
      if (fromDeposit != null)
        Card(
          color: Brand.orangeLight,
          child: Padding(padding: const EdgeInsets.all(12), child: Text('Paid from ${fromDeposit['tenant']}\'s security deposit. Changing the amount changes the deposit too.')),
        ),
      if (fromDeposit != null) gap,
      DropdownButtonFormField<int>(
        initialValue: _itemId,
        isExpanded: true,
        decoration: InputDecoration(labelText: 'Type of expense', errorText: errorFor('expense_item_id')),
        items: [for (final i in items) DropdownMenuItem(value: (i['id'] as num).toInt(), child: Text('${i['name']}'))],
        onChanged: (v) => setState(() => _itemId = v),
      ),
      gap,
      TextField(controller: _amount, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: 'Amount (UGX)', errorText: errorFor('amount'))),
      gap,
      DateField(label: 'Date', value: _date, error: errorFor('date'), onChanged: (d) => setState(() => _date = d)),
      gap,
      DropdownButtonFormField<int>(
        initialValue: _estateId,
        isExpanded: true,
        decoration: InputDecoration(labelText: 'Property', errorText: errorFor('estate_id')),
        items: [for (final e in estates) DropdownMenuItem(value: (e['id'] as num).toInt(), child: Text('${e['name']}'))],
        onChanged: (v) {
          setState(() {
            _estateId = v;
            _unitId = null;
            _units = [];
          });
          _loadUnits();
        },
      ),
      gap,
      DropdownButtonFormField<int?>(
        key: ValueKey('units-$_estateId-${_units.length}'),
        initialValue: _units.any((u) => u['id'] == _unitId) ? _unitId : null,
        isExpanded: true,
        decoration: InputDecoration(labelText: 'Unit (optional)', errorText: errorFor('unit_id')),
        items: [
          const DropdownMenuItem<int?>(value: null, child: Text('Whole property')),
          for (final u in _units) DropdownMenuItem<int?>(value: (u['id'] as num).toInt(), child: Text('${u['name']}')),
        ],
        onChanged: (v) => setState(() => _unitId = v),
      ),
      gap,
      TextField(controller: _notes, maxLines: 2, decoration: InputDecoration(labelText: 'Notes', hintText: 'e.g. Replaced kitchen tap', errorText: errorFor('notes'))),
      if (widget.id == null && depositLeases.isNotEmpty) ...[
        SwitchListTile(
          value: _fromDeposit,
          contentPadding: EdgeInsets.zero,
          title: const Text("Paid from a tenant's security deposit"),
          subtitle: const Text('The deposit goes down by this amount and the tenant gets an SMS'),
          onChanged: (v) => setState(() => _fromDeposit = v),
        ),
        if (_fromDeposit) ...[
          DropdownButtonFormField<int>(
            initialValue: _depositLeaseId,
            isExpanded: true,
            decoration: InputDecoration(labelText: 'Whose deposit', errorText: errorFor('deposit_lease_id')),
            items: [for (final l in depositLeases) DropdownMenuItem(value: (l['lease_id'] as num).toInt(), child: Text('${l['label']}', overflow: TextOverflow.ellipsis))],
            onChanged: (v) {
              final lease = depositLeases.firstWhere((l) => l['lease_id'] == v);
              setState(() {
                _depositLeaseId = v;
                _estateId = (lease['estate_id'] as num?)?.toInt() ?? _estateId;
                _unitId = (lease['unit_id'] as num?)?.toInt();
              });
              _loadUnits();
            },
          ),
          gap,
          DropdownButtonFormField<String>(
            initialValue: _depositCategory,
            decoration: InputDecoration(labelText: 'Reason', errorText: errorFor('deposit_category')),
            items: [for (final c in categories) DropdownMenuItem(value: '${c['value']}', child: Text('${c['label']}'))],
            onChanged: (v) => setState(() => _depositCategory = v!),
          ),
        ],
      ],
      const SizedBox(height: 20),
      if (!readOnly) formFooter(widget.id == null ? 'Record expense' : 'Save', _save),
    ]);
  }
}
