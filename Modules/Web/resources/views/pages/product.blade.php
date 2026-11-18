@extends('web::layouts.app')

@section('title', 'Product')

@section('content')
    <div id="product"></div>
    <div id="gallery" class="gallery"></div>
    <p id="actions"></p>
@endsection

@section('scripts')
    <script src="/ui/js/pages/product.js"></script>
@endsection
