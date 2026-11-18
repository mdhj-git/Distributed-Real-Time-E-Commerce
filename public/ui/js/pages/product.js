(function (Shop) {
    'use strict';

    var ui = Shop.ui;
    var errorBox = ui.byId('error');
    var id = ui.idFromPath();

    function fact(dl, label, value) {
        dl.appendChild(ui.el('dt', null, label));
        dl.appendChild(ui.el('dd', null, value));
    }

    ui.guard(errorBox, function () {
        return Shop.api.get('/products/' + encodeURIComponent(id)).then(function (res) {
            var p = res.data;
            var box = ui.byId('product');
            ui.clear(box);

            box.appendChild(ui.el('h2', null, p.name));
            var dl = ui.el('dl', 'facts');
            fact(dl, 'Price', ui.money(p.price));
            fact(dl, 'Category', p.category && p.category.name ? p.category.name : '');
            fact(dl, 'In stock', ui.stockText(p.stock));
            fact(dl, 'Description', p.description || '');
            fact(dl, 'Updated', ui.dateTime(p.updated_at));
            box.appendChild(dl);

            var gallery = ui.byId('gallery');
            ui.clear(gallery);
            (p.images || []).forEach(function (image) {
                var src = ui.safeUrl(image.url);
                if (!src) {
                    return;
                }
                var img = ui.el('img');
                img.setAttribute('src', src);
                img.setAttribute('alt', p.name);
                gallery.appendChild(img);
            });

            var actions = ui.byId('actions');
            ui.clear(actions);
            var user = Shop.auth.user();
            var order = ui.el('a', 'btn', 'Order this product');
            order.setAttribute('href', '/orders/new?product_id=' + encodeURIComponent(String(p.id)));
            actions.appendChild(order);
            if (user && user.role === 'admin') {
                actions.appendChild(document.createTextNode(' '));
                var edit = ui.el('a', 'btn', 'Edit (admin)');
                edit.setAttribute('href', '/admin/products/' + encodeURIComponent(String(p.id)) + '/edit');
                actions.appendChild(edit);
            }
        });
    });
})(window.Shop);
