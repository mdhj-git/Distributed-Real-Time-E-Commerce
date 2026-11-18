(function (Shop) {
    'use strict';

    var ui = Shop.ui;
    var errorBox = ui.byId('error');
    var query = ui.query();
    var category = query.get('category_id') || '';
    var search = query.get('search') || '';
    var page = Number(query.get('page')) || 1;

    var select = ui.byId('category');
    var searchInput = ui.byId('search');
    searchInput.value = search;

    function hrefFor(targetPage) {
        var p = new URLSearchParams();
        if (category) {
            p.set('category_id', category);
        }
        if (search) {
            p.set('search', search);
        }
        p.set('page', String(targetPage));
        return '/products?' + p.toString();
    }

    ui.byId('filter').addEventListener('submit', function (event) {
        event.preventDefault();
        var p = new URLSearchParams();
        if (select.value) {
            p.set('category_id', select.value);
        }
        if (searchInput.value.trim()) {
            p.set('search', searchInput.value.trim());
        }
        window.location.assign('/products' + (p.toString() ? '?' + p.toString() : ''));
    });

    function card(product) {
        var box = ui.el('div', 'card');

        var first = product.images && product.images.length ? ui.safeUrl(product.images[0].url) : '';
        if (first) {
            var img = ui.el('img');
            img.setAttribute('src', first);
            img.setAttribute('alt', product.name);
            img.setAttribute('loading', 'lazy');
            box.appendChild(img);
        }

        var title = ui.el('h3');
        var link = ui.el('a', null, product.name);
        link.setAttribute('href', '/products/' + encodeURIComponent(String(product.id)));
        title.appendChild(link);
        box.appendChild(title);

        box.appendChild(ui.el('div', null, ui.money(product.price)));
        if (product.category && product.category.name) {
            box.appendChild(ui.el('div', 'muted', product.category.name));
        }
        box.appendChild(ui.el('div', 'muted', 'In stock: ' + ui.stockText(product.stock)));
        return box;
    }

    function load() {
        var params = new URLSearchParams();
        if (category) {
            params.set('category_id', category);
        }
        if (search) {
            params.set('search', search);
        }
        params.set('page', String(page));
        params.set('per_page', '12');

        return Shop.api.get('/products?' + params.toString()).then(function (res) {
            var list = ui.byId('list');
            ui.clear(list);
            (res.data || []).forEach(function (p) {
                list.appendChild(card(p));
            });
            if (!res.data || res.data.length === 0) {
                list.appendChild(ui.el('p', 'muted', 'No products found.'));
            }
            ui.pager(ui.byId('pager'), res.meta, hrefFor);
        });
    }

    ui.guard(errorBox, function () {
        return ui
            .loadCategories(select, true)
            .then(function () {
                select.value = category;
            })
            .then(load);
    });
})(window.Shop);
