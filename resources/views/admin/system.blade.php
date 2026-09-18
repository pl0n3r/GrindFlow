<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#070a12">
    <title>System · GrindFlow</title>
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

                <span class="gf-navitem gf-navitem--disabled" aria-disabled="true">
                    <span class="gf-navitem__icon" aria-hidden="true">⌁</span>
                    <span class="gf-navitem__text">Scheduler</span>
                </span>

                <span class="gf-navitem gf-navitem--disabled" aria-disabled="true">
                    <span class="gf-navitem__icon" aria-hidden="true">↗</span>
                    <span class="gf-navitem__text">Distribution</span>
                </span>

                <span class="gf-sidebar__label">Admin</span>

                <a class="gf-navitem gf-navitem--active" href="{{ route('admin.system') }}" aria-current="page">
                    <span class="gf-navitem__icon" aria-hidden="true">⌘</span>
                    <span class="gf-navitem__text">System</span>
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
                <div class="gf-appbar__meta">
                    GF / ADMIN / SYSTEM
                </div>

                <div class="gf-avatar" aria-label="Usuario autenticado">
                    {{ strtoupper(substr((string) auth()->user()?->name, 0, 2)) }}
                </div>
            </header>

            <section class="gf-pagehead">
                <div>
                    <span class="gf-kicker">
                        <span class="gf-kicker__dot"></span>
                        Runtime status
                    </span>
                    <h1>System</h1>
                    <p>
                        Vista operativa del runtime de GrindFlow sin depender de SSH
                        para comprobaciones rutinarias.
                    </p>
                </div>

                <span class="gf-badge">Admin only</span>
            </section>

            <section class="gf-metrics" aria-label="Estado del sistema">
                <article class="gf-metric">
                    <div class="gf-metric__label">Application</div>
                    <div class="gf-metric__value">Online</div>
                    <div class="gf-metric__meta">Laravel {{ $laravelVersion }}</div>
                </article>

                <article class="gf-metric">
                    <div class="gf-metric__label">Database</div>
                    <div class="gf-metric__value">
                        {{ $databaseOnline ? 'Online' : 'Offline' }}
                    </div>
                    <div class="gf-metric__meta">{{ strtoupper($databaseDriver) }}</div>
                </article>

                <article class="gf-metric">
                    <div class="gf-metric__label">Environment</div>
                    <div class="gf-metric__value">{{ ucfirst($environment) }}</div>
                    <div class="gf-metric__meta">Server configuration</div>
                </article>
            </section>

            <section class="gf-panel">
                <header class="gf-panel__head">
                    <h2>Runtime configuration</h2>
                    <span class="gf-appbar__meta">read only</span>
                </header>

                <div class="gf-panel__body">
                    <div class="gf-system-grid">
                        <article class="gf-system-item">
                            <div class="gf-system-item__label">Database connection</div>
                            <div class="gf-system-item__row">
                                <span>{{ strtoupper($databaseDriver) }}</span>
                                <span class="gf-state {{ $databaseOnline ? 'gf-state--ok' : 'gf-state--error' }}">
                                    {{ $databaseOnline ? 'Connected' : 'Unavailable' }}
                                </span>
                            </div>
                        </article>

                        <article class="gf-system-item">
                            <div class="gf-system-item__label">Session driver</div>
                            <div class="gf-system-item__row">
                                <span>{{ $sessionDriver }}</span>
                                <span class="gf-state gf-state--neutral">Configured</span>
                            </div>
                        </article>

                        <article class="gf-system-item">
                            <div class="gf-system-item__label">Queue connection</div>
                            <div class="gf-system-item__row">
                                <span>{{ $queueConnection }}</span>
                                <span class="gf-state gf-state--neutral">Configured</span>
                            </div>
                        </article>

                        <article class="gf-system-item">
                            <div class="gf-system-item__label">Environment</div>
                            <div class="gf-system-item__row">
                                <span>{{ $environment }}</span>
                                <span class="gf-state gf-state--ok">Detected</span>
                            </div>
                        </article>
                    </div>
                </div>
            </section>

            <section class="gf-panel gf-panel--spaced">
                <header class="gf-panel__head">
                    <h2>Operational contract</h2>
                    <span class="gf-appbar__meta">no SSH required</span>
                </header>

                <div class="gf-panel__body">
                    <p class="gf-system-copy">
                        Esta pantalla solo expone estado no sensible. No muestra claves,
                        credenciales, variables de entorno ni datos internos de conexion.
                        Las operaciones rutinarias deben moverse al panel web conforme
                        se implementen.
                    </p>
                </div>
            </section>
        </main>
    </div>
</body>
</html>
