import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// The sign-in token, kept in the phone's secure storage (Keychain / Keystore).
class TokenStore {
  static const _key = 'primora_token';
  final _storage = const FlutterSecureStorage();
  String? _cached;
  bool _loaded = false;

  Future<String?> read() async {
    if (!_loaded) {
      _cached = await _storage.read(key: _key);
      _loaded = true;
    }
    return _cached;
  }

  Future<void> write(String token) async {
    _cached = token;
    _loaded = true;
    await _storage.write(key: _key, value: token);
  }

  Future<void> clear() async {
    _cached = null;
    _loaded = true;
    await _storage.delete(key: _key);
  }
}
