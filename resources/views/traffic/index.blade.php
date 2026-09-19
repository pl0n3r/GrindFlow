<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#070a12">
    <title>Traffic · {{ $organization->name }} · GrindFlow</title>
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
                    class="gf-navitem"
                    href="{{ route('organizations.vault.index', ['organizationId' => $organization->id]) }}"
                >
                    <span class="gf-navitem__icon" aria-hidden="true">◇</span>
                    <span class="gf-navitem__text">Vault</span>
                </a>

                <a class="gf-navitem" href="{{ route('organizations.distribution.index', ['organizationId' => $organization->id]) }}">
                    <span class="gf-navitem__icon" aria-hidden="true">⇢</span>
                    <span class="gf-navitem__text">Distribution</span>
                </a>

                <a
                    class="gf-navitem"
                    href="{{ route('organizations.scheduler.index', ['organizationId' => $organization->id]) }}"
                >
                    <span class="gf-navitem__icon" aria-hidden="true">⌁</span>
                    <span class="gf-navitem__text">Scheduler</span>
                </a>

                <span class="gf-sidebar__label">Insights</span>

                <a
                    class="gf-navitem gf-navitem--active"
                    href="{{ route('organizations.traffic.index', ['organizationId' => $organization->id]) }}"
                    aria-current="page"
                >
                    <span class="gf-navitem__icon" aria-hidden="true">⌗</span>
                    <span class="gf-navitem__text">Traffic</span>
                </a>

                @if (auth()->user()?->canManageFinanceOrganization($organization))
                    <a
                        class="gf-navitem"
                        href="{{ route('organizations.finance.index', ['organizationId' => $organization->id]) }}"
                    >
                        <span class="gf-navitem__icon" aria-hidden="true">$</span>
                        <span class="gf-navitem__text">Finance</span>
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
                    GF / {{ strtoupper($organization->slug) }} / TRAFFIC
                </div>

                <div class="gf-avatar" aria-label="Usuario autenticado">
                    {{ strtoupper(substr((string) auth()->user()?->name, 0, 2)) }}
                </div>
            </header>

            <section class="gf-pagehead">
                <div>
                    <span class="gf-kicker">
                        <span class="gf-kicker__dot"></span>
                        Privacy-preserving attribution
                    </span>
                    <h1>Traffic</h1>
                    <p>
                        Crea enlaces medibles sin conservar IP, User-Agent ni referrer
                        en la base de datos.
                    </p>
                </div>

                <span class="gf-badge">
                    {{ $trafficReady ? 'Traffic schema ready' : 'Migration required' }}
                </span>
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

            @if (! $trafficReady)
                <section class="gf-panel">
                    <div class="gf-panel__body">
                        <div class="gf-empty">
                            <div>
                                <div class="gf-empty__icon" aria-hidden="true">⌗</div>
                                <h3>Traffic migration required.</h3>
                                <p>
                                    La aplicacion queda en modo seguro hasta que las tablas
                                    de atribucion existan. Los writes responden 503 y el
                                    redirector nuevo no intenta resolver links inexistentes.
                                </p>
                            </div>
                        </div>
                    </div>
                </section>
            @else
                <section class="gf-metrics" aria-label="Metricas de Traffic">
                    <article class="gf-metric">
                        <div class="gf-metric__label">Matched tracked links</div>
                        <div class="gf-metric__value">{{ number_format($linkCount) }}</div>
                        <div class="gf-metric__meta">Mostrando hasta 100 links recientes</div>
                    </article>

                    <article class="gf-metric">
                        <div class="gf-metric__label">Loaded clicks</div>
                        <div class="gf-metric__value">
                            {{ number_format($totalClicks) }}
                        </div>
                        <div class="gf-metric__meta">{{ $from }} → {{ $to }}, sin IP cruda</div>
                    </article>

                    <article class="gf-metric">
                        <div class="gf-metric__label">Dedupe</div>
                        <div class="gf-metric__value">10m</div>
                        <div class="gf-metric__meta">Ventana fija server-side</div>
                    </article>
                </section>

                <section class="gf-panel gf-panel--spaced">
                    <header class="gf-panel__head"><h2>Traffic analytics</h2><span class="gf-appbar__meta">Filtered daily aggregates</span></header>
                    <div class="gf-panel__body">
                        <form class="gf-form" method="GET">
                            <div class="gf-field"><label for="from">From</label><input class="gf-input" id="from" name="from" type="date" value="{{ $from }}" required></div>
                            <div class="gf-field"><label for="to">To</label><input class="gf-input" id="to" name="to" type="date" value="{{ $to }}" required></div>
                            <div class="gf-field"><label for="channel_filter">Channel</label><select class="gf-input" id="channel_filter" name="channel"><option value="">All channels</option>@foreach($channels as $channel)<option value="{{ $channel }}" @selected(($filters['channel'] ?? '') === $channel)>{{ $channel }}</option>@endforeach</select></div>
                            <div class="gf-field"><label for="campaign_filter">Campaign</label><input class="gf-input" id="campaign_filter" name="campaign" maxlength="128" value="{{ $filters['campaign'] ?? '' }}"></div>
                            <div class="gf-field"><label for="status_filter">Link status</label><select class="gf-input" id="status_filter" name="status"><option value="">Any status</option><option value="active" @selected(($filters['status'] ?? '') === 'active')>Active</option><option value="disabled" @selected(($filters['status'] ?? '') === 'disabled')>Disabled</option></select></div>
                            <button class="gf-button gf-button--primary" type="submit">Apply filters</button>
                            <button class="gf-button gf-button--ghost" type="submit" formaction="{{ route('organizations.traffic.export', ['organizationId' => $organization->id]) }}">Export daily CSV</button>
                        </form>
                        @php($chartMax = max(1, (int) $series->max('clicks')))
                        <div class="gf-chart" role="img" aria-label="Daily clicks from {{ $from }} to {{ $to }}">
                            @forelse($series as $point)
                                <div class="gf-chart__column" title="{{ $point->metric_date->format('Y-m-d') }}: {{ $point->clicks }} clicks"><span class="gf-chart__value">{{ $point->clicks }}</span><div class="gf-chart__bar" style="height: {{ max(4, round(((int)$point->clicks / $chartMax) * 140)) }}px"></div><small>{{ $point->metric_date->format('m/d') }}</small></div>
                            @empty
                                <div class="gf-empty gf-empty--compact"><div><h3>No clicks in this period.</h3><p>Adjust the filters or use a tracked link to collect test metrics.</p></div></div>
                            @endforelse
                        </div>
                    </div>
                </section>

                @if ($canManageTraffic)
                    <section class="gf-panel gf-panel--spaced">
                        <header class="gf-panel__head">
                            <h2>New tracked link</h2>
                            <span class="gf-appbar__meta">GF-FR-006</span>
                        </header>

                        <div class="gf-panel__body">
                            <form
                                class="gf-form"
                                method="POST"
                                action="{{ route('organizations.traffic.store', ['organizationId' => $organization->id]) }}"
                            >
                                @csrf

                                <div class="gf-field">
                                    <label for="label">Label</label>
                                    <input
                                        class="gf-input"
                                        id="label"
                                        name="label"
                                        type="text"
                                        maxlength="191"
                                        value="{{ old('label') }}"
                                        required
                                    >
                                </div>

                                <div class="gf-field">
                                    <label for="destination_url">Destination URL</label>
                                    <input
                                        class="gf-input"
                                        id="destination_url"
                                        name="destination_url"
                                        type="url"
                                        maxlength="2048"
                                        value="{{ old('destination_url') }}"
                                        placeholder="https://example.com/..."
                                        required
                                    >
                                </div>

                                <div class="gf-field">
                                    <label for="channel">Channel</label>
                                    <input
                                        class="gf-input"
                                        id="channel"
                                        name="channel"
                                        type="text"
                                        maxlength="64"
                                        value="{{ old('channel') }}"
                                        placeholder="x, telegram, bio..."
                                    >
                                </div>

                                <div class="gf-field">
                                    <label for="campaign">Campaign</label>
                                    <input
                                        class="gf-input"
                                        id="campaign"
                                        name="campaign"
                                        type="text"
                                        maxlength="128"
                                        value="{{ old('campaign') }}"
                                    >
                                </div>

                                <div class="gf-upload__footer">
                                    <p>
                                        El visitante se atribuye al link/campana. El redirector
                                        no persiste IP, User-Agent ni referrer.
                                    </p>
                                    <button class="gf-button gf-button--primary" type="submit">
                                        Create link
                                    </button>
                                </div>
                            </form>
                        </div>
                    </section>
                @endif

                <section class="gf-panel gf-panel--spaced">
                    <header class="gf-panel__head">
                        <h2>Tracked links</h2>
                        <span class="gf-appbar__meta">{{ $linkCount }} matching · {{ $links->count() }} on this page</span>
                    </header>

                    <div class="gf-panel__body gf-panel__body--flush-mobile">
                        @if ($links->isEmpty())
                            <div class="gf-empty">
                                <div>
                                    <div class="gf-empty__icon" aria-hidden="true">⌗</div>
                                    <h3>{{ $linkCount > 0 ? 'No links on this page.' : 'No matching tracked links.' }}</h3>
                                    <p>
                                        @if ($linkCount > 0)
                                            <a href="{{ $links->url($links->lastPage()) }}">Go to last available page</a>
                                        @else
                                            Ajusta los filtros o crea un enlace para atribuir clics.
                                        @endif
                                    </p>
                                </div>
                            </div>
                        @else
                            <div class="gf-table-wrap">
                                <table class="gf-table">
                                    <thead>
                                        <tr>
                                            <th>Label</th>
                                            <th>Short link</th>
                                            <th>Channel / campaign</th>
                                            <th>Clicks</th>
                                            <th>Status</th>
                                            <th>Publications</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($links as $link)
                                            <tr>
                                                <td>
                                                    <div class="gf-media-name">{{ $link->label }}</div>
                                                    <div class="gf-media-meta">
                                                        {{ $link->destination_url }}
                                                    </div>
                                                </td>
                                                <td>
                                                    <a
                                                        href="{{ route('traffic.redirect', ['token' => $link->token]) }}"
                                                        target="_blank"
                                                        rel="noreferrer"
                                                    >
                                                        /l/{{ $link->token }}
                                                    </a>
                                                </td>
                                                <td>
                                                    {{ $link->channel ?? '—' }}
                                                    <div class="gf-media-meta">
                                                        {{ $link->campaign ?? '—' }}
                                                    </div>
                                                </td>
                                                <td>{{ number_format((int) ($link->total_clicks ?? 0)) }}</td>
                                                <td>
                                                    <span class="gf-state {{ $link->status === 'active' ? 'gf-state--ok' : '' }}">
                                                        {{ $link->status }}
                                                    </span>
                                                </td>
                                                <td>
                                                    @forelse($link->scheduledPublicationLinks as $assignment)
                                                        <div>{{ $assignment->scheduledPublication?->destination?->name ?? 'Unknown destination' }}</div><small class="gf-media-meta">{{ $assignment->scheduledPublication?->scheduled_for_utc?->format('Y-m-d H:i') }} UTC · shared link metric</small>
                                                    @empty — @endforelse
                                                </td>
                                                <td>
                                                    @if ($canManageTraffic)
                                                        <details>
                                                            <summary>Edit link</summary>
                                                            <form class="gf-form" method="POST" action="{{ route('organizations.traffic.links.update', ['organizationId' => $organization->id, 'linkId' => $link->id]) }}">
                                                                @csrf
                                                                @method('PATCH')
                                                                <label for="traffic_label_{{ $link->id }}">Link label</label>
                                                                <input class="gf-input" id="traffic_label_{{ $link->id }}" name="label" type="text" maxlength="191" value="{{ $link->label }}" required>
                                                                <label for="traffic_destination_{{ $link->id }}">Redirect destination URL</label>
                                                                <input class="gf-input" id="traffic_destination_{{ $link->id }}" name="destination_url" type="url" maxlength="2048" value="{{ $link->destination_url }}" required>
                                                                <label for="traffic_channel_{{ $link->id }}">Channel</label>
                                                                <input class="gf-input" id="traffic_channel_{{ $link->id }}" name="channel" type="text" maxlength="64" value="{{ $link->channel }}">
                                                                <label for="traffic_campaign_{{ $link->id }}">Campaign</label>
                                                                <input class="gf-input" id="traffic_campaign_{{ $link->id }}" name="campaign" type="text" maxlength="128" value="{{ $link->campaign }}">
                                                                <small class="gf-media-meta">Changing the destination changes future redirects, not the short link. Historical clicks remain attached to this link; reports show current label/channel/campaign.</small>
                                                                <button class="gf-button gf-button--ghost" type="submit">Save link details</button>
                                                            </form>
                                                        </details>
                                                        <form method="POST" action="{{ route('organizations.traffic.links.status', ['organizationId' => $organization->id, 'linkId' => $link->id]) }}">
                                                            @csrf
                                                            @method('PATCH')
                                                            <input type="hidden" name="status" value="{{ $link->status === 'active' ? 'disabled' : 'active' }}">
                                                            <button class="gf-button gf-button--ghost" type="submit" aria-label="{{ $link->status === 'active' ? 'Disable' : 'Enable' }} link {{ $link->label }}">
                                                                {{ $link->status === 'active' ? 'Disable' : 'Enable' }}
                                                            </button>
                                                        </form>
                                                    @else
                                                        —
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                    @if ($linkCount > 0)
                        <nav class="gf-panel__body" aria-label="Tracked links pagination">
                            <div class="gf-upload__footer">
                                <p class="gf-media-meta">
                                    Showing {{ $links->firstItem() ?? 0 }}–{{ $links->lastItem() ?? 0 }} of {{ $linkCount }}
                                    · Page {{ $links->currentPage() }} of {{ $links->lastPage() }}
                                </p>
                                <div>
                                    @if ($links->previousPageUrl())
                                        <a class="gf-button gf-button--ghost" rel="prev" href="{{ $links->previousPageUrl() }}">Previous</a>
                                    @endif
                                    @if ($links->nextPageUrl())
                                        <a class="gf-button gf-button--ghost" rel="next" href="{{ $links->nextPageUrl() }}">Next</a>
                                    @endif
                                </div>
                            </div>
                        </nav>
                    @endif
                </section>
            @endif
        </main>
    </div>
</body>
</html>
