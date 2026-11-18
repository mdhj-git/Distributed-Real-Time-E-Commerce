(function (Shop) {
    'use strict';

    var ui = Shop.ui;
    var errorBox = ui.byId('error');
    var result = ui.byId('result');

    ui.guard(errorBox, function () {
        return ui.loadCategories(ui.byId('category_id'), false);
    });

    ui.byId('form').addEventListener('submit', function (event) {
        event.preventDefault();
        ui.clearError(result);

        // multipart over POST: uploads must never go through PUT or PATCH.
        var form = new FormData();
        form.append('name', ui.byId('name').value);
        var description = ui.byId('description').value;
        if (description !== '') {
            form.append('description', description);
        }
        form.append('price', ui.byId('price').value);
        form.append('category_id', ui.byId('category_id').value);
        var stock = ui.byId('initial_stock').value;
        if (stock !== '') {
            form.append('initial_stock', stock);
        }
        Array.prototype.forEach.call(ui.byId('images').files, function (file) {
            form.append('images[]', file);
        });

        ui.guard(errorBox, function () {
            return Shop.api.postForm('/products', form).then(function (res) {
                var p = res.data;
                ui.clear(result);
                result.hidden = false;
                result.className = 'ok';
                result.appendChild(ui.el('span', null, 'Product created (HTTP ' + res.status + '): '));
                var link = ui.el('a', null, p.name + ' #' + p.id);
                link.setAttribute('href', '/products/' + encodeURIComponent(String(p.id)));
                result.appendChild(link);
                result.appendChild(ui.el('span', null, ' - stock: ' + ui.stockText(p.stock)));
            });
        });
    });
})(window.Shop);
