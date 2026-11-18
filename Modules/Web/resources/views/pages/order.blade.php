@extends('web::layouts.app')

@section('title', 'Order tracking')

@section('content')
    <p id="live" class="live warn">Live updates: not connected yet</p>
    <div id="live-error" hidden></div>

    <section>
        <h2>Order</h2>
        <dl id="summary" class="facts"></dl>
    </section>

    <section>
        <h2>Items</h2>
        <table>
            <thead>
            <tr>
                <th>Product</th>
                <th>Unit price</th>
                <th>Quantity</th>
            </tr>
            </thead>
            <tbody id="items"></tbody>
        </table>
    </section>

    <section>
        <h2>Live events</h2>
        <ul id="log" class="log"></ul>
    </section>
@endsection

@section('scripts')
    <script src="/ui/vendor/pusher.min.js"></script>
    <script src="/ui/js/realtime.js"></script>
    <script src="/ui/js/pages/order.js"></script>
@endsection
