(function (Shop) {
    'use strict';

    var ui = Shop.ui;
    var errorBox = ui.byId('error');
    var result = ui.byId('result');
    var me = ui.byId('me');

    function fact(label, value) {
        me.appendChild(ui.el('dt', null, label));
        me.appendChild(ui.el('dd', null, value));
    }

    function load() {
        return ui.guard(errorBox, function () {
            return Shop.api.get('/auth/me').then(function (res) {
                var u = res.data;
                ui.clear(me);
                fact('Id', u.id);
                fact('Name', u.name);
                fact('Email', u.email);
                fact('Role', u.role);
                fact('Registered', ui.dateTime(u.created_at));
            });
        });
    }

    ui.byId('refresh').addEventListener('click', function () {
        ui.guard(result, function () {
            return Shop.api.post('/auth/refresh').then(function (res) {
                Shop.auth.setToken(res.data.token);
                ui.showOk(result, 'The token was refreshed. The old one is now invalid.');
            });
        });
    });

    load();
})(window.Shop);
