/**
 * Payroll System - Shared JS layer
 * Theme toggle, toasts, confirm modal, centralized AJAX API layer (ajax.php),
 * live payroll math, and DTR keyboard shortcuts / in-place rendering.
 * Progressive enhancement only: every page works without JavaScript.
 */
(function () {
    'use strict';

    const PAYROLL = {};

    /* ===== Small helpers ===== */
    const esc = function (s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    };

    const fmt2 = new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    function otFmt(v) {
        return (Math.round((Number(v) || 0) * 100) / 100).toString();
    }

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

    /* ===== Centralized AJAX API (ajax.php) ===== */
    function closeOpenModals() {
        document.querySelectorAll('.modal.show').forEach(function (m) {
            const inst = bootstrap.Modal.getInstance(m);
            if (inst) inst.hide();
        });
    }

    async function apiSubmit(form) {
        // The current query string carries site_id / date / payroll_id through
        // to the API; the form body carries the csrf_token + action + fields.
        const apiUrl = 'ajax.php' + (window.location.search || '');
        const btn = form.querySelector('[type="submit"]');
        const orig = btn ? btn.innerHTML : '';
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Saving...';
        }
        closeOpenModals();
        try {
            const resp = await fetch(apiUrl, {
                method: 'POST',
                body: new FormData(form),
                headers: { 'X-Requested-With': 'fetch' },
                credentials: 'same-origin'
            });
            let data = null;
            try {
                data = await resp.json();
            } catch (e) {
                data = null;
            }
            if (!data || typeof data !== 'object') {
                PAYROLL.toast('danger', 'Unexpected server response (HTTP ' + resp.status + '). Please reload.');
                return;
            }
            if (resp.status === 401) {
                window.location.replace('index.php');
                return;
            }
            if (data.render === 'dtr_day' && data.data) {
                PAYROLL.renderDtr(data.data);
            }
            if (data.render === 'redirect' && data.data && data.data.url) {
                if (data.msg) PAYROLL.toast(data.type || 'success', data.msg);
                setTimeout(function () { window.location.href = data.data.url; }, 900);
                return;
            }
            if (data.ok) {
                if (data.msg) PAYROLL.toast(data.type || 'success', data.msg);
                if (data.render === 'refresh') {
                    await PAYROLL.refreshContent();
                } else if (data.render !== 'dtr_day') {
                    PAYROLL.init();
                }
            } else {
                PAYROLL.toast(data.type || 'danger', data.msg || 'Request failed.');
                if (data.render !== 'dtr_day') {
                    PAYROLL.init();
                }
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

    function bindApiForm(form) {
        if (form.dataset.apiBound) return;
        form.dataset.apiBound = '1';
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            const doSubmit = function () { apiSubmit(form); };
            if (form.dataset.confirm) {
                PAYROLL.confirm(form.dataset.confirm).then(function (ok) {
                    if (ok) doSubmit();
                });
            } else {
                doSubmit();
            }
        });
    }

    /**
     * After a list-page save, re-fetch the current page and swap the content
     * area so the new server state (table, counts) is reflected.
     */
    PAYROLL.refreshContent = async function () {
        const resp = await fetch(window.location.href, {
            headers: { 'X-Requested-With': 'fetch' },
            credentials: 'same-origin'
        });
        const html = await resp.text();
        const doc = new DOMParser().parseFromString(html, 'text/html');
        const main = doc.getElementById('app-content');
        const cur = document.getElementById('app-content');
        if (main && cur) {
            cur.innerHTML = main.innerHTML;
            PAYROLL.init();
            if (window.scrollTo) window.scrollTo({ top: 0, behavior: 'smooth' });
        }
    };

    /* ===== DTR in-place render (day table + week strip) ===== */
    function dtrStatusOptions(status) {
        return ['P', 'A', 'H', '.'].map(function (c) {
            return '<option value="' + c + '"' + (status === c ? ' selected' : '') + '>' + c + '</option>';
        }).join('');
    }

    function dtrRowHtml(d) {
        const has = d.status !== '.' || Number(d.ot_hours) > 0 || String(d.note || '') !== '';
        return '<tr class="' + (has ? 'has-value' : '') + '">' +
            '<td class="fw-semibold">' + esc(d.name) + '</td>' +
            '<td class="text-muted small">' + esc(d.position || '-') + '</td>' +
            '<td class="text-end">' + fmt2.format(Number(d.rate)) + '</td>' +
            '<td><select class="form-select form-select-sm text-center" name="att_' + d.site_employee_id + '">' +
            dtrStatusOptions(d.status) + '</select></td>' +
            '<td><input type="number" step="0.5" min="0" class="form-control form-control-sm text-center" name="otd_' +
            d.site_employee_id + '" value="' + otFmt(d.ot_hours) + '"></td>' +
            '<td><input type="text" class="form-control form-control-sm" name="note_' + d.site_employee_id +
            '" value="' + esc(d.note || '') + '" placeholder="optional"></td>' +
            '</tr>';
    }

    function dtrCardHtml(d) {
        const has = d.status !== '.' || Number(d.ot_hours) > 0 || String(d.note || '') !== '';
        return '<div class="border rounded p-3 mb-3 ' + (has ? 'has-value' : '') + '">' +
            '<div class="d-flex justify-content-between align-items-start">' +
            '<div class="fw-semibold">' + esc(d.name) + '</div>' +
            '<div class="text-muted small">' + esc(d.position || '-') + ' &middot; ' + fmt2.format(Number(d.rate)) + '/day</div>' +
            '</div>' +
            '<div class="row g-2 mt-1">' +
            '<div class="col-4"><label class="form-label small mb-0">Status</label>' +
            '<select class="form-select form-select-sm text-center" data-name="att_' + d.site_employee_id + '">' +
            dtrStatusOptions(d.status) + '</select></div>' +
            '<div class="col-4"><label class="form-label small mb-0">OT Hours</label>' +
            '<input type="number" step="0.5" min="0" class="form-control form-control-sm text-center" data-name="otd_' +
            d.site_employee_id + '" value="' + otFmt(d.ot_hours) + '"></div>' +
            '<div class="col-4"><label class="form-label small mb-0">Note</label>' +
            '<input type="text" class="form-control form-control-sm" data-name="note_' + d.site_employee_id +
            '" value="' + esc(d.note || '') + '" placeholder="opt."></div>' +
            '</div></div>';
    }

    /**
     * Paint the DTR day table, mobile cards, summary line and week strip from
     * the server-produced payload (`dtr_day_payload` in config/actions.php).
     */
    PAYROLL.renderDtr = function (d) {
        if (!d || !Array.isArray(d.day)) return;
        const sum = d.summary || {};

        const rows = document.getElementById('dtrRows');
        if (rows) rows.innerHTML = d.day.map(dtrRowHtml).join('');

        const cards = document.getElementById('dtrCards');
        if (cards) cards.innerHTML = d.day.map(dtrCardHtml).join('');

        if (d.date) {
            const head = document.getElementById('dtrDayHeading');
            if (head) {
                const dd = new Date(d.date + 'T00:00:00');
                head.textContent = dd.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });
            }
            const saveBtn = document.getElementById('dtrSaveBtn');
            if (saveBtn) {
                const dd = new Date(d.date + 'T00:00:00');
                const mon = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'][dd.getMonth()];
                saveBtn.innerHTML = '<i class="bi bi-save"></i> Save ' + mon + ' ' + String(dd.getDate()).padStart(2, '0');
            }
        }

        const s = document.getElementById('dtrSummary');
        if (s) {
            s.textContent = '';
            const w = document.createElement('span');
            w.className = 'fw-semibold';
            w.textContent = String(sum.workers || 0);
            const setTxt = document.createElement('span');
            setTxt.className = 'dtr-summary-set';
            setTxt.textContent = (sum.set || 0) + ' set';
            const otTxt = document.createElement('span');
            otTxt.className = 'dtr-summary-ot';
            otTxt.textContent = (sum.ot_total || 0) > 0 ? otFmt(sum.ot_total) + 'h OT' : '0h OT';
            s.append(w, ' workers \u00b7 ', setTxt, ' \u00b7 ', otTxt);
        }

        const strip = document.querySelector('.week-strip');
        if (strip && Array.isArray(d.week)) {
            strip.querySelectorAll('a.dtr-day').forEach(function (link) {
                const wd = d.week.find(function (x) { return x.date === link.getAttribute('data-date'); });
                if (!wd) return;
                const filled = Number(wd.filled) || 0;
                const ot = Number(wd.ot_total) || 0;
                const countEl = link.querySelector('.dtr-day-count');
                if (countEl) countEl.textContent = filled > 0 ? filled + '/' + (sum.workers || '') : '';
                const otEl = link.querySelector('.dtr-day-ot');
                if (otEl) otEl.textContent = ot > 0 ? otFmt(ot) + 'h' : '';
                link.classList.remove('btn-outline-success', 'btn-outline-secondary', 'btn-dark');
                const selected = wd.date === d.date;
                link.classList.add(selected ? 'btn-dark' : (filled > 0 ? 'btn-outline-success' : 'btn-outline-secondary'));
            });
        }

        PAYROLL.init();
    };

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
            const pcaInput = card.querySelector('.in-pca');
            const skip = card.querySelector('.pca-skip');
            if (skip && pcaInput) {
                skip.addEventListener('change', function () {
                    if (skip.checked) {
                        pcaInput.dataset.prev = pcaInput.value;
                        pcaInput.value = 0;
                        pcaInput.disabled = true;
                    } else {
                        pcaInput.disabled = false;
                        if (pcaInput.dataset.prev) pcaInput.value = pcaInput.dataset.prev;
                    }
                    calcAll();
                });
                if (skip.checked) {
                    pcaInput.value = 0;
                    pcaInput.disabled = true;
                }
            }
            card.querySelectorAll('input, select').forEach(function (inp) {
                if (inp === skip) return;
                inp.addEventListener('input', calcAll);
                inp.addEventListener('change', calcAll);
            });
        });

        calcAll();
    }

    /* ===== DTR: keyboard shortcuts (P / A / H / .) ===== */
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

    /* ===== DTR: mirror mobile card edits into the named desktop fields ===== */
    function dtrCardSync() {
        const cards = document.getElementById('dtrCards');
        if (!cards || cards.dataset.cardBound) return;
        cards.dataset.cardBound = '1';
        const sync = function (e) {
            const t = e.target;
            if (!t || !t.getAttribute || !t.getAttribute('data-name')) return;
            const named = document.querySelector('[name="' + t.getAttribute('data-name') + '"]');
            if (named) named.value = t.value;
        };
        cards.addEventListener('input', sync);
        cards.addEventListener('change', sync);
    }

    /* ===== DTR: week strip day switching via the API ===== */
    function bindDtrWeek() {
        const strip = document.querySelector('.week-strip');
        if (!strip || strip.dataset.dtrWeekBound) return;
        strip.dataset.dtrWeekBound = '1';
        const siteId = strip.getAttribute('data-site');
        strip.addEventListener('click', async function (e) {
            const link = e.target.closest('a.dtr-day');
            if (!link) return;
            const date = link.getAttribute('data-date');
            if (!date || !siteId) return;
            e.preventDefault();
            try {
                const resp = await fetch('ajax.php?action=dtr.get_day&site_id=' + siteId + '&date=' + date, {
                    headers: { 'X-Requested-With': 'fetch' },
                    credentials: 'same-origin'
                });
                const data = await resp.json();
                if (resp.status === 401) {
                    window.location.replace('index.php');
                    return;
                }
                if (data && data.data) {
                    PAYROLL.renderDtr(data.data);
                    if (window.history && window.history.replaceState) {
                        window.history.replaceState(null, '', link.getAttribute('href'));
                    }
                } else {
                    window.location.href = link.getAttribute('href');
                }
            } catch (err) {
                window.location.href = link.getAttribute('href');
            }
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

    /* ===== Table filters (Payroll hub history tables) ===== */
    function bindTableFilters() {
        document.querySelectorAll('select.js-table-filter').forEach(function (sel) {
            const key = sel.getAttribute('data-filter-key') || '';
            sel.addEventListener('change', function () {
                const table = document.getElementById(sel.getAttribute('data-filter-table'));
                if (!table) return;
                const value = sel.value;
                let visible = 0;
                table.querySelectorAll('tbody tr').forEach(function (row) {
                    if (row.hasAttribute('data-filter-empty')) return;
                    const show = key === '' || value === '' || row.getAttribute('data-' + key) === value;
                    row.style.display = show ? '' : 'none';
                    if (show) visible++;
                });
                const empty = table.querySelector('tr[data-filter-empty]');
                if (empty) empty.style.display = visible ? 'none' : '';
            });
        });
    }

    /* ===== Init ===== */
    PAYROLL.init = function () {
        document.querySelectorAll('#app-content form[data-api]').forEach(bindApiForm);
        convertFlash();
        bindPayrollForm();
        bindDtr();
        bindDtrWeek();
        dtrCardSync();
        bindTableFilters();
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