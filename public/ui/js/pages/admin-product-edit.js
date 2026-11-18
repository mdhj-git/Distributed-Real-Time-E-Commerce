(function (Shop) {
    'use strict';

    var ui = Shop.ui;
    var errorBox = ui.byId('error');
    var id = ui.idFromPath();
    var base = '/products/' + encodeURIComponent(id);

    function fact(dl, label, value) {
        dl.appendChild(ui.el('dt', null, label));
        dl.appendChild(ui.el('dd', null, value));
    }

    function showProduct(p) {
        var dl = ui.byId('current');
        ui.clear(dl);
        fact(dl, 'Id', p.id);
        fact(dl, 'Name', p.name);
        fact(dl, 'Price', ui.money(p.price));
        fact(dl, 'Category', p.category && p.category.name ? p.category.name : '');
        fact(dl, 'In stock', ui.stockText(p.stock));
        fact(dl, 'Updated', ui.dateTime(p.updated_at));

        var gallery = ui.byId('gallery');
        ui.clear(gallery);
        (p.images || []).forEach(function (image) {
            var src = ui.safeUrl(image.url);
            if (src) {
                var img = ui.el('img');
                img.setAttribute('src', src);
                img.setAttribute('alt', p.name);
                gallery.appendChild(img);
            }
        });
    }

    function fillForm(p) {
        ui.byId('name').value = p.name || '';
        ui.byId('description').value = p.description || '';
        ui.byId('price').value = p.price !== undefined ? String(p.price) : '';
        if (p.category && p.category.id !== undefined) {
            ui.byId('category_id').value = String(p.category.id);
        }
    }

    function load() {
        return Shop.api.get(base).then(function (res) {
            showProduct(res.data);
            return res.data;
        });
    }

    ui.guard(errorBox, function () {
        return ui
            .loadCategories(ui.byId('category_id'), false)
            .then(load)
            .then(fillForm);
    });

    // Edit details: JSON over PATCH.
    ui.byId('edit-form').addEventListener('submit', function (event) {
        event.preventDefault();
        var out = ui.byId('edit-result');
        var body = {
            name: ui.byId('name').value,
            description: ui.byId('description').value,
            price: ui.byId('price').value,
            category_id: Number(ui.byId('category_id').value)
        };
        ui.guard(out, function () {
            return Shop.api.patch(base, body).then(function (res) {
                ui.showOk(out, 'Saved (HTTP ' + res.status + ').');
                return load();
            });
        });
    });

    // Stock: a signed difference, JSON over PATCH.
    ui.byId('stock-form').addEventListener('submit', function (event) {
        event.preventDefault();
        var out = ui.byId('stock-result');
        var raw = ui.byId('adjustment').value;
        ui.guard(out, function () {
            return Shop.api.patch(base + '/stock', { adjustment: raw === '' ? null : Number(raw) }).then(function (res) {
                showProduct(res.data);
                ui.showOk(out, 'Stock updated (HTTP ' + res.status + '). It is now ' + ui.stockText(res.data.stock) + '.');
            });
        });
    });

    // Images: multipart over POST only.
    ui.byId('images-form').addEventListener('submit', function (event) {
        event.preventDefault();
        var out = ui.byId('images-result');
        var form = new FormData();
        Array.prototype.forEach.call(ui.byId('images').files, function (file) {
            form.append('images[]', file);
        });
        ui.guard(out, function () {
            return Shop.api.postForm(base + '/images', form).then(function (res) {
                ui.showOk(out, 'Uploaded (HTTP ' + res.status + ').');
                return load();
            });
        });
    });

    ui.byId('delete').addEventListener('click', function () {
        var out = ui.byId('delete-result');
        if (!window.confirm('Delete this product?')) {
            return;
        }
        ui.guard(out, function () {
            return Shop.api.del(base).then(function (res) {
                ui.clear(out);
                out.hidden = false;
                out.className = 'ok';
                out.appendChild(ui.el('span', null, 'Deleted (HTTP ' + res.status + '). '));
                var link = ui.el('a', null, 'Back to the products');
                link.setAttribute('href', '/products');
                out.appendChild(link);
            });
        });
    });
})(window.Shop);
