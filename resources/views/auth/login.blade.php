<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#c8105a">
    <title>Entrar · GrindFlow</title>
    <link rel="stylesheet" href="{{ asset('css/grindflow.css') }}?v={{ config('version.number') }}">
    <link rel="stylesheet" href="{{ asset('css/grindflow-parrilla.css') }}?v={{ config('version.number') }}">
</head>
<body class="gp-page gp-login-page">
    <main class="gp-login">
        <section class="gp-login__band" aria-label="GrindFlow">
            <x-brand />

            <div class="gp-login__statement">
                <h1>Tu operación.<span>Una sola parrilla.</span></h1>
                <p>
                    Accede al workspace de tu organización. El aislamiento multi-tenant
                    se aplica en Laravel y se respalda con integridad en MariaDB.
                </p>
                <ol class="gp-login__stages" aria-label="Etapas del flujo">
                    <li class="gp-row--lib">Biblioteca</li>
                    <li class="gp-row--rules">Reglas</li>
                    <li class="gp-row--dist">Distribución</li>
                    <li class="gp-row--traf">Tráfico</li>
                </ol>
            </div>

            <div class="gp-login__foot">
                <span>Tenant isolation / GF-MIG-002</span>
                <span>MariaDB active</span>
                <span data-grindflow-version="{{ config('version.number', 'unknown') }}">GrindFlow · v{{ config('version.number', 'unknown') }}</span>
            </div>
        </section>

        <section class="gp-login__panel">
            <div class="gp-login__card">
                                <h1 class="gp-login__title">Bienvenido.</h1>
                <p class="gp-login__sub">
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
                        <label for="password">Contraseña</label>
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
                        Mantener sesión iniciada
                    </label>

                    <button class="gp-btn gp-btn--ink gp-btn--full" type="submit">
                        Entrar al workspace
                        <span aria-hidden="true">→</span>
                    </button>
                </form>
            </div>
        </section>
    </main>
</body>
</html>
