@if (auth()->user()?->isPlatformAdmin())
    <span class="gf-sidebar__label">Admin</span>

    <a class="gf-navitem" href="{{ route('admin.system') }}">
        <span class="gf-navitem__icon" aria-hidden="true">⌘</span>
        <span class="gf-navitem__text">System</span>
    </a>
@endif
