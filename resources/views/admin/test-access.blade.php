<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#070a12">
    <title>Test Access · GrindFlow</title>
    <link rel="stylesheet" href="{{ asset('css/grindflow.css') }}">
</head>
<body>
    <div class="gf-grid" aria-hidden="true"></div>

    <div class="gf-app">
        <aside class="gf-sidebar">
            <x-brand :href="route('dashboard')" />

            <nav class="gf-sidebar__nav" aria-label="Navegacion del workspace">
                <span class="gf-sidebar__label">Workspace</span>

                <a class="gf-navitem" href="{{ route('dashboard') }}">
                    <span class="gf-navitem__icon" aria-hidden="true">◫</span>
                    <span class="gf-navitem__text">Overview</span>
                </a>

                <span class="gf-navitem gf-navitem--disabled" aria-disabled="true">
                    <span class="gf-navitem__icon" aria-hidden="true">◇</span>
                    <span class="gf-navitem__text">Vault</span>
                </span>

                <span class="gf-sidebar__label">Admin</span>

                <a class="gf-navitem gf-navitem--active" href="{{ route('admin.test-access') }}" aria-current="page">
                    <span class="gf-navitem__icon" aria-hidden="true">⌘</span>
                    <span class="gf-navitem__text">Test Access</span>
                </a>
            </nav>

            <div class="gf-sidebar__bottom">
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button class="gf-button gf-button--ghost gf-button--full" type="submit">
                        Cerrar sesion
                    </button>
                </form>
            </div>
        </aside>

        <main class="gf-main">
            <header class="gf-appbar">
                <div class="gf-appbar__meta">GF / ADMIN / TEST ACCESS</div>

                <div class="gf-avatar" aria-label="Usuario autenticado">
                    {{ strtoupper(substr((string) auth()->user()?->name, 0, 2)) }}
                </div>
            </header>

            <section class="gf-pagehead">
                <div>
                    <span class="gf-kicker">
                        <span class="gf-kicker__dot"></span>
                        Admin only
                    </span>
                    <h1>Test Access</h1>
                    <p>
                        Gestiona la cuenta E2E desde la web. No necesitas SSH para
                        rotar las credenciales de pruebas.
                    </p>
                </div>
            </section>

            @if (session('status'))
                <div class="gf-notice gf-notice--success" role="status">
                    {{ session('status') }}
                </div>
            @endif

            @if (session('generated_password'))
                <section class="gf-panel gf-panel--spaced" aria-label="Credenciales E2E generadas">
                    <header class="gf-panel__head">
                        <h2>Nuevas credenciales E2E</h2>
                        <span class="gf-badge">Generated now</span>
                    </header>

                    <div class="gf-panel__body">
                        <div class="gf-credential-grid">
                            <div>
                                <div class="gf-metric__label">Email</div>
                                <code class="gf-secret">{{ $e2eEmail }}</code>
                            </div>

                            <div>
                                <div class="gf-metric__label">Password</div>
                                <code class="gf-secret">{{ session('generated_password') }}</code>
                            </div>
                        </div>

                        <p class="gf-helper">
                            Copia esta clave ahora. No se guarda en texto plano y no
                            volvera a mostrarse al recargar la pagina.
                        </p>
                    </div>
                </section>
            @endif

            <section class="gf-panel">
                <header class="gf-panel__head">
                    <h2>E2E administrator</h2>
                    <span class="gf-appbar__meta">
                        {{ $accountExists ? 'active' : 'not created' }}
                    </span>
                </header>

                <div class="gf-panel__body">
                    <div class="gf-admin-action">
                        <div>
                            <div class="gf-metric__label">Test account</div>
                            <h3>{{ $e2eEmail }}</h3>
                            <p>
                                Esta cuenta es exclusivamente para pruebas automatizadas
                                y manuales del panel administrativo.
                            </p>
                        </div>

                        <form method="POST" action="{{ route('admin.test-access.reset') }}">
                            @csrf
                            <button class="gf-button gf-button--primary" type="submit">
                                {{ $accountExists ? 'Rotar credenciales' : 'Crear cuenta E2E' }}
                            </button>
                        </form>
                    </div>

                    <div class="gf-notice gf-notice--warning">
                        Rotar las credenciales invalida la contraseña anterior.
                        Hazlo solo cuando necesites entregar un nuevo acceso de pruebas.
                    </div>
                </div>
            </section>
        </main>
    </div>
</body>
</html>
