import 'package:firebase_core/firebase_core.dart';

/// Where the app finds the Primora API.
///
/// Set it when building or running, e.g.
///   flutter run --dart-define=API_BASE_URL=https://staging.primoraproptech.com/api/v1
///
/// The default suits the Android emulator talking to `php artisan serve` on
/// the development machine (10.0.2.2 is the host's localhost).
class AppConfig {
  // Push notifications: the Firebase project's Android app settings (not secret),
  // passed with --dart-define-from-file=firebase.json (see README). Empty = no push.
  static const _fcmApiKey = String.fromEnvironment('FCM_API_KEY');
  static const _fcmAppId = String.fromEnvironment('FCM_APP_ID');
  static const _fcmSenderId = String.fromEnvironment('FCM_SENDER_ID');
  static const _fcmProjectId = String.fromEnvironment('FCM_PROJECT_ID');

  static bool get pushEnabled => _fcmApiKey.isNotEmpty && _fcmAppId.isNotEmpty && _fcmSenderId.isNotEmpty && _fcmProjectId.isNotEmpty;

  static FirebaseOptions get firebaseOptions =>
      const FirebaseOptions(apiKey: _fcmApiKey, appId: _fcmAppId, messagingSenderId: _fcmSenderId, projectId: _fcmProjectId);

  static const apiBaseUrl = String.fromEnvironment(
    'API_BASE_URL',
    defaultValue: 'http://10.0.2.2:8000/api/v1',
  );

  /// The web portal, for features the app doesn't have yet.
  static String get webBaseUrl => apiBaseUrl.replaceFirst(RegExp(r'/api/v1/?$'), '');
}
