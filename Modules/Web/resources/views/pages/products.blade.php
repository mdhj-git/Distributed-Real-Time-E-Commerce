@extends('web::layouts.app')

@section('title', 'Products')

@section('content')
    <form id="filter" class="inline" novalidate>
        <label for="category">Category</label>
        <select id="category" name="category_id"></select>

        <label for="search">Search</label>
        <input id="search" name="search" type="search">

        <button type="submit" class="btn">Filter</button>
    </form>

    <div id="list" class="cards"></div>
    <div id="pager" class="pager"></div>
@endsection

@section('scripts')
    <script src="/ui/js/pages/products.js"></script>
@endsection
