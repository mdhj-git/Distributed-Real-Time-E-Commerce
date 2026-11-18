/*
 * Order tracking (live). The page loads the order from the API, then joins the private channel
 * "private-orders.{id}". The status in the page changes when the server pushes "order.status.changed"; no reload.
 * Someone else's order shows the API's own 403, and the channel refuses them too.
 */
(function (Shop) {
    'use strict';

    var ui = Shop.ui;
    var errorBox = ui.byId('error');
    var id = ui.idFromPath();
    var statusCell = null;

    function fact(dl, label, value) {
        dl.appendChild(ui.el('dt', null, label));
        var dd = ui.el('dd');
        if (value instanceof Node) {
            dd.appendChild(value);
        } else {
            dd.textContent = String(value);
        }
        dl.appendChild(dd);
        return dd;
    }

    function showStatus(status) {
        if (!statusCell) {
            return;
        }
        ui.clear(statusCell);
        statusCell.appendChild(ui.statusBadge(status));
    }

    function render(order) {
        var dl = ui.byId('summary');
        ui.clear(dl);
        fact(dl, 'Order', '#' + order.id);
        statusCell = fact(dl, 'Status', ui.statusBadge(order.status));
        fact(dl, 'Total', ui.money(order.total));
        fact(dl, 'Customer id', order.user_id);
        fact(dl, 'Created', ui.dateTime(order.created_at));

        var items = ui.byId('items');
        ui.clear(items);
        (order.items || []).forEach(function (item) {
            var tr = ui.el('tr');
            tr.appendChild(ui.el('td', null, item.product_name));
            tr.appendChild(ui.el('td', null, ui.money(item.unit_price)));
            tr.appendChild(ui.el('td', null, item.quantity));
            items.appendChild(tr);
        });
    }

    function load() {
        return ui.guard(errorBox, function () {
            return Shop.api.get('/orders/' + encodeURIComponent(id)).then(function (res) {
                render(res.data);
            });
        });
    }

    function log(text) {
        var li = ui.el('li', null, new Date().toLocaleTimeString() + '  ' + text);
        var list = ui.byId('log');
        list.insertBefore(li, list.firstChild);
    }

    load();

    Shop.realtime.subscribe('private-orders.' + id, {
        statusEl: ui.byId('live'),
        errorEl: ui.byId('live-error'),
        // Fetch again whenever the channel is (re)joined, so a change missed while offline is not lost.
        onSubscribed: load,
        events: {
            'order.status.changed': function (data) {
                showStatus(data.status);
                log('Order #' + data.order_id + ' is now ' + data.status);
            }
        }
    });
})(window.Shop);
