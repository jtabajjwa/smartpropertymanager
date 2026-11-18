import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../core/session.dart';
import '../features/auth/forgot_password_screen.dart';
import '../features/auth/login_screen.dart';
import '../features/auth/setup_screen.dart';
import '../features/dashboard/dashboard_screen.dart';
import '../features/invoices/invoice_detail_screen.dart';
import '../features/invoices/invoice_form_screen.dart';
import '../features/invoices/invoices_screen.dart';
import '../features/more/more_screen.dart';
import '../features/payments/payment_detail_screen.dart';
import '../features/payments/payment_form_screen.dart';
import '../features/payments/payments_screen.dart';
import '../features/shell.dart';
import '../features/tenants/tenant_detail_screen.dart';
import '../features/tenants/tenant_form_screen.dart';
import '../features/tenants/tenants_screen.dart';

/// Re-runs the router's redirect when the session changes.
class _SessionListenable extends ChangeNotifier {
  void changed() => notifyListeners();
}

final routerProvider = Provider<GoRouter>((ref) {
  final listenable = _SessionListenable();
  ref.listen(sessionProvider, (_, __) => listenable.changed());
  ref.onDispose(listenable.dispose);

  const guest = ['/login', '/forgot-password'];

  return GoRouter(
    initialLocation: '/home',
    refreshListenable: listenable,
    redirect: (context, state) {
      final session = ref.read(sessionProvider);
      final at = state.matchedLocation;

      switch (session.status) {
        case SessionStatus.loading:
          return at == '/splash' ? null : '/splash';
        case SessionStatus.signedOut:
          return guest.contains(at) ? null : '/login';
        case SessionStatus.signedIn:
          if (session.needsSetup) return at == '/setup' ? null : '/setup';
          return (guest.contains(at) || at == '/splash' || at == '/setup') ? '/home' : null;
      }
    },
    routes: [
      GoRoute(path: '/splash', builder: (_, __) => const _Splash()),
      GoRoute(path: '/login', builder: (_, __) => const LoginScreen()),
      GoRoute(path: '/forgot-password', builder: (_, __) => const ForgotPasswordScreen()),
      GoRoute(path: '/setup', builder: (_, __) => const SetupScreen()),
      StatefulShellRoute.indexedStack(
        builder: (context, state, shell) => AppShell(shell: shell),
        branches: [
          StatefulShellBranch(routes: [GoRoute(path: '/home', builder: (_, __) => const DashboardScreen())]),
          StatefulShellBranch(routes: [
            GoRoute(path: '/tenants', builder: (_, __) => const TenantsScreen(), routes: [
              GoRoute(path: 'new', builder: (_, __) => const TenantFormScreen()),
              GoRoute(path: ':id', builder: (_, s) => TenantDetailScreen(id: int.parse(s.pathParameters['id']!))),
            ]),
          ]),
          StatefulShellBranch(routes: [
            GoRoute(path: '/invoices', builder: (_, __) => const InvoicesScreen(), routes: [
              GoRoute(path: 'new', builder: (_, s) => InvoiceFormScreen(leaseId: int.tryParse(s.uri.queryParameters['lease'] ?? ''))),
              GoRoute(path: ':id', builder: (_, s) => InvoiceDetailScreen(id: int.parse(s.pathParameters['id']!))),
            ]),
          ]),
          StatefulShellBranch(routes: [
            GoRoute(path: '/payments', builder: (_, __) => const PaymentsScreen(), routes: [
              GoRoute(path: 'new', builder: (_, s) => PaymentFormScreen(tenantId: int.tryParse(s.uri.queryParameters['tenant'] ?? ''))),
              GoRoute(path: ':id', builder: (_, s) => PaymentDetailScreen(id: int.parse(s.pathParameters['id']!))),
            ]),
          ]),
          StatefulShellBranch(routes: [GoRoute(path: '/more', builder: (_, __) => const MoreScreen())]),
        ],
      ),
    ],
  );
});

class _Splash extends StatelessWidget {
  const _Splash();

  @override
  Widget build(BuildContext context) => const Scaffold(body: Center(child: CircularProgressIndicator()));
}
