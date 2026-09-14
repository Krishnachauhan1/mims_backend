<aside class="sidebar">
    <div class="sidebar-brand">
        <div class="brand-mark">
            <span class="material-symbols-outlined">factory</span>
        </div>
        <div class="brand-text">
            <strong>Power Force Industries</strong>
            <span>Manufacturing & Inventory</span>
        </div>
    </div>
    <nav class="sidebar-nav">
        @foreach ($navSections as $section)
            <div class="nav-section-label">{{ $section['label'] }}</div>
            @foreach ($section['items'] as $item)
                @php
                    $icon = preg_replace('/_outlined$/', '', $item['icon'] ?? 'folder');
                    $icon = str_replace('_', '_', $icon);
                @endphp
                <a href="{{ url('/'.$item['route']) }}"
                   class="nav-link {{ request()->is($item['route']) || request()->is($item['route'].'/*') ? 'active' : '' }}">
                    <span class="material-symbols-outlined nav-icon">{{ $icon }}</span>
                    <span class="nav-label">{{ $item['name'] }}</span>
                </a>
            @endforeach
        @endforeach
    </nav>
    <div class="sidebar-footer">
        <div class="user-chip">
            <div class="avatar">{{ strtoupper(substr(auth()->user()->name ?? 'U', 0, 1)) }}</div>
            <div class="user-meta">
                <strong>{{ auth()->user()->name }}</strong>
                <span>{{ $userRoleLabel ?? auth()->user()->role }}</span>
            </div>
        </div>
        <form method="POST" action="{{ route('web.logout') }}">
            @csrf
            <button type="submit" class="btn btn-ghost btn-sm btn-block">
                <span class="material-symbols-outlined">logout</span>
                Sign out
            </button>
        </form>
    </div>
</aside>
