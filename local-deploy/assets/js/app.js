/**
 * Payroll System - Shared JS layer
 * Theme toggle, toasts, confirm modal, AJAX form layer, live payroll math,
 * and DTR keyboard shortcuts. Progressive enhancement only: every page works
 * without JavaScript.
 */
(function () {
    'use strict';

    const PAYROLL = {};

    /* ===== Theme ===== */
    function systemDark() {
        return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
    }

    function applyTheme() {
        let pref = localStorage.getItem('payroll-theme');
        if (pref !== 'light' && pref !== 'dark') {
            pref = systemDark() ? 'dark' : 'light';
        }
        document.documentElement.setAttribute('data-theme', pref);
        document.documentElement.setAttribute('data-bs-theme', pref);
        const icon = document.getElementById('themeIcon');
        if (icon) {
            icon.className = pref === 'dark' ? 'bi bi-moon-stars' : 'bi bi-sun';
        }
        return pref;
    }

    function toggleTheme() {
        const next = applyTheme() === 'dark' ? 'light' : 'dark';
        localStorage.setItem('payroll-theme', next);
        applyTheme();
    }

    /* ===== Toast ===== */
    const TOAST_ICONS = {
        success: 'bi-check-circle-fill',
        danger: 'bi-exclamation-triangle-fill',
        warning: 'bi-exclamation-circle-fill',
        info: 'bi-info-circle-fill'
    };

    PAYROLL.toast = function (type, msg) {
        let cont = document.getElementById('toastContainer');
        if (!cont) {
            cont = document.createElement('div');
            cont.id = 'toastContainer';
            cont.className = 'toast-container';
            document.body.appendChild(cont);
        }
        const el = document.createElement('div');
        el.className = 'toast-item toast-' + (TOAST_ICONS[type] ? type : 'info');
        const icon = TOAST_ICONS[type] || TOAST_ICONS.info;
        el.innerHTML = '<i class="bi ' + icon + '"></i><span></span><button type="button" class="btn-close" aria-label="Close"></button>';
        el.querySelector('span').textContent = msg;
        cont.appendChild(el);
        requestAnimationFrame(function () {
            el.classList.add('show');
        });
        let closed = false;
        const close = function () {
            if (closed) return;
            closed = true;
            el.classList.remove('show');
            setTimeout(function () { el.remove(); }, 300);
        };
        el.querySelector('.btn-close').addEventListener('click', close);
        setTimeout(close, 4500);
    };

    /* ===== Confirm modal ===== */
    PAYROLL.confirm = function (message) {
        return new Promise(function (resolve) {
            const wrap = document.createElement('div');
            wrap.className = 'modal fade';
            wrap.tabIndex = -1;
            wrap.setAttribute('aria-hidden', 'true');
            wrap.innerHTML =
                '<div class="modal-dialog modal-dialog-centered">' +
                '  <div class="modal-content">' +
                '    <div class="modal-body pt-4 pb-2 text-center">' +
                '      <div class="mb-2" style="font-size:34px;color:var(--secondary-color)"><i class="bi bi-exclamation-triangle"></i></div>' +
                '      <p class="mb-0 fw-semibold">' + (message || 'Are you sure?') + '</p>' +
                '    </div>' +
                '    <div class="modal-footer border-0 justify-content-center pb-4">' +
                '      <button type="button" class="btn btn-outline-secondary px-4" data-act="no">Cancel</button>' +
                '      <button type="button" class="btn btn-danger px-4" data-act="yes">Confirm</button>' +
                '    </div>' +
                '  </div>' +
                '</div>';
            document.body.appendChild(wrap);
            const modal = new bootstrap.Modal(wrap, { backdrop: 'static' });
            let done = false;
            const finish = function (val) {
                if (done) return;
                done = true;
                modal.hide();
                wrap.addEventListener('hidden.bs.modal', function () { wrap.remove(); });
                resolve(val);
            };
            wrap.querySelector('[data-act="yes"]').addEventListener('click', function () { finish(true); });
            wrap.querySelector('[data-act="no"]').addEventListener('click', function () { finish(false); });
            wrap.addEventListener('hidden.bs.modal', function () {
                if (!done) finish(false);
            });
            modal.show();
        });
    };

    /* ===== AJAX content swap ===== */
    function closeOpenModals() {
        document.querySelectorAll('.modal.show').forEach(function (m) {
            const inst = bootstrap.Modal.getInstance(m);
            if (inst) inst.hide();
        });
    }

    async function ajaxSubmit(form) {
        // Use getAttribute() here: a form field named "action" or "method"
        // (e.g. <input name="action" value="save">) shadows the form.action /
        // form.method DOM properties, which made fetch POST to
        // "/[object HTMLInputElement]" and fail every AJAX save.
        const url = form.getAttribute('action') || window.location.href;
        const method = (form.getAttribute('method') || 'POST').toUpperCase();
        const btn = form.querySelector('[type="submit"]');
        const orig = btn ? btn.innerHTML : '';
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Saving...';
        }
        closeOpenModals();
        try {
            const resp = await fetch(url, {
                method: method,
                body: new FormData(form),
                headers: { 'X-Requested-With': 'fetch' },
                credentials: 'same-origin'
            });
            const html = await resp.text();
            const doc = new DOMParser().parseFromString(html, 'text/html');
            const main = doc.getElementById('app-content');
            if (!main) {
                throw new Error('bad-response');
            }
            const cur = document.getElementById('app-content');
            if (cur) {
                cur.innerHTML = main.innerHTML;
            }
            if (resp.status >= 400) {
                PAYROLL.toast('danger', 'Request failed (HTTP ' + resp.status + ').');
            }
            PAYROLL.init();
            if (window.scrollTo) {
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }
        } catch (e) {
            PAYROLL.toast('danger', 'Could not save. Please check your connection and try again.');
        } finally {
            if (btn && btn.isConnected) {
                btn.disabled = false;
                btn.innerHTML = orig;
            }
        }
    }

    function bindForm(form) {
        if (form.dataset.ajaxBound) return;
        form.dataset.ajaxBound = '1';
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            const doSubmit = function () { ajaxSubmit(form); };
            if (form.dataset.confirm) {
                PAYROLL.confirm(form.dataset.confirm).then(function (ok) {
                    if (ok) doSubmit();
                });
            } else {
                doSubmit();
            }
        });
    }

    /* ===== Convert flash alerts into toasts ===== */
    function convertFlash() {
        const main = document.getElementById('app-content');
        if (!main) return;
        main.querySelectorAll('.flash-toast').forEach(function (al) {
            const cls = al.className;
            let type = 'info';
            if (/alert-danger/.test(cls)) type = 'danger';
            else if (/alert-success/.test(cls)) type = 'success';
            else if (/alert-warning/.test(cls)) type = 'warning';
            const clone = al.cloneNode(true);
            const closeBtn = clone.querySelector('.btn-close');
            if (closeBtn) closeBtn.remove();
            const text = clone.textContent.replace(/\s+/g, ' ').trim();
            al.remove();
            if (text) PAYROLL.toast(type, text);
        });
    }

    /* ===== Payroll form live math ===== */
    function bindPayrollForm() {
        const cards = document.querySelectorAll('.worker-card');
        if (!cards.length) return;
        const fmt = new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

        function money(v) {
            return fmt.format(v);
        }

        function calcRow(card) {
            const rate = parseFloat(card.dataset.rate) || 0;
            const ot = parseFloat(card.dataset.ot) || 0;
            let days = 0;
            const gridSel = card.querySelectorAll('.in-att');
            if (gridSel.length) {
                gridSel.forEach(function (s) {
                    if (s.value === 'P') {
                        days += 1;
                    } else if (s.value === 'H') {
                        days += 0.5;
                    }
                });
            } else {
                days = parseFloat(card.dataset.days) || 0;
            }
            const ca = parseFloat(card.querySelector('.in-ca').value) || 0;
            const pca = parseFloat(card.querySelector('.in-pca').value) || 0;
            const flat = parseFloat(card.querySelector('.in-flat').value) || 0;

            const daysCell = card.querySelector('.cell-days');
            const otCell = card.querySelector('.cell-ot');
            if (daysCell) daysCell.textContent = Math.round(days * 2) / 2;
            if (otCell) otCell.textContent = ot;

            let gross = flat > 0 ? flat : rate * days + (rate / 8) * ot;
            gross = Math.round(gross * 100) / 100;
            const net = Math.round((gross - ca - pca) * 100) / 100;
            const g = card.querySelector('.cell-gross');
            const n = card.querySelector('.cell-net');
            if (g) g.textContent = money(gross);
            if (n) n.textContent = money(net);
            return { gross: gross, net: net };
        }

        function calcAll() {
            let payroll = 0;
            let net = 0;
            cards.forEach(function (card) {
                const r = calcRow(card);
                payroll += r.gross;
                net += r.net;
            });
            const sPayroll = document.getElementById('sPayroll');
            const sNet = document.getElementById('sNet');
            if (sPayroll) sPayroll.textContent = money(payroll);
            if (sNet) sNet.textContent = money(net);
        }

        cards.forEach(function (card) {
            if (card.dataset.calcBound) return;
            card.dataset.calcBound = '1';
            card.querySelectorAll('input, select').forEach(function (inp) {
                inp.addEventListener('input', calcAll);
                inp.addEventListener('change', calcAll);
            });
        });

        calcAll();
    }

    /* ===== DTR keyboard shortcuts (P / A / H / .) ===== */
    function bindDtr() {
        const table = document.querySelector('.dtr-table');
        if (!table || table.dataset.dtrBound) return;
        table.dataset.dtrBound = '1';
        table.addEventListener('keydown', function (e) {
            const k = e.key.toLowerCase();
            if (!['p', 'a', 'h', '.'].includes(k)) return;
            if (e.ctrlKey || e.metaKey || e.altKey) return;
            const t = e.target;
            if (!t || (t.tagName !== 'SELECT' && t.tagName !== 'INPUT')) return;
            if (!t.closest('tr')) return;
            const sel = t.closest('tr').querySelector('select[name^="att_"]');
            if (!sel) return;
            e.preventDefault();
            sel.value = k === 'p' ? 'P' : k === 'a' ? 'A' : k === 'h' ? 'H' : '.';
        });
    }

    /* ===== Sidebar (mobile) ===== */
    function bindSidebar() {
        const sidebar = document.getElementById('sidebar');
        const backdrop = document.getElementById('sidebarBackdrop');
        const toggle = document.getElementById('sidebarToggle');
        if (!sidebar) return;
        const open = function () {
            sidebar.classList.add('open');
            if (backdrop) backdrop.classList.add('show');
        };
        const close = function () {
            sidebar.classList.remove('open');
            if (backdrop) backdrop.classList.remove('show');
        };
        if (toggle) toggle.addEventListener('click', function () {
            if (sidebar.classList.contains('open')) close();
            else open();
        });
        if (backdrop) backdrop.addEventListener('click', close);
    }

    /* ===== Init ===== */
    PAYROLL.init = function () {
        document.querySelectorAll('#app-content form[data-ajax]').forEach(bindForm);
        convertFlash();
        bindPayrollForm();
        bindDtr();
    };

    document.addEventListener('DOMContentLoaded', function () {
        applyTheme();
        bindSidebar();
        const themeBtn = document.getElementById('themeToggle');
        if (themeBtn) themeBtn.addEventListener('click', toggleTheme);
        PAYROLL.init();
    });

    window.PAYROLL = PAYROLL;
})();
