/*
 * Live updates through Laravel Reverb, using the local copy of pusher-js (/ui/vendor/pusher.min.js).
 *
 * The browser only talks to the page's own host and port: nginx proxies /app to the reverb service.
 * Joining a private channel needs a signature from POST /api/v1/broadcasting/auth. That call is made here with
 * fetch (through Shop.api) and the Bearer token, so the API checks identity and role first, and a refusal
 * (401 or 403) is shown on the page with its status, code and message.
 */
(function (Shop) {
    'use strict';

    var pusher = null;
    var authErrorHandlers = {};

    function publicKey() {
        var meta = document.querySelector('meta[name="reverb-key"]');
        return meta ? meta.getAttribute('content') : '';
    }

    // pusher-js calls this for every private channel it joins.
    function authorize(params, callback) {
        Shop.api
            .request('POST', '/broadcasting/auth', {
                json: { socket_id: params.socketId, channel_name: params.channelName }
            })
            .then(function (res) {
                callback(null, res.body);
            })
            .catch(function (err) {
                var handler = authErrorHandlers[params.channelName];
                if (handler) {
                    handler(err);
                }
                callback(err, null);
            });
    }

    function connect() {
        if (pusher) {
            return pusher;
        }
        var secure = window.location.protocol === 'https:';
        var port = Number(window.location.port) || (secure ? 443 : 80);

        pusher = new window.Pusher(publicKey(), {
            cluster: 'local',
            wsHost: window.location.hostname,
            wsPort: port,
            wssPort: port,
            forceTLS: secure,
            enabledTransports: ['ws', 'wss'],
            disableStats: true,
            channelAuthorization: {
                transport: 'ajax',
                endpoint: '/api/v1/broadcasting/auth',
                customHandler: authorize
            }
        });
        return pusher;
    }

    /**
     * subscribe(channelName, { statusEl, errorEl, events: { name: fn }, onSubscribed })
     * channelName is the wire name, for example "private-orders.12".
     */
    function subscribe(channelName, opts) {
        var statusEl = opts.statusEl;

        function setStatus(text, kind) {
            statusEl.textContent = text;
            statusEl.className = 'live ' + kind;
        }

        var client;
        try {
            client = connect();
        } catch (e) {
            setStatus('Live updates: unavailable (' + e.message + ')', 'bad');
            return null;
        }

        authErrorHandlers[channelName] = function (err) {
            setStatus('Live updates: not subscribed', 'bad');
            if (opts.errorEl) {
                Shop.ui.showError(opts.errorEl, err);
            }
        };

        var channel = client.subscribe(channelName);

        channel.bind('pusher:subscription_succeeded', function () {
            setStatus('Live updates: subscribed to ' + channelName, 'ok');
            if (opts.errorEl) {
                Shop.ui.clearError(opts.errorEl);
            }
            if (opts.onSubscribed) {
                opts.onSubscribed();
            }
        });

        Object.keys(opts.events || {}).forEach(function (name) {
            channel.bind(name, opts.events[name]);
        });

        client.connection.bind('state_change', function (states) {
            if (states.current !== 'connected') {
                setStatus('Live updates: connection ' + states.current, 'warn');
            }
        });

        setStatus('Live updates: connecting...', 'warn');
        return channel;
    }

    Shop.realtime = { subscribe: subscribe };
})(window.Shop);
