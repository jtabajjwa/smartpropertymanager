import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../app/theme.dart';
import 'api.dart';

/// Loading / error / data for a [FutureProvider], with pull-to-refresh and a retry button.
class AsyncBody<T> extends ConsumerWidget {
  const AsyncBody({super.key, required this.value, required this.builder, required this.onRefresh});

  final AsyncValue<T> value;
  final Widget Function(T data) builder;
  final Future<void> Function() onRefresh;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return value.when(
      skipLoadingOnRefresh: true,
      data: (data) => RefreshIndicator(onRefresh: onRefresh, child: builder(data)),
      loading: () => const Center(child: CircularProgressIndicator()),
      error: (error, _) => ErrorView(message: error is ApiException ? error.message : 'Something went wrong.', onRetry: onRefresh),
    );
  }
}

class ErrorView extends StatelessWidget {
  const ErrorView({super.key, required this.message, this.onRetry});

  final String message;
  final Future<void> Function()? onRetry;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          const Icon(Icons.cloud_off_outlined, size: 40, color: Brand.navyMuted),
          const SizedBox(height: 12),
          Text(message, textAlign: TextAlign.center),
          if (onRetry != null) ...[
            const SizedBox(height: 12),
            OutlinedButton(onPressed: onRetry, child: const Text('Try again')),
          ],
        ]),
      ),
    );
  }
}

class EmptyState extends StatelessWidget {
  const EmptyState({super.key, required this.icon, required this.message});

  final IconData icon;
  final String message;

  @override
  Widget build(BuildContext context) {
    return ListView(children: [
      const SizedBox(height: 80),
      Icon(icon, size: 44, color: Brand.navyMuted),
      const SizedBox(height: 12),
      Text(message, textAlign: TextAlign.center, style: const TextStyle(color: Brand.navyMuted)),
    ]);
  }
}

/// A small coloured label, e.g. an invoice's status.
class StatusChip extends StatelessWidget {
  const StatusChip(this.label, {super.key, this.color = Brand.navyMuted});

  final String label;
  final Color color;

  factory StatusChip.invoice(Map<String, dynamic> invoice) {
    if (invoice['overdue'] == true) return const StatusChip('Overdue', color: Brand.red);
    return switch (invoice['status']) {
      'paid' => const StatusChip('Paid', color: Brand.green),
      'partial' => const StatusChip('Part paid', color: Brand.amber),
      'cancelled' => const StatusChip('Cancelled'),
      _ => const StatusChip('Unpaid', color: Brand.orange),
    };
  }

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
      decoration: BoxDecoration(color: color.withValues(alpha: 0.1), borderRadius: BorderRadius.circular(999)),
      child: Text(label, style: TextStyle(color: color, fontSize: 12, fontWeight: FontWeight.w600)),
    );
  }
}

/// A white card with a title, for detail screens.
class SectionCard extends StatelessWidget {
  const SectionCard({super.key, this.title, required this.children, this.trailing});

  final String? title;
  final List<Widget> children;
  final Widget? trailing;

  @override
  Widget build(BuildContext context) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 14, 16, 8),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          if (title != null)
            Padding(
              padding: const EdgeInsets.only(bottom: 6),
              child: Row(children: [
                Expanded(child: Text(title!, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 15))),
                if (trailing != null) trailing!,
              ]),
            ),
          ...children,
        ]),
      ),
    );
  }
}

/// "Label ........ value" rows inside a [SectionCard].
class InfoRow extends StatelessWidget {
  const InfoRow(this.label, this.value, {super.key, this.bold = false});

  final String label;
  final String? value;
  final bool bold;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 7),
      child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Expanded(child: Text(label, style: const TextStyle(color: Brand.navyMuted))),
        const SizedBox(width: 12),
        Flexible(child: Text(value == null || value!.isEmpty ? '—' : value!, textAlign: TextAlign.right,
            style: TextStyle(fontWeight: bold ? FontWeight.w700 : FontWeight.w500))),
      ]),
    );
  }
}

void showMessage(BuildContext context, String message, {bool error = false}) {
  ScaffoldMessenger.of(context)
    ..hideCurrentSnackBar()
    ..showSnackBar(SnackBar(content: Text(message), backgroundColor: error ? Brand.red : Brand.navy));
}
