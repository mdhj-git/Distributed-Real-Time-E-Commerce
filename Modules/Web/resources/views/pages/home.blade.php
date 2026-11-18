@extends('web::layouts.app')

@section('title', 'Home')

@section('content')
    <p>A small shop to try the API from the browser: catalogue, orders, notifications and live order updates.</p>

    <section>
        <h2>API status</h2>
        <p id="health">Checking the API...</p>
    </section>

    <section>
        <h2>Where to start</h2>
        <ul>
            <li><a href="/products">Browse the products</a></li>
            <li><a href="/login">Log in</a> or <a href="/register">create a customer account</a></li>
            <li>Customers: place an order, then watch its status change live on the tracking page.</li>
            <li>Admins: open the live orders dashboard and change order statuses.</li>
        </ul>
    </section>
@endsection

@section('scripts')
    <script src="/ui/js/pages/home.js"></script>
@endsection
