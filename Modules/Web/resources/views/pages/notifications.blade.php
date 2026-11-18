@extends('web::layouts.app')

@section('title', 'Notifications')

@section('content')
    <form id="filter" class="inline" novalidate>
        <label for="unread"><input id="unread" type="checkbox"> Unread only</label>
        <button id="read-all" type="button" class="btn">Mark all as read</button>
    </form>

    <ul id="list" class="notes"></ul>
    <div id="pager" class="pager"></div>
@endsection

@section('scripts')
    <script src="/ui/js/pages/notifications.js"></script>
@endsection
