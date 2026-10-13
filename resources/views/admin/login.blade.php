@extends('layouts.app')

@section('title', 'Admin sign in | Convention Registration')

@section('content')
    <div class="intro">
        <div class="eyebrow">Organizer access</div>
        <h1>Admin sign in</h1>
        <p>Enter the private access key configured by the site administrator.</p>
    </div>
    <form class="card" action="{{ route('admin.authenticate') }}" method="POST" style="max-width: 480px">
        @csrf
        <div class="field">
            <label for="access_key">Admin access key</label>
            <input id="access_key" name="access_key" type="password" autocomplete="current-password" required>
            @error('access_key') <p class="error-text">{{ $message }}</p> @enderror
        </div>
        <div class="actions"><button type="submit">Sign in</button></div>
    </form>
@endsection
