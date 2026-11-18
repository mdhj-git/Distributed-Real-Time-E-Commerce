@extends('web::layouts.app')

@section('title', 'Login')

@section('content')
    <form id="form" novalidate>
        <label for="email">Email</label>
        <input id="email" name="email" type="email" autocomplete="username">

        <label for="password">Password</label>
        <input id="password" name="password" type="password" autocomplete="current-password">

        <button type="submit" class="btn primary">Log in</button>
    </form>
    <p>No account yet? <a href="/register">Register</a>.</p>
@endsection

@section('scripts')
    <script src="/ui/js/pages/login.js"></script>
@endsection
