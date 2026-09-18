@props(['href' => route('home')])

<a class="gf-brand" href="{{ $href }}" {{ $attributes }}>
    <span class="gf-brand__mark" aria-hidden="true">GF</span>
    <span class="gf-brand__name">GrindFlow</span>
</a>
