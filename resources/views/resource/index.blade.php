@extends('layouts.app')

@section('title', $title)
@section('page-title', $title)

@section('content')
<div class="toolbar">
    <form class="search-form" method="GET" action="{{ url('/'.$slug) }}">
        <input type="search" name="q" value="{{ $search }}" placeholder="Search…">
        <button class="btn btn-secondary" type="submit">Search</button>
    </form>
</div>

<div class="card">
    <table class="data-table">
        <thead>
            <tr>
                @foreach ($columns as $field => $label)
                    <th>{{ $label }}</th>
                @endforeach
                <th></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    @foreach ($columns as $field => $label)
                        @php
                            $value = data_get($row, $field);
                            if ($value instanceof \DateTimeInterface) {
                                $value = $value->format('Y-m-d H:i');
                            } elseif (is_bool($value)) {
                                $value = $value ? 'Yes' : 'No';
                            } elseif (is_array($value)) {
                                $value = json_encode($value);
                            }
                        @endphp
                        <td>{{ $value ?? '—' }}</td>
                    @endforeach
                    <td>
                        <a class="btn btn-secondary btn-sm" href="{{ url('/'.$slug.'/'.$row->getKey()) }}">View</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="{{ count($columns) + 1 }}">No records found.</td></tr>
            @endforelse
        </tbody>
    </table>
    <div class="pagination">{{ $rows->links() }}</div>
</div>
@endsection
