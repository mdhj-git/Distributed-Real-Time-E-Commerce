(function (Shop) {
    'use strict';

    var ui = Shop.ui;
    var errorBox = ui.byId('error');
    var rows = ui.byId('rows');
    var preselect = ui.query().get('product_id');

    ui.guard(errorBox, function () {
        return Shop.api.get('/products?per_page=50').then(function (res) {
            ui.clear(rows);
            (res.data || []).forEach(function (p) {
                var tr = ui.el('tr');

                var nameCell = ui.el('td');
                var link = ui.el('a', null, p.name);
                link.setAttribute('href', '/products/' + encodeURIComponent(String(p.id)));
                nameCell.appendChild(link);
                tr.appendChild(nameCell);

                tr.appendChild(ui.el('td', null, ui.money(p.price)));
                tr.appendChild(ui.el('td', null, ui.stockText(p.stock)));

                var qtyCell = ui.el('td');
                var input = ui.el('input');
                input.setAttribute('type', 'number');
                input.setAttribute('min', '0');
                input.setAttribute('max', '100');
                input.setAttribute('step', '1');
                input.setAttribute('aria-label', 'Quantity of ' + p.name);
                input.value = preselect !== null && String(p.id) === preselect ? '1' : '0';
                input.dataset.productId = String(p.id);
                qtyCell.appendChild(input);
                tr.appendChild(qtyCell);

                rows.appendChild(tr);
            });

            if (res.meta && res.meta.last_page > 1) {
                ui.byId('note').textContent = 'Showing the first 50 of ' + res.meta.total + ' products.';
            }
        });
    });

    ui.byId('form').addEventListener('submit', function (event) {
        event.preventDefault();

        var items = [];
        Array.prototype.forEach.call(rows.querySelectorAll('input'), function (input) {
            var quantity = Number(input.value);
            if (quantity > 0) {
                items.push({ product_id: Number(input.dataset.productId), quantity: quantity });
            }
        });

        if (items.length === 0) {
            ui.showNote(errorBox, 'Choose a quantity for at least one product.');
            return;
        }

        // 409 INSUFFICIENT_STOCK, 422 VALIDATION_FAILED and 503 INVENTORY_UNAVAILABLE are shown as the API sent them.
        ui.guard(errorBox, function () {
            return Shop.api.post('/orders', { items: items }).then(function (res) {
                window.location.assign('/orders/' + encodeURIComponent(String(res.data.id)));
            });
        });
    });
})(window.Shop);
