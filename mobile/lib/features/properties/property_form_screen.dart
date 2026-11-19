import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api.dart';
import '../../core/form.dart';

final placesProvider = FutureProvider<Map<String, dynamic>>((ref) => ref.read(apiProvider).get('/estates/places'));

/// Add or edit a property: name, country, district (from the list in Uganda), address.
class PropertyFormScreen extends ConsumerStatefulWidget {
  const PropertyFormScreen({super.key, this.id});
  final int? id;

  @override
  ConsumerState<PropertyFormScreen> createState() => _PropertyFormScreenState();
}

class _PropertyFormScreenState extends ConsumerState<PropertyFormScreen> with ApiForm {
  final _name = TextEditingController();
  final _address = TextEditingController();
  final _district = TextEditingController();
  final _description = TextEditingController();
  String _country = 'Uganda';
  bool _loading = false;

  @override
  void initState() {
    super.initState();
    if (widget.id != null) _load();
  }

  Future<void> _load() async {
    setState(() => _loading = true);
    try {
      final e = await ref.read(apiProvider).get('/estates/${widget.id}');
      _name.text = '${e['name'] ?? ''}';
      _address.text = '${e['address'] ?? ''}';
      _district.text = '${e['district'] ?? ''}';
      _description.text = '${e['description'] ?? ''}';
      _country = '${e['country'] ?? 'Uganda'}';
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _save() => submit(() async {
        await ref.read(apiProvider).post(widget.id == null ? '/estates' : '/estates/${widget.id}', data: {
          'name': _name.text.trim(),
          'country': _country,
          'district': _district.text.trim(),
          'address': _address.text.trim(),
          'description': _description.text.trim(),
        });
      }, done: widget.id == null ? 'Property added.' : 'Property saved.');

  @override
  Widget build(BuildContext context) {
    final places = ref.watch(placesProvider);
    final countries = ((places.hasValue ? places.requireValue['countries'] : null) as List?)?.map((e) => '$e').toList() ?? ['Uganda'];
    final districts = ((places.hasValue ? places.requireValue['uganda_districts'] : null) as List?)?.map((e) => '$e').toList() ?? <String>[];

    return Scaffold(
      appBar: AppBar(title: Text(widget.id == null ? 'New property' : 'Edit property')),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : ListView(padding: const EdgeInsets.all(16), children: [
              TextField(controller: _name, decoration: InputDecoration(labelText: 'Name', hintText: 'e.g. Acacia Court', errorText: errorFor('name'))),
              gap,
              DropdownButtonFormField<String>(
                initialValue: countries.contains(_country) ? _country : countries.first,
                isExpanded: true,
                decoration: InputDecoration(labelText: 'Country', errorText: errorFor('country')),
                items: [for (final c in countries) DropdownMenuItem(value: c, child: Text(c))],
                onChanged: (v) => setState(() => _country = v!),
              ),
              gap,
              if (_country == 'Uganda' && districts.isNotEmpty)
                Autocomplete<String>(
                  initialValue: TextEditingValue(text: _district.text),
                  optionsBuilder: (v) => districts.where((d) => d.toLowerCase().contains(v.text.toLowerCase())),
                  onSelected: (d) => _district.text = d,
                  fieldViewBuilder: (context, controller, focus, onSubmit) {
                    controller.addListener(() => _district.text = controller.text);
                    return TextField(controller: controller, focusNode: focus,
                        decoration: InputDecoration(labelText: 'District', hintText: 'Start typing, e.g. Wakiso', errorText: errorFor('district')));
                  },
                )
              else
                TextField(controller: _district, decoration: InputDecoration(labelText: 'District / region', errorText: errorFor('district'))),
              gap,
              TextField(controller: _address, decoration: InputDecoration(labelText: 'Address (optional)', errorText: errorFor('address'))),
              gap,
              TextField(controller: _description, maxLines: 3, decoration: const InputDecoration(labelText: 'Description (optional)')),
              const SizedBox(height: 20),
              formFooter(widget.id == null ? 'Add property' : 'Save', _save),
            ]),
    );
  }
}
