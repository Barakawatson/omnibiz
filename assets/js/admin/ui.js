/* ============================================================
   OmniBiz Admin - shared UI behaviour
   ------------------------------------------------------------
   Vanilla JS, no dependencies (Bootstrap is optional). Everything
   hangs off a single global: window.MX
     MX.toast(...)          non-blocking notifications
     MX.filterTable(...)    client-side search/filter, no page reload
     MX.initSidebar()       accordion nav + persisted state
     MX.initDropdowns()     row action menus
     MX.watchAlerts()       polls notifications-api.php, toasts changes
   Auto-initialises on DOMContentLoaded; safe to include everywhere.
   ============================================================ */

(function (window, document) {
    'use strict';

    const MX = {};
    const LS_SECTIONS = 'mxOpenSections';
    const LS_ALERTS = 'mxLastAlertCounts';

    /* ========================================================
       TOASTS
       ======================================================== */
    function toastHost() {
        let host = document.querySelector('.mx-toasts');
        if (!host) {
            host = document.createElement('div');
            host.className = 'mx-toasts';
            document.body.appendChild(host);
        }
        return host;
    }

    const TOAST_ICONS = {
        success: 'fa-circle-check',
        warning: 'fa-triangle-exclamation',
        danger: 'fa-circle-exclamation',
        info: 'fa-circle-info'
    };

    /**
     * MX.toast('Message')                       -> info toast
     * MX.toast('Message', 'success')
     * MX.toast({ title, message, type, timeout, href })
     */
    MX.toast = function (opts, type) {
        if (typeof opts === 'string') { opts = { message: opts, type: type || 'info' }; }
        const o = Object.assign({ title: '', message: '', type: 'info', timeout: 5000, href: '' }, opts || {});

        const el = document.createElement('div');
        el.className = 'mx-toast t-' + o.type;

        const icon = document.createElement('i');
        icon.className = 'fas ' + (TOAST_ICONS[o.type] || TOAST_ICONS.info) + ' mx-toast-icon';

        const body = document.createElement('div');
        body.className = 'mx-toast-body';
        if (o.title) {
            const t = document.createElement('div');
            t.className = 'mx-toast-title';
            t.textContent = o.title;
            body.appendChild(t);
        }
        if (o.message) {
            const m = document.createElement('div');
            m.className = 'mx-toast-msg';
            m.textContent = o.message;
            if (o.href) {
                const a = document.createElement('a');
                a.href = o.href;
                a.textContent = ' Open';
                m.appendChild(a);
            }
            body.appendChild(m);
        }

        const close = document.createElement('button');
        close.className = 'mx-toast-close';
        close.type = 'button';
        close.innerHTML = '<i class="fas fa-xmark"></i>';

        el.append(icon, body, close);
        toastHost().appendChild(el);

        function dismiss() {
            el.classList.add('leaving');
            setTimeout(() => el.remove(), 240);
        }
        close.addEventListener('click', dismiss);
        if (o.timeout > 0) { setTimeout(dismiss, o.timeout); }
        return el;
    };

    /* ========================================================
       SIDEBAR - grouped accordion with persisted state
       ======================================================== */
    MX.initSidebar = function () {
        const sections = document.querySelectorAll('.mx-section');
        if (!sections.length) { return; }

        // True single-open accordion: at most one section expanded at a
        // time. The section containing the current page is server-side
        // truth (navSectionOpen() already stamped it open/has-active
        // before this script ran) - a real navigation always re-derives
        // the right section that way, so nothing here needs to remember
        // MULTIPLE previously-open sections across page loads. What used
        // to be persisted as an array let sections accumulate open
        // forever; only the single most-recent key is kept now, purely so
        // a same-page toggle-then-reload (e.g. F5) doesn't spring the menu
        // back to whatever the URL happens to land on with no user choice
        // remembered at all.
        let openKey = null;
        try { openKey = localStorage.getItem(LS_SECTIONS) || null; } catch (e) { openKey = null; }

        function closeAllExcept(exceptSec) {
            sections.forEach(other => {
                if (other === exceptSec) { return; }
                other.classList.remove('open');
                const otherToggle = other.querySelector('.mx-section-toggle');
                if (otherToggle) { otherToggle.setAttribute('aria-expanded', 'false'); }
            });
        }

        let activeSec = null;
        sections.forEach(sec => {
            // A section containing the current page is always expanded and
            // flagged, so the user can see where they are at a glance.
            if (sec.querySelector('a.mx-link.active')) {
                sec.classList.add('open', 'has-active');
                activeSec = sec;
            }
        });
        // No page-derived active section (rare - e.g. a top-level link):
        // fall back to whichever single section was last opened by hand.
        if (!activeSec && openKey) {
            sections.forEach(sec => { if (sec.dataset.section === openKey) { sec.classList.add('open'); } });
        }
        if (activeSec) { closeAllExcept(activeSec); }

        sections.forEach(sec => {
            const key = sec.dataset.section || '';
            const toggle = sec.querySelector('.mx-section-toggle');
            if (!toggle) { return; }
            toggle.setAttribute('aria-expanded', sec.classList.contains('open') ? 'true' : 'false');

            toggle.addEventListener('click', function () {
                const willOpen = !sec.classList.contains('open');
                // Collapse every other section before applying this one's
                // own new state - the actual "only one open at a time" rule.
                closeAllExcept(sec);
                sec.classList.toggle('open', willOpen);
                toggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
                try { localStorage.setItem(LS_SECTIONS, willOpen ? key : ''); } catch (e) {}
            });
        });
    };

    /* ========================================================
       CLIENT-SIDE TABLE FILTERING (no page reload)
       ========================================================
       Markup contract:
         <input data-mx-filter="#myTable">
         <table id="myTable"> <tr data-search="lowercase haystack"> …
       Rows without data-search fall back to their text content.
       Optional: [data-mx-empty="#emptyEl"] toggles an empty state.
    ======================================================== */
    MX.filterTable = function (table, query) {
        if (!table) { return 0; }
        const q = (query || '').trim().toLowerCase();
        const rows = table.querySelectorAll('tbody tr');
        let visible = 0;

        rows.forEach(row => {
            if (row.dataset.mxNofilter !== undefined) { return; }
            const hay = row.dataset.search || row.textContent.toLowerCase();
            const show = q === '' || hay.indexOf(q) !== -1;
            row.classList.toggle('mx-row-hidden', !show);
            if (show) { visible++; }
        });

        const counter = document.querySelector('[data-mx-count="#' + table.id + '"]');
        if (counter) { counter.textContent = visible; }

        const empty = document.querySelector('[data-mx-empty="#' + table.id + '"]');
        if (empty) { empty.style.display = visible === 0 ? '' : 'none'; }

        return visible;
    };

    MX.initFilters = function () {
        document.querySelectorAll('[data-mx-filter]').forEach(input => {
            const table = document.querySelector(input.dataset.mxFilter);
            if (!table) { return; }
            let timer = null;
            input.addEventListener('input', function () {
                clearTimeout(timer);
                timer = setTimeout(() => MX.filterTable(table, input.value), 90);
            });
            // Escape clears the box.
            input.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') { input.value = ''; MX.filterTable(table, ''); }
            });
        });

        // Chip filters: [data-mx-chip="#table"][data-mx-value="pending"]
        document.querySelectorAll('[data-mx-chip]').forEach(chip => {
            chip.addEventListener('click', function (e) {
                e.preventDefault();
                const table = document.querySelector(chip.dataset.mxChip);
                if (!table) { return; }
                document.querySelectorAll('[data-mx-chip="' + chip.dataset.mxChip + '"]')
                    .forEach(c => c.classList.remove('active'));
                chip.classList.add('active');

                const val = (chip.dataset.mxValue || '').toLowerCase();
                let visible = 0;
                table.querySelectorAll('tbody tr').forEach(row => {
                    const show = val === '' || (row.dataset.status || '').toLowerCase() === val;
                    row.classList.toggle('mx-row-hidden', !show);
                    if (show) { visible++; }
                });
                const empty = document.querySelector('[data-mx-empty="' + chip.dataset.mxChip + '"]');
                if (empty) { empty.style.display = visible === 0 ? '' : 'none'; }
            });
        });
    };

    /* ========================================================
       ROW ACTION DROPDOWNS
       ======================================================== */
    MX.initDropdowns = function () {
        document.addEventListener('click', function (e) {
            const btn = e.target.closest('.mx-actions-btn');
            const openMenus = document.querySelectorAll('.mx-actions.open');

            if (btn) {
                e.preventDefault();
                e.stopPropagation();
                const wrap = btn.closest('.mx-actions');
                const wasOpen = wrap.classList.contains('open');
                openMenus.forEach(m => m.classList.remove('open'));
                if (!wasOpen) { wrap.classList.add('open'); }
                return;
            }
            if (!e.target.closest('.mx-actions-menu')) {
                openMenus.forEach(m => m.classList.remove('open'));
            }
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('.mx-actions.open').forEach(m => m.classList.remove('open'));
            }
        });
    };

    /* ========================================================
       LIVE ALERT WATCHER
       --------------------------------------------------------
       Polls notifications-api.php, updates sidebar badges in place
       and toasts anything that went UP since the last check
       (stock alert, stock request, unclosed trading day).

       Keys must match the badge ids rendered by sidebar-admin.php
       and the keys returned by notifications-api.php.
       ======================================================== */
    const ALERT_META = {
        low_stock:     { title: 'Low stock alert',   type: 'danger',  href: 'inventory-items.php',           verb: 'at or below reorder level' },
        requests:      { title: 'Stock request',     type: 'warning', href: 'inventory-requests.php',        verb: 'awaiting approval' },
        open_pos:      { title: 'Purchase orders',   type: 'info',    href: 'inventory-purchase-orders.php', verb: 'still open' },
        unclosed_days: { title: 'Unclosed day',      type: 'warning', href: 'z-report.php',                  verb: 'never reconciled' },
        disposal:      { title: 'Disposal request',  type: 'warning', href: 'inventory-disposal.php',        verb: 'awaiting approval' },
        cancelled_carts_today: { title: 'Cart cancelled', type: 'danger', href: 'report.php?g=audit&r=cancelled_carts', verb: 'cancelled today - admin only' }
    };

    MX.watchAlerts = function (intervalMs) {
        const endpoint = 'notifications-api.php';
        let previous = null;
        try { previous = JSON.parse(sessionStorage.getItem(LS_ALERTS)); } catch (e) { previous = null; }

        function paintBadge(key, count) {
            // Sidebar badge ids come from sidebar-nav.php.
            const el = document.getElementById('nav-badge-' + key);
            if (!el) { return; }
            el.textContent = count;
            el.style.display = count > 0 ? '' : 'none';
        }

        // The top bar shows a single dot when anything at all is
        // outstanding, so the bell reflects the sidebar without
        // duplicating the individual numbers.
        function paintTopbarDot(counts) {
            const dot = document.getElementById('topbarAlertDot');
            if (!dot) { return; }
            const total = Object.keys(counts).reduce((s, k) => s + (parseInt(counts[k], 10) || 0), 0);
            dot.style.display = total > 0 ? '' : 'none';
            const bell = document.getElementById('topbarAlerts');
            if (bell) {
                bell.setAttribute('title', total > 0 ? total + ' item(s) need attention' : 'Nothing needs attention');
            }
        }

        function tick(announce) {
            fetch(endpoint, { credentials: 'same-origin' })
                .then(r => (r.ok ? r.json() : null))
                .then(counts => {
                    if (!counts) { return; }

                    Object.keys(counts).forEach(key => {
                        const n = parseInt(counts[key], 10) || 0;
                        paintBadge(key, n);

                        // Only announce increases, and only after the first
                        // successful poll, so a page load isn't noisy.
                        if (announce && previous && ALERT_META[key]) {
                            const before = parseInt(previous[key], 10) || 0;
                            if (n > before) {
                                const delta = n - before;
                                const meta = ALERT_META[key];
                                MX.toast({
                                    title: meta.title,
                                    message: delta + (delta === 1 ? ' item ' : ' items ') + meta.verb + '.',
                                    type: meta.type,
                                    href: meta.href,
                                    timeout: 9000
                                });
                            }
                        }
                    });

                    paintTopbarDot(counts);
                    previous = counts;
                    try { sessionStorage.setItem(LS_ALERTS, JSON.stringify(counts)); } catch (e) {}
                })
                .catch(() => { /* offline - badges simply keep their last value */ });
        }

        tick(false);                                  // prime without toasting
        setInterval(() => tick(true), intervalMs || 30000);
    };

    /* ========================================================
       SEARCH BOXES
       --------------------------------------------------------
       Adds the clear (x) affordance to any .ui-search wrapper.
       Works alongside data-mx-filter, which does the filtering.
       ======================================================== */
    MX.initSearch = function () {
        document.querySelectorAll('.ui-search').forEach(wrap => {
            const input = wrap.querySelector('input');
            const clear = wrap.querySelector('.ui-search-clear');
            if (!input) { return; }

            const sync = () => wrap.classList.toggle('has-value', input.value !== '');
            sync();
            input.addEventListener('input', sync);

            if (clear) {
                clear.addEventListener('click', function () {
                    input.value = '';
                    sync();
                    // Re-run whatever is listening (filter, form, etc).
                    input.dispatchEvent(new Event('input', { bubbles: true }));
                    input.focus();
                });
            }
        });
    };

    /* ========================================================
       SHARED MODAL
       --------------------------------------------------------
       ONE modal reused for many rows instead of rendering a
       modal per row. A trigger declares which modal to open and
       carries the row's values as data-field-* attributes:

         <button data-ui-modal="#productModal"
                 data-field-name="Azam Cola"
                 data-field-price="1000"
                 data-title="Edit product">

       Inside the modal, elements opt in by name:
         <input name="name">            value is set
         <span data-ui-text="name">     textContent is set
         <img  data-ui-src="image">     src is set
         [data-ui-show="barcode"]       shown only when non-empty

       Nothing is injected as HTML, so row data can never inject
       markup into the page.
       ======================================================== */
    MX.fillModal = function (modal, data, title) {
        if (!modal) { return; }

        if (title) {
            const t = modal.querySelector('[data-ui-modal-title]');
            if (t) { t.textContent = title; }
        }

        Object.keys(data).forEach(key => {
            const value = data[key];

            // Form controls, addressed by name.
            modal.querySelectorAll('[name="' + key + '"]').forEach(el => {
                if (el.type === 'checkbox') {
                    el.checked = value === '1' || value === 'true' || value === true;
                } else if (el.tagName === 'SELECT') {
                    el.value = value;
                    // A value not present in the list would silently blank
                    // the select; fall back to the first option instead.
                    if (el.selectedIndex === -1) { el.selectedIndex = 0; }
                } else {
                    el.value = value;
                }
            });

            modal.querySelectorAll('[data-ui-text="' + key + '"]').forEach(el => { el.textContent = value; });
            modal.querySelectorAll('[data-ui-src="' + key + '"]').forEach(el => { el.src = value; });
            modal.querySelectorAll('[data-ui-href="' + key + '"]').forEach(el => { el.href = value; });
            modal.querySelectorAll('[data-ui-show="' + key + '"]').forEach(el => {
                el.style.display = (value === '' || value === null || value === undefined) ? 'none' : '';
            });
        });
    };

    MX.initModals = function () {
        document.addEventListener('click', function (e) {
            const trigger = e.target.closest('[data-ui-modal]');
            if (!trigger) { return; }

            const modal = document.querySelector(trigger.dataset.uiModal);
            if (!modal) { return; }
            e.preventDefault();

            // Collect data-field-* into a plain object. dataset keys are
            // camelCased by the browser: data-field-selling-price -> fieldSellingPrice.
            const data = {};
            Object.keys(trigger.dataset).forEach(k => {
                if (k.indexOf('field') === 0 && k.length > 5) {
                    // fieldSellingPrice -> selling_price
                    const name = k.slice(5)
                        .replace(/^[A-Z]/, c => c.toLowerCase())
                        .replace(/[A-Z]/g, c => '_' + c.toLowerCase());
                    data[name] = trigger.dataset[k];
                }
            });

            MX.fillModal(modal, data, trigger.dataset.title || '');

            if (window.bootstrap && window.bootstrap.Modal) {
                window.bootstrap.Modal.getOrCreateInstance(modal).show();
            }
        });
    };

    /* ========================================================
       BUTTON LOADING STATE
       --------------------------------------------------------
       <form data-ui-loading> disables its submit button and shows
       a spinner, so a slow save cannot be double-submitted.
       ======================================================== */
    MX.initFormLoading = function () {
        document.querySelectorAll('form[data-ui-loading]').forEach(form => {
            form.addEventListener('submit', function () {
                const btn = form.querySelector('[type="submit"]');
                if (btn && !btn.disabled) {
                    btn.classList.add('is-loading');
                    // Re-enable on bfcache restore (browser Back).
                    setTimeout(() => { btn.disabled = true; }, 0);
                }
            });
        });
        window.addEventListener('pageshow', function () {
            document.querySelectorAll('.is-loading').forEach(b => {
                b.classList.remove('is-loading');
                b.disabled = false;
            });
        });
    };

    /** Put a single button into / out of its loading state. */
    MX.setLoading = function (btn, on) {
        if (!btn) { return; }
        btn.classList.toggle('is-loading', !!on);
        btn.disabled = !!on;
    };

    /* ========================================================
       SMALL HELPERS
       ======================================================== */
    MX.money = function (n) { return 'Tsh ' + Math.round(Number(n) || 0).toLocaleString(); };

    MX.timeAgo = function (dateStr) {
        const then = new Date((dateStr || '').replace(' ', 'T'));
        if (isNaN(then)) { return ''; }
        const secs = Math.max(0, (Date.now() - then.getTime()) / 1000);
        if (secs < 60) { return 'just now'; }
        if (secs < 3600) { return Math.floor(secs / 60) + 'm ago'; }
        if (secs < 86400) { return Math.floor(secs / 3600) + 'h ago'; }
        return Math.floor(secs / 86400) + 'd ago';
    };

    /** Confirm-then-submit for destructive row actions. */
    MX.confirmSubmit = function (formId, message) {
        if (window.confirm(message || 'Are you sure?')) {
            document.getElementById(formId).submit();
        }
    };


    /* ========================================================
       THEME  (light / dark / system)
       --------------------------------------------------------
       Three states. "system" stores nothing on the element and
       lets the prefers-color-scheme rules in ui.css decide, so
       the OS stays the single source of truth for that mode.

       The preference lives in localStorage: this application has
       no per-user preferences table, and a theme choice is a
       property of the device someone is sitting at rather than
       of the account - a cashier signing in at a different till
       should get that till's setting.

       inventory-header.php applies the stored value before any
       CSS paints; this only handles switching afterwards.
       ======================================================== */
    const LS_THEME = 'mxTheme';

    MX.getTheme = function () {
        try {
            const t = localStorage.getItem(LS_THEME);
            return (t === 'light' || t === 'dark') ? t : 'system';
        } catch (e) { return 'system'; }
    };

    MX.setTheme = function (choice) {
        const root = document.documentElement;
        if (choice === 'light' || choice === 'dark') {
            root.setAttribute('data-theme', choice);
            try { localStorage.setItem(LS_THEME, choice); } catch (e) {}
        } else {
            root.removeAttribute('data-theme');
            try { localStorage.removeItem(LS_THEME); } catch (e) {}
        }
        MX.paintThemeSwitch();
        // Let anything that draws its own colours (the POS customer
        // display, charts) react without polling.
        document.dispatchEvent(new CustomEvent('mx:themechange', { detail: { theme: choice } }));
    };

    MX.paintThemeSwitch = function () {
        const current = MX.getTheme();
        document.querySelectorAll('[data-theme-choice]').forEach(btn => {
            btn.setAttribute('aria-pressed', btn.dataset.themeChoice === current ? 'true' : 'false');
        });
    };

    MX.initTheme = function () {
        MX.paintThemeSwitch();
        document.addEventListener('click', function (e) {
            const btn = e.target.closest('[data-theme-choice]');
            if (!btn) { return; }
            e.preventDefault();
            MX.setTheme(btn.dataset.themeChoice);
        });
    };

    /* ========================================================
       BOOT
       ======================================================== */
    function boot() {
        // Scripts are alive - drop the fallback that keeps every nav
        // section forced open (see .no-js rules in ui.css).
        document.documentElement.classList.remove('no-js');

        MX.initTheme();
        MX.initSidebar();
        MX.initFilters();
        MX.initDropdowns();
        MX.initSearch();
        MX.initModals();
        MX.initFormLoading();

        // Flash messages rendered server-side become toasts.
        document.querySelectorAll('[data-mx-flash]').forEach(el => {
            MX.toast({
                message: el.dataset.mxFlash,
                type: el.dataset.mxFlashType || 'info',
                timeout: 6000
            });
            el.remove();
        });

        // Opt out with <body data-mx-noalerts> (e.g. the POS full-screen till).
        if (!document.body.hasAttribute('data-mx-noalerts')) {
            MX.watchAlerts(30000);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }

    window.MX = MX;
})(window, document);
