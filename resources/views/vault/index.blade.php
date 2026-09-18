<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#070a12">
    <title>Vault · {{ $organization->name }} · GrindFlow</title>
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

                <a
                    class="gf-navitem gf-navitem--active"
                    href="{{ route('organizations.vault.index', ['organizationId' => $organization->id]) }}"
                    aria-current="page"
                >
                    <span class="gf-navitem__icon" aria-hidden="true">◇</span>
                    <span class="gf-navitem__text">Vault</span>
                </a>

                <span class="gf-navitem gf-navitem--disabled" aria-disabled="true">
                    <span class="gf-navitem__icon" aria-hidden="true">⌁</span>
                    <span class="gf-navitem__text">Scheduler</span>
                </span>

                <span class="gf-navitem gf-navitem--disabled" aria-disabled="true">
                    <span class="gf-navitem__icon" aria-hidden="true">↗</span>
                    <span class="gf-navitem__text">Distribution</span>
                </span>

                @if (auth()->user()?->isPlatformAdmin())
                    <span class="gf-sidebar__label">Admin</span>

                    <a class="gf-navitem" href="{{ route('admin.system') }}">
                        <span class="gf-navitem__icon" aria-hidden="true">⌘</span>
                        <span class="gf-navitem__text">System</span>
                    </a>

                    <a class="gf-navitem" href="{{ route('admin.diagnostics') }}">
                        <span class="gf-navitem__icon" aria-hidden="true">!</span>
                        <span class="gf-navitem__text">Diagnostics</span>
                    </a>
                @endif
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
                    GF / {{ strtoupper($organization->slug) }} / VAULT
                </div>

                <div class="gf-avatar" aria-label="Usuario autenticado">
                    {{ strtoupper(substr((string) auth()->user()?->name, 0, 2)) }}
                </div>
            </header>

            <section class="gf-pagehead">
                <div>
                    <span class="gf-kicker">
                        <span class="gf-kicker__dot"></span>
                        Organization scoped
                    </span>
                    <h1>Vault</h1>
                    <p>
                        {{ $organization->name }} · medios privados, metadata trazable
                        y deduplicacion por SHA-256.
                    </p>
                </div>

                @if ($canUpload)
                    <a class="gf-button gf-button--primary" href="#vault-upload">
                        + Add media
                    </a>
                @endif
            </section>

            @if (session('status'))
                <div class="gf-notice gf-notice--success" role="status">
                    {{ session('status') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="gf-alert" role="alert">
                    {{ $errors->first() }}
                </div>
            @endif

            <section class="gf-metrics" aria-label="Metricas del Vault">
                <article class="gf-metric">
                    <div class="gf-metric__label">Assets</div>
                    <div class="gf-metric__value">{{ $assetCount }}</div>
                    <div class="gf-metric__meta">Registros de ingesta</div>
                </article>

                <article class="gf-metric">
                    <div class="gf-metric__label">Stored blobs</div>
                    <div class="gf-metric__value">{{ $blobCount }}</div>
                    <div class="gf-metric__meta">
                        {{ $storageBytes >= 1073741824
                            ? number_format($storageBytes / 1073741824, 2).' GB'
                            : number_format($storageBytes / 1048576, 2).' MB' }}
                    </div>
                </article>

                <article class="gf-metric">
                    <div class="gf-metric__label">Duplicates</div>
                    <div class="gf-metric__value">{{ $duplicateCount }}</div>
                    <div class="gf-metric__meta">Sin segunda copia de bytes</div>
                </article>
            </section>

            @if ($canUpload)
                <section id="vault-upload" class="gf-panel gf-panel--spaced">
                    <header class="gf-panel__head">
                        <h2>Ingest media</h2>
                        <span class="gf-appbar__meta">manual upload · foundation</span>
                    </header>

                    <div class="gf-panel__body">
                        <form
                            class="gf-upload"
                            method="POST"
                            action="{{ route('organizations.vault.store', ['organizationId' => $organization->id]) }}"
                            enctype="multipart/form-data"
                        >
                            @csrf

                            <label class="gf-upload__drop">
                                <span class="gf-upload__icon" aria-hidden="true">＋</span>
                                <span>
                                    <strong>Selecciona una imagen o video</strong>
                                    <small>
                                        JPEG, PNG, WebP, GIF, MP4, MOV o WebM ·
                                        max 8 MB
                                    </small>
                                </span>
                                <input
                                    type="file"
                                    name="media"
                                    accept="image/jpeg,image/png,image/webp,image/gif,video/mp4,video/quicktime,video/webm"
                                    required
                                >
                            </label>

                            <div class="gf-upload__footer">
                                <p>
                                    Los bytes iguales comparten un unico blob. Cada ingesta
                                    conserva su propio registro y referencia al original.
                                </p>
                                <button class="gf-button gf-button--primary" type="submit">
                                    Upload
                                </button>
                            </div>
                        </form>
                    </div>
                </section>
            @endif

            <section class="gf-panel gf-panel--spaced">
                <header class="gf-panel__head">
                    <h2>Recent media</h2>
                    <span class="gf-appbar__meta">{{ $assets->count() }} loaded</span>
                </header>

                <div class="gf-panel__body gf-panel__body--flush-mobile">
                    @if ($assets->isEmpty())
                        <div class="gf-empty">
                            <div>
                                <div class="gf-empty__icon" aria-hidden="true">◇</div>
                                <h3>El Vault esta vacio.</h3>
                                <p>
                                    Cuando se ingrese el primer archivo aparecera aqui con
                                    su hash, estado, origen y metadata de almacenamiento.
                                </p>
                            </div>
                        </div>
                    @else
                        <div class="gf-table-wrap">
                            <table class="gf-table">
                                <thead>
                                    <tr>
                                        <th>Media</th>
                                        <th>Status</th>
                                        <th>Size</th>
                                        <th>SHA-256</th>
                                        <th>Source</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($assets as $asset)
                                        <tr>
                                            <td>
                                                <div class="gf-media-name">{{ $asset->original_filename }}</div>
                                                <div class="gf-media-meta">
                                                    {{ $asset->blob?->mime_type ?? 'unknown' }}
                                                </div>
                                            </td>
                                            <td>
                                                <span class="gf-state {{ $asset->status === 'duplicate' ? 'gf-state--neutral' : 'gf-state--ok' }}">
                                                    {{ $asset->status }}
                                                </span>
                                            </td>
                                            <td>
                                                {{ number_format(($asset->blob?->byte_size ?? 0) / 1048576, 2) }} MB
                                            </td>
                                            <td>
                                                <code class="gf-hash">
                                                    {{ substr((string) $asset->blob?->sha256, 0, 16) }}…
                                                </code>
                                            </td>
                                            <td>{{ $asset->source_type }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </section>
        </main>
    </div>
</body>
</html>
