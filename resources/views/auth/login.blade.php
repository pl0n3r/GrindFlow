<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#070a12">
    <title>Entrar · GrindFlow</title>
    <link rel="stylesheet" href="{{ asset('css/grindflow.css') }}">
</head>
<body>
    <main class="gf-auth">
        <section class="gf-auth__visual" aria-label="GrindFlow">
            <x-brand />

            <div class="gf-auth__statement">
                <span class="gf-kicker">
                    <span class="gf-kicker__dot"></span>
                    Secure workspace
                </span>

                <h1>
                    Tu operacion,
                    <span class="gf-gradient-text">una sola vista.</span>
                </h1>

                <p>
                    Accede al workspace de tu organizacion. El aislamiento multi-tenant
                    se aplica en Laravel y se respalda con integridad en MariaDB.
                </p>
            </div>

            <div class="gf-footer">
                <span>Tenant isolation / GF-MIG-002</span>
                <span>MariaDB active</span>
            </div>
        </section>

        <section class="gf-auth__panel">
            <div class="gf-auth-card">
                <x-brand class="gf-auth-card__brand" />

                <div style="height: 42px"></div>

                <h1 class="gf-auth-card__title">Bienvenido.</h1>
                <p class="gf-auth-card__subtitle">
                    Entra con las credenciales de tu cuenta GrindFlow.
                </p>

                @if ($errors->any())
                    <div class="gf-alert" role="alert">
                        {{ $errors->first() }}
                    </div>
                @endif

                <form class="gf-form" method="POST" action="{{ route('login') }}">
                    @csrf

                    <div class="gf-field">
                        <label for="email">Correo</label>
                        <input
                            class="gf-input"
                            id="email"
                            type="email"
                            name="email"
                            value="{{ old('email') }}"
                            autocomplete="email"
                            placeholder="tu@correo.com"
                            required
                            autofocus
                        >
                    </div>

                    <div class="gf-field">
                        <label for="password">Contrasena</label>
                        <input
                            class="gf-input"
                            id="password"
                            type="password"
                            name="password"
                            autocomplete="current-password"
                            placeholder="••••••••••••"
                            required
                        >
                    </div>

                    <label class="gf-check">
                        <input type="checkbox" name="remember" value="1">
                        Mantener sesion iniciada
                    </label>

                    <button class="gf-button gf-button--primary gf-button--full" type="submit">
                        Entrar al workspace
                        <span aria-hidden="true">→</span>
                    </button>
                </form>
            </div>
        </section>
    </main>
</body>
</html>
