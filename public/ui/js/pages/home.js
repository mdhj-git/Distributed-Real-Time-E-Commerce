(function (Shop) {
    'use strict';

    var ui = Shop.ui;
    var health = ui.byId('health');

    Shop.api
        .get('/health')
        .then(function (res) {
            health.textContent = 'API status: ' + (res.data && res.data.status ? res.data.status : 'unknown');
        })
        .catch(function (err) {
            health.textContent = 'API status: unreachable';
            ui.showError(ui.byId('error'), err);
        });
})(window.Shop);
