@props([
    'organization' => null,
    'active' => 'summary',
])

@php
    use Illuminate\Support\Facades\Route;

    $user = auth()->user();
    $organizationId = $organization?->getKey();
    $hasOrganization = is_string($organizationId) && $organizationId !== '';
    $organizationParams = $hasOrganization ? ['organizationId' => $organizationId] : [];

    $workspaceItems = [
        [
            'key' => 'summary',
            'label' => 'Resumen',
            'href' => Route::has('dashboard') ? route('dashboard') : null,
            'disabled_reason' => 'Resumen no disponible.',
        ],
        [
            'key' => 'vault',
            'label' => 'Biblioteca',
            'href' => $hasOrganization && Route::has('organizations.vault.index')
                ? route('organizations.vault.index', $organizationParams)
                : null,
            'disabled_reason' => $hasOrganization ? 'Biblioteca no disponible.' : 'Selecciona una organización.',
        ],
        [
            'key' => 'scheduler',
            'label' => 'Programación',
            'href' => $hasOrganization && Route::has('organizations.scheduler.index')
                ? route('organizations.scheduler.index', $organizationParams)
                : null,
            'disabled_reason' => $hasOrganization ? 'Programación no disponible.' : 'Selecciona una organización.',
        ],
        [
            'key' => 'distribution',
            'label' => 'Distribución',
            'href' => $hasOrganization && Route::has('organizations.distribution.index')
                ? route('organizations.distribution.index', $organizationParams)
                : null,
            'disabled_reason' => $hasOrganization ? 'Distribución no disponible.' : 'Selecciona una organización.',
        ],
    ];

    $canTraffic = $hasOrganization
        && $user?->canManageTrafficOrganization($organization);
    $canFinance = $hasOrganization
        && $user?->canManageFinanceOrganization($organization);

    $insightItems = [
        [
            'key' => 'traffic',
            'label' => 'Tráfico',
            'href' => $canTraffic && Route::has('organizations.traffic.index')
                ? route('organizations.traffic.index', $organizationParams)
                : null,
            'disabled_reason' => ! $hasOrganization
                ? 'Selecciona una organización.'
                : ($canTraffic ? 'Tráfico no disponible.' : 'Tu rol no permite administrar Tráfico.'),
        ],
        [
            'key' => 'finance',
            'label' => 'Finanzas',
            'href' => $canFinance && Route::has('organizations.finance.index')
                ? route('organizations.finance.index', $organizationParams)
                : null,
            'disabled_reason' => ! $hasOrganization
                ? 'Selecciona una organización.'
                : ($canFinance ? 'Finanzas no disponible.' : 'Tu rol no permite administrar Finanzas.'),
        ],
    ];

    $adminItems = $user?->isPlatformAdmin() ? [
        [
            'key' => 'system',
            'label' => 'Sistema',
            'href' => Route::has('admin.system') ? route('admin.system') : null,
            'disabled_reason' => 'Sistema no disponible.',
        ],
        [
            'key' => 'diagnostics',
            'label' => 'Diagnósticos',
            'href' => Route::has('admin.diagnostics') ? route('admin.diagnostics') : null,
            'disabled_reason' => 'Diagnósticos no disponibles.',
        ],
    ] : [];
@endphp

<aside class="gf-sidebar" data-workspace-sidebar>
    <x-brand :href="route('dashboard')" />

    <svg class="gf-nav-sprite" aria-hidden="true">
        <symbol id="gf-icon-summary" viewBox="0 0 24 24">
            <path d="M3 10.5 12 3l9 7.5"></path><path d="M5 9.5V21h14V9.5"></path><path d="M9 21v-6h6v6"></path>
        </symbol>
        <symbol id="gf-icon-vault" viewBox="0 0 24 24">
            <rect x="3" y="4" width="18" height="16" rx="2"></rect><path d="M8 4v16"></path><path d="M12 9h5M12 13h5"></path>
        </symbol>
        <symbol id="gf-icon-scheduler" viewBox="0 0 24 24">
            <rect x="3" y="5" width="18" height="16" rx="2"></rect><path d="M8 3v4M16 3v4M3 10h18"></path><path d="M8 14h3v3H8z"></path>
        </symbol>
        <symbol id="gf-icon-distribution" viewBox="0 0 24 24">
            <path d="M4 12h15"></path><path d="m14 7 5 5-5 5"></path><path d="M4 7v10"></path>
        </symbol>
        <symbol id="gf-icon-traffic" viewBox="0 0 24 24">
            <path d="m4 18 5-5 4 3 7-9"></path><path d="M15 7h5v5"></path>
        </symbol>
        <symbol id="gf-icon-finance" viewBox="0 0 24 24">
            <circle cx="12" cy="12" r="9"></circle><path d="M15.5 8.5c-.8-.8-2-1.2-3.3-1.2-1.8 0-3.2.9-3.2 2.3 0 3.6 6.7 1.4 6.7 5.1 0 1.4-1.5 2.4-3.5 2.4-1.5 0-2.8-.5-3.7-1.4M12 5.5v13"></path>
        </symbol>
        <symbol id="gf-icon-system" viewBox="0 0 24 24">
            <circle cx="12" cy="12" r="3"></circle><path d="M12 2v3M12 19v3M2 12h3M19 12h3M5 5l2.1 2.1M16.9 16.9 19 19M19 5l-2.1 2.1M7.1 16.9 5 19"></path>
        </symbol>
        <symbol id="gf-icon-diagnostics" viewBox="0 0 24 24">
            <path d="M3 12h4l2-5 4 10 2-5h6"></path>
        </symbol>
        <symbol id="gf-icon-logout" viewBox="0 0 24 24">
            <path d="M10 5H5v14h5"></path><path d="M13 8l4 4-4 4M17 12H9"></path>
        </symbol>
    </svg>

    <nav class="gf-sidebar__nav" aria-label="Navegación principal">
        <span class="gf-sidebar__label">Workspace</span>

        @foreach ($workspaceItems as $item)
            @if ($item['href'])
                <a
                    class="gf-navitem {{ $active === $item['key'] ? 'gf-navitem--active' : '' }}"
                    href="{{ $item['href'] }}"
                    title="{{ $item['label'] }}"
                    @if ($active === $item['key']) aria-current="page" @endif
                >
                    <svg class="gf-navitem__icon" aria-hidden="true"><use href="#gf-icon-{{ $item['key'] }}"></use></svg>
                    <span class="gf-navitem__text">{{ $item['label'] }}</span>
                </a>
            @else
                <span
                    class="gf-navitem gf-navitem--disabled"
                    aria-disabled="true"
                    title="{{ $item['disabled_reason'] }}"
                >
                    <svg class="gf-navitem__icon" aria-hidden="true"><use href="#gf-icon-{{ $item['key'] }}"></use></svg>
                    <span class="gf-navitem__text">{{ $item['label'] }}</span>
                </span>
            @endif
        @endforeach

        <span class="gf-sidebar__label">Insights</span>

        @foreach ($insightItems as $item)
            @if ($item['href'])
                <a
                    class="gf-navitem {{ $active === $item['key'] ? 'gf-navitem--active' : '' }}"
                    href="{{ $item['href'] }}"
                    title="{{ $item['label'] }}"
                    @if ($active === $item['key']) aria-current="page" @endif
                >
                    <svg class="gf-navitem__icon" aria-hidden="true"><use href="#gf-icon-{{ $item['key'] }}"></use></svg>
                    <span class="gf-navitem__text">{{ $item['label'] }}</span>
                </a>
            @else
                <span
                    class="gf-navitem gf-navitem--disabled"
                    aria-disabled="true"
                    title="{{ $item['disabled_reason'] }}"
                >
                    <svg class="gf-navitem__icon" aria-hidden="true"><use href="#gf-icon-{{ $item['key'] }}"></use></svg>
                    <span class="gf-navitem__text">{{ $item['label'] }}</span>
                </span>
            @endif
        @endforeach

        @if ($adminItems !== [])
            <span class="gf-sidebar__label">Admin</span>

            @foreach ($adminItems as $item)
                @if ($item['href'])
                    <a
                        class="gf-navitem {{ $active === $item['key'] ? 'gf-navitem--active' : '' }}"
                        href="{{ $item['href'] }}"
                        title="{{ $item['label'] }}"
                        @if ($active === $item['key']) aria-current="page" @endif
                    >
                        <svg class="gf-navitem__icon" aria-hidden="true"><use href="#gf-icon-{{ $item['key'] }}"></use></svg>
                        <span class="gf-navitem__text">{{ $item['label'] }}</span>
                    </a>
                @else
                    <span
                        class="gf-navitem gf-navitem--disabled"
                        aria-disabled="true"
                        title="{{ $item['disabled_reason'] }}"
                    >
                        <svg class="gf-navitem__icon" aria-hidden="true"><use href="#gf-icon-{{ $item['key'] }}"></use></svg>
                        <span class="gf-navitem__text">{{ $item['label'] }}</span>
                    </span>
                @endif
            @endforeach
        @endif
    </nav>

    <div class="gf-sidebar__bottom">
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button class="gf-button gf-button--ghost gf-button--full gf-sidebar__logout" type="submit" title="Cerrar sesión">
                <svg class="gf-navitem__icon" aria-hidden="true"><use href="#gf-icon-logout"></use></svg>
                <span class="gf-sidebar__logout-label">Cerrar sesión</span>
            </button>
        </form>
    </div>
</aside>
