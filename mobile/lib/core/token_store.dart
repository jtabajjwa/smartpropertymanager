import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// The sign-in token, kept in the phone's secure storage (Keychain / Keystore),
/// and whose it is: 'landlord' (landlords and their staff) or 'tenant'.
class TokenStore {
  static const _key = 'primora_token';
  static const _roleKey = 'primora_role';
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

  Future<void> write(String token, {String role = 'landlord'}) async {
    _cached = token;
    _loaded = true;
    await _storage.write(key: _key, value: token);
    await _storage.write(key: _roleKey, value: role);
  }

  Future<String> role() async => await _storage.read(key: _roleKey) ?? 'landlord';

  Future<void> clear() async {
    _cached = null;
    _loaded = true;
    await _storage.delete(key: _key);
    await _storage.delete(key: _roleKey);
  }
}
