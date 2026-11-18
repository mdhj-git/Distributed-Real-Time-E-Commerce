(function (Shop) {
    'use strict';

    var ui = Shop.ui;
    var errorBox = ui.byId('error');
    var query = ui.query();
    var page = Number(query.get('page')) || 1;
    var unreadOnly = query.get('unread') === '1';
    var checkbox = ui.byId('unread');
    checkbox.checked = unreadOnly;

    function hrefFor(targetPage) {
        return '/notifications?' + (unreadOnly ? 'unread=1&' : '') + 'page=' + targetPage;
    }

    checkbox.addEventListener('change', function () {
        window.location.assign(checkbox.checked ? '/notifications?unread=1' : '/notifications');
    });

    function paintItem(li, n) {
        ui.clear(li);
        li.className = n.read_at ? '' : 'unread';
        li.appendChild(ui.el('div', 'title', n.title));
        li.appendChild(ui.el('div', null, n.message));
        li.appendChild(ui.el('div', 'muted', ui.dateTime(n.created_at) + (n.read_at ? ' - read' : ' - unread')));

        var orderId = n.data && n.data.order_id;
        if (typeof orderId === 'number' && isFinite(orderId)) {
            var link = ui.el('a', null, 'Open order #' + orderId);
            link.setAttribute('href', '/orders/' + encodeURIComponent(String(orderId)));
            li.appendChild(link);
            li.appendChild(document.createTextNode(' '));
        }

        if (!n.read_at) {
            var button = ui.el('button', 'btn', 'Mark as read');
            button.setAttribute('type', 'button');
            button.addEventListener('click', function () {
                ui.guard(errorBox, function () {
                    return Shop.api.patch('/notifications/' + encodeURIComponent(String(n.id)) + '/read').then(function (res) {
                        paintItem(li, res.data);
                    });
                });
            });
            li.appendChild(button);
        }
    }

    function load() {
        return ui.guard(errorBox, function () {
            var params = new URLSearchParams();
            params.set('per_page', '15');
            params.set('page', String(page));
            if (unreadOnly) {
                params.set('unread', '1');
            }
            return Shop.api.get('/notifications?' + params.toString()).then(function (res) {
                var list = ui.byId('list');
                ui.clear(list);
                (res.data || []).forEach(function (n) {
                    var li = ui.el('li');
                    paintItem(li, n);
                    list.appendChild(li);
                });
                if (!res.data || res.data.length === 0) {
                    list.appendChild(ui.el('li', 'muted', 'No notifications.'));
                }
                ui.pager(ui.byId('pager'), res.meta, hrefFor);
            });
        });
    }

    ui.byId('read-all').addEventListener('click', function () {
        ui.guard(errorBox, function () {
            return Shop.api.post('/notifications/read-all').then(load);
        });
    });

    load();
})(window.Shop);
