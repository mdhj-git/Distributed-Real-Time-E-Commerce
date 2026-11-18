/*
 * Admin live dashboard. It joins "private-admin.orders" (admin role only; the server refuses everyone else).
 * A new order is pushed as "order.placed" (order_id and status only), the page fetches its details from the API
 * and puts the row at the top. "order.status.changed" updates the row in place.
 */
(function (Shop) {
    'use strict';

    var ui = Shop.ui;
    var errorBox = ui.byId('error');
    var actionError = ui.byId('action-error');
    var rows = ui.byId('rows');
    var notice = ui.byId('notice');
    var known = {};

    // Mirrors the order state machine, to show only the allowed buttons. The API still enforces it (422).
    var ALLOWED = {
        pending: ['confirmed', 'cancelled'],
        confirmed: ['shipped', 'cancelled'],
        shipped: ['delivered'],
        delivered: [],
        cancelled: []
    };

    var MAX_ROWS = 50;
    // A new order can be a 404 for a moment when the read replica lags behind the primary: try again briefly.
    var RETRY_DELAYS = [300, 800, 1500, 3000];

    function log(text) {
        var li = ui.el('li', null, new Date().toLocaleTimeString() + '  ' + text);
        var list = ui.byId('log');
        list.insertBefore(li, list.firstChild);
    }

    function wait(ms) {
        return new Promise(function (resolve) {
            window.setTimeout(resolve, ms);
        });
    }

    function buildActions(entry) {
        var cell = entry.actions;
        ui.clear(cell);
        var box = ui.el('div', 'actions');
        (ALLOWED[entry.order.status] || []).forEach(function (target) {
            var button = ui.el('button', 'btn', 'Set ' + target);
            button.setAttribute('type', 'button');
            button.addEventListener('click', function () {
                changeStatus(entry, target, box);
            });
            box.appendChild(button);
        });
        if (!box.firstChild) {
            box.appendChild(ui.el('span', 'muted', 'final status'));
        }
        cell.appendChild(box);
    }

    function changeStatus(entry, target, box) {
        Array.prototype.forEach.call(box.querySelectorAll('button'), function (b) {
            b.disabled = true;
        });
        ui.guard(actionError, function () {
            return Shop.api.patch('/orders/' + encodeURIComponent(String(entry.order.id)) + '/status', { status: target }).then(function (res) {
                upsert(res.data, { flash: true });
            });
        }).then(function () {
            buildActions(entry);
        });
    }

    function paint(entry) {
        var o = entry.order;
        ui.clear(entry.status);
        entry.status.appendChild(ui.statusBadge(o.status));
        entry.total.textContent = ui.money(o.total);
        entry.created.textContent = ui.dateTime(o.created_at);
        buildActions(entry);
    }

    // options: top (insert first), flash (highlight)
    function upsert(order, options) {
        options = options || {};
        var entry = known[order.id];

        if (!entry) {
            var tr = ui.el('tr');
            var idCell = ui.el('td');
            var link = ui.el('a', null, '#' + order.id);
            link.setAttribute('href', '/orders/' + encodeURIComponent(String(order.id)));
            idCell.appendChild(link);
            tr.appendChild(idCell);

            var user = ui.el('td', null, order.user_id);
            var status = ui.el('td');
            var total = ui.el('td');
            var created = ui.el('td');
            var actions = ui.el('td');
            [user, status, total, created, actions].forEach(function (cell) {
                tr.appendChild(cell);
            });

            entry = known[order.id] = { tr: tr, status: status, total: total, created: created, actions: actions, order: order };

            if (options.top) {
                rows.insertBefore(tr, rows.firstChild);
            } else {
                rows.appendChild(tr);
            }
            while (rows.children.length > MAX_ROWS) {
                rows.removeChild(rows.lastChild);
            }
        }

        entry.order = order;
        paint(entry);

        if (options.flash) {
            entry.tr.classList.remove('flash');
            void entry.tr.offsetWidth;
            entry.tr.classList.add('flash');
        }
    }

    function fetchOrder(orderId, attempt) {
        return Shop.api.get('/orders/' + encodeURIComponent(String(orderId))).then(
            function (res) {
                upsert(res.data, { top: true, flash: true });
            },
            function (err) {
                if (err.status === 404 && attempt < RETRY_DELAYS.length) {
                    return wait(RETRY_DELAYS[attempt]).then(function () {
                        return fetchOrder(orderId, attempt + 1);
                    });
                }
                ui.showError(actionError, err);
            }
        );
    }

    function loadList() {
        return ui.guard(errorBox, function () {
            return Shop.api.get('/orders?per_page=20').then(function (res) {
                (res.data || []).forEach(function (order) {
                    upsert(order, {});
                });
            });
        });
    }

    var user = Shop.auth.user();
    if (user && user.role !== 'admin') {
        // Not loaded on purpose: this page is for admins. The channel request below shows the server's 403.
        notice.hidden = false;
        notice.textContent = 'You are signed in as a customer. The server decides who may open this dashboard: see its answer to the live channel below.';
    } else {
        loadList();
    }

    Shop.realtime.subscribe('private-admin.orders', {
        statusEl: ui.byId('live'),
        errorEl: ui.byId('live-error'),
        onSubscribed: function () {
            if (!user || user.role === 'admin') {
                loadList();
            }
        },
        events: {
            'order.placed': function (data) {
                log('New order #' + data.order_id + ' (' + data.status + ')');
                fetchOrder(data.order_id, 0);
            },
            'order.status.changed': function (data) {
                log('Order #' + data.order_id + ' is now ' + data.status);
                var entry = known[data.order_id];
                if (entry) {
                    entry.order.status = data.status;
                    paint(entry);
                    entry.tr.classList.remove('flash');
                    void entry.tr.offsetWidth;
                    entry.tr.classList.add('flash');
                } else {
                    fetchOrder(data.order_id, 0);
                }
            }
        }
    });
})(window.Shop);
