/**
 * ALC Mock Server — simula le risposte AJAX del plugin WordPress e il PayPal SDK.
 *
 * Configurazione via query string:
 *   ?paypal=0          → disabilita PayPal (solo invio diretto)
 *   ?capture=0         → PayPal abilitato ma cattura non obbligatoria (submit sempre attivo)
 *   ?submit_error=1    → l'invio del form restituisce un errore
 *   ?paypal_error=1    → la cattura PayPal restituisce un errore
 *   ?delay=1500        → simula rete lenta (ms, default 700)
 *
 * Questo file deve essere caricato PRIMA di form.js.
 */

(function () {
    'use strict';

    /* ── Configurazione da URL params ─────────────────────────────────────── */
    const p = new URLSearchParams(window.location.search);
    const CFG = {
        paypal:        p.get('paypal')        !== '0',
        capture:       p.get('capture')       !== '0',
        submit_error:  p.get('submit_error')  === '1',
        paypal_error:  p.get('paypal_error')  === '1',
        delay:         Math.min(5000, Math.max(0, parseInt(p.get('delay') || '700', 10))),
    };

    /* ── Globals PHP→JS (equivalente a wp_localize_script) ───────────────── */
    window.ALC_VARS = {
        ajax:           '__mock_ajax__',
        nonce:          'test-nonce-alc-2800',
        fees: {
            romanzo_inedito:  25.00,
            romanzo_edito:    25.00,
            racconto_inedito: 20.00,
            poesia_inedita:   15.00,
        },
        currency:       'EUR',
        max_file_mb:    10,
        privacy_url:    '#privacy',
        regolamento_url:'#regolamento',
        success_url:    '#',
    };

    window.ALC_PAYPAL = {
        enabled:          CFG.paypal,
        capture_required: CFG.paypal && CFG.capture,
        mode:             'sandbox',
    };

    /* ── Logger ───────────────────────────────────────────────────────────── */
    function log(dir, action, payload) {
        const el = document.getElementById('alc-test-log');
        if (!el) return;
        const entry = document.createElement('div');
        const isErr = dir === 'error' || (payload && payload.ok === false);
        entry.className = 'tlog-entry tlog-' + (dir === 'req' ? 'req' : isErr ? 'err' : 'ok');
        const time = new Date().toLocaleTimeString('it-IT');
        const arrow = dir === 'req' ? '→' : '←';
        const detail = payload
            ? (payload.ok === false
                ? (payload.errors ? payload.errors.join(', ') : payload.error || 'ERROR')
                : 'OK ' + (payload.submission_id || payload.orderID || payload.captureID || ''))
            : '';
        entry.innerHTML =
            '<span class="tlog-time">' + time + '</span> ' +
            '<span class="tlog-arrow">' + arrow + '</span> ' +
            '<strong>' + action + '</strong>' +
            (detail ? ' <span class="tlog-detail">' + detail + '</span>' : '');
        el.insertBefore(entry, el.firstChild);
        // Tieni massimo 50 voci
        while (el.children.length > 50) el.removeChild(el.lastChild);
    }

    /* ── Utility ──────────────────────────────────────────────────────────── */
    function randId(prefix, len) {
        return prefix + Math.random().toString(36).substr(2, len || 8).toUpperCase();
    }
    function calcTotal(cats) {
        return cats.reduce(function (t, c) { return t + (window.ALC_VARS.fees[c] || 0); }, 0);
    }
    function mockResponse(data, delayMs) {
        return new Promise(function (resolve) {
            setTimeout(function () {
                resolve({
                    ok: true,
                    status: data.ok !== false ? 200 : 400,
                    json: function () { return Promise.resolve(data); },
                    text: function () { return Promise.resolve(JSON.stringify(data)); },
                });
            }, delayMs);
        });
    }

    /* ── Mock fetch ───────────────────────────────────────────────────────── */
    var _fetch = window.fetch;
    window.fetch = function (url, opts) {
        if (url !== '__mock_ajax__') return _fetch(url, opts);

        var body   = opts && opts.body;
        var action = body instanceof FormData ? body.get('action') : '';
        log('req', action, null);

        var cats = (body instanceof FormData) ? body.getAll('categorie[]') : [];
        var resp;

        switch (action) {

            case 'alc_submit':
                if (CFG.submit_error) {
                    resp = { ok: false, errors: ['Errore simulato: impossibile salvare l\'iscrizione.'] };
                } else {
                    var subId = randId('ALC-', 10);
                    resp = {
                        ok: true,
                        submission_id: subId,
                        post_id: 9999,
                        total: calcTotal(cats),
                        currency: 'EUR',
                        per_category: {},
                    };
                }
                break;

            case 'alc_paypal_create_order':
                var total = calcTotal(cats);
                resp = {
                    ok: true,
                    orderID: randId('MOCK-ORD-'),
                    amount: total.toFixed(2),
                    currency: 'EUR',
                    provisional_submission_id: randId('ALC-PROV-', 8),
                };
                break;

            case 'alc_paypal_capture_order':
                if (CFG.paypal_error) {
                    resp = { ok: false, error: 'Pagamento non completato (errore simulato).' };
                } else {
                    resp = {
                        ok: true,
                        status: 'COMPLETED',
                        orderID: (body instanceof FormData) ? body.get('orderID') : '',
                        captureID: randId('MOCK-CAP-'),
                        payer_email: 'acquirente.test@paypal.com',
                        amount: '0.00', // form.js legge paypal_amount dal campo hidden
                        currency: 'EUR',
                    };
                }
                break;

            default:
                resp = { ok: false, error: 'Azione non riconosciuta: ' + action };
        }

        log('res', action, resp);
        return mockResponse(resp, CFG.delay);
    };

    /* ── Mock PayPal SDK ──────────────────────────────────────────────────── */
    if (CFG.paypal) {
        window.paypal = {
            Buttons: function (config) {
                return {
                    render: function (container) {
                        if (!container) return;
                        container.innerHTML = '';

                        var wrap = document.createElement('div');
                        wrap.className = 'alc-mock-paypal';

                        var btn = document.createElement('button');
                        btn.type = 'button';
                        btn.className = 'alc-mock-paypal-btn';
                        btn.disabled = true;
                        btn.innerHTML =
                            '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" style="vertical-align:middle;margin-right:6px">' +
                            '<circle cx="12" cy="12" r="12" fill="#009cde"/>' +
                            '<path d="M8 16h2l.5-3h2c2 0 3.5-1 4-3 .3-1.3-.3-2-1.5-2H10L8 16z" fill="#fff"/>' +
                            '</svg>Paga con PayPal <small style="opacity:.7">(mock)</small>';

                        var note = document.createElement('p');
                        note.style.cssText = 'font-size:11px;color:#6b7280;margin:4px 0 0;text-align:center';
                        note.textContent = 'Sandbox simulato — nessun addebito reale.';

                        wrap.appendChild(btn);
                        wrap.appendChild(note);
                        container.appendChild(wrap);

                        /* Azioni controllate da onInit */
                        var actions = {
                            enable:  function () { btn.disabled = false; btn.style.opacity = '1'; },
                            disable: function () { btn.disabled = true;  btn.style.opacity = '.5'; },
                        };
                        if (config.onInit) config.onInit({}, actions);

                        btn.addEventListener('click', async function () {
                            var origHtml = btn.innerHTML;
                            btn.disabled = true;
                            btn.style.opacity = '.7';
                            btn.textContent = 'Connessione PayPal…';
                            try {
                                var orderID = await config.createOrder();
                                btn.textContent = 'Elaborazione pagamento…';
                                // Breve pausa per simulare il dialogo PayPal
                                await new Promise(function (r) { setTimeout(r, 500); });
                                await config.onApprove({ orderID: orderID });
                            } catch (err) {
                                if (config.onError) config.onError(err);
                                btn.innerHTML = origHtml;
                                btn.disabled = false;
                                btn.style.opacity = '1';
                            }
                        });
                    },
                };
            },
        };
    }

    /* ── Popola il pannello config dopo il DOM ────────────────────────────── */
    document.addEventListener('DOMContentLoaded', function () {
        var el = document.getElementById('alc-test-config');
        if (!el) return;
        el.innerHTML =
            row('PayPal',          CFG.paypal        ? 'abilitato'           : '<span style="color:#b32d2e">disabilitato</span>') +
            row('Cattura richiesta', CFG.capture && CFG.paypal ? 'sì' : '<span style="color:#b32d2e">no</span>') +
            row('Errore invio',    CFG.submit_error  ? '<span style="color:#b32d2e">sì</span>' : 'no') +
            row('Errore PayPal',   CFG.paypal_error  ? '<span style="color:#b32d2e">sì</span>' : 'no') +
            row('Delay rete',      CFG.delay + ' ms');
    });

    function row(k, v) {
        return '<div class="cfg-row"><span class="cfg-key">' + k + '</span><span class="cfg-val">' + v + '</span></div>';
    }

})();
