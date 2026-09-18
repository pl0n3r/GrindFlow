<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login · GrindFlow</title>
</head>
<body>
    <main>
        <h1>GrindFlow</h1>
        <h2>Sign in</h2>

        @if ($errors->any())
            <div role="alert">
                {{ $errors->first() }}
            </div>
        @endif

        <form method="POST" action="{{ route('login') }}">
            @csrf

            <label>
                Email
                <input
                    type="email"
                    name="email"
                    value="{{ old('email') }}"
                    autocomplete="email"
                    required
                    autofocus
                >
            </label>

            <label>
                Password
                <input
                    type="password"
                    name="password"
                    autocomplete="current-password"
                    required
                >
            </label>

            <label>
                <input type="checkbox" name="remember" value="1">
                Remember me
            </label>

            <button type="submit">Sign in</button>
        </form>
    </main>
</body>
</html>
