@props(['context' => 'workspace'])

<footer class="gf-release-footer gf-release-footer--{{ $context }}"
    data-grindflow-version="{{ config('version.number', 'unknown') }}">
    <span>GrindFlow · <strong>v{{ config('version.number', 'unknown') }}</strong></span>
    <span>{{ $context === 'public' ? 'Tu contenido, en movimiento.' : 'Workspace · GrindFlow' }}</span>
</footer>
