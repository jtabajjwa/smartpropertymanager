import 'package:flutter/material.dart';

import 'paged.dart';

/// A searchable bottom sheet listing records from the API (e.g. tenants,
/// active leases); returns the chosen one.
Future<Map<String, dynamic>?> pickRecord(
  BuildContext context, {
  required String title,
  required String path,
  Map<String, dynamic> query = const {},
  required String Function(Map<String, dynamic>) label,
  String Function(Map<String, dynamic>)? subtitle,
}) {
  return showModalBottomSheet<Map<String, dynamic>>(
    context: context,
    isScrollControlled: true,
    showDragHandle: true,
    builder: (context) => SizedBox(
      height: MediaQuery.of(context).size.height * 0.8,
      child: Column(children: [
        Text(title, style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w700)),
        Expanded(
          child: PagedList(
            path: path,
            query: query,
            searchHint: 'Search',
            itemBuilder: (context, item) => Card(
              child: ListTile(
                title: Text(label(item)),
                subtitle: subtitle == null ? null : Text(subtitle(item)),
                onTap: () => Navigator.of(context).pop(item),
              ),
            ),
          ),
        ),
      ]),
    ),
  );
}
