<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#070a12">
    <title>Finance · {{ $organization->name }} · GrindFlow</title>
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
                    class="gf-navitem"
                    href="{{ route('organizations.scheduler.index', ['organizationId' => $organization->id]) }}"
                >
                    <span class="gf-navitem__icon" aria-hidden="true">⌁</span>
                    <span class="gf-navitem__text">Scheduler</span>
                </a>

                <span class="gf-sidebar__label">Insights</span>

                <a
                    class="gf-navitem"
                    href="{{ route('organizations.traffic.index', ['organizationId' => $organization->id]) }}"
                >
                    <span class="gf-navitem__icon" aria-hidden="true">⌗</span>
                    <span class="gf-navitem__text">Traffic</span>
                </a>

                <a
                    class="gf-navitem gf-navitem--active"
                    href="{{ route('organizations.finance.index', ['organizationId' => $organization->id]) }}"
                    aria-current="page"
                >
                    <span class="gf-navitem__icon" aria-hidden="true">$</span>
                    <span class="gf-navitem__text">Finance</span>
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
                    GF / {{ strtoupper($organization->slug) }} / FINANCE
                </div>

                <div class="gf-avatar" aria-label="Usuario autenticado">
                    {{ strtoupper(substr((string) auth()->user()?->name, 0, 2)) }}
                </div>
            </header>

            <section class="gf-pagehead">
                <div>
                    <span class="gf-kicker">
                        <span class="gf-kicker__dot"></span>
                        Append-only revenue ledger
                    </span>
                    <h1>Finance</h1>
                    <p>
                        Registra asignaciones de ingresos sin editar ni borrar historia.
                        Las correcciones se representan como reversas auditables.
                    </p>
                </div>

                <span class="gf-badge">
                    {{ $financeReady ? 'Finance schema ready' : 'Migration required' }}
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

            @if (! $financeReady)
                <section class="gf-panel">
                    <div class="gf-panel__body">
                        <div class="gf-empty">
                            <div>
                                <div class="gf-empty__icon" aria-hidden="true">$</div>
                                <h3>Finance migration required.</h3>
                                <p>
                                    El workspace queda read-safe y los writes responden 503
                                    hasta que exista el ledger de Finance.
                                </p>
                            </div>
                        </div>
                    </div>
                </section>
            @else
                <section class="gf-panel" aria-label="Metricas de Finance por moneda">
                    <header class="gf-panel__head">
                        <h2>Currency totals</h2>
                        <span class="gf-appbar__meta">Minor units · never cross-currency</span>
                    </header>

                    <div class="gf-panel__body gf-panel__body--flush-mobile">
                        @if ($currencySummaries->isEmpty())
                            <div class="gf-empty">
                                <div>
                                    <h3>No currency totals yet.</h3>
                                    <p>Los totales apareceran cuando exista el primer asiento.</p>
                                </div>
                            </div>
                        @else
                            <div class="gf-table-wrap">
                                <table class="gf-table">
                                    <thead>
                                        <tr>
                                            <th>Currency</th>
                                            <th>Allocated</th>
                                            <th>Reversed</th>
                                            <th>Net</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($currencySummaries as $summary)
                                            <tr>
                                                <td><strong>{{ $summary['currency'] }}</strong></td>
                                                <td>{{ number_format($summary['allocated_minor']) }}</td>
                                                <td>{{ number_format($summary['reversed_minor']) }}</td>
                                                <td>
                                                    <strong>{{ number_format($summary['net_minor']) }}</strong>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                </section>

                <section class="gf-panel gf-panel--spaced">
                    <header class="gf-panel__head">
                        <h2>New revenue allocation</h2>
                        <span class="gf-appbar__meta">GF-FR-007</span>
                    </header>

                    <div class="gf-panel__body">
                        <form
                            class="gf-form"
                            method="POST"
                            action="{{ route('organizations.finance.store', ['organizationId' => $organization->id]) }}"
                        >
                            @csrf

                            <div class="gf-field">
                                <label for="source_label">Source</label>
                                <input
                                    class="gf-input"
                                    id="source_label"
                                    name="source_label"
                                    type="text"
                                    maxlength="191"
                                    value="{{ old('source_label') }}"
                                    required
                                >
                            </div>

                            <div class="gf-field">
                                <label for="amount_minor">Amount (minor units)</label>
                                <input
                                    class="gf-input"
                                    id="amount_minor"
                                    name="amount_minor"
                                    type="number"
                                    min="1"
                                    step="1"
                                    value="{{ old('amount_minor') }}"
                                    required
                                >
                            </div>

                            <div class="gf-field">
                                <label for="currency">Currency</label>
                                <input
                                    class="gf-input"
                                    id="currency"
                                    name="currency"
                                    type="text"
                                    maxlength="3"
                                    value="{{ old('currency', 'COP') }}"
                                    required
                                >
                            </div>

                            <div class="gf-field">
                                <label for="occurred_on">Occurred on</label>
                                <input
                                    class="gf-input"
                                    id="occurred_on"
                                    name="occurred_on"
                                    type="date"
                                    value="{{ old('occurred_on', now('UTC')->toDateString()) }}"
                                    required
                                >
                            </div>

                            <div class="gf-field">
                                <label for="beneficiary_user_id">Beneficiary</label>
                                <select
                                    class="gf-input"
                                    id="beneficiary_user_id"
                                    name="beneficiary_user_id"
                                >
                                    <option value="">Unassigned / organization</option>
                                    @foreach ($beneficiaries as $beneficiary)
                                        <option
                                            value="{{ $beneficiary->id }}"
                                            @selected(old('beneficiary_user_id') === $beneficiary->id)
                                        >
                                            {{ $beneficiary->name }} · {{ $beneficiary->email }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="gf-field">
                                <label for="note">Note</label>
                                <textarea
                                    class="gf-input"
                                    id="note"
                                    name="note"
                                    maxlength="1000"
                                    rows="3"
                                >{{ old('note') }}</textarea>
                            </div>

                            <div class="gf-upload__footer">
                                <p>
                                    Los asientos son append-only. Para corregir uno se crea
                                    una reversa; nunca se edita ni elimina el original.
                                </p>
                                <button class="gf-button gf-button--primary" type="submit">
                                    Record allocation
                                </button>
                            </div>
                        </form>
                    </div>
                </section>

                <section class="gf-panel gf-panel--spaced">
                    <header class="gf-panel__head">
                        <h2>Revenue ledger</h2>
                        <span class="gf-appbar__meta">{{ $allocations->count() }} loaded</span>
                    </header>

                    <div class="gf-panel__body gf-panel__body--flush-mobile">
                        @if ($allocations->isEmpty())
                            <div class="gf-empty">
                                <div>
                                    <div class="gf-empty__icon" aria-hidden="true">$</div>
                                    <h3>No finance entries yet.</h3>
                                    <p>Registra la primera asignacion para iniciar el ledger.</p>
                                </div>
                            </div>
                        @else
                            <div class="gf-table-wrap">
                                <table class="gf-table">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Source</th>
                                            <th>Beneficiary</th>
                                            <th>Amount</th>
                                            <th>Actor</th>
                                            <th>Audit</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($allocations as $allocation)
                                            <tr>
                                                <td>{{ $allocation->occurred_on->toDateString() }}</td>
                                                <td>
                                                    <div class="gf-media-name">{{ $allocation->source_label }}</div>
                                                    <div class="gf-media-meta">{{ $allocation->note ?? '—' }}</div>
                                                </td>
                                                <td>{{ $allocation->beneficiary?->name ?? 'Organization' }}</td>
                                                <td>
                                                    <strong>
                                                        {{ $allocation->reversal_of_id ? '−' : '' }}{{ number_format($allocation->amount_minor) }}
                                                        {{ $allocation->currency }}
                                                    </strong>
                                                </td>
                                                <td>{{ $allocation->createdBy?->name ?? 'Deleted actor' }}</td>
                                                <td>
                                                    @if ($allocation->reversal_of_id)
                                                        <span class="gf-state">Reversal</span>
                                                    @elseif ($allocation->reversal)
                                                        <span class="gf-state">Reversed</span>
                                                    @else
                                                        <form
                                                            method="POST"
                                                            action="{{ route('organizations.finance.reverse', [
                                                                'organizationId' => $organization->id,
                                                                'allocationId' => $allocation->id,
                                                            ]) }}"
                                                        >
                                                            @csrf
                                                            <label
                                                                for="reason-{{ $allocation->id }}"
                                                            >
                                                                Reversal reason
                                                            </label>
                                                            <input
                                                                class="gf-input"
                                                                id="reason-{{ $allocation->id }}"
                                                                name="reason"
                                                                type="text"
                                                                maxlength="1000"
                                                                placeholder="Reversal reason"
                                                                required
                                                            >
                                                            <button
                                                                class="gf-button gf-button--ghost"
                                                                type="submit"
                                                            >
                                                                Reverse
                                                            </button>
                                                        </form>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                </section>
            @endif
        </main>
    </div>
</body>
</html>
