import java.util.Properties

// Release signing key: kept OUTSIDE the project (it's secret). Point PRIMORA_SIGNING at a
// key.properties file (storeFile, storePassword, keyAlias, keyPassword); the default is
// ~/primora-signing/key.properties. Without it, release builds fall back to the debug key.
val signingFile = file(System.getenv("PRIMORA_SIGNING") ?: "${System.getProperty("user.home")}/primora-signing/key.properties")
val signing = Properties().apply { if (signingFile.exists()) signingFile.inputStream().use { load(it) } }

plugins {
    id("com.android.application")
    id("kotlin-android")
    // The Flutter Gradle Plugin must be applied after the Android and Kotlin Gradle plugins.
    id("dev.flutter.flutter-gradle-plugin")
}

android {
    namespace = "com.primoraproptech.primora"
    compileSdk = flutter.compileSdkVersion
    // NDK 25 is what this machine has fully installed; the app has no native code of its own.
    ndkVersion = "25.1.8937393"

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_11
        targetCompatibility = JavaVersion.VERSION_11
    }

    kotlinOptions {
        jvmTarget = JavaVersion.VERSION_11.toString()
    }

    defaultConfig {
        // TODO: Specify your own unique Application ID (https://developer.android.com/studio/build/application-id.html).
        applicationId = "com.primoraproptech.primora"
        // You can update the following values to match your application needs.
        // For more information, see: https://flutter.dev/to/review-gradle-config.
        minSdk = flutter.minSdkVersion
        targetSdk = flutter.targetSdkVersion
        versionCode = flutter.versionCode
        versionName = flutter.versionName
    }

    signingConfigs {
        if (signingFile.exists()) {
            create("release") {
                storeFile = file(signing.getProperty("storeFile"))
                storePassword = signing.getProperty("storePassword")
                keyAlias = signing.getProperty("keyAlias")
                keyPassword = signing.getProperty("keyPassword")
            }
        }
    }

    buildTypes {
        release {
            signingConfig = if (signingFile.exists()) {
                signingConfigs.getByName("release")
            } else {
                logger.warn("Release key not found at $signingFile: signing with the DEBUG key (not for distribution).")
                signingConfigs.getByName("debug")
            }
        }
    }
}

flutter {
    source = "../.."
}
