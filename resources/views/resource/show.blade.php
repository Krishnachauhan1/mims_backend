@extends('layouts.app')

@section('title', $title)
@section('page-title', $title)

@section('content')
<div class="toolbar">
    <a class="btn btn-secondary" href="{{ url('/'.$slug) }}">← Back to list</a>
</div>

<div class="card">
    <table class="data-table">
        <tbody>
            @foreach ($record->getAttributes() as $field => $value)
                @if (in_array($field, ['password', 'remember_token'], true))
                    @continue
                @endif
                @php
                    if ($value instanceof \DateTimeInterface) {
                        $value = $value->format('Y-m-d H:i');
                    } elseif (is_bool($value)) {
                        $value = $value ? 'Yes' : 'No';
                    } elseif (is_array($value)) {
                        $value = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                    }
                @endphp
                <tr>
                    <th style="width:220px;text-align:left;">{{ str_replace('_', ' ', ucfirst($field)) }}</th>
                    <td>{{ $value ?? '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endsection
