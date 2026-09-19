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

                <a class="gf-navitem" href="{{ route('admin.diagnostics') }}">
                    <span class="gf-navitem__icon" aria-hidden="true">!</span>
                    <span class="gf-navitem__text">Diagnostics</span>
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
                <div class="gf-appbar__meta">GF / ADMIN / SYSTEM</div>

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
                        Estado del runtime y operaciones controladas sin depender de SSH
                        para el trabajo rutinario.
                    </p>
                </div>

                <span class="gf-badge">Admin only</span>
            </section>

            @if (session('status'))
                <div class="gf-notice gf-notice--success" role="status">
                    {{ session('status') }}
                </div>
            @endif

            @if ($errors->has('migration') || $errors->has('backup_confirmed') || $errors->has('confirmation') || $errors->has('migration_batch'))
                <div class="gf-alert" role="alert">
                    {{ $errors->first('migration')
                        ?? $errors->first('backup_confirmed')
                        ?? $errors->first('confirmation')
                        ?? $errors->first('migration_batch') }}
                </div>
            @endif

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
                    <div class="gf-metric__label">Pending migrations</div>
                    <div class="gf-metric__value">
                        {{ $pendingMigrations === null ? '?' : $pendingMigrations }}
                    </div>
                    <div class="gf-metric__meta">
                        {{ $pendingMigrations === 0 ? 'Schema current' : 'Operator action required' }}
                    </div>
                </article>

                <article class="gf-metric">
                    <div class="gf-metric__label">Media storage</div>
                    <div class="gf-metric__value">
                        {{ $mediaStorageConfigured ? 'Ready' : 'Setup required' }}
                    </div>
                    <div class="gf-metric__meta">
                        {{ strtoupper($mediaStorageDriver) }} · {{ $mediaStorageDisk }}
                    </div>
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
                            <div class="gf-system-item__label">Database schema</div>
                            <div class="gf-system-item__row">
                                <span>Pending migrations</span>
                                <span
                                    class="gf-state {{ $pendingMigrations === 0 ? 'gf-state--ok' : 'gf-state--error' }}"
                                    data-pending-migrations="{{ $pendingMigrations === null ? 'unknown' : $pendingMigrations }}"
                                >
                                    {{ $pendingMigrations === null ? 'Unknown' : $pendingMigrations }}
                                </span>
                            </div>
                        </article>

                        <article class="gf-system-item">
                            <div class="gf-system-item__label">Media object storage</div>
                            <div class="gf-system-item__row">
                                <span>
                                    {{ strtoupper($mediaStorageDriver) }} ·
                                    {{ number_format($mediaStorageMaxBytes / 1073741824, 1) }} GiB max
                                </span>
                                <span
                                    class="gf-state {{ $mediaStorageConfigured ? 'gf-state--ok' : 'gf-state--neutral' }}"
                                    data-media-storage-configured="{{ $mediaStorageConfigured ? '1' : '0' }}"
                                >
                                    {{ $mediaStorageConfigured ? 'Configured' : 'Setup required' }}
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
                    <h2>Database migrations</h2>
                    <span class="gf-appbar__meta">explicit operator action</span>
                </header>

                <div class="gf-panel__body">
                    <div class="gf-admin-action">
                        <div>
                            <div class="gf-metric__label">Schema maintenance</div>
                            <h3>
                                {{ $pendingMigrations === 0
                                    ? 'Database schema is current'
                                    : 'Pending migrations need to be applied' }}
                            </h3>
                            <p class="gf-system-copy">
                                Revisa el lote exacto antes de continuar. CI no ejecuta
                                migraciones productivas. Esta pantalla no hace ni verifica
                                backups: confirma un respaldo externo restaurable por separado.
                            </p>

                            @if ($pendingMigrations === null)
                                <p class="gf-system-copy" role="status">
                                    El inventario no esta disponible. No es seguro ejecutar migraciones.
                                </p>
                            @elseif ($pendingMigrations === 0)
                                <p class="gf-system-copy" role="status">
                                    Sin migraciones pendientes.
                                </p>
                            @else
                                <p class="gf-metric__label">
                                    Lote pendiente: {{ $pendingMigrations }} migraciones
                                </p>
                                <ol class="gf-system-copy" data-pending-migration-inventory>
                                    @foreach ($pendingMigrationNames as $migrationName)
                                        <li><code>{{ $migrationName }}</code></li>
                                    @endforeach
                                </ol>
                            @endif
                        </div>

                        @if ($databaseOnline && $pendingMigrations !== null && $pendingMigrations > 0)
                            <form method="POST" action="{{ route('admin.system.migrate') }}">
                                @csrf
                                <input
                                    type="hidden"
                                    name="migration_batch"
                                    value="{{ $migrationFingerprint }}"
                                >

                                <div class="gf-field">
                                    <label for="backup_confirmed">
                                        <input
                                            id="backup_confirmed"
                                            type="checkbox"
                                            name="backup_confirmed"
                                            value="1"
                                            required
                                        >
                                        Verifique personalmente un backup externo restaurable
                                        de esta base de datos.
                                    </label>
                                </div>

                                <div class="gf-field">
                                    <label for="confirmation">
                                        Escribe MIGRAR para confirmar este lote
                                    </label>
                                    <input
                                        class="gf-input"
                                        id="confirmation"
                                        name="confirmation"
                                        type="text"
                                        autocomplete="off"
                                        spellcheck="false"
                                        placeholder="MIGRAR"
                                        required
                                    >
                                </div>

                                <button class="gf-button gf-button--primary" type="submit">
                                    Run pending migrations
                                </button>
                            </form>
                        @endif
                    </div>
                </div>
            </section>

            <section class="gf-panel gf-panel--spaced">
                <header class="gf-panel__head">
                    <h2>Operational contract</h2>
                    <span class="gf-appbar__meta">no routine SSH</span>
                </header>

                <div class="gf-panel__body">
                    <p class="gf-system-copy">
                        Esta pantalla no muestra claves, credenciales, variables de
                        entorno ni datos internos de conexion. SSH queda reservado para
                        diagnostico excepcional o recuperacion.
                    </p>
                </div>
            </section>
        </main>
    </div>
</body>
</html>
