@extends('web::layouts.app')

@section('title', 'New order')

@section('content')
    <p>Choose quantities, then place the order. The price and the total are always calculated by the server.</p>

    <form id="form" novalidate>
        <table>
            <thead>
            <tr>
                <th>Product</th>
                <th>Price</th>
                <th>In stock</th>
                <th>Quantity</th>
            </tr>
            </thead>
            <tbody id="rows"></tbody>
        </table>
        <p id="note"></p>
        <button type="submit" class="btn primary">Place order</button>
    </form>
@endsection

@section('scripts')
    <script src="/ui/js/pages/orders-new.js"></script>
@endsection
