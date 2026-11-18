@extends('web::layouts.app')

@section('title', 'Edit product')

@section('content')
    <p>Admin only. The server answers 401 without a token and 403 for a customer.</p>

    <section>
        <h2>Current product</h2>
        <dl id="current" class="facts"></dl>
        <div id="gallery" class="gallery"></div>
    </section>

    <section>
        <h2>Edit details (PATCH)</h2>
        <form id="edit-form" novalidate>
            <label for="name">Name</label>
            <input id="name" name="name" type="text">

            <label for="description">Description</label>
            <textarea id="description" name="description" rows="3"></textarea>

            <label for="price">Price</label>
            <input id="price" name="price" type="text" inputmode="decimal">

            <label for="category_id">Category</label>
            <select id="category_id" name="category_id"></select>

            <button type="submit" class="btn primary">Save</button>
        </form>
        <div id="edit-result" hidden></div>
    </section>

    <section>
        <h2>Adjust stock</h2>
        <p>A signed number: positive adds, negative removes. A change that would make the stock negative is refused with 409.</p>
        <form id="stock-form" class="inline" novalidate>
            <label for="adjustment">Adjustment</label>
            <input id="adjustment" name="adjustment" type="number" step="1">
            <button type="submit" class="btn primary">Apply</button>
        </form>
        <div id="stock-result" hidden></div>
    </section>

    <section>
        <h2>Add images</h2>
        <form id="images-form" class="inline" novalidate>
            <label for="images">Images</label>
            <input id="images" name="images" type="file" accept="image/jpeg,image/png,image/webp" multiple>
            <button type="submit" class="btn primary">Upload</button>
        </form>
        <div id="images-result" hidden></div>
    </section>

    <section>
        <h2>Delete</h2>
        <p>Soft delete: the product disappears from the catalogue, old orders keep its name and price.</p>
        <button id="delete" type="button" class="btn danger">Delete product</button>
        <div id="delete-result" hidden></div>
    </section>
@endsection

@section('scripts')
    <script src="/ui/js/pages/admin-product-edit.js"></script>
@endsection
