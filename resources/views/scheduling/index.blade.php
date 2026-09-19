<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#070a12">
    <title>Scheduler · {{ $organization->name }} · GrindFlow</title>
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

                <a
                    class="gf-navitem gf-navitem--active"
                    href="{{ route('organizations.scheduler.index', ['organizationId' => $organization->id]) }}"
                    aria-current="page"
                >
                    <span class="gf-navitem__icon" aria-hidden="true">⌁</span>
                    <span class="gf-navitem__text">Scheduler</span>
                </a>

                <a class="gf-navitem" href="{{ route('organizations.distribution.index', ['organizationId' => $organization->id]) }}">
                    <span class="gf-navitem__icon" aria-hidden="true">⇢</span>
                    <span class="gf-navitem__text">Distribution</span>
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
                    GF / {{ strtoupper($organization->slug) }} / SCHEDULER
                </div>

                <div class="gf-avatar" aria-label="Usuario autenticado">
                    {{ strtoupper(substr((string) auth()->user()?->name, 0, 2)) }}
                </div>
            </header>

            <section class="gf-pagehead">
                <div>
                    <span class="gf-kicker">
                        <span class="gf-kicker__dot"></span>
                        Server-side scheduling
                    </span>
                    <h1>Scheduler</h1>
                    <p>
                        Programa contenido validado para destinos activos con timezone
                        explicita y aislamiento por organizacion.
                    </p>
                </div>

                <span class="gf-badge">
                    {{ $schedulingReady ? 'Scheduling schema ready' : 'Migration required' }}
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

            @if (! $schedulingReady)
                <section class="gf-panel">
                    <div class="gf-panel__body">
                        <div class="gf-empty">
                            <div>
                                <div class="gf-empty__icon" aria-hidden="true">⌁</div>
                                <h3>Scheduling migration required.</h3>
                                <p>
                                    El codigo ya esta preparado, pero las tablas de Scheduling
                                    aun no existen en esta base de datos. La pantalla queda en modo
                                    seguro hasta aplicar la migracion.
                                </p>
                            </div>
                        </div>
                    </div>
                </section>
            @else
                <section class="gf-metrics" aria-label="Metricas del Scheduler">
                    <article class="gf-metric">
                        <div class="gf-metric__label">Scheduled</div>
                        <div class="gf-metric__value">{{ $publications->count() }}</div>
                        <div class="gf-metric__meta">Publicaciones en cola logica</div>
                    </article>

                    <article class="gf-metric">
                        <div class="gf-metric__label">Destinations</div>
                        <div class="gf-metric__value">{{ $destinations->count() }}</div>
                        <div class="gf-metric__meta">Destinos activos configurados</div>
                    </article>

                    <article class="gf-metric">
                        <div class="gf-metric__label">Eligible media</div>
                        <div class="gf-metric__value">{{ $eligibleAssets->count() }}</div>
                        <div class="gf-metric__meta">Procesamiento actual completado</div>
                    </article>
                </section>

                @if ($canSchedule && $destinations->isNotEmpty() && $eligibleAssets->isNotEmpty())
                    <section class="gf-panel gf-panel--spaced">
                        <header class="gf-panel__head">
                            <h2>New schedule</h2>
                            <span class="gf-appbar__meta">GF-FR-004</span>
                        </header>

                        <div class="gf-panel__body">
                            <form
                                class="gf-form"
                                method="POST"
                                action="{{ route('organizations.scheduler.store', ['organizationId' => $organization->id]) }}"
                            >
                                @csrf
                                <input type="hidden" name="request_key" value="{{ old('request_key', (string) \Illuminate\Support\Str::uuid()) }}">

                                <div class="gf-field">
                                    <label for="asset_id">Media</label>
                                    <select class="gf-input" id="asset_id" name="asset_id" required>
                                        <option value="">Select media</option>
                                        @foreach ($eligibleAssets as $asset)
                                            <option
                                                value="{{ $asset->id }}"
                                                @selected(old('asset_id') === $asset->id)
                                            >
                                                {{ $asset->original_filename }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>

                                <div class="gf-field">
                                    <label for="destination_ids">Destinations</label>
                                    <select class="gf-input" id="destination_ids" name="destination_ids[]" multiple required size="{{ min(6, max(2, $destinations->count())) }}">
                                        @foreach ($destinations as $destination)
                                            <option
                                                value="{{ $destination->id }}"
                                                @selected(in_array($destination->id, old('destination_ids', []), true))
                                            >
                                                {{ $destination->name }} · {{ $destination->provider }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>

                                @if ($linkingReady)
                                    <div class="gf-field">
                                        <label for="tracked_link_id">Tracked link (optional)</label>
                                        <select class="gf-input" id="tracked_link_id" name="tracked_link_id">
                                            <option value="">No tracked link</option>
                                            @foreach ($trackedLinks as $link)
                                                <option
                                                    value="{{ $link->id }}"
                                                    @selected(old('tracked_link_id') === $link->id)
                                                >
                                                    {{ $link->label }}{{ $link->campaign ? ' · '.$link->campaign : '' }}
                                                </option>
                                            @endforeach
                                        </select>
                                    </div>
                                @endif

                                <div class="gf-field">
                                    <label for="scheduled_for_local">Local date and time</label>
                                    <input
                                        class="gf-input"
                                        id="scheduled_for_local"
                                        name="scheduled_for_local"
                                        type="datetime-local"
                                        value="{{ old('scheduled_for_local') }}"
                                        required
                                    >
                                </div>

                                <div class="gf-field">
                                    <label for="timezone">Timezone</label>
                                    <select class="gf-input" id="timezone" name="timezone" required>
                                        @foreach ($timezones as $timezone)
                                            <option
                                                value="{{ $timezone }}"
                                                @selected(old('timezone', $defaultTimezone) === $timezone)
                                            >
                                                {{ $timezone }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>

                                <div class="gf-upload__footer">
                                    <p>
                                        GrindFlow revalida tenant, media, destino y tracked link
                                        activo antes de crear la programacion.
                                    </p>
                                    <button class="gf-button gf-button--primary" type="submit">
                                        Schedule
                                    </button>
                                </div>
                            </form>
                        </div>
                    </section>
                @elseif ($canSchedule)
                    <section class="gf-panel gf-panel--spaced">
                        <div class="gf-panel__body">
                            <div class="gf-empty gf-empty--compact">
                                <div>
                                    <div class="gf-empty__icon" aria-hidden="true">⌁</div>
                                    <h3>Scheduling is blocked by prerequisites.</h3>
                                    <p>
                                        Se necesita al menos un destino activo y un asset canonico
                                        cuyo procesamiento actual haya terminado correctamente.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </section>
                @endif

                <section class="gf-panel gf-panel--spaced">
                    <header class="gf-panel__head">
                        <h2>Schedule calendar · UTC</h2>
                        <span class="gf-appbar__meta">{{ $publications->total() }} matching · {{ $publications->count() }} on this page</span>
                    </header>

                    <div class="gf-panel__body">
                        <form class="gf-form" method="GET">
                            <div class="gf-field"><label for="filter_status">Status</label><select class="gf-input" id="filter_status" name="status"><option value="">All</option><option value="scheduled" @selected(($filters['status'] ?? '') === 'scheduled')>Scheduled</option><option value="cancelled" @selected(($filters['status'] ?? '') === 'cancelled')>Cancelled</option></select></div>
                            <div class="gf-field"><label for="filter_destination">Destination</label><select class="gf-input" id="filter_destination" name="destination_id"><option value="">All</option>@foreach($filterDestinations as $destination)<option value="{{ $destination->id }}" @selected(($filters['destination_id'] ?? '') === $destination->id)>{{ $destination->name }}{{ $destination->status !== 'active' ? ' · inactive' : '' }}</option>@endforeach</select></div>
                            <div class="gf-field"><label for="filter_from">From (UTC)</label><input class="gf-input" id="filter_from" type="date" name="from" value="{{ $filters['from'] ?? '' }}"></div>
                            <div class="gf-field"><label for="filter_to">To (UTC)</label><input class="gf-input" id="filter_to" type="date" name="to" value="{{ $filters['to'] ?? '' }}"></div>
                            <button class="gf-button gf-button--primary">Apply filters</button>
                        </form>
                    </div>

                    @if($calendarDays->isNotEmpty())
                        <div class="gf-panel__body">
                            <p class="gf-media-meta">Calendar preview reflects this page; {{ $publications->total() }} schedules match all filters.</p>
                            <div class="gf-calendar">
                            @foreach($calendarDays as $day => $items)
                                <article class="gf-calendar__day"><strong>{{ \Carbon\CarbonImmutable::parse($day)->format('M d') }}</strong><span>{{ $items->count() }} publication(s)</span>@foreach($items->take(3) as $item)<small>{{ $item->scheduled_for_utc?->format('H:i') }} UTC · {{ $item->destination?->name }}</small>@endforeach</article>
                            @endforeach
                            </div>
                        </div>
                    @endif

                    <div class="gf-panel__body gf-panel__body--flush-mobile">
                        @if ($publications->isEmpty())
                            <div class="gf-empty">
                                <div>
                                    <div class="gf-empty__icon" aria-hidden="true">⌁</div>
                                    <h3>{{ $publications->total() > 0 ? 'No schedules on this page.' : 'No matching schedules.' }}</h3>
                                    <p>
                                        @if ($publications->total() > 0)
                                            <a href="{{ $publications->url($publications->lastPage()) }}">Go to last available page</a>
                                        @else
                                            Ajusta los filtros o programa nuevo contenido para Distribution.
                                        @endif
                                    </p>
                                </div>
                            </div>
                        @else
                            <div class="gf-table-wrap">
                                <table class="gf-table">
                                    <thead>
                                        <tr>
                                            <th>Media</th>
                                            <th>Destination</th>
                                            @if ($linkingReady)
                                                <th>Tracked link</th>
                                            @endif
                                            <th>Local time</th>
                                            <th>Timezone</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($publications as $publication)
                                            <tr>
                                                <td>
                                                    <div class="gf-media-name">
                                                        {{ $publication->mediaAsset?->original_filename ?? 'Missing media' }}
                                                    </div>
                                                </td>
                                                <td>
                                                    {{ $publication->destination?->name ?? 'Missing destination' }}
                                                    <div class="gf-media-meta">
                                                        {{ $publication->destination?->provider ?? 'unknown' }}
                                                    </div>
                                                </td>
                                                @if ($linkingReady)
                                                    <td>
                                                        {{ $publication->linkAssignment?->trackedLink?->label ?? 'Not linked' }}
                                                    </td>
                                                @endif
                                                <td>
                                                    {{ $publication->scheduled_for_utc
                                                        ?->setTimezone($publication->timezone)
                                                        ->format('Y-m-d H:i') }}
                                                </td>
                                                <td>{{ $publication->timezone }}</td>
                                                <td>
                                                    <span class="gf-state gf-state--ok">
                                                        {{ $publication->status }}
                                                    </span>
                                                </td>
                                                <td>
                                                    @if ($canSchedule && $publication->status === 'scheduled' && $publication->scheduled_for_utc?->isFuture() && ! $publication->delivery)
                                                        <details>
                                                            <summary>Edit</summary>
                                                            <form class="gf-form" method="POST" action="{{ route('organizations.scheduler.update', ['organizationId' => $organization->id]) }}">
                                                                @csrf
                                                                @method('PATCH')
                                                                <input type="hidden" name="publication_id" value="{{ $publication->id }}">
                                                                <label for="scheduled_for_{{ $publication->id }}">New local time</label>
                                                                <input
                                                                    class="gf-input"
                                                                    id="scheduled_for_{{ $publication->id }}"
                                                                    name="scheduled_for_local"
                                                                    type="datetime-local"
                                                                    value="{{ $publication->scheduled_for_utc->setTimezone($publication->timezone)->format('Y-m-d\\TH:i') }}"
                                                                    required
                                                                >
                                                                <input type="hidden" name="timezone" value="{{ $publication->timezone }}">
                                                                <button class="gf-button gf-button--ghost">Save</button>
                                                            </form>
                                                        </details>
                                                        @if ($linkingReady)
                                                            <details>
                                                                <summary>Tracked link</summary>
                                                                <form class="gf-form" method="POST" action="{{ route('organizations.scheduler.tracked-link.update', ['organizationId' => $organization->id]) }}">
                                                                    @csrf
                                                                    @method('PATCH')
                                                                    <input type="hidden" name="publication_id" value="{{ $publication->id }}">
                                                                    <label for="link_for_{{ $publication->id }}">Assigned tracked link</label>
                                                                    <select class="gf-input" id="link_for_{{ $publication->id }}" name="tracked_link_id">
                                                                        <option value="" @selected(! $publication->linkAssignment)>No tracked link</option>
                                                                        @if ($publication->linkAssignment && ! $trackedLinks->contains('id', $publication->linkAssignment->tracked_link_id))
                                                                            <option selected disabled value="unavailable">Current link unavailable. Choose another or remove.</option>
                                                                        @endif
                                                                        @foreach ($trackedLinks as $link)
                                                                            <option
                                                                                value="{{ $link->id }}"
                                                                                @selected($publication->linkAssignment?->tracked_link_id === $link->id)
                                                                            >
                                                                                {{ $link->label }}{{ $link->campaign ? ' · '.$link->campaign : '' }}
                                                                            </option>
                                                                        @endforeach
                                                                    </select>
                                                                    <small class="gf-media-meta">The assignment can change only before delivery. Existing click totals stay intact.</small>
                                                                    <button class="gf-button gf-button--ghost" type="submit">Save tracked link</button>
                                                                </form>
                                                            </details>
                                                        @endif
                                                        <form method="POST" action="{{ route('organizations.scheduler.cancel', ['organizationId' => $organization->id]) }}">
                                                            @csrf
                                                            <input type="hidden" name="publication_id" value="{{ $publication->id }}">
                                                            <button class="gf-button gf-button--ghost">Cancel</button>
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
                        @if ($publications->total() > 0)
                            <nav class="gf-panel__body" aria-label="Scheduler pagination">
                                <div class="gf-upload__footer">
                                    <p class="gf-media-meta">
                                        Showing {{ $publications->firstItem() ?? 0 }}–{{ $publications->lastItem() ?? 0 }} of {{ $publications->total() }}
                                        · Page {{ $publications->currentPage() }} of {{ $publications->lastPage() }}
                                    </p>
                                    <div>
                                        @if ($publications->previousPageUrl())
                                            <a class="gf-button gf-button--ghost" rel="prev" href="{{ $publications->previousPageUrl() }}">Previous</a>
                                        @endif
                                        @if ($publications->nextPageUrl())
                                            <a class="gf-button gf-button--ghost" rel="next" href="{{ $publications->nextPageUrl() }}">Next</a>
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
