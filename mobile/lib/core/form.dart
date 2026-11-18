import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../app/theme.dart';
import 'api.dart';
import 'widgets.dart';

/// Shared handling for the app's forms: a busy flag, the server's per-field
/// errors shown under each field, and a general error at the bottom.
mixin ApiForm<T extends ConsumerStatefulWidget> on ConsumerState<T> {
  bool busy = false;
  Map<String, String> fieldErrors = {};
  String? formError;

  String? errorFor(String field) => fieldErrors[field];

  /// Runs [action]; on success shows [done] and closes the screen with `true`.
  Future<void> submit(Future<void> Function() action, {required String done}) async {
    setState(() {
      busy = true;
      fieldErrors = {};
      formError = null;
    });
    try {
      await action();
      if (!mounted) return;
      showMessage(context, done);
      context.pop(true);
    } on ApiException catch (e) {
      setState(() {
        fieldErrors = e.fieldErrors;
        formError = e.fieldErrors.isEmpty ? e.message : 'Please check the highlighted fields.';
      });
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  Widget formFooter(String label, VoidCallback onPressed) => Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        if (formError != null) Padding(padding: const EdgeInsets.only(bottom: 12), child: Text(formError!, style: const TextStyle(color: Brand.red))),
        FilledButton(
          onPressed: busy ? null : onPressed,
          child: busy ? const SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white)) : Text(label),
        ),
        const SizedBox(height: 24),
      ]);
}

/// A date field that opens a date picker.
class DateField extends StatelessWidget {
  const DateField({super.key, required this.label, required this.value, required this.onChanged, this.error});

  final String label;
  final DateTime value;
  final ValueChanged<DateTime> onChanged;
  final String? error;

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: () async {
        final picked = await showDatePicker(context: context, initialDate: value, firstDate: DateTime(2015), lastDate: DateTime(2100));
        if (picked != null) onChanged(picked);
      },
      child: InputDecorator(
        decoration: InputDecoration(labelText: label, errorText: error, suffixIcon: const Icon(Icons.calendar_today_outlined, size: 18)),
        child: Text('${value.day.toString().padLeft(2, '0')}/${value.month.toString().padLeft(2, '0')}/${value.year}'),
      ),
    );
  }
}

const gap = SizedBox(height: 12);
