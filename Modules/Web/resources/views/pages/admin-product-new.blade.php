@extends('web::layouts.app')

@section('title', 'New product')

@section('content')
    <p>Admin only. The server answers 401 without a token and 403 for a customer.</p>

    <form id="form" novalidate>
        <label for="name">Name</label>
        <input id="name" name="name" type="text">

        <label for="description">Description</label>
        <textarea id="description" name="description" rows="3"></textarea>

        <label for="price">Price (for example 19.99)</label>
        <input id="price" name="price" type="text" inputmode="decimal">

        <label for="category_id">Category</label>
        <select id="category_id" name="category_id"></select>

        <label for="initial_stock">Initial stock</label>
        <input id="initial_stock" name="initial_stock" type="number" min="0" value="0">

        <label for="images">Images (up to 5; jpg, png or webp; 2 MB each)</label>
        <input id="images" name="images" type="file" accept="image/jpeg,image/png,image/webp" multiple>

        <button type="submit" class="btn primary">Create product</button>
    </form>
    <div id="result" hidden></div>
@endsection

@section('scripts')
    <script src="/ui/js/pages/admin-product-new.js"></script>
@endsection
