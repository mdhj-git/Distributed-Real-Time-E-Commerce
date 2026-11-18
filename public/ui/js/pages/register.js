(function (Shop) {
    'use strict';

    var ui = Shop.ui;
    var errorBox = ui.byId('error');

    ui.byId('form').addEventListener('submit', function (event) {
        event.preventDefault();

        var body = {
            name: ui.byId('name').value,
            email: ui.byId('email').value,
            password: ui.byId('password').value,
            password_confirmation: ui.byId('password_confirmation').value
        };

        ui.guard(errorBox, function () {
            return Shop.api.request('POST', '/auth/register', { json: body, anonymous: true }).then(function (res) {
                Shop.auth.setSession(res.data.token, res.data.user);
                window.location.assign('/products');
            });
        });
    });
})(window.Shop);
