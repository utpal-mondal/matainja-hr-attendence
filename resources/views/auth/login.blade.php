<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Sign in · HRM Matainja Admin</title>
    <link rel="stylesheet" href="{{ asset('admin-assets/admin.css') }}?v=6">
</head>
<body>
<div class="login-wrap">
    <div class="card login-card">
        <div class="brand">
            @include('admin.partials.brand-mark')
            <span>HRM Matainja<small>Super Admin console</small></span>
        </div>
        <h1 style="margin-bottom:4px">Sign in</h1>
        <p class="muted" style="margin:0 0 18px">Use a Super Admin account. Other roles keep using the mobile app.</p>

        @include('admin.partials.flash')

        <form method="POST" action="{{ route('admin.login.attempt') }}">
            @csrf
            <div class="field">
                <label for="email">Email</label>
                <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username">
            </div>
            <div class="field">
                <label for="password">Password</label>
                <input id="password" type="password" name="password" required autocomplete="current-password">
            </div>
            <div class="field">
                <label class="check"><input type="checkbox" name="remember" value="1" @checked(old('remember'))> Keep me signed in</label>
            </div>
            <button class="btn primary" type="submit" style="width:100%">Sign in</button>
        </form>
    </div>
</div>
</body>
</html>
