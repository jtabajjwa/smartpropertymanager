/// Where the app finds the Primora API.
///
/// Set it when building or running, e.g.
///   flutter run --dart-define=API_BASE_URL=https://staging.primoraproptech.com/api/v1
///
/// The default suits the Android emulator talking to `php artisan serve` on
/// the development machine (10.0.2.2 is the host's localhost).
class AppConfig {
  static const apiBaseUrl = String.fromEnvironment(
    'API_BASE_URL',
    defaultValue: 'http://10.0.2.2:8000/api/v1',
  );

  /// The web portal, for features the app doesn't have yet.
  static String get webBaseUrl => apiBaseUrl.replaceFirst(RegExp(r'/api/v1/?$'), '');
}
