@extends('web::layouts.app')

@section('title', 'Orders')

@section('content')
    <p id="scope"></p>
    <table>
        <thead>
        <tr>
            <th>Order</th>
            <th>Customer id</th>
            <th>Status</th>
            <th>Total</th>
            <th>Created</th>
        </tr>
        </thead>
        <tbody id="rows"></tbody>
    </table>
    <div id="pager" class="pager"></div>
@endsection

@section('scripts')
    <script src="/ui/js/pages/orders.js"></script>
@endsection
