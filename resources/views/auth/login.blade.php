<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('app.login') }} | {{ __('app.app_name') }}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            min-height: 100vh;
            background: #f5f7fb;
            display: grid;
            place-items: center;
            color: #16213e;
        }
        .login-panel {
            width: min(420px, calc(100vw - 32px));
            background: #fff;
            border: 1px solid #e7ecf4;
            border-radius: 8px;
            box-shadow: 0 16px 40px rgba(24, 39, 75, .08);
        }
        .brand-logo {
            display: block;
            width: 210px;
            max-width: 100%;
            height: auto;
            margin-bottom: .75rem;
        }
    </style>
</head>
<body>
    <main class="login-panel p-4">
        <div class="mb-4">
            <img src="{{ asset('assets/north-west-logo.png') }}" alt="North West" class="brand-logo">
            <div>
                <div class="fw-bold fs-4">{{ __('app.app_name') }}</div>
                <div class="text-secondary small">{{ __('app.tagline') }}</div>
            </div>
        </div>

        @include('partials.flash')

        <form method="POST" action="{{ route('login') }}" class="vstack gap-3">
            @csrf
            <div>
                <label class="form-label">{{ __('app.email') }}</label>
                <input type="email" name="email" value="{{ old('email') }}" class="form-control" required autofocus>
            </div>
            <div>
                <label class="form-label">{{ __('app.password') }}</label>
                <input type="password" name="password" class="form-control" required>
            </div>
            <label class="form-check">
                <input class="form-check-input" type="checkbox" name="remember" value="1">
                <span class="form-check-label">{{ __('app.remember') }}</span>
            </label>
            <button class="btn btn-primary w-100">
                <i class="bi bi-box-arrow-in-right me-1"></i>{{ __('app.login') }}
            </button>
        </form>
    </main>
</body>
</html>
