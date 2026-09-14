<!DOCTYPE html>
<html lang="en">
<head>
    @include('partials.head')
    @stack('head')
    <title>@yield('title', 'MIMS') — Power Force Industries</title>
</head>
<body>
<div class="app-shell">
    @include('partials.sidebar')
    <div class="main">
        <header class="app-header">
            <h1 class="app-header-title">@yield('page-title', 'Dashboard')</h1>
        </header>
        <main class="content">
            @include('partials.flash')
            @yield('content')
        </main>
    </div>
</div>
</body>
</html>
