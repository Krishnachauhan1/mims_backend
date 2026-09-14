@if (session('success'))
    <div class="flash flash-success">{{ session('success') }}</div>
@endif
@if (session('error'))
    <div class="flash flash-error">{{ session('error') }}</div>
@endif
@if ($errors->any())
    <div class="flash flash-error">
        @foreach ($errors->all() as $error)
            <div>{{ $error }}</div>
        @endforeach
    </div>
@endif
