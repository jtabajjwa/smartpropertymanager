import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../core/logo.dart';
import '../core/session.dart';
import 'theme.dart';
import '../features/subscription/subscription_screen.dart';
import '../features/auth/forgot_password_screen.dart';
import '../features/auth/login_screen.dart';
import '../features/auth/setup_screen.dart';
import '../features/books/books_screens.dart';
import '../features/dashboard/dashboard_screen.dart';
import '../features/invoices/invoice_detail_screen.dart';
import '../features/invoices/invoice_form_screen.dart';
import '../features/expenses/expense_screens.dart';
import '../features/invoices/invoices_screen.dart';
import '../features/issues/issue_screens.dart';
import '../features/leases/lease_screens.dart';
import '../features/payouts/payout_screens.dart';
import '../features/properties/properties_screen.dart';
import '../features/properties/property_detail_screen.dart';
import '../features/properties/property_form_screen.dart';
import '../features/properties/unit_screens.dart';
import '../features/more/more_screen.dart';
import '../features/payments/payment_detail_screen.dart';
import '../features/payments/payment_form_screen.dart';
import '../features/payments/payments_screen.dart';
import '../features/shell.dart';
import '../features/sms/sms_screens.dart';
import '../features/tenants/tenant_detail_screen.dart';
import '../features/tenants/tenant_form_screen.dart';
import '../features/tenants/tenants_screen.dart';
import '../features/tenant/tenant_account_screen.dart';
import '../features/tenant/tenant_home_screen.dart';
import '../features/tenant/tenant_issue_screens.dart';
import '../features/tenant/tenant_login_screen.dart';
import '../features/tenant/tenant_money_screens.dart';

/// Re-runs the router's redirect when the session changes.
class _SessionListenable extends ChangeNotifier {
  void changed() => notifyListeners();
}

final routerProvider = Provider<GoRouter>((ref) {
  final listenable = _SessionListenable();
  ref.listen(sessionProvider, (_, __) => listenable.changed());
  ref.onDispose(listenable.dispose);

  const guest = ['/login', '/forgot-password', '/tenant-login'];

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
          // Tenants have their own pages, under /t.
          if (session.isTenant) return at.startsWith('/t/') ? null : '/t/home';
          if (session.needsSetup) return at == '/setup' ? null : '/setup';
          if (at.startsWith('/t/')) return '/home';
          return (guest.contains(at) || at == '/splash' || at == '/setup') ? '/home' : null;
      }
    },
    routes: [
      GoRoute(path: '/splash', builder: (_, __) => const _Splash()),
      GoRoute(path: '/login', builder: (_, __) => const LoginScreen()),
      GoRoute(path: '/forgot-password', builder: (_, __) => const ForgotPasswordScreen()),
      GoRoute(path: '/setup', builder: (_, __) => const SetupScreen()),
      GoRoute(path: '/tenant-login', builder: (_, __) => const TenantLoginScreen()),
      // Tenants.
      GoRoute(path: '/t/pay', builder: (_, s) => TenantPayScreen(invoiceId: int.tryParse(s.uri.queryParameters['invoice'] ?? ''))),
      GoRoute(path: '/t/deposit', builder: (_, __) => const TenantDepositScreen()),
      GoRoute(path: '/t/issues/new', builder: (_, __) => const TenantIssueFormScreen()),
      GoRoute(path: '/t/issues/:id', builder: (_, s) => TenantIssueDetailScreen(id: int.parse(s.pathParameters['id']!))),
      StatefulShellRoute.indexedStack(
        builder: (context, state, shell) => TenantShell(shell: shell),
        branches: [
          StatefulShellBranch(routes: [GoRoute(path: '/t/home', builder: (_, __) => const TenantHomeScreen())]),
          StatefulShellBranch(routes: [GoRoute(path: '/t/bills', builder: (_, __) => const TenantInvoicesScreen())]),
          StatefulShellBranch(routes: [GoRoute(path: '/t/payments', builder: (_, __) => const TenantPaymentsScreen())]),
          StatefulShellBranch(routes: [GoRoute(path: '/t/issues', builder: (_, __) => const TenantIssuesScreen())]),
          StatefulShellBranch(routes: [GoRoute(path: '/t/account', builder: (_, __) => const TenantAccountScreen())]),
        ],
      ),
      // Full-screen pages (no tab bar), opened from the tabs.
      GoRoute(path: '/properties', builder: (_, __) => const PropertiesScreen(), routes: [
        GoRoute(path: 'new', builder: (_, __) => const PropertyFormScreen()),
        GoRoute(path: ':id', builder: (_, s) => PropertyDetailScreen(id: int.parse(s.pathParameters['id']!)), routes: [
          GoRoute(path: 'edit', builder: (_, s) => PropertyFormScreen(id: int.parse(s.pathParameters['id']!))),
        ]),
      ]),
      GoRoute(path: '/expenses', builder: (_, __) => const ExpensesScreen(), routes: [
        GoRoute(path: 'new', builder: (_, __) => const ExpenseFormScreen()),
        GoRoute(path: ':id', builder: (_, s) => ExpenseFormScreen(id: int.parse(s.pathParameters['id']!))),
      ]),
      GoRoute(path: '/issues', builder: (_, __) => const IssuesScreen(), routes: [
        GoRoute(path: ':id', builder: (_, s) => IssueDetailScreen(id: int.parse(s.pathParameters['id']!))),
      ]),
      GoRoute(path: '/books', builder: (_, __) => const BooksScreen(), routes: [
        GoRoute(path: 'reports/:report', builder: (_, s) => ReportScreen(report: s.pathParameters['report']!, year: int.tryParse(s.uri.queryParameters['year'] ?? ''))),
      ]),
      GoRoute(path: '/payouts', builder: (_, __) => const PayoutsScreen()),
      GoRoute(path: '/subscription', builder: (_, __) => const SubscriptionScreen()),
      GoRoute(path: '/sms', builder: (_, __) => const SmsScreen(), routes: [
        GoRoute(path: 'logs', builder: (_, __) => const SmsLogScreen()),
        GoRoute(path: 'broadcasts', builder: (_, __) => const BroadcastsScreen(), routes: [
          GoRoute(path: 'new', builder: (_, __) => const BroadcastFormScreen()),
          GoRoute(path: ':id', builder: (_, s) => BroadcastDetailScreen(id: int.parse(s.pathParameters['id']!))),
        ]),
      ]),
      GoRoute(path: '/units/new', builder: (_, s) => UnitFormScreen(estateId: int.tryParse(s.uri.queryParameters['estate'] ?? ''))),
      GoRoute(path: '/units/:id', builder: (_, s) => UnitDetailScreen(id: int.parse(s.pathParameters['id']!)), routes: [
        GoRoute(path: 'edit', builder: (_, s) => UnitFormScreen(id: int.parse(s.pathParameters['id']!))),
      ]),
      GoRoute(path: '/leases', builder: (_, __) => const LeasesScreen(), routes: [
        GoRoute(path: 'new', builder: (_, s) => LeaseFormScreen(
              unitId: int.tryParse(s.uri.queryParameters['unit'] ?? ''),
              tenantId: int.tryParse(s.uri.queryParameters['tenant'] ?? ''),
            )),
        GoRoute(path: ':id', builder: (_, s) => LeaseDetailScreen(id: int.parse(s.pathParameters['id']!))),
      ]),
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
  Widget build(BuildContext context) => const Scaffold(
        backgroundColor: Brand.navy,
        body: Center(
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            PrimoraMark(size: 96, dark: true),
            SizedBox(height: 28),
            SizedBox(width: 22, height: 22, child: CircularProgressIndicator(strokeWidth: 2, color: Brand.orange)),
          ]),
        ),
      );
}
