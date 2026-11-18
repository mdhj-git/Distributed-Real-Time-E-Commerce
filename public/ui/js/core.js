/*
 * Shared code of the web pages: API client, session (sessionStorage) and small DOM helpers.
 *
 * Rules (docs/PROJECT_RULES.md, section 13): data from the API is only ever written with textContent or
 * as an attribute, never with innerHTML. Every failed call is shown with its HTTP status, error.code and
 * error.message. No library, no CDN.
 */
(function () {
    'use strict';

    var API_BASE = '/api/v1';
    var TOKEN_KEY = 'shop.token';
    var USER_KEY = 'shop.user';

    var Shop = (window.Shop = {});

    // ---- session -------------------------------------------------------------------------------------------

    // sessionStorage is per tab, so an admin and a customer can be signed in side by side in two tabs.
    // Every access is wrapped: storage can be blocked by the browser.
    function safe(fn, fallback) {
        try {
            return fn();
        } catch (e) {
            return fallback;
        }
    }

    Shop.auth = {
        getToken: function () {
            return safe(function () { return sessionStorage.getItem(TOKEN_KEY); }, null);
        },
        setToken: function (token) {
            safe(function () { sessionStorage.setItem(TOKEN_KEY, token); });
        },
        user: function () {
            return safe(function () {
                var raw = sessionStorage.getItem(USER_KEY);
                return raw ? JSON.parse(raw) : null;
            }, null);
        },
        setSession: function (token, user) {
            safe(function () {
                sessionStorage.setItem(TOKEN_KEY, token);
                sessionStorage.setItem(USER_KEY, JSON.stringify({ id: user.id, name: user.name, role: user.role }));
            });
        },
        clear: function () {
            safe(function () {
                sessionStorage.removeItem(TOKEN_KEY);
                sessionStorage.removeItem(USER_KEY);
            });
        },
        isAdmin: function () {
            var u = Shop.auth.user();
            return !!u && u.role === 'admin';
        }
    };

    // ---- API client ----------------------------------------------------------------------------------------

    function ApiError(status, code, message, details, requestId, retryAfter) {
        this.status = status;
        this.code = code;
        this.message = message;
        this.details = details || {};
        this.requestId = requestId || null;
        this.retryAfter = retryAfter || null;
    }
    ApiError.prototype = Object.create(Error.prototype);
    ApiError.prototype.name = 'ApiError';
    Shop.ApiError = ApiError;

    /**
     * request(method, path, { json, form, anonymous })
     * Resolves { status, body, data, meta, headers }. Rejects with ApiError for any non-2xx answer.
     * "form" is a FormData (the browser sets the multipart boundary itself); uploads are POST only.
     */
    function request(method, path, opts) {
        opts = opts || {};
        var headers = { Accept: 'application/json' };
        var token = Shop.auth.getToken();
        if (token && !opts.anonymous) {
            headers.Authorization = 'Bearer ' + token;
        }
        var body;
        if (opts.form) {
            body = opts.form;
        } else if (opts.json !== undefined) {
            headers['Content-Type'] = 'application/json';
            body = JSON.stringify(opts.json);
        }

        return fetch(API_BASE + path, { method: method, headers: headers, body: body })
            .catch(function () {
                throw new ApiError(0, 'NETWORK_ERROR', 'The server could not be reached.', {}, null, null);
            })
            .then(function (res) {
                return res.text().then(function (text) {
                    var parsed = null;
                    if (text) {
                        try {
                            parsed = JSON.parse(text);
                        } catch (e) {
                            parsed = null;
                        }
                    }
                    if (!res.ok) {
                        var err = parsed && parsed.error ? parsed.error : {};
                        throw new ApiError(
                            res.status,
                            err.code || 'HTTP_ERROR',
                            err.message || res.statusText || 'The request failed.',
                            err.details,
                            err.request_id || res.headers.get('X-Request-Id'),
                            res.headers.get('Retry-After')
                        );
                    }
                    return {
                        status: res.status,
                        body: parsed,
                        data: parsed && 'data' in parsed ? parsed.data : null,
                        meta: parsed && parsed.meta ? parsed.meta : null,
                        headers: res.headers
                    };
                });
            });
    }

    Shop.api = {
        request: request,
        get: function (path) { return request('GET', path); },
        post: function (path, json) { return request('POST', path, { json: json }); },
        postForm: function (path, form) { return request('POST', path, { form: form }); },
        patch: function (path, json) { return request('PATCH', path, { json: json }); },
        del: function (path) { return request('DELETE', path); }
    };

    // ---- DOM helpers ---------------------------------------------------------------------------------------

    var ui = (Shop.ui = {});

    ui.el = function (tag, className, text) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (text !== undefined && text !== null) {
            node.textContent = String(text);
        }
        return node;
    };

    ui.clear = function (node) {
        while (node.firstChild) {
            node.removeChild(node.firstChild);
        }
    };

    ui.byId = function (id) {
        return document.getElementById(id);
    };

    ui.query = function () {
        return new URLSearchParams(window.location.search);
    };

    // Last numeric segment of the path, for /products/5 and /orders/12 and /admin/products/7/edit.
    ui.idFromPath = function () {
        var parts = window.location.pathname.split('/');
        for (var i = parts.length - 1; i >= 0; i--) {
            if (/^[0-9]+$/.test(parts[i])) {
                return parts[i];
            }
        }
        return null;
    };

    ui.money = function (value) {
        return Number(value).toFixed(2);
    };

    ui.dateTime = function (iso) {
        if (!iso) {
            return '';
        }
        var d = new Date(iso);
        return isNaN(d.getTime()) ? '' : d.toLocaleString();
    };

    var STATUSES = ['pending', 'confirmed', 'shipped', 'delivered', 'cancelled'];

    ui.statusBadge = function (status) {
        var known = STATUSES.indexOf(status) !== -1;
        return ui.el('span', 'badge' + (known ? ' s-' + status : ''), status);
    };

    /**
     * Shows an error in "box": HTTP status, error.code, error.message, field details, Retry-After and the
     * request id. Everything is textContent.
     */
    ui.showError = function (box, err) {
        if (!(err instanceof ApiError)) {
            err = new ApiError(0, 'CLIENT_ERROR', err && err.message ? err.message : 'Unexpected error.', {}, null, null);
        }
        ui.clear(box);
        box.hidden = false;
        box.className = 'error';
        box.setAttribute('role', 'alert');

        box.appendChild(ui.el('strong', null, (err.status ? 'HTTP ' + err.status : 'No response') + ' · ' + err.code));
        box.appendChild(ui.el('div', null, err.message));

        Object.keys(err.details).forEach(function (field) {
            var value = err.details[field];
            var text = Array.isArray(value) ? value.join(' ') : typeof value === 'object' ? JSON.stringify(value) : String(value);
            box.appendChild(ui.el('div', 'detail', field + ': ' + text));
        });
        if (err.retryAfter) {
            box.appendChild(ui.el('div', 'detail', 'Retry after ' + err.retryAfter + ' seconds.'));
        }
        if (err.requestId) {
            box.appendChild(ui.el('div', 'detail', 'Request id: ' + err.requestId));
        }
    };

    ui.clearError = function (box) {
        ui.clear(box);
        box.hidden = true;
    };

    // A message that does not come from an API call (for example "choose at least one product").
    ui.showNote = function (box, text) {
        ui.clear(box);
        box.hidden = false;
        box.className = 'error';
        box.setAttribute('role', 'alert');
        box.appendChild(ui.el('span', null, text));
    };

    // Only http(s) addresses and same-site paths are ever put into an href or src.
    ui.safeUrl = function (url) {
        return typeof url === 'string' && /^(https?:\/\/|\/)/.test(url) ? url : '';
    };

    ui.showOk = function (box, text) {
        ui.clear(box);
        box.hidden = false;
        box.className = 'ok';
        box.setAttribute('role', 'status');
        box.appendChild(ui.el('span', null, text));
    };

    /** Clears "box", runs a promise-returning function and shows its failure in "box". */
    ui.guard = function (box, fn) {
        ui.clearError(box);
        return Promise.resolve()
            .then(fn)
            .catch(function (err) {
                ui.showError(box, err);
                return undefined;
            });
    };

    // Previous / next links. hrefFor(page) returns the address of that page.
    ui.pager = function (box, meta, hrefFor) {
        ui.clear(box);
        if (!meta) {
            return;
        }
        box.appendChild(ui.el('span', null, 'Page ' + meta.current_page + ' of ' + meta.last_page + ' (' + meta.total + ' in total) '));
        if (meta.current_page > 1) {
            var prev = ui.el('a', 'btn', 'Previous');
            prev.setAttribute('href', hrefFor(meta.current_page - 1));
            box.appendChild(prev);
        }
        if (meta.current_page < meta.last_page) {
            var next = ui.el('a', 'btn', 'Next');
            next.setAttribute('href', hrefFor(meta.current_page + 1));
            box.appendChild(next);
        }
    };

    // Fills a <select> with the categories from the API.
    ui.loadCategories = function (select, withAll) {
        return Shop.api.get('/categories').then(function (res) {
            ui.clear(select);
            if (withAll) {
                var all = ui.el('option', null, 'All categories');
                all.value = '';
                select.appendChild(all);
            }
            (res.data || []).forEach(function (c) {
                var o = ui.el('option', null, c.name);
                o.value = String(c.id);
                select.appendChild(o);
            });
        });
    };

    ui.stockText = function (stock) {
        return stock === null || stock === undefined ? 'inventory unavailable' : String(stock);
    };

    // ---- navigation ----------------------------------------------------------------------------------------

    function link(text, href) {
        var a = ui.el('a', null, text);
        a.setAttribute('href', href);
        return a;
    }

    ui.renderNav = function () {
        var nav = ui.byId('nav');
        if (!nav) {
            return;
        }
        ui.clear(nav);

        var user = Shop.auth.user();
        var links = ui.el('div', 'nav-links');
        links.appendChild(link('Home', '/'));
        links.appendChild(link('Products', '/products'));

        if (!user) {
            links.appendChild(link('Login', '/login'));
            links.appendChild(link('Register', '/register'));
        } else if (user.role === 'admin') {
            links.appendChild(link('Live orders', '/admin/orders'));
            links.appendChild(link('All orders', '/orders'));
            links.appendChild(link('New product', '/admin/products/new'));
            links.appendChild(link('Notifications', '/notifications'));
            links.appendChild(link('Account', '/account'));
        } else {
            links.appendChild(link('New order', '/orders/new'));
            links.appendChild(link('My orders', '/orders'));
            links.appendChild(link('Notifications', '/notifications'));
            links.appendChild(link('Account', '/account'));
        }
        nav.appendChild(links);

        var who = ui.el('div', 'nav-user');
        if (user) {
            who.appendChild(ui.el('span', 'role role-' + (user.role === 'admin' ? 'admin' : 'customer'), user.role));
            who.appendChild(ui.el('span', null, ' ' + user.name + ' '));
            var out = ui.el('button', 'btn', 'Logout');
            out.setAttribute('type', 'button');
            out.addEventListener('click', ui.logout);
            who.appendChild(out);
        } else {
            who.appendChild(ui.el('span', 'role role-guest', 'not signed in'));
        }
        nav.appendChild(who);
    };

    // Logout: tells the API (the token goes on the blacklist), then forgets it. The token is dropped even when
    // the call fails, for example with a 401 because it had already expired.
    ui.logout = function () {
        var box = ui.byId('error');
        Shop.api
            .post('/auth/logout')
            .catch(function (err) {
                if (box) {
                    ui.showError(box, err);
                }
            })
            .then(function () {
                var failed = box && !box.hidden;
                Shop.auth.clear();
                if (failed) {
                    ui.renderNav();
                } else {
                    window.location.assign('/login');
                }
            });
    };

    document.addEventListener('DOMContentLoaded', ui.renderNav);
})();
