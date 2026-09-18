<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard · GrindFlow</title>
</head>
<body>
    <main>
        <header>
            <h1>GrindFlow</h1>

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit">Sign out</button>
            </form>
        </header>

        <h2>Organizations</h2>

        @if ($organizations->isEmpty())
            <p>No organizations are available for this account.</p>
        @else
            <ul>
                @foreach ($organizations as $organization)
                    <li data-organization-id="{{ $organization->id }}">
                        {{ $organization->name }}
                    </li>
                @endforeach
            </ul>
        @endif
    </main>
</body>
</html>
