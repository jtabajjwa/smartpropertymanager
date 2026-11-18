import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../app/theme.dart';
import 'api.dart';
import 'widgets.dart';

/// A searchable, paginated list from a Laravel paginator (`data`, `current_page`, `last_page`).
class PagedList extends ConsumerStatefulWidget {
  const PagedList({
    super.key,
    required this.path,
    required this.itemBuilder,
    this.query = const {},
    this.searchHint,
    this.emptyIcon = Icons.inbox_outlined,
    this.emptyMessage = 'Nothing here yet.',
    this.header,
  });

  final String path;
  final Map<String, dynamic> query;
  final Widget Function(BuildContext context, Map<String, dynamic> item) itemBuilder;
  final String? searchHint;
  final IconData emptyIcon;
  final String emptyMessage;
  final Widget? header;

  @override
  ConsumerState<PagedList> createState() => PagedListState();
}

class PagedListState extends ConsumerState<PagedList> {
  final _items = <Map<String, dynamic>>[];
  int _page = 0;
  int _lastPage = 1;
  bool _loading = false;
  String? _error;
  String _search = '';
  final _scroll = ScrollController();

  @override
  void initState() {
    super.initState();
    _scroll.addListener(() {
      if (_scroll.position.pixels > _scroll.position.maxScrollExtent - 300) _loadMore();
    });
    reload();
  }

  @override
  void didUpdateWidget(PagedList oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.query.toString() != widget.query.toString()) reload();
  }

  @override
  void dispose() {
    _scroll.dispose();
    super.dispose();
  }

  Future<void> reload() async {
    setState(() {
      _items.clear();
      _page = 0;
      _lastPage = 1;
      _error = null;
    });
    await _loadMore();
  }

  Future<void> _loadMore() async {
    if (_loading || _page >= _lastPage) return;
    setState(() => _loading = true);
    try {
      final data = await ref.read(apiProvider).get(widget.path, query: {...widget.query, 'search': _search, 'page': _page + 1});
      setState(() {
        _items.addAll(((data['data'] as List?) ?? const []).cast<Map<String, dynamic>>());
        _page = (data['current_page'] as num?)?.toInt() ?? _page + 1;
        _lastPage = (data['last_page'] as num?)?.toInt() ?? _page;
      });
    } on ApiException catch (e) {
      setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Column(children: [
      if (widget.searchHint != null)
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 12, 16, 4),
          child: TextField(
            decoration: InputDecoration(hintText: widget.searchHint, prefixIcon: const Icon(Icons.search), isDense: true),
            textInputAction: TextInputAction.search,
            onSubmitted: (value) {
              _search = value.trim();
              reload();
            },
          ),
        ),
      if (widget.header != null) widget.header!,
      Expanded(child: _body()),
    ]);
  }

  Widget _body() {
    if (_error != null && _items.isEmpty) return ErrorView(message: _error!, onRetry: reload);
    if (_items.isEmpty && _loading) return const Center(child: CircularProgressIndicator());

    return RefreshIndicator(
      onRefresh: reload,
      child: _items.isEmpty
          ? EmptyState(icon: widget.emptyIcon, message: widget.emptyMessage)
          : ListView.separated(
              controller: _scroll,
              physics: const AlwaysScrollableScrollPhysics(),
              padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
              itemCount: _items.length + (_page < _lastPage ? 1 : 0),
              separatorBuilder: (_, __) => const SizedBox(height: 8),
              itemBuilder: (context, i) => i < _items.length
                  ? widget.itemBuilder(context, _items[i])
                  : const Padding(padding: EdgeInsets.all(16), child: Center(child: CircularProgressIndicator(color: Brand.orange))),
            ),
    );
  }
}
