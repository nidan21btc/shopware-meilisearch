const dialog = document.querySelector('[data-el-search]');
if (dialog && typeof dialog.showModal === 'function') {
    const query = dialog.querySelector('[data-el-query]');
    const results = dialog.querySelector('[data-el-results]');
    const status = dialog.querySelector('[data-el-status]');
    const networkError = dialog.querySelector('[data-el-network-error]');
    const clearQuery = dialog.querySelector('[data-el-clear]');
    const voice = dialog.querySelector('[data-el-voice]');
    const voiceNotice = dialog.querySelector('[data-el-voice-notice]');
    // Only the unprefixed API with an explicit local-only control is eligible.
    // Never use a prefixed/cloud fallback or install a language pack.
    const Speech = window.SpeechRecognition;
    const voiceSupported = Boolean(window.isSecureContext && typeof Speech === 'function' && Speech.prototype && 'processLocally' in Speech.prototype);
    let recognition = null;
    let voiceTimer = null;
    let controller;
    let revision = 0;
    let timer;
    let popularityTimer;
    const countedQueries = new Set();
    let opener;
    let oldOverflow = '';
    let restoringFocus = false;
    // Null leaves the locale/query-specific default to the server. Once the
    // customer changes the option, send their explicit choice instead.
    let includeSimilar = null;
    let lastAttempt = null;
    // Keep the latest query only in this tab's session, separately for each
    // language endpoint. Storage can be unavailable in restricted browsers.
    const queryStorageKey = `el-search:last-query:${dialog.dataset.locale || ''}:${dialog.dataset.endpoint}`;
    const rememberQuery = () => {
        try {
            if (query.value === '') sessionStorage.removeItem(queryStorageKey);
            else sessionStorage.setItem(queryStorageKey, Array.from(query.value).slice(0, 150).join(''));
        } catch (_) { /* Search remains usable without browser storage. */ }
    };
    try {
        const previousQuery = sessionStorage.getItem(queryStorageKey);
        if (previousQuery) query.value = Array.from(previousQuery).slice(0, 150).join('');
    } catch (_) { /* Search remains usable without browser storage. */ }
    if (clearQuery) clearQuery.hidden = query.value === '';
    const mobile = window.matchMedia('(max-width:600px)');
    let mobileExpanded = false;
    const syncMobileView = () => dialog.classList.toggle('el-search--mobile-preview', mobile.matches && !mobileExpanded && query.value.trim() !== '');
    const expandMobile = (focusSort = false) => {
        mobileExpanded = true;
        syncMobileView();
        if (focusSort) results.querySelector('[data-el-sort]')?.focus({preventScroll: true});
    };
    const syncVoice = () => {
        if (!voice) return;
        voice.hidden = !voiceSupported || (!recognition && query.value.trim() !== '');
        voice.setAttribute('aria-pressed', recognition ? 'true' : 'false');
        voice.setAttribute('aria-label', recognition ? dialog.dataset.voiceStop : dialog.dataset.voiceStart);
    };
    const stopVoice = () => {
        clearTimeout(voiceTimer);
        voiceTimer = null;
        const previous = recognition;
        recognition = null;
        if (previous) {
            try { previous.abort(); } catch (_) { /* Already stopped. */ }
        }
        syncVoice();
    };
    const voiceError = () => {
        if (voiceNotice) {
            voiceNotice.textContent = dialog.dataset.voiceError;
            voiceNotice.hidden = false;
        }
    };
    syncVoice();
    mobile.addEventListener('change', () => {
        syncMobileView();
        for (const facet of results.querySelectorAll('details')) facet.open = !mobile.matches;
        if (!mobile.matches) {
            stopVoice();
            if (voiceNotice) voiceNotice.hidden = true;
        }
    });

    const load = async (page = 1, reset = false, retry = false) => {
        clearTimeout(timer);
        controller?.abort();
        controller = new AbortController();
        const current = ++revision;
        const canRetry = retry && lastAttempt !== null;
        const params = canRetry ? new URLSearchParams(lastAttempt) : new URLSearchParams({q: query.value.trim(), page: String(page)});
        if (!canRetry) {
            if (includeSimilar !== null) params.set('similar', includeSimilar ? '1' : '0');
            const filters = results.querySelector('[data-el-filters]');
            if (!reset && filters) {
                for (const [key, value] of new FormData(filters)) {
                    if (value !== '') params.append(key, value);
                }
            }
            if (!reset) params.set('sort', results.querySelector('[data-el-sort]')?.value || 'relevance');
        }
        lastAttempt = params.toString();
        dialog.classList.toggle('el-search--discovery', (params.get('q') || '') === '');
        syncMobileView();
        results.setAttribute('aria-busy', 'true');
        status.textContent = dialog.dataset.loading;
        try {
            // Relative fetch URLs inherit userinfo from a credentialed page URL.
            // Resolve against the credential-free origin; HTTP Basic auth stays
            // in the browser's same-origin authentication cache, never in the URL.
            const endpoint = new URL(dialog.dataset.endpoint, window.location.origin);
            if (endpoint.origin !== window.location.origin) throw new Error('Invalid search origin');
            endpoint.username = '';
            endpoint.password = '';
            endpoint.search = params.toString();
            endpoint.hash = '';
            const response = await fetch(endpoint.href, {
                signal: controller.signal,
                headers: {'X-Requested-With': 'XMLHttpRequest'},
                credentials: 'same-origin',
                cache: 'no-store',
            });
            if (!response.ok && response.status !== 503) throw new Error('Search unavailable');
            const html = await response.text();
            if (!html.trim()) throw new Error('Search unavailable');
            if (current !== revision || !dialog.open) return;
            // Filters are rebuilt from Shopware's current counts. Save their
            // interaction state immediately before replacement so a customer
            // can select several values without reopening the same section.
            const facetState = new Map();
            if (!reset) for (const facet of results.querySelectorAll('details[data-el-facet]')) {
                facetState.set(facet.dataset.elFacet, {
                    open: facet.open,
                    expanded: facet.dataset.elExpanded === 'true',
                    search: facet.querySelector('[data-el-facet-search]')?.value || '',
                });
            }
            const focused = results.contains(document.activeElement) ? document.activeElement : null;
            const externalRetryFocused = canRetry && networkError.contains(document.activeElement);
            networkError.hidden = true;
            const active = !reset || focused?.matches('[data-el-reset]') ? focused : null;
            const activeFacet = active?.closest('details[data-el-facet]')?.dataset.elFacet;
            let focusTarget = null;
            if (active?.matches('input[name]')) focusTarget = {type: 'input', name: active.name, value: active.type === 'checkbox' ? active.value : null};
            else if (active?.matches('[data-el-sort]')) focusTarget = {type: 'sort'};
            else if (active?.matches('[data-el-page-direction]')) focusTarget = {type: 'page', direction: active.dataset.elPageDirection};
            else if (active?.matches('[data-el-similar]')) focusTarget = {type: 'similar'};
            else if (active?.matches('[data-el-reset]')) focusTarget = {type: 'reset'};
            else if (active?.matches('[data-el-retry]')) focusTarget = {type: 'retry'};
            else if (active?.matches('[data-el-chip]')) focusTarget = {type: 'chip', name: active.dataset.facet, value: active.dataset.value};
            else if (activeFacet && active?.matches('[data-el-facet-search]')) focusTarget = {type: 'facet-search', name: activeFacet};
            else if (activeFacet && active?.matches('summary')) focusTarget = {type: 'summary', name: activeFacet};
            const oldScroll = results.scrollTop;
            results.innerHTML = html;
            // Count only a settled query, once per tab/session and language. Never
            // send intermediate keystrokes or a search whose results failed.
            clearTimeout(popularityTimer);
            const settled = (params.get('q') || '').trim();
            const proof = response.headers.get('X-Shopware-Search-Proof');
            if (response.ok && proof && settled && page === 1) popularityTimer = setTimeout(async () => {
                if (!dialog.open || query.value.trim() !== settled) return;
                const key = `el-search:counted:${dialog.dataset.locale || ''}:${dialog.dataset.endpoint}:${settled.toLocaleLowerCase()}`;
                try { if (sessionStorage.getItem(key)) return; } catch (_) { if (countedQueries.has(key)) return; }
                const countUrl = new URL(dialog.dataset.popularEndpoint, window.location.origin);
                if (countUrl.origin !== window.location.origin) return;
                countUrl.username = ''; countUrl.password = ''; countUrl.hash = '';
                countUrl.search = '';
                try {
                    const tracked = await fetch(countUrl.href, {method: 'POST', headers: {'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/json'}, body: JSON.stringify({q: settled, proof}), credentials: 'same-origin', cache: 'no-store'});
                    if (tracked.ok) { countedQueries.add(key); try { sessionStorage.setItem(key, '1'); } catch (_) {} }
                } catch (_) { /* Popularity tracking is optional. */ }
            }, 2500);
            results.querySelector('[data-el-filter-toggle]')?.setAttribute('aria-expanded', String(dialog.classList.contains('el-search--filters-open')));
            for (const facet of results.querySelectorAll('details[data-el-facet]')) {
                const state = facetState.get(facet.dataset.elFacet);
                facet.open = state ? state.open : !mobile.matches;
                if (!state) continue;
                facet.dataset.elExpanded = String(state.expanded);
                const more = facet.querySelector('[data-el-show-more]');
                if (more) more.textContent = (state.expanded ? more.dataset.less : more.dataset.more) + (state.expanded ? ' ⌃' : ' ⌄');
                const search = facet.querySelector('[data-el-facet-search]');
                if (search) {
                    search.value = state.search;
                    search.dispatchEvent(new Event('input', {bubbles: true}));
                }
            }
            let replacement = null;
            if (focusTarget?.type === 'input') replacement = [...results.querySelectorAll('input[name]')].find(el => el.name === focusTarget.name && (focusTarget.value === null || el.value === focusTarget.value));
            else if (focusTarget?.type === 'sort') replacement = results.querySelector('[data-el-sort]');
            else if (focusTarget?.type === 'page') {
                const pages = [...results.querySelectorAll('[data-el-page-direction]:not(:disabled)')];
                replacement = pages.find(el => el.dataset.elPageDirection === focusTarget.direction) || pages[0];
            } else if (focusTarget?.type === 'similar') replacement = results.querySelector('[data-el-similar]');
            else if (focusTarget?.type === 'reset') replacement = results.querySelector('[data-el-reset]');
            else if (focusTarget?.type === 'chip') replacement = [...results.querySelectorAll('[data-el-chip]')].find(el => el.dataset.facet === focusTarget.name && el.dataset.value === focusTarget.value);
            else if (focusTarget?.type === 'facet-search' || focusTarget?.type === 'summary') {
                const facet = [...results.querySelectorAll('details[data-el-facet]')].find(el => el.dataset.elFacet === focusTarget.name);
                replacement = facet?.querySelector(focusTarget.type === 'summary' ? 'summary' : '[data-el-facet-search]');
            }
            // A server error replaces every result control with its retry button.
            // Do not move focus if the customer has already returned to typing.
            if (response.status === 503 && (focused || externalRetryFocused)) {
                replacement = results.querySelector('[data-el-retry]');
            }
            if (replacement) replacement.focus({preventScroll: true});
            else if (focusTarget || externalRetryFocused) query.focus({preventScroll: true});
            status.textContent = results.querySelector('[data-el-result-status]')?.dataset.elResultStatus || '';
            // Reuse Shopware's add-to-cart behavior and offcanvas rather than a
            // separate cart implementation with different purchase rules.
            if (results.querySelector('[data-add-to-cart]') && window.PluginManager) {
                try {
                    await window.PluginManager.initializePlugin('AddToCart', '[data-el-results] [data-add-to-cart]');
                } catch (error) {
                    // The native form still submits to Shopware if an optional
                    // storefront script cannot be loaded.
                    console.warn('Could not initialize the cart action', error);
                }
            }
            // Cart initialization is asynchronous. A newer search or a
            // closed dialog may have replaced this view while it was pending.
            if (current !== revision || !dialog.open) return;
            results.scrollTop = replacement && focusTarget?.type !== 'page' ? oldScroll : 0;
        } catch (error) {
            if (error.name === 'AbortError' || current !== revision) return;
            const retryFocus = results.contains(document.activeElement) || networkError.contains(document.activeElement);
            results.replaceChildren();
            networkError.hidden = false;
            if (retryFocus) networkError.querySelector('[data-el-retry]')?.focus({preventScroll: true});
            status.textContent = dialog.dataset.error;
        } finally {
            if (current === revision) results.setAttribute('aria-busy', 'false');
        }
    };

    const open = (source) => {
        if (dialog.open) return;
        opener = source;
        oldOverflow = document.documentElement.style.overflow;
        document.documentElement.style.overflow = 'hidden';
        dialog.showModal();
        query.focus();
        load(1);
    };
    const close = () => {
        stopVoice();
        restoringFocus = true;
        dialog.close();
    };
    // Native dialog focus restoration can precede its asynchronous close event.
    dialog.addEventListener('cancel', () => { restoringFocus = true; });
    dialog.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            close();
        }
    });
    dialog.addEventListener('close', () => {
        clearTimeout(timer);
        clearTimeout(popularityTimer);
        controller?.abort();
        stopVoice();
        if (voiceNotice) voiceNotice.hidden = true;
        ++revision;
        mobileExpanded = false;
        syncMobileView();
        document.documentElement.style.overflow = oldOverflow;
        restoringFocus = true;
        opener?.focus();
        restoringFocus = false;
    });
    document.addEventListener('click', (event) => {
        const trigger = event.target.closest('.search-toggle-btn, .header-search-input');
        if (!trigger || dialog.contains(trigger)) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        open(trigger);
    }, true);
    document.addEventListener('focusin', (event) => {
        if (!restoringFocus && event.target.matches('.header-search-input') && !dialog.open) open(event.target);
    });
    dialog.querySelectorAll('[data-el-close]').forEach(button => button.addEventListener('click', close));
    voice?.addEventListener('click', () => {
        if (!voiceSupported) return;
        if (recognition) {
            stopVoice();
            if (voiceNotice) voiceNotice.hidden = true;
            return;
        }
        try {
            const active = new Speech();
            if (!('processLocally' in active)) throw new Error('Local recognition is unavailable');
            active.processLocally = true;
            if (active.processLocally !== true) throw new Error('Local recognition is unavailable');
            active.lang = dialog.dataset.locale;
            active.continuous = false;
            active.interimResults = false;
            active.onresult = (event) => {
                if (recognition !== active || !dialog.open) return;
                const result = event.results?.[event.resultIndex];
                const text = result?.[0]?.transcript;
                if (typeof text !== 'string' || result.isFinal === false) return;
                clearTimeout(voiceTimer);
                voiceTimer = null;
                recognition = null;
                try { active.stop(); } catch (_) { /* Already stopped. */ }
                if (voiceNotice) voiceNotice.hidden = true;
                query.value = Array.from(text.trim()).slice(0, 150).join('');
                query.dispatchEvent(new Event('input', {bubbles: true}));
                query.focus();
            };
            active.onerror = () => {
                if (recognition !== active) return;
                stopVoice();
                voiceError();
            };
            active.onend = () => {
                if (recognition !== active) return;
                clearTimeout(voiceTimer);
                voiceTimer = null;
                recognition = null;
                syncVoice();
                if (voiceNotice) voiceNotice.hidden = true;
            };
            recognition = active;
            syncVoice();
            if (voiceNotice) {
                voiceNotice.textContent = dialog.dataset.voiceListening;
                voiceNotice.hidden = false;
            }
            voiceTimer = setTimeout(() => {
                if (recognition !== active) return;
                stopVoice();
                voiceError();
            }, 12000);
            active.start();
        } catch (_) {
            stopVoice();
            voiceError();
        }
    });
    clearQuery?.addEventListener('click', () => {
        stopVoice();
        if (voiceNotice) voiceNotice.hidden = true;
        query.value = '';
        rememberQuery();
        syncMobileView();
        includeSimilar = null;
        clearQuery.hidden = true;
        syncVoice();
        dialog.classList.remove('el-search--filters-open');
        query.focus();
        load(1, true);
    });
    dialog.querySelector('[data-el-query-form]').addEventListener('submit', (event) => {
        event.preventDefault();
        load(1, true);
    });
    query.addEventListener('input', () => {
        clearTimeout(popularityTimer);
        rememberQuery();
        stopVoice();
        if (voiceNotice) voiceNotice.hidden = true;
        includeSimilar = null;
        lastAttempt = null;
        syncMobileView();
        if (clearQuery) clearQuery.hidden = query.value === '';
        syncVoice();
        dialog.classList.remove('el-search--filters-open');
        controller?.abort();
        ++revision;
        clearTimeout(timer);
        timer = setTimeout(() => load(1, true), 300);
    });
    dialog.addEventListener('click', (event) => {
        if (event.target === dialog) {
            const rect = dialog.getBoundingClientRect();
            if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) {
                close();
                return;
            }
        }
        if (event.target.closest('[data-el-show-all]')) {
            expandMobile(true);
            return;
        }
        const suggestion = event.target.closest('[data-el-suggestion]');
        if (suggestion) {
            stopVoice();
            if (voiceNotice) voiceNotice.hidden = true;
            query.value = suggestion.dataset.elSuggestion;
            rememberQuery();
            includeSimilar = null;
            if (clearQuery) clearQuery.hidden = query.value === '';
            syncVoice();
            query.focus();
            load(1, true);
        }
        const similar = event.target.closest('[data-el-similar]');
        if (similar) {
            includeSimilar = similar.getAttribute('aria-pressed') !== 'true';
            load(1);
        }
        const toggle = event.target.closest('[data-el-filter-toggle]');
        if (toggle) {
            const expanded = dialog.classList.toggle('el-search--filters-open');
            toggle.setAttribute('aria-expanded', String(expanded));
        }
        const chip = event.target.closest('[data-el-chip]');
        if (chip && ['category', 'manufacturer'].includes(chip.dataset.facet)) {
            const checkbox = [...results.querySelectorAll('[data-el-filters] input[type="checkbox"]')]
                .find(input => input.name === `${chip.dataset.facet}[]` && input.value === chip.dataset.value);
            if (checkbox) {
                if (mobile.matches) expandMobile();
                checkbox.checked = !checkbox.checked;
                checkbox.dispatchEvent(new Event('change', {bubbles: true}));
            }
        }
        const more = event.target.closest('[data-el-show-more]');
        if (more) {
            const facet = more.closest('details');
            const expanded = facet.dataset.elExpanded !== 'true';
            facet.dataset.elExpanded = String(expanded);
            more.textContent = (expanded ? more.dataset.less : more.dataset.more) + (expanded ? ' ⌃' : ' ⌄');
            const search = facet.querySelector('[data-el-facet-search]');
            if (search) search.dispatchEvent(new Event('input', {bubbles: true}));
        }
        const page = event.target.closest('[data-el-page]');
        if (page && !page.disabled) load(Number(page.dataset.elPage));
        const carousel = event.target.closest('[data-el-carousel]');
        if (carousel) {
            const grid = results.querySelector('[data-el-carousel-grid]');
            if (grid) grid.scrollBy({left: Number(carousel.dataset.elCarousel) * grid.clientWidth * 0.75, behavior: 'smooth'});
        }
        if (event.target.closest('[data-el-reset]')) load(1, true);
        if (event.target.closest('[data-el-retry]')) load(1, lastAttempt === null, true);
    });
    dialog.addEventListener('change', (event) => {
        if (event.target.closest('[data-el-filters]') || event.target.matches('[data-el-sort]')) load(1);
    });
    dialog.addEventListener('input', (event) => {
        if (!event.target.matches('[data-el-facet-search]')) return;
        const value = event.target.value.toLocaleLowerCase();
        const facet = event.target.closest('details');
        for (const [index, label] of [...facet.querySelectorAll('[data-el-facet-value]')].entries()) {
            const matches = label.textContent.toLocaleLowerCase().includes(value);
            const shown = value !== '' || facet.dataset.elExpanded === 'true' || index < 5 || label.querySelector('input')?.checked;
            label.hidden = !matches || !shown;
        }
    });
    dialog.addEventListener('submit', (event) => {
        if (event.target.matches('[data-add-to-cart]')) close();
        if (event.target.matches('[data-el-filters]')) { event.preventDefault(); load(1); }
    }, true);
}
