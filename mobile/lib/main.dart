import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'app/router.dart';
import 'app/theme.dart';

void main() {
  WidgetsFlutterBinding.ensureInitialized();
  runApp(const ProviderScope(child: PrimoraApp()));
}

class PrimoraApp extends ConsumerWidget {
  const PrimoraApp({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return MaterialApp.router(
      title: 'Primora',
      debugShowCheckedModeBanner: false,
      theme: primoraTheme(),
      routerConfig: ref.watch(routerProvider),
    );
  }
}
