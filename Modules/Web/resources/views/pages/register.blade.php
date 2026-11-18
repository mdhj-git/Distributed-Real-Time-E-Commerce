@extends('web::layouts.app')

@section('title', 'Register')

@section('content')
    <form id="form" novalidate>
        <label for="name">Name</label>
        <input id="name" name="name" type="text" autocomplete="name">

        <label for="email">Email</label>
        <input id="email" name="email" type="email" autocomplete="email">

        <label for="password">Password (at least 8 characters)</label>
        <input id="password" name="password" type="password" autocomplete="new-password">

        <label for="password_confirmation">Confirm password</label>
        <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password">

        <button type="submit" class="btn primary">Create account</button>
    </form>
    <p>Already have an account? <a href="/login">Log in</a>.</p>
@endsection

@section('scripts')
    <script src="/ui/js/pages/register.js"></script>
@endsection
