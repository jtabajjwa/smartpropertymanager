import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'app/background.dart';
import 'app/router.dart';
import 'app/theme.dart';
import 'core/api.dart';
import 'core/push.dart';
import 'core/session.dart';

void main() {
  WidgetsFlutterBinding.ensureInitialized();
  runApp(const ProviderScope(retry: retryOnlyNetworkErrors, child: PrimoraApp()));
}

/// Riverpod retries failed loads by default, with growing delays, which made
/// a server error spin for a long time before showing. Only retry when the
/// network failed (no answer at all), twice; show server errors at once.
Duration? retryOnlyNetworkErrors(int retryCount, Object error) {
  if (error is ApiException && error.status == null && retryCount < 2) {
    return Duration(milliseconds: 600 * (retryCount + 1));
  }

  return null;
}

class PrimoraApp extends ConsumerWidget {
  const PrimoraApp({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    // After each sign-in (or switching tenancy), register this phone for notifications.
    ref.listen(sessionProvider, (previous, next) {
      final who = next.isTenant ? 'tenant:${next.tenant['id']}' : 'user:${next.user['id']}';
      final before = previous == null ? null : (previous.isTenant ? 'tenant:${previous.tenant['id']}' : 'user:${previous.user['id']}');
      if (next.status == SessionStatus.signedIn && (previous?.status != SessionStatus.signedIn || who != before)) {
        ref.read(pushProvider).signedIn();
      }
    });

    return MaterialApp.router(
      scaffoldMessengerKey: messengerKey,
      title: 'Primora',
      debugShowCheckedModeBanner: false,
      theme: primoraTheme(),
      routerConfig: ref.watch(routerProvider),
      builder: (context, child) => PrimoraBackground(child: child ?? const SizedBox()),
    );
  }
}
