import 'dart:io';

import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'api.dart';

enum SessionStatus { loading, signedOut, signedIn }

/// Who's signed in: the profile from /auth/me (user, landlord, plan features,
/// and setup steps still to do).
class SessionState {
  const SessionState(this.status, [this.profile = const {}]);

  final SessionStatus status;
  final Map<String, dynamic> profile;

  Map<String, dynamic> get user => (profile['user'] as Map?)?.cast<String, dynamic>() ?? {};
  Map<String, dynamic> get landlord => (profile['landlord'] as Map?)?.cast<String, dynamic>() ?? {};
  Map<String, dynamic> get setup => (profile['setup'] as Map?)?.cast<String, dynamic>() ?? {};
  List<String> get features => ((profile['features'] as List?) ?? const []).map((e) => '$e').toList();

  bool get needsPasswordChange => setup['must_change_password'] == true;
  bool get needsTerms => setup['terms_required'] == true;
  bool get needsEmailVerification => setup['email_verified'] == false;
  bool get needsSetup => needsPasswordChange || needsTerms || needsEmailVerification;
  bool get readOnly => landlord['read_only'] == true;
  bool get isAdmin => user['is_admin'] == true;
}

class SessionController extends Notifier<SessionState> {
  @override
  SessionState build() {
    final api = ref.read(apiProvider);
    api.onSignedOut = () => _signedOut();
    Future.microtask(restore);

    return const SessionState(SessionStatus.loading);
  }

  ApiClient get _api => ref.read(apiProvider);

  /// On launch: a saved token? Check it's still good.
  Future<void> restore() async {
    final token = await ref.read(tokenStoreProvider).read();
    if (token == null) {
      state = const SessionState(SessionStatus.signedOut);
      return;
    }
    try {
      state = SessionState(SessionStatus.signedIn, await _api.get('/auth/me'));
    } on ApiException catch (e) {
      // Offline at launch: stay signed in with what we know; screens show the error.
      state = e.status == null ? const SessionState(SessionStatus.signedIn) : const SessionState(SessionStatus.signedOut);
    }
  }

  Future<void> login(String email, String password) async {
    final data = await _api.post('/auth/login', data: {'email': email.trim(), 'password': password, 'device_name': _deviceName()});
    await ref.read(tokenStoreProvider).write(data['token'] as String);
    state = SessionState(SessionStatus.signedIn, data..remove('token'));
  }

  Future<void> refresh() async {
    state = SessionState(SessionStatus.signedIn, await _api.get('/auth/me'));
  }

  Future<void> changePassword(String current, String password, String confirmation) async {
    final data = await _api.post('/auth/change-password', data: {'current_password': current, 'password': password, 'password_confirmation': confirmation});
    state = SessionState(SessionStatus.signedIn, data..remove('message'));
  }

  Future<void> acceptTerms() async {
    state = SessionState(SessionStatus.signedIn, await _api.post('/auth/accept-terms', data: {'terms': true}));
  }

  Future<String> resendVerification() async => (await _api.post('/auth/resend-verification'))['message'] as String;

  Future<void> logout() async {
    try {
      await _api.post('/auth/logout');
    } catch (_) {
      // Signed out on this phone either way.
    }
    await _signedOut();
  }

  Future<void> _signedOut() async {
    await ref.read(tokenStoreProvider).clear();
    state = const SessionState(SessionStatus.signedOut);
  }

  String _deviceName() {
    try {
      return '${Platform.operatingSystem} ${Platform.localHostname}'.trim();
    } catch (_) {
      return 'Primora app';
    }
  }
}

final sessionProvider = NotifierProvider<SessionController, SessionState>(SessionController.new);
