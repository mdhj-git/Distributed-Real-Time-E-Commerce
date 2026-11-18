(function (Shop) {
    'use strict';

    var ui = Shop.ui;
    var errorBox = ui.byId('error');
    var page = Number(ui.query().get('page')) || 1;

    var user = Shop.auth.user();
    ui.byId('scope').textContent =
        user && user.role === 'admin' ? 'All orders (admin).' : 'Your orders. Another customer\'s orders are never listed.';

    ui.guard(errorBox, function () {
        return Shop.api.get('/orders?per_page=15&page=' + page).then(function (res) {
            var rows = ui.byId('rows');
            ui.clear(rows);
            (res.data || []).forEach(function (o) {
                var tr = ui.el('tr');

                var idCell = ui.el('td');
                var link = ui.el('a', null, '#' + o.id);
                link.setAttribute('href', '/orders/' + encodeURIComponent(String(o.id)));
                idCell.appendChild(link);
                tr.appendChild(idCell);

                tr.appendChild(ui.el('td', null, o.user_id));
                var statusCell = ui.el('td');
                statusCell.appendChild(ui.statusBadge(o.status));
                tr.appendChild(statusCell);
                tr.appendChild(ui.el('td', null, ui.money(o.total)));
                tr.appendChild(ui.el('td', null, ui.dateTime(o.created_at)));
                rows.appendChild(tr);
            });

            ui.pager(ui.byId('pager'), res.meta, function (target) {
                return '/orders?page=' + target;
            });
        });
    });
})(window.Shop);
