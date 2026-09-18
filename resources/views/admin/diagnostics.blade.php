<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#070a12">
    <title>Diagnostics · GrindFlow</title>
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

                <span class="gf-sidebar__label">Admin</span>

                <a class="gf-navitem" href="{{ route('admin.system') }}">
                    <span class="gf-navitem__icon" aria-hidden="true">⌘</span>
                    <span class="gf-navitem__text">System</span>
                </a>

                <a class="gf-navitem gf-navitem--active" href="{{ route('admin.diagnostics') }}" aria-current="page">
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
                <div class="gf-appbar__meta">GF / ADMIN / DIAGNOSTICS</div>

                <div class="gf-avatar" aria-label="Usuario autenticado">
                    {{ strtoupper(substr((string) auth()->user()?->name, 0, 2)) }}
                </div>
            </header>

            <section class="gf-pagehead">
                <div>
                    <span class="gf-kicker">
                        <span class="gf-kicker__dot"></span>
                        Server errors
                    </span>
                    <h1>Diagnostics</h1>
                    <p>
                        Ultimos errores 5xx capturados por GrindFlow. No se registran
                        passwords, cookies, headers ni cuerpos de las peticiones.
                    </p>
                </div>

                <a class="gf-button gf-button--ghost" href="{{ route('admin.diagnostics.json') }}">
                    JSON
                </a>
            </section>

            <section class="gf-panel">
                <header class="gf-panel__head">
                    <h2>Recent incidents</h2>
                    <span class="gf-appbar__meta">{{ count($entries) }} loaded</span>
                </header>

                <div class="gf-panel__body">
                    @if ($entries === [])
                        <div class="gf-empty">
                            <div>
                                <div class="gf-empty__icon" aria-hidden="true">✓</div>
                                <h3>No hay errores 5xx registrados.</h3>
                                <p>
                                    Los nuevos fallos del servidor apareceran aqui
                                    automaticamente con un incident ID.
                                </p>
                            </div>
                        </div>
                    @else
                        <div class="gf-diagnostics">
                            @foreach ($entries as $entry)
                                <details class="gf-diagnostic">
                                    <summary class="gf-diagnostic__summary">
                                        <div>
                                            <div class="gf-diagnostic__title">
                                                {{ $entry['exception'] ?? 'Server error' }}
                                            </div>
                                            <div class="gf-diagnostic__meta">
                                                {{ $entry['timestamp'] ?? 'unknown time' }}
                                                · HTTP {{ $entry['status'] ?? 500 }}
                                                · {{ $entry['request']['method'] ?? 'N/A' }}
                                                {{ $entry['request']['path'] ?? '' }}
                                            </div>
                                        </div>

                                        <code class="gf-diagnostic__id">
                                            {{ $entry['incident_id'] ?? 'no-id' }}
                                        </code>
                                    </summary>

                                    <div class="gf-diagnostic__body">
                                        <div>
                                            <div class="gf-metric__label">Message</div>
                                            <pre class="gf-diagnostic__code">{{ $entry['message'] ?? '' }}</pre>
                                        </div>

                                        <div>
                                            <div class="gf-metric__label">Location</div>
                                            <pre class="gf-diagnostic__code">{{ $entry['location'] ?? '' }}</pre>
                                        </div>

                                        <div>
                                            <div class="gf-metric__label">Context</div>
                                            <pre class="gf-diagnostic__code">{{ json_encode($entry['request'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                        </div>

                                        <div>
                                            <div class="gf-metric__label">Trace</div>
                                            <pre class="gf-diagnostic__code">@foreach (($entry['trace'] ?? []) as $frame){{ ($frame['file'] ?? '?') }}:{{ ($frame['line'] ?? '?') }} {{ ($frame['call'] ?? '') }}
@endforeach</pre>
                                        </div>
                                    </div>
                                </details>
                            @endforeach
                        </div>
                    @endif
                </div>
            </section>
        </main>
    </div>
</body>
</html>
