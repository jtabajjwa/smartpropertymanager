import 'dart:io';

import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:path_provider/path_provider.dart';

import 'config.dart';
import 'token_store.dart';

/// An error from the API, in words for the user, with the server's
/// machine-readable [code] (e.g. `terms_required`) and per-field messages.
class ApiException implements Exception {
  ApiException(this.message, {this.code, this.status, this.fieldErrors = const {}});

  final String message;
  final String? code;
  final int? status;
  final Map<String, String> fieldErrors;

  bool get isUnauthenticated => status == 401;

  @override
  String toString() => message;
}

/// Called when the server says the token is no longer valid.
typedef SignedOutCallback = void Function();

/// A thin wrapper around Dio: base URL, the bearer token, JSON, and errors
/// turned into [ApiException].
class ApiClient {
  ApiClient(this._tokens, {this.onSignedOut})
      : _dio = Dio(BaseOptions(
          baseUrl: AppConfig.apiBaseUrl,
          connectTimeout: const Duration(seconds: 15),
          receiveTimeout: const Duration(seconds: 30),
          headers: {'Accept': 'application/json'},
        )) {
    _dio.interceptors.add(InterceptorsWrapper(onRequest: (options, handler) async {
      final token = await _tokens.read();
      if (token != null) options.headers['Authorization'] = 'Bearer $token';
      handler.next(options);
    }));
  }

  final Dio _dio;
  final TokenStore _tokens;
  SignedOutCallback? onSignedOut;

  Future<Map<String, dynamic>> get(String path, {Map<String, dynamic>? query}) =>
      _send(() => _dio.get(path, queryParameters: _clean(query)));

  Future<Map<String, dynamic>> post(String path, {Object? data}) => _send(() => _dio.post(path, data: data));

  Future<Map<String, dynamic>> put(String path, {Object? data}) => _send(() => _dio.put(path, data: data));

  Future<Map<String, dynamic>> delete(String path) => _send(() => _dio.delete(path));

  /// Downloads a file (e.g. a PDF receipt) to the app's temporary folder.
  Future<File> download(String path, String filename) async {
    final dir = await getTemporaryDirectory();
    final file = File('${dir.path}/$filename');
    try {
      await _dio.download(path, file.path, options: Options(headers: {'Accept': 'application/pdf'}));
    } on DioException catch (e) {
      throw _toException(e);
    }

    return file;
  }

  Future<Map<String, dynamic>> _send(Future<Response<dynamic>> Function() request) async {
    try {
      final response = await request();
      final data = response.data;
      return data is Map<String, dynamic> ? data : <String, dynamic>{'data': data};
    } on DioException catch (e) {
      final error = _toException(e);
      if (error.isUnauthenticated) onSignedOut?.call();
      throw error;
    }
  }

  ApiException _toException(DioException e) {
    final response = e.response;
    if (response == null) {
      return ApiException(
        e.type == DioExceptionType.connectionTimeout || e.type == DioExceptionType.receiveTimeout
            ? 'The connection timed out. Check your internet and try again.'
            : 'Could not reach Primora. Check your internet connection.',
      );
    }

    final body = response.data;
    final fields = <String, String>{};
    String? message;
    String? code;
    if (body is Map) {
      message = body['message']?.toString();
      code = body['code']?.toString();
      final errors = body['errors'];
      if (errors is Map) {
        errors.forEach((key, value) {
          if (value is List && value.isNotEmpty) fields[key.toString()] = value.first.toString();
        });
      }
    }

    return ApiException(
      fields.isNotEmpty ? fields.values.first : (message ?? 'Something went wrong (${response.statusCode}).'),
      code: code,
      status: response.statusCode,
      fieldErrors: fields,
    );
  }

  static Map<String, dynamic>? _clean(Map<String, dynamic>? query) =>
      query == null ? null : (Map.of(query)..removeWhere((_, v) => v == null || v == ''));
}

final tokenStoreProvider = Provider<TokenStore>((ref) => TokenStore());

final apiProvider = Provider<ApiClient>((ref) => ApiClient(ref.watch(tokenStoreProvider)));
