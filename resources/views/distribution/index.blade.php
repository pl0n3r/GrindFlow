<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#070a12">
    <title>Distribution · {{ $organization->name }} · GrindFlow</title>
    <link rel="stylesheet" href="{{ asset('css/grindflow.css') }}">
</head>
<body>
<div class="gf-grid" aria-hidden="true"></div>
<div class="gf-app">
    <aside class="gf-sidebar">
        <x-brand :href="route('dashboard')" />
        <nav class="gf-sidebar__nav" aria-label="Workspace navigation">
            <span class="gf-sidebar__label">Workspace</span>
            <a class="gf-navitem" href="{{ route('dashboard') }}"><span class="gf-navitem__icon">◫</span><span class="gf-navitem__text">Overview</span></a>
            <a class="gf-navitem" href="{{ route('organizations.vault.index', ['organizationId' => $organization->id]) }}"><span class="gf-navitem__icon">◇</span><span class="gf-navitem__text">Vault</span></a>
            <a class="gf-navitem" href="{{ route('organizations.scheduler.index', ['organizationId' => $organization->id]) }}"><span class="gf-navitem__icon">⌁</span><span class="gf-navitem__text">Scheduler</span></a>
            <a class="gf-navitem gf-navitem--active" aria-current="page" href="{{ route('organizations.distribution.index', ['organizationId' => $organization->id]) }}"><span class="gf-navitem__icon">⇢</span><span class="gf-navitem__text">Distribution</span></a>
            <span class="gf-sidebar__label">Insights</span>
            <a class="gf-navitem" href="{{ route('organizations.traffic.index', ['organizationId' => $organization->id]) }}"><span class="gf-navitem__icon">⌗</span><span class="gf-navitem__text">Traffic</span></a>
        </nav>
        <div class="gf-sidebar__bottom"><form method="POST" action="{{ route('logout') }}">@csrf<button class="gf-button gf-button--ghost gf-button--full">Cerrar sesion</button></form></div>
    </aside>
    <main class="gf-main">
        <header class="gf-appbar"><div class="gf-appbar__meta">GF / {{ strtoupper($organization->slug) }} / DISTRIBUTION</div><div class="gf-avatar">{{ strtoupper(substr((string) auth()->user()?->name, 0, 2)) }}</div></header>
        <section class="gf-pagehead"><div><span class="gf-kicker"><span class="gf-kicker__dot"></span>Reliable delivery control</span><h1>Distribution</h1><p>Estado, reintentos e idempotencia de cada entrega programada.</p></div><span class="gf-badge">{{ $ready ? 'Distribution ready' : 'Migration required' }}</span></section>
        @if (session('status'))<div class="gf-notice gf-notice--success" role="status">{{ session('status') }}</div>@endif
        @if ($errors->any())<div class="gf-alert" role="alert">{{ $errors->first() }}</div>@endif
        @if (! $ready)
            <section class="gf-panel"><div class="gf-panel__body"><div class="gf-empty"><div><div class="gf-empty__icon">⇢</div><h3>Distribution migration required.</h3><p>La consulta queda bloqueada de forma segura hasta que exista el schema.</p></div></div></div></section>
        @else
            <section class="gf-metrics" aria-label="Distribution metrics">
                @foreach (['queued' => 'Pending', 'processing' => 'Processing', 'published' => 'Published', 'retry_scheduled' => 'Retry', 'failed' => 'Attention'] as $key => $label)
                    <article class="gf-metric"><div class="gf-metric__label">{{ $label }}</div><div class="gf-metric__value">{{ (int) ($counts[$key] ?? 0) }}</div><div class="gf-metric__meta">{{ str_replace('_', ' ', $key) }}</div></article>
                @endforeach
            </section>
            <section class="gf-panel gf-panel--spaced"><header class="gf-panel__head"><h2>Delivery filters</h2><span class="gf-appbar__meta">tenant scoped</span></header><div class="gf-panel__body">
                <form class="gf-form" method="GET">
                    <div class="gf-field"><label for="status">Status</label><select class="gf-input" id="status" name="status"><option value="">All statuses</option>@foreach (['queued','processing','retry_scheduled','published','authentication_failed','failed'] as $status)<option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ str_replace('_',' ',ucfirst($status)) }}</option>@endforeach</select></div>
                    <div class="gf-field"><label for="destination_id">Destination</label><select class="gf-input" id="destination_id" name="destination_id"><option value="">All destinations</option>@foreach($destinations as $destination)<option value="{{ $destination->id }}" @selected(($filters['destination_id'] ?? '') === $destination->id)>{{ $destination->name }}</option>@endforeach</select></div>
                    <div class="gf-field"><label for="from">From</label><input class="gf-input" id="from" name="from" type="date" value="{{ $filters['from'] ?? '' }}"></div>
                    <div class="gf-field"><label for="to">To</label><input class="gf-input" id="to" name="to" type="date" value="{{ $filters['to'] ?? '' }}"></div>
                    <button class="gf-button gf-button--primary">Apply filters</button>
                </form>
            </div></section>
            @if ($canManageDestinations)
                <section class="gf-panel gf-panel--spaced"><header class="gf-panel__head"><h2>Sandbox destinations</h2><span class="gf-appbar__meta">No external publishing</span></header><div class="gf-panel__body">
                    <form class="gf-form" method="POST" action="{{ route('organizations.distribution.destinations.store', ['organizationId' => $organization->id]) }}">@csrf
                        <div class="gf-field"><label for="name">Name</label><input class="gf-input" id="name" name="name" maxlength="120" required></div><input type="hidden" name="provider" value="sandbox"><button class="gf-button gf-button--primary">Create sandbox destination</button>
                    </form>
                    <div class="gf-table-wrap gf-panel--spaced"><table class="gf-table"><thead><tr><th>Name</th><th>Provider</th><th>Status</th><th>Action</th></tr></thead><tbody>@foreach($destinations as $destination)<tr><td>{{ $destination->name }}</td><td>{{ $destination->provider }}</td><td>{{ $destination->status }}</td><td><form method="POST" action="{{ route('organizations.distribution.destinations.update', ['organizationId'=>$organization->id,'destinationId'=>$destination->id]) }}">@csrf @method('PATCH')<input type="hidden" name="name" value="{{ $destination->name }}"><input type="hidden" name="status" value="{{ $destination->status === 'active' ? 'disabled' : 'active' }}"><button class="gf-button gf-button--ghost">{{ $destination->status === 'active' ? 'Disable' : 'Enable' }}</button></form></td></tr>@endforeach</tbody></table></div>
                </div></section>
            @endif
            <section class="gf-panel gf-panel--spaced"><header class="gf-panel__head"><h2>Delivery history</h2><span class="gf-appbar__meta">{{ $deliveries->count() }} loaded</span></header><div class="gf-panel__body gf-panel__body--flush-mobile">
                @if($deliveries->isEmpty())<div class="gf-empty"><div><div class="gf-empty__icon">⇢</div><h3>No deliveries match.</h3><p>Las entregas aparecen cuando una programación vence.</p></div></div>@else
                <div class="gf-table-wrap"><table class="gf-table"><thead><tr><th>Media</th><th>Destination</th><th>Status</th><th>Attempts</th><th>Result</th><th>Action</th></tr></thead><tbody>
                    @foreach($deliveries as $delivery)<tr><td>{{ $delivery->scheduledPublication?->mediaAsset?->original_filename ?? 'Missing media' }}</td><td>{{ $delivery->scheduledPublication?->destination?->name ?? 'Missing destination' }}</td><td><span class="gf-state {{ $delivery->status === 'published' ? 'gf-state--ok' : '' }}">{{ str_replace('_',' ',$delivery->status) }}</span></td><td>{{ $delivery->attempts }}</td><td><div>{{ $delivery->external_publication_id ?? '—' }}</div><div class="gf-media-meta">{{ $delivery->last_error_code ?? ($delivery->next_attempt_at ? 'Retry '.$delivery->next_attempt_at->format('Y-m-d H:i').' UTC' : 'No error') }}</div></td><td>@if($canManageDestinations && in_array($delivery->status,['failed','retry_scheduled'],true))<form method="POST" action="{{ route('organizations.distribution.deliveries.retry',['organizationId'=>$organization->id,'deliveryId'=>$delivery->id]) }}">@csrf<button class="gf-button gf-button--ghost">Retry</button></form>@else — @endif</td></tr>@endforeach
                </tbody></table></div>@endif
            </div></section>
        @endif
    </main>
</div>
</body></html>
