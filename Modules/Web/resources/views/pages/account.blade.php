@extends('web::layouts.app')

@section('title', 'Account')

@section('content')
    <section>
        <h2>Signed-in user (GET /auth/me)</h2>
        <dl id="me" class="facts"></dl>
    </section>

    <section>
        <h2>Token</h2>
        <p>The token lives in this tab only (sessionStorage). It is never shown here.</p>
        <button id="refresh" type="button" class="btn">Refresh token</button>
        <div id="result" hidden></div>
    </section>
@endsection

@section('scripts')
    <script src="/ui/js/pages/account.js"></script>
@endsection
