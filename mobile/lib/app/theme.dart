import 'package:flutter/material.dart';

import 'background.dart';

/// Primora's colours, as on the web: terracotta and navy.
class Brand {
  static const orange = Color(0xFFC1704A);
  static const orangeLight = Color(0xFFFAF3EE);
  static const navy = Color(0xFF1C2230);
  static const navyMuted = Color(0xFF6B7181);
  static const background = Color(0xFFF7F7F8);
  static const green = Color(0xFF15803D);
  static const red = Color(0xFFDC2626);
  static const amber = Color(0xFFB45309);
}

ThemeData primoraTheme() {
  final scheme = ColorScheme.fromSeed(
    seedColor: Brand.orange,
    primary: Brand.orange,
    secondary: Brand.navy,
    surface: Colors.white,
  );

  return ThemeData(
    useMaterial3: true,
    colorScheme: scheme,
    // Screens are see-through over the web's gradient, which each page paints under itself (app/background.dart).
    scaffoldBackgroundColor: Colors.transparent,
    pageTransitionsTheme: const PageTransitionsTheme(builders: {
      TargetPlatform.android: GlowPageTransitionsBuilder(ZoomPageTransitionsBuilder()),
      TargetPlatform.iOS: GlowPageTransitionsBuilder(CupertinoPageTransitionsBuilder()),
    }),
    appBarTheme: const AppBarTheme(
      backgroundColor: Brand.navy,
      foregroundColor: Colors.white,
      elevation: 0,
      centerTitle: false,
    ),
    cardTheme: CardThemeData(
      color: Colors.white,
      elevation: 0,
      margin: EdgeInsets.zero,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16), side: const BorderSide(color: Color(0xFFE5E7EB))),
    ),
    inputDecorationTheme: InputDecorationTheme(
      filled: true,
      fillColor: const Color(0xFFF9FAFB),
      border: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: const BorderSide(color: Color(0xFFE5E7EB))),
      enabledBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: const BorderSide(color: Color(0xFFE5E7EB))),
      focusedBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: const BorderSide(color: Brand.orange, width: 1.5)),
      contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 14),
    ),
    filledButtonTheme: FilledButtonThemeData(
      style: FilledButton.styleFrom(
        backgroundColor: Brand.orange,
        foregroundColor: Colors.white,
        minimumSize: const Size.fromHeight(48),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
        textStyle: const TextStyle(fontWeight: FontWeight.w600),
      ),
    ),
    navigationBarTheme: NavigationBarThemeData(
      backgroundColor: Colors.white,
      indicatorColor: Brand.orangeLight,
      iconTheme: WidgetStateProperty.resolveWith((states) =>
          IconThemeData(color: states.contains(WidgetState.selected) ? Brand.orange : Brand.navyMuted)),
      labelTextStyle: WidgetStateProperty.resolveWith((states) => TextStyle(
            fontSize: 12,
            fontWeight: states.contains(WidgetState.selected) ? FontWeight.w600 : FontWeight.w500,
            color: states.contains(WidgetState.selected) ? Brand.orange : Brand.navyMuted,
          )),
    ),
  );
}
