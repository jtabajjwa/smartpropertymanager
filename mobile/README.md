# Primora mobile app (Flutter)

One app for Primora. This first release is for **landlords and their staff**;
the tenant side comes in a later phase. It talks to the Laravel app's API
(`/api/v1`, see `routes/api.php`), signed in with a Sanctum token per device.

## What's in it (phase 1: landlord core)

- Sign in, forgot password (6-digit email code), and the setup steps the server
  asks for: verify email, change a temporary password, accept updated terms.
- **Home:** net income (from Books), unpaid and overdue invoices, occupancy,
  the last six months, recent payments, leases ending soon.
- **Tenants:** search, a tenant's balance, deposit, lease, invoices and
  payments; call or SMS them; add a tenant.
- **Invoices:** filter (unpaid, overdue, paid), see what has been paid, raise one.
- **Payments:** record a payment (applied to unpaid invoices, SMS receipt),
  see what it paid, open or share the PDF receipt.
- **More:** account, plan, and links to the web portal for everything else.

A lapsed subscription is read-only in the app too.

## Run it

Start the Laravel app as usual (`php artisan serve` from the repo root), then:

```
cd mobile
flutter pub get
flutter run
```

By default the app calls `http://10.0.2.2:8000/api/v1`, which is the dev
machine's `php artisan serve` as seen from the **Android emulator**. For
anything else, pass the API address:

```
# A real phone on the same Wi-Fi (serve with --host=0.0.0.0)
flutter run --dart-define=API_BASE_URL=http://192.168.1.20:8000/api/v1

# Staging
flutter run --dart-define=API_BASE_URL=https://staging.primoraproptech.com/api/v1
```

Plain `http://` only works in debug builds; release builds need `https://`.

## Build

```
flutter build apk --release --dart-define=API_BASE_URL=https://app.primoraproptech.com/api/v1
flutter build appbundle --release --dart-define=API_BASE_URL=https://app.primoraproptech.com/api/v1
```

Release signing (a keystore) and store listings are still to be set up.

## Push notifications

Optional at build time: without the Firebase settings the app builds and runs, just without push.
With them, add `--dart-define-from-file=firebase.json` to any `flutter run` / `flutter build`:

```
flutter build apk --release --dart-define=API_BASE_URL=https://app.primoraproptech.com/api/v1 --dart-define-from-file=firebase.json
```

`firebase.json` (copy `firebase.example.json`) holds the Firebase project's **Android app** values from
Firebase console → Project settings → General → Your apps (or the downloaded `google-services.json`):
`FCM_API_KEY` = `current_key`, `FCM_APP_ID` = `mobilesdk_app_id`, `FCM_SENDER_ID` = `project_number`,
`FCM_PROJECT_ID` = `project_id`. These identify the app and are not secret.

The server sends the notifications: set `FCM_PROJECT_ID` and `FCM_CREDENTIALS` (path to the service
account key; that one **is** secret, keep it only on the server) in its `.env`; the queue worker sends them.
What is sent and when: `app/Services/Push/PushNotifications.php`.
iOS builds need a Mac with Xcode.

## Layout

- `lib/core/`: API client, session, formatting, shared widgets and forms
- `lib/app/`: theme (Primora's terracotta and navy) and routes
- `lib/features/`: one folder per screen group

The server deploy leaves this folder out (`.gitattributes`: `export-ignore`).
