@extends('web::layouts.app')

@section('title', 'Live orders')

@section('content')
    <p>Admin only. New orders appear at the top without reloading.</p>
    <p id="live" class="live warn">Live updates: not connected yet</p>
    <div id="live-error" hidden></div>
    <p id="notice" hidden></p>
    <div id="action-error" hidden></div>

    <table>
        <thead>
        <tr>
            <th>Order</th>
            <th>Customer id</th>
            <th>Status</th>
            <th>Total</th>
            <th>Created</th>
            <th>Change status</th>
        </tr>
        </thead>
        <tbody id="rows"></tbody>
    </table>

    <section>
        <h2>Live events</h2>
        <ul id="log" class="log"></ul>
    </section>
@endsection

@section('scripts')
    <script src="/ui/vendor/pusher.min.js"></script>
    <script src="/ui/js/realtime.js"></script>
    <script src="/ui/js/pages/admin-orders.js"></script>
@endsection
