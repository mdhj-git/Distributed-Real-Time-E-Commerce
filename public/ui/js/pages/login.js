(function (Shop) {
    'use strict';

    var ui = Shop.ui;
    var errorBox = ui.byId('error');

    ui.byId('form').addEventListener('submit', function (event) {
        event.preventDefault();

        var body = {
            email: ui.byId('email').value,
            password: ui.byId('password').value
        };

        // Wrong credentials give 401; more than 5 tries a minute give 429 with Retry-After. Both are shown as sent.
        ui.guard(errorBox, function () {
            return Shop.api.request('POST', '/auth/login', { json: body, anonymous: true }).then(function (res) {
                Shop.auth.setSession(res.data.token, res.data.user);
                window.location.assign(res.data.user.role === 'admin' ? '/admin/orders' : '/products');
            });
        });
    });
})(window.Shop);
