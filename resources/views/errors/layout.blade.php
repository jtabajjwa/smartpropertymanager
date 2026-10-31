<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') — {{ config('app.name', 'Primora') }}</title>
    {{-- Plain CSS, no @vite: these pages must render even when the app's assets or code are what's broken. --}}
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 16px;
            background: #f9fafb; color: #111827; font-family: Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
        .card { width: 100%; max-width: 420px; background: #fff; border: 1px solid #e5e7eb; border-radius: 16px;
            box-shadow: 0 20px 40px -12px rgba(28, 34, 48, .18); padding: 32px 28px; text-align: center; }
        .logo { display: flex; flex-direction: column; align-items: center; margin-bottom: 24px; }
        .logo p { margin: 6px 0 0; font-size: 22px; font-weight: 700; letter-spacing: -.02em; color: #1c2230; }
        .code { display: inline-block; font-size: 12px; font-weight: 600; letter-spacing: .12em; color: #c1704a;
            background: #fdf4ef; border-radius: 999px; padding: 4px 10px; margin-bottom: 12px; }
        h1 { margin: 0 0 8px; font-size: 18px; font-weight: 600; }
        .msg { margin: 0 0 24px; font-size: 14px; line-height: 1.55; color: #6b7280; }
        .actions { display: flex; gap: 12px; }
        .btn { flex: 1; display: inline-block; text-decoration: none; border-radius: 12px; padding: 10px 16px; font-size: 14px; font-weight: 500; cursor: pointer; border: 0; font-family: inherit; }
        .btn-primary { background: #c1704a; color: #fff; }
        .btn-primary:hover { background: #a95f3c; }
        .btn-secondary { background: #f3f4f6; color: #374151; }
        .btn-secondary:hover { background: #e5e7eb; }
    </style>
</head>
<body>
    <main class="card">
        <div class="logo">
            <svg width="48" height="48" viewBox="0 0 120 120" fill="none" aria-hidden="true">
                <path d="M15 55 L60 15 L105 55" stroke="#1c2230" stroke-width="11" stroke-linecap="square" stroke-linejoin="miter"/>
                <path d="M35 72 L60 50 L85 72" stroke="#c1704a" stroke-width="10" stroke-linecap="square" stroke-linejoin="miter"/>
                <rect x="54" y="83" width="12" height="17" rx="1" fill="#1c2230"/>
            </svg>
            <p>{{ config('app.name', 'Primora') }}</p>
        </div>

        <span class="code">ERROR @yield('code')</span>
        <h1>@yield('title')</h1>
        <p class="msg">@yield('message')</p>

        <div class="actions">
            <button type="button" class="btn btn-secondary" onclick="history.length > 1 ? history.back() : (location.href = '/')">Go back</button>
            <a href="@yield('home', url('/'))" class="btn btn-primary">@yield('homeLabel', 'Go to home page')</a>
        </div>
    </main>
</body>
</html>
