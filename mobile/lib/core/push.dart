import 'dart:async';
import 'dart:io';

import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../app/router.dart';
import '../app/theme.dart';
import 'api.dart';
import 'config.dart';
import 'session.dart';

/// Shows in-app banners for notifications that arrive while the app is open.
final messengerKey = GlobalKey<ScaffoldMessengerState>();

/// Push notifications (Firebase Cloud Messaging).
///
/// Only switched on when the app is built with the Firebase settings (see
/// [AppConfig.pushEnabled]); otherwise it does nothing. After signing in, the
/// phone's token is sent to the server (landlords: /auth/devices, tenants:
/// /tenant/devices). Each notification carries `route`, the screen to open
/// when it's tapped; one that arrives while the app is open shows as a banner.
class PushService {
  PushService(this.ref);
  final Ref ref;

  bool _started = false;
  String? _pendingRoute;

  /// Called once the session is signed in (and again after switching tenancy).
  Future<void> signedIn() async {
    if (!AppConfig.pushEnabled) return;
    try {
      await _start();
      final messaging = FirebaseMessaging.instance;
      final settings = await messaging.requestPermission();
      if (settings.authorizationStatus == AuthorizationStatus.denied) return;
      // Classic FCM registration tokens, which the server's HTTP v1 sends target. firebase_messaging 17
      // prefers register()/onRegistered (installation IDs); move over once the server sends to those.
      // ignore: deprecated_member_use
      final token = await messaging.getToken();
      if (token != null) await _register(token);

      // Opened from a notification while the app was closed.
      final route = _pendingRoute ?? (await messaging.getInitialMessage())?.data['route'] as String?;
      _pendingRoute = null;
      if (route != null) _open(route);
    } catch (e) {
      debugPrint('Push notifications unavailable: $e');
    }
  }

  Future<void> _start() async {
    if (_started) return;
    _started = true;
    await Firebase.initializeApp(options: AppConfig.firebaseOptions);
    final messaging = FirebaseMessaging.instance;

    // ignore: deprecated_member_use
    messaging.onTokenRefresh.listen((token) {
      if (ref.read(sessionProvider).status == SessionStatus.signedIn) _register(token);
    });
    FirebaseMessaging.onMessageOpenedApp.listen((m) {
      final route = m.data['route'] as String?;
      if (route != null) _open(route);
    });
    FirebaseMessaging.onMessage.listen(_showBanner);
  }

  Future<void> _register(String token) async {
    final path = ref.read(sessionProvider).isTenant ? '/tenant/devices' : '/auth/devices';
    try {
      await ref.read(apiProvider).post(path, data: {'token': token, 'platform': Platform.isIOS ? 'ios' : 'android'});
    } on ApiException catch (e) {
      debugPrint('Could not register for notifications: ${e.message}');
    }
  }

  void _showBanner(RemoteMessage m) {
    final n = m.notification;
    if (n == null) return;
    final route = m.data['route'] as String?;
    messengerKey.currentState
      ?..hideCurrentSnackBar()
      ..showSnackBar(SnackBar(
        backgroundColor: Brand.navy,
        duration: const Duration(seconds: 6),
        content: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(n.title ?? '', style: const TextStyle(fontWeight: FontWeight.w700)),
          if (n.body != null) Text(n.body!),
        ]),
        action: route == null ? null : SnackBarAction(label: 'Open', textColor: Brand.orange, onPressed: () => _open(route)),
      ));
  }

  /// Tab pages are switched to; detail pages open on top, so Back returns.
  void _open(String route) {
    if (ref.read(sessionProvider).status != SessionStatus.signedIn) {
      _pendingRoute = route;
      return;
    }
    final router = ref.read(routerProvider);
    RegExp(r'/\d+$').hasMatch(route) ? router.push(route) : router.go(route);
  }
}

final pushProvider = Provider<PushService>((ref) => PushService(ref));
