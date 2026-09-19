<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#070a12">
    <title>Dashboard · GrindFlow</title>
    <link rel="stylesheet" href="{{ asset('css/grindflow.css') }}">
</head>
<body>
    @php
        $vaultRouteAvailable = \Illuminate\Support\Facades\Route::has('organizations.vault.index');
        $schedulerRouteAvailable = \Illuminate\Support\Facades\Route::has('organizations.scheduler.index');
        $distributionRouteAvailable = \Illuminate\Support\Facades\Route::has('organizations.distribution.index');
        $trafficRouteAvailable = \Illuminate\Support\Facades\Route::has('organizations.traffic.index');
        $financeRouteAvailable = \Illuminate\Support\Facades\Route::has('organizations.finance.index');
        $systemRouteAvailable = \Illuminate\Support\Facades\Route::has('admin.system');
        $diagnosticsRouteAvailable = \Illuminate\Support\Facades\Route::has('admin.diagnostics');
    @endphp

    <div class="gf-grid" aria-hidden="true"></div>

    <div class="gf-app">
        <aside class="gf-sidebar">
            <x-brand :href="route('dashboard')" />

            <nav class="gf-sidebar__nav" aria-label="Navegacion del workspace">
                <span class="gf-sidebar__label">Workspace</span>

                <a class="gf-navitem gf-navitem--active" href="{{ route('dashboard') }}" aria-current="page">
                    <span class="gf-navitem__icon" aria-hidden="true">◫</span>
                    <span class="gf-navitem__text">Overview</span>
                </a>

                @if ($organizations->isNotEmpty() && $vaultRouteAvailable)
                    <a
                        class="gf-navitem"
                        href="{{ route('organizations.vault.index', ['organizationId' => $organizations->first()->id]) }}"
                    >
                        <span class="gf-navitem__icon" aria-hidden="true">◇</span>
                        <span class="gf-navitem__text">Vault</span>
                    </a>
                @else
                    <span class="gf-navitem gf-navitem--disabled" aria-disabled="true">
                        <span class="gf-navitem__icon" aria-hidden="true">◇</span>
                        <span class="gf-navitem__text">Vault</span>
                    </span>
                @endif

                @if ($organizations->isNotEmpty() && $schedulerRouteAvailable)
                    <a
                        class="gf-navitem"
                        href="{{ route('organizations.scheduler.index', ['organizationId' => $organizations->first()->id]) }}"
                    >
                        <span class="gf-navitem__icon" aria-hidden="true">⌁</span>
                        <span class="gf-navitem__text">Scheduler</span>
                    </a>
                @else
                    <span class="gf-navitem gf-navitem--disabled" aria-disabled="true">
                        <span class="gf-navitem__icon" aria-hidden="true">⌁</span>
                        <span class="gf-navitem__text">Scheduler</span>
                    </span>
                @endif

                @if ($organizations->isNotEmpty() && $distributionRouteAvailable)
                    <a
                        class="gf-navitem"
                        href="{{ route('organizations.distribution.index', ['organizationId' => $organizations->first()->id]) }}"
                    >
                        <span class="gf-navitem__icon" aria-hidden="true">⇢</span>
                        <span class="gf-navitem__text">Distribution</span>
                    </a>
                @else
                    <span class="gf-navitem gf-navitem--disabled" aria-disabled="true">
                        <span class="gf-navitem__icon" aria-hidden="true">⇢</span>
                        <span class="gf-navitem__text">Distribution</span>
                    </span>
                @endif

                <span class="gf-sidebar__label">Insights</span>

                @if (
                    $organizations->isNotEmpty()
                    && $trafficRouteAvailable
                    && auth()->user()?->canManageTrafficOrganization($organizations->first())
                )
                    <a
                        class="gf-navitem"
                        href="{{ route('organizations.traffic.index', ['organizationId' => $organizations->first()->id]) }}"
                    >
                        <span class="gf-navitem__icon" aria-hidden="true">⌗</span>
                        <span class="gf-navitem__text">Traffic</span>
                    </a>
                @else
                    <span class="gf-navitem gf-navitem--disabled" aria-disabled="true">
                        <span class="gf-navitem__icon" aria-hidden="true">⌗</span>
                        <span class="gf-navitem__text">Traffic</span>
                    </span>
                @endif

                @if (
                    $organizations->isNotEmpty()
                    && $financeRouteAvailable
                    && auth()->user()?->canManageFinanceOrganization($organizations->first())
                )
                    <a
                        class="gf-navitem"
                        href="{{ route('organizations.finance.index', ['organizationId' => $organizations->first()->id]) }}"
                    >
                        <span class="gf-navitem__icon" aria-hidden="true">$</span>
                        <span class="gf-navitem__text">Finance</span>
                    </a>
                @else
                    <span class="gf-navitem gf-navitem--disabled" aria-disabled="true">
                        <span class="gf-navitem__icon" aria-hidden="true">$</span>
                        <span class="gf-navitem__text">Finance</span>
                    </span>
                @endif

                @if (auth()->user()?->isPlatformAdmin())
                    <span class="gf-sidebar__label">Admin</span>

                    @if ($systemRouteAvailable)
                        <a class="gf-navitem" href="{{ route('admin.system') }}">
                            <span class="gf-navitem__icon" aria-hidden="true">⌘</span>
                            <span class="gf-navitem__text">System</span>
                        </a>
                    @else
                        <span class="gf-navitem gf-navitem--disabled" aria-disabled="true">
                            <span class="gf-navitem__icon" aria-hidden="true">⌘</span>
                            <span class="gf-navitem__text">System</span>
                        </span>
                    @endif

                    @if ($diagnosticsRouteAvailable)
                        <a class="gf-navitem" href="{{ route('admin.diagnostics') }}">
                            <span class="gf-navitem__icon" aria-hidden="true">!</span>
                            <span class="gf-navitem__text">Diagnostics</span>
                        </a>
                    @else
                        <span class="gf-navitem gf-navitem--disabled" aria-disabled="true">
                            <span class="gf-navitem__icon" aria-hidden="true">!</span>
                            <span class="gf-navitem__text">Diagnostics</span>
                        </span>
                    @endif
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
                    GF / WORKSPACE / OVERVIEW
                </div>

                <div class="gf-avatar" aria-label="Usuario autenticado">
                    {{ strtoupper(substr((string) auth()->user()?->name, 0, 2)) }}
                </div>
            </header>

            <section class="gf-pagehead">
                <div>
                    <span class="gf-kicker">
                        <span class="gf-kicker__dot"></span>
                        Core online
                    </span>
                    <h1>Overview</h1>
                    <p>
                        Hola, {{ auth()->user()?->name }}. Este es el nuevo shell
                        operativo de GrindFlow.
                    </p>
                </div>

                <span class="gf-badge">Tenant isolation active</span>
            </section>

            <section class="gf-metrics" aria-label="Metricas base">
                <article class="gf-metric">
                    <div class="gf-metric__label">Organizations</div>
                    <div class="gf-metric__value">{{ $organizations->count() }}</div>
                    <div class="gf-metric__meta">Visibles para esta cuenta</div>
                </article>

                <article class="gf-metric">
                    <div class="gf-metric__label">Identity</div>
                    <div class="gf-metric__value">Active</div>
                    <div class="gf-metric__meta">Laravel session auth</div>
                </article>

                <article class="gf-metric">
                    <div class="gf-metric__label">Isolation</div>
                    <div class="gf-metric__value">Scoped</div>
                    <div class="gf-metric__meta">Laravel + MariaDB integrity</div>
                </article>
            </section>

            <section class="gf-panel">
                <header class="gf-panel__head">
                    <h2>Organizations</h2>
                    <span class="gf-appbar__meta">{{ $organizations->count() }} available</span>
                </header>

                <div class="gf-panel__body">
                    @if ($organizations->isEmpty())
                        <div class="gf-empty">
                            <div>
                                <div class="gf-empty__icon" aria-hidden="true">◇</div>
                                <h3>Aun no hay organizaciones disponibles.</h3>
                                <p>
                                    La interfaz ya esta lista. El siguiente paso de produccion
                                    es conectar MariaDB y cargar las memberships reales
                                    de esta cuenta.
                                </p>
                            </div>
                        </div>
                    @else
                        <div class="gf-org-grid">
                            @foreach ($organizations as $organization)
                                @if ($vaultRouteAvailable)
                                    <a
                                        class="gf-org gf-org--link"
                                        href="{{ route('organizations.vault.index', ['organizationId' => $organization->id]) }}"
                                        data-organization-id="{{ $organization->id }}"
                                    >
                                        <div>
                                            <h3 class="gf-org__name">{{ $organization->name }}</h3>
                                            <div class="gf-org__id">{{ $organization->id }}</div>
                                        </div>
                                        <span class="gf-org__action">Open Vault →</span>
                                    </a>
                                @else
                                    <article class="gf-org" data-organization-id="{{ $organization->id }}">
                                        <div>
                                            <h3 class="gf-org__name">{{ $organization->name }}</h3>
                                            <div class="gf-org__id">{{ $organization->id }}</div>
                                        </div>
                                    </article>
                                @endif
                            @endforeach
                        </div>
                    @endif
                </div>
            </section>
        </main>
    </div>
</body>
</html>
