import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:open_filex/open_filex.dart';

import '../../app/theme.dart';
import '../../core/api.dart';
import '../../core/format.dart';
import '../../core/widgets.dart';

final booksYearProvider = NotifierProvider<_Year, int?>(_Year.new);

class _Year extends Notifier<int?> {
  @override
  int? build() => null;

  void set(int? year) => state = year;
}

final booksSummaryProvider = FutureProvider.autoDispose<Map<String, dynamic>>((ref) {
  final year = ref.watch(booksYearProvider);
  return ref.read(apiProvider).get('/books', query: {'year': year});
});

/// Downloads a report file and opens it (to read, print or share).
Future<void> openReportFile(BuildContext context, WidgetRef ref, String path, String filename) async {
  try {
    final file = await ref.read(apiProvider).download(path, filename);
    await OpenFilex.open(file.path);
  } on ApiException catch (e) {
    if (context.mounted) showMessage(context, e.message, error: true);
  }
}

/// Books & taxes: the fiscal year at a glance, estimated taxes, and the reports.
class BooksScreen extends ConsumerWidget {
  const BooksScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return Scaffold(
      appBar: AppBar(title: const Text('Books & taxes')),
      body: AsyncBody(
        value: ref.watch(booksSummaryProvider),
        onRefresh: () => ref.refresh(booksSummaryProvider.future),
        builder: (b) {
          final years = (b['years'] as List).cast<Map<String, dynamic>>();
          final taxes = (b['taxes'] as Map).cast<String, dynamic>();
          final lines = (taxes['lines'] as List).cast<Map<String, dynamic>>();
          final reports = (b['reports'] as List).cast<Map<String, dynamic>>();
          final year = b['year'] as int;

          return ListView(padding: const EdgeInsets.all(16), children: [
            DropdownButtonFormField<int>(
              initialValue: year,
              decoration: const InputDecoration(labelText: 'Fiscal year'),
              items: [for (final y in years) DropdownMenuItem(value: y['year'] as int, child: Text('${y['label']}'))],
              onChanged: (v) => ref.read(booksYearProvider.notifier).set(v),
            ),
            const SizedBox(height: 12),
            Card(
              color: Brand.navy,
              child: Padding(
                padding: const EdgeInsets.all(18),
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text('Net income · ${b['basis']}', style: const TextStyle(color: Color(0xFFC9CCD4), fontSize: 13)),
                  const SizedBox(height: 6),
                  Text(ugx(toNum(b['net'])), style: const TextStyle(color: Colors.white, fontSize: 28, fontWeight: FontWeight.w800)),
                  const SizedBox(height: 10),
                  Text('Income ${ugx(toNum(b['income']))}   ·   Expenses ${ugx(toNum(b['expenses']))}', style: const TextStyle(color: Color(0xFFC9CCD4))),
                  Text('${date(b['from'] as String?)} to ${date(b['to'] as String?)}', style: const TextStyle(color: Color(0xFF9BA0AE), fontSize: 12)),
                ]),
              ),
            ),
            const SizedBox(height: 12),
            SectionCard(title: 'Taxes (estimated)', children: [
              if (lines.isEmpty) const Padding(padding: EdgeInsets.only(bottom: 10), child: Text('No taxes switched on. Set them up under Books settings on the web.')),
              for (final l in lines)
                Padding(
                  padding: const EdgeInsets.symmetric(vertical: 6),
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Row(children: [
                      Expanded(child: Text('${l['label']}', style: const TextStyle(fontWeight: FontWeight.w600))),
                      Text(ugx(toNum(l['due']) - toNum(l['paid'])), style: const TextStyle(fontWeight: FontWeight.w700)),
                    ]),
                    Text('${l['authority']} · due ${amount(toNum(l['due']))}, paid ${amount(toNum(l['paid']))}', style: const TextStyle(fontSize: 12, color: Brand.navyMuted)),
                  ]),
                ),
              if (lines.isNotEmpty) ...[
                const Divider(),
                InfoRow('Still to pay', ugx(toNum(taxes['due']) - toNum(taxes['paid'])), bold: true),
              ],
            ]),
            const SizedBox(height: 12),
            SectionCard(title: 'Reports', children: [
              for (final r in reports)
                ListTile(
                  contentPadding: EdgeInsets.zero,
                  leading: const Icon(Icons.table_chart_outlined, color: Brand.orange),
                  title: Text('${r['title']}'),
                  subtitle: Text(r['period'] == 'as_at' ? 'As at today' : '${years.firstWhere((y) => y['year'] == year, orElse: () => years.first)['label']}'),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: () => context.push('/books/reports/${r['key']}${r['period'] == 'as_at' ? '' : '?year=$year'}'),
                ),
            ]),
            const SizedBox(height: 12),
            FilledButton.icon(
              onPressed: () => openReportFile(context, ref, '/books/pack?year=$year', 'tax-pack-$year.pdf'),
              icon: const Icon(Icons.picture_as_pdf_outlined),
              label: const Text('Tax pack for the year (PDF)'),
            ),
            const SizedBox(height: 8),
            const Text('Tax figures are estimates to help you file. Confirm with URA and your local council.',
                textAlign: TextAlign.center, style: TextStyle(fontSize: 12, color: Brand.navyMuted)),
          ]);
        },
      ),
    );
  }
}

final reportProvider = FutureProvider.autoDispose.family<Map<String, dynamic>, String>((ref, pathAndQuery) => ref.read(apiProvider).get(pathAndQuery));

/// Any books report, drawn from its data: sections of rows, subtotals,
/// totals, notes and its check. PDF and Excel to share.
class ReportScreen extends ConsumerWidget {
  const ReportScreen({super.key, required this.report, this.year});
  final String report;
  final int? year;

  String get _path => '/books/reports/$report${year != null ? '?year=$year' : ''}';

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    String file(String ext) => '$report${year != null ? '-$year' : ''}.$ext';
    String withFormat(String f) => '$_path${_path.contains('?') ? '&' : '?'}format=$f';

    return Scaffold(
      appBar: AppBar(title: const Text('Report'), actions: [
        IconButton(tooltip: 'PDF', icon: const Icon(Icons.picture_as_pdf_outlined), onPressed: () => openReportFile(context, ref, withFormat('pdf'), file('pdf'))),
        IconButton(tooltip: 'Excel', icon: const Icon(Icons.grid_on_outlined), onPressed: () => openReportFile(context, ref, withFormat('xlsx'), file('xlsx'))),
      ]),
      body: AsyncBody(
        value: ref.watch(reportProvider(_path)),
        onRefresh: () => ref.refresh(reportProvider(_path).future),
        builder: (r) {
          final columns = (r['columns'] as List).map((c) => '$c').toList();
          final blocks = (r['blocks'] as List).cast<Map<String, dynamic>>();
          final check = (r['check'] as Map?)?.cast<String, dynamic>();

          Widget values(List v, {bool bold = false, double size = 13}) => Row(mainAxisSize: MainAxisSize.min, children: [
                for (var i = 0; i < v.length; i++)
                  SizedBox(
                    width: columns.length > 2 ? 78 : 96,
                    child: Text(amount(toNum(v[i])), textAlign: TextAlign.right,
                        style: TextStyle(fontSize: size, fontWeight: bold ? FontWeight.w700 : FontWeight.w400, color: i > 0 && columns.length == 2 ? Brand.navyMuted : null)),
                  ),
              ]);

          return ListView(padding: const EdgeInsets.all(16), children: [
            Text('${r['title']}', style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w800)),
            Text('${r['period']}', style: const TextStyle(color: Brand.navyMuted)),
            const SizedBox(height: 12),
            Card(
              child: Padding(
                padding: const EdgeInsets.all(12),
                child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                  Row(children: [
                    const Expanded(child: SizedBox()),
                    for (final c in columns)
                      SizedBox(width: columns.length > 2 ? 78 : 96, child: Text(c, textAlign: TextAlign.right, style: const TextStyle(fontSize: 11, color: Brand.navyMuted, fontWeight: FontWeight.w600))),
                  ]),
                  for (final b in blocks)
                    if (b['kind'] == 'section') ...[
                      if (b['title'] != null && '${b['title']}'.isNotEmpty)
                        Padding(
                          padding: const EdgeInsets.only(top: 12, bottom: 4),
                          child: Text('${b['title']}'.toUpperCase(), style: const TextStyle(fontSize: 11, letterSpacing: 0.6, fontWeight: FontWeight.w700, color: Brand.navyMuted)),
                        ),
                      for (final row in (b['rows'] as List).cast<Map<String, dynamic>>())
                        Padding(
                          padding: const EdgeInsets.symmetric(vertical: 4),
                          child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                            Expanded(
                              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                                Text('${row['name']}', style: const TextStyle(fontSize: 13)),
                                if (row['note'] != null) Text('${row['note']}', style: const TextStyle(fontSize: 11, color: Brand.navyMuted)),
                              ]),
                            ),
                            values(row['values'] as List),
                          ]),
                        ),
                      if (b['total_label'] != null)
                        Container(
                          padding: const EdgeInsets.symmetric(vertical: 6),
                          decoration: const BoxDecoration(border: Border(top: BorderSide(color: Color(0xFFD1D5DB)))),
                          child: Row(children: [
                            Expanded(child: Text('${b['total_label']}', style: const TextStyle(fontWeight: FontWeight.w600))),
                            values(b['total'] as List, bold: true),
                          ]),
                        ),
                    ] else
                      Container(
                        margin: const EdgeInsets.only(top: 8),
                        padding: const EdgeInsets.symmetric(vertical: 8, horizontal: 6),
                        decoration: BoxDecoration(color: Brand.orangeLight, borderRadius: BorderRadius.circular(8)),
                        child: Row(children: [
                          Expanded(child: Text('${b['label']}', style: TextStyle(fontWeight: FontWeight.w800, fontSize: b['grand'] == true ? 15 : 13))),
                          values(b['values'] as List, bold: true, size: b['grand'] == true ? 15 : 13),
                        ]),
                      ),
                ]),
              ),
            ),
            if (check != null) ...[
              const SizedBox(height: 10),
              Text('${check['ok'] == true ? '✓' : '✗'} ${check['label']}',
                  style: TextStyle(fontWeight: FontWeight.w600, color: check['ok'] == true ? Brand.green : Brand.red)),
            ],
            const SizedBox(height: 10),
            for (final n in (r['notes'] as List)) Padding(padding: const EdgeInsets.only(bottom: 4), child: Text('$n', style: const TextStyle(fontSize: 12, color: Brand.navyMuted))),
          ]);
        },
      ),
    );
  }
}
