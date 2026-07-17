/**
 * Client-side navigation for Project Alpha
 * Prevents full page reloads and provides SPA-like experience
 */

'use strict';

// Track current page to prevent unnecessary updates
let currentPage = getCurrentPage();

// Cache for loaded content
const contentCache = new Map();
const cachedScripts = new Array();
let navigationInitialized = false;
const pageInitializers = new Map();
let pageCleanupCallbacks = [];

function getCurrentPage() {
    const urlParams = new URLSearchParams(window.location.search);
    const pathPages = {
        '/time': 'workforce/time',
        '/workforce': 'workforce/overview',
        '/approvals': 'workforce/approvals',
        '/pay': 'workforce/pay'
    };
    return urlParams.get('page') || pathPages[window.location.pathname] || 'home';
}

function normalizePageName(page) {
    return String(page || getCurrentPage() || 'home').split('&')[0];
}

function getMainContentRoot() {
    return document.querySelector('.main-content') || document;
}

function runPageCleanups() {
    pageCleanupCallbacks.forEach(cleanup => {
        try {
            cleanup();
        } catch (err) {
            console.error('Page cleanup failed', err);
        }
    });
    pageCleanupCallbacks = [];
}

function runPageInitializers(page, root = getMainContentRoot()) {
    const normalizedPage = normalizePageName(page);
    const pageSpecific = pageInitializers.get(normalizedPage) || [];
    const globalInitializers = pageInitializers.get('*') || [];
    const initializers = Array.from(new Set([...globalInitializers, ...pageSpecific]));

    initializers.forEach(initializer => {
        try {
            const cleanup = initializer({
                page: normalizedPage,
                fullPage: page || normalizedPage,
                root: root || document
            });
            if (typeof cleanup === 'function') {
                pageCleanupCallbacks.push(cleanup);
            }
        } catch (err) {
            console.error('Page initializer failed for %s', normalizedPage, err);
        }
    });
}

function registerPageInitializer(pages, initializer) {
    if (typeof initializer !== 'function') return;

    const pageList = Array.isArray(pages) ? pages : [pages];
    const normalizedPages = pageList
        .filter(Boolean)
        .map(normalizePageName);
    const initializerId = initializer.pageInitializerId || initializer.__pageInitializerId || initializer.name || '';

    normalizedPages.forEach(page => {
        const initializers = pageInitializers.get(page) || [];
        const existingIndex = initializerId
            ? initializers.findIndex(existing => (
                existing.pageInitializerId ||
                existing.__pageInitializerId ||
                existing.name ||
                ''
            ) === initializerId)
            : -1;

        if (existingIndex >= 0) {
            initializers[existingIndex] = initializer;
        } else if (!initializers.includes(initializer)) {
            initializers.push(initializer);
        }
        pageInitializers.set(page, initializers);
    });

    const activePage = normalizePageName(currentPage || getCurrentPage());
    if (normalizedPages.includes(activePage) || normalizedPages.includes('*')) {
        runPageInitializers(activePage, getMainContentRoot());
    }
}

window.ProjectAlpha = window.ProjectAlpha || {};
window.ProjectAlpha.registerPage = registerPageInitializer;
window.ProjectAlpha.runPageInitializers = runPageInitializers;
window.ProjectAlpha.cleanupPage = runPageCleanups;

/* 
    Updates the navigation sidebar 
*/
function updateActiveNavigation(page) {
    // Remove active class from all nav links
    document.querySelectorAll('.primary-nav a').forEach(link => {
        link.classList.remove('active');
    });

    // Add active class to current page link
    const activeLink = document.querySelector(`[data-page="${page}"]`);
    if (activeLink) {
        activeLink.classList.add('active');
    }
}

// Build a canonical URL from a "page" string which may include additional
// query parameters (e.g. "contract/contracts-edit&id=3"). This ensures we
// never put encoded ampersands/equals inside the `page` GET param.
function buildUrlFromPageString(page) {
    const u = new URL(window.location.origin + '/');
    if (!page) {
        u.searchParams.set('page', 'home');
        return u.href;
    }

    const separatorIndex = page.indexOf('&');
    const pagePart = separatorIndex >= 0 ? page.slice(0, separatorIndex) : page;
    const rest = separatorIndex >= 0 ? page.slice(separatorIndex + 1) : '';
    u.searchParams.set('page', pagePart);

    if (rest) {
        const tmp = new URLSearchParams(rest);
        for (const [k, v] of tmp) {
            // Only set missing params so we don't overwrite intentional GETs
            if (!u.searchParams.has(k)) {
                u.searchParams.set(k, v);
            }
        }
    }
    return u.href;
}

async function loadPageContent(page) {
    // Clear cache when loading a new page
    contentCache.clear();

    try {
        // Build proper URL using URLSearchParams to avoid double-encoding
        let url = buildUrlFromPageString(page);
        // Add cache-busting parameter
        const separator = url.includes('?') ? '&' : '?';
        url = url + separator + '_=' + Date.now();

        const response = await fetch(url, {
            method: 'GET',
            headers: {
                'Cache-Control': 'no-cache, no-store, must-revalidate',
                'Pragma': 'no-cache'
            }
        });

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }

        const html = await response.text();

        // Extract main content and scripts from the response
        const parser = new DOMParser();
        const doc = parser.parseFromString(html, 'text/html');
        const newMainContent = doc.querySelector('.main-content');

        const redirectedPage = (() => {
            try { return new URL(response.url).searchParams.get('page'); } catch (err) { return null; }
        })();
        const looksLikeLogin = redirectedPage === 'login' || !!doc.querySelector('form[action*="login"], .auth-wrap input[type="password"]');
        if (looksLikeLogin) {
            return { authRequired: true, url: response.url || '/?page=login' };
        }

        if (!newMainContent) {
            console.error('ERROR: .main-content not found in response!');
        }

        if (newMainContent) {
            const scripts = Array.from(newMainContent.querySelectorAll('script'));

            // Debug: check the parsed response for scripts outside the main fragment.
            if (scripts.length === 0 && doc.querySelector('script')) {
                console.warn('WARNING: Script tags exist in the response but not in the main content!');
            }
            const inlineScripts = scripts.map(s => ({
                src: s.getAttribute('src') || null,
                code: s.getAttribute('src') ? null : s.textContent,
                type: s.getAttribute('type') || ''
            }));

            // Remove scripts from the HTML fragment to avoid duplicate execution when inserted
            scripts.forEach(s => s.remove());

            // Cache the content
            contentCache.set(page, newMainContent.innerHTML);
            return { html: newMainContent.innerHTML, scripts: inlineScripts };
        }

        // A full document must never be injected inside the authenticated shell.
        return null;
    } catch (error) {
        console.error('Failed to load page content:', error);
        // Fall back to full page reload
        return null;
    }
}

function dispatchPageLoaded(page) {
    try {
        document.dispatchEvent(new CustomEvent('pageLoaded', { detail: { page } }));
    } catch (err) {
        try { document.dispatchEvent(new Event('pageLoaded')); } catch (e) { /* ignore */ }
    }
}

function appendPageScript(scriptData) {
    return new Promise(resolve => {
        try {
            const scr = document.createElement('script');
            let blobUrl = '';
            cachedScripts.push(scr);

            if (scriptData.type) {
                scr.type = scriptData.type;
            }

            scr.async = false;

            const cleanup = () => {
                if (blobUrl) {
                    try { URL.revokeObjectURL(blobUrl); } catch (e) { /* ignore */ }
                }
                resolve();
            };

            scr.addEventListener('load', cleanup, { once: true });
            scr.addEventListener('error', function () {
                console.error('Error loading page script', scriptData.src || '[inline]');
                cleanup();
            }, { once: true });

            if (scriptData.src) {
                scr.src = scriptData.src;
            } else if (scriptData.code) {
                // Run inline fragment scripts as same-origin blob scripts so CSP stays centralized.
                blobUrl = URL.createObjectURL(new Blob([scriptData.code], { type: 'application/javascript' }));
                scr.src = blobUrl;
            } else {
                resolve();
                return;
            }

            document.body.appendChild(scr);
        } catch (err) {
            console.error('Error executing page script', err);
            resolve();
        }
    });
}

async function executePageScripts(scripts) {
    for (const scriptData of scripts) {
        await appendPageScript(scriptData);
    }
}

function scrollToPageHash(targetHash) {
    if (!targetHash || targetHash === '#') return;
    let targetId = targetHash.slice(1);
    try {
        targetId = decodeURIComponent(targetId);
    } catch (error) {
        return;
    }
    requestAnimationFrame(() => {
        const target = document.getElementById(targetId);
        if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
}

async function navigateToPage(page, updateHistory = true, targetHash = '') {
    if (page === currentPage && !page.includes('selected_client_id')) {
        if (targetHash) {
            const samePageUrl = new URL(window.location.href);
            samePageUrl.hash = targetHash;
            if (updateHistory) history.pushState({ page }, '', samePageUrl.href);
            scrollToPageHash(targetHash);
        }
        return; // Already on this page
    }

    runPageCleanups();

    //Removed previously added scripts
    cachedScripts.forEach(s => {
        s.remove();
    });

    cachedScripts.length = 0;

    try {
        const content = await loadPageContent(page);

        if (content && content.authRequired) {
            window.location.href = content.url || '/?page=login';
            return;
        }

        if (content === null) {
            // Fallback to full page reload using canonical builder
            const fallbackUrl = new URL(buildUrlFromPageString(page));
            fallbackUrl.hash = targetHash;
            window.location.href = fallbackUrl.href;
            return;
        }

        // Update main content
        const mainContent = document.querySelector('.main-content');
        if (mainContent) {
            // content may be an object with html and scripts
            const html = (typeof content === 'string') ? content : content.html;
            const scripts = (typeof content === 'string') ? [] : content.scripts;

            mainContent.innerHTML = html;
            initializeDocumentFilterToggles();

            // Update browser history before page scripts run so initializers see the new URL.
            if (updateHistory) {
                // Use same URL builder so history uses the canonical query string
                const historyUrl = new URL(buildUrlFromPageString(page));
                historyUrl.hash = targetHash;
                const rel = historyUrl.href.replace(window.location.origin, '');
                history.pushState({ page }, '', rel);
            }

            // Update navigation state
            updateActiveNavigation(page);
            currentPage = page;

            // Update page title if needed
            updatePageTitle(page);

            await executePageScripts(scripts);
            runPageInitializers(page, mainContent);

            // Keep the legacy event for older scripts while new page scripts use ProjectAlpha.registerPage.
            dispatchPageLoaded(page);
            scrollToPageHash(targetHash);
        }

    } catch (error) {
        console.error('Navigation error:', error);
        // Fallback to full page reload
        const fallbackUrl = new URL(buildUrlFromPageString(page));
        fallbackUrl.hash = targetHash;
        window.location.href = fallbackUrl.href;
    }
}

function updatePageTitle(page) {
    const pageTitles = {
        'home': 'Dashboard',
        'client/clients-list': 'List Clients',
        'client/client-details': 'Client Details',
        'client/clients-create': 'Create Client',
        'client/clients-edit': 'Edit Client',
        'client/archived-clients': 'Archived Clients',
        'quote/quotes-list': 'List Quotes',
        'quote/quotes-create': 'Create Quote',
        'quote/quotes-edit': 'Edit Quote',
        'contract/contracts-list': 'List Contracts',
        'contract/contracts-create': 'Create Contract',
        'contract/contracts-edit': 'Edit Contract',
        'invoice/invoices-list': 'List Invoices',
        'invoice/invoices-create': 'Create Invoice',
        'invoice/invoices-edit': 'Edit Invoice',
        'payments/payments-list': 'List Payments',
        'payments/payments-create': 'Record Payment',
        'jobs/jobs-list': 'Jobs',
        'jobs/job-details': 'Job Details',
        'project/projects-list': 'Projects',
        'project/projects-create': 'Create Project',
        'project/projects-edit': 'Edit Project',
        'api-keys': 'API Keys',
        'api-keys-new': 'New API Key',
        'api-keys-edit': 'Edit API Key',
        'settings': 'Settings',
        'financial/financial-dashboard': 'Financial Dashboard',
        'financial/expenses-list': 'Assets & Expenses',
        'financial/asset-form': 'Asset',
        'financial/asset-detail': 'Asset Details',
        'financial/audit': 'Audit & Reports',
        'account': 'My Account',
        'account-edit': 'Edit Account',
        'workforce/overview': 'Workforce Overview',
        'workforce/time': 'Time',
        'workforce/approvals': 'Time Approvals',
        'workforce/pay': 'Employee Pay'
    };

    const pageTitle = pageTitles[page] || 'Project Alpha';
    document.title = `${pageTitle} · Project Alpha`;
}

function handleNavigation(event) {
    const link = event.target.closest('a[href^="/?page="]');
    if (!link) return;

    // Don't interfere with external links or links with special attributes
    if (link.hostname !== window.location.hostname ||
        link.hasAttribute('target') ||
        event.metaKey || event.ctrlKey) {
        return;
    }

    // Parse the link URL and page param
    let linkUrl;
    try {
        linkUrl = new URL(link.href);
    } catch (err) {
        return; // invalid URL, let browser handle
    }

    const pageName = linkUrl.searchParams.get('page');
    if (!pageName) return; // nothing for client-side router to do

    // Allow normal navigation for PDF/print/download/serve-upload links
    if (pageName.includes('-print') ||
        pageName.includes('-pdf') ||
        pageName.includes('project-file-download') ||
        pageName.includes('serve-upload') ||
        link.hasAttribute('data-skip-nav') ||
        link.hasAttribute('download')) {
        return;
    }

    // Intercept and navigate via client-side router
    event.preventDefault();

    const additionalParams = Array.from(linkUrl.searchParams)
        .filter(([key]) => key !== 'page')
        .map(([key, value]) => `${key}=${encodeURIComponent(value)}`)
        .join('&');

    const fullPage = additionalParams ? `${pageName}&${additionalParams}` : pageName;

    navigateToPage(fullPage, true, linkUrl.hash);
}

// Handle browser back/forward buttons
function handlePopState(event) {
    const page = event.state?.page || getCurrentPage();
    navigateToPage(page, false, window.location.hash); // Don't update history since this is from history
}

function getDocumentFilterStorageKey(buttonOrPanel) {
    return buttonOrPanel?.getAttribute('data-filter-storage-key') || '';
}

function setDocumentFilterPanel(button, panel, open, persist = false) {
    if (!button || !panel) return;

    panel.style.display = open ? 'block' : 'none';
    button.setAttribute('aria-expanded', open ? 'true' : 'false');
    button.textContent = open ? 'Hide filters' : 'More filters';

    if (persist) {
        const key = getDocumentFilterStorageKey(button) || getDocumentFilterStorageKey(panel);
        if (key) {
            try {
                window.localStorage.setItem(key, open ? 'open' : 'closed');
            } catch (err) {
                // Storage can be unavailable in private or locked-down contexts.
            }
        }
    }
}

function initializeDocumentFilterToggles(root = document) {
    root.querySelectorAll('[data-filter-toggle]').forEach(button => {
        const panel = document.getElementById(button.getAttribute('data-filter-toggle'));
        if (!panel) return;

        const key = getDocumentFilterStorageKey(button) || getDocumentFilterStorageKey(panel);
        let open = false;

        if (key) {
            try {
                open = window.localStorage.getItem(key) === 'open';
            } catch (err) {
                open = false;
            }
        }

        setDocumentFilterPanel(button, panel, open, false);
    });
}

function handleDocumentFilterToggle(event) {
    const button = event.target.closest('[data-filter-toggle]');
    if (!button) return;

    const panel = document.getElementById(button.getAttribute('data-filter-toggle'));
    if (!panel) return;

    event.preventDefault();
    const isOpen = button.getAttribute('aria-expanded') === 'true';
    setDocumentFilterPanel(button, panel, !isOpen, true);
}

function handleFilePickerAction(event) {
    const trigger = event.target.closest('[data-file-picker-target]');
    if (!trigger) return;

    const inputId = trigger.getAttribute('data-file-picker-target');
    const input = inputId ? document.getElementById(inputId) : null;
    if (!(input instanceof HTMLInputElement) || input.type !== 'file') return;

    event.preventDefault();
    input.click();
}

function handleFileAutoSubmit(event) {
    const input = event.target.closest('input[type="file"][data-submit-on-file]');
    if (!(input instanceof HTMLInputElement) || !input.form || input.files.length === 0) return;

    if (typeof input.form.requestSubmit === 'function') {
        input.form.requestSubmit();
    } else {
        input.form.submit();
    }
}

// Initialize client-side navigation
function initialize() {
    if (navigationInitialized) return;
    navigationInitialized = true;

    // Set up event listeners
    document.addEventListener('click', handleNavigation);
    document.addEventListener('click', handleDocumentFilterToggle);
    document.addEventListener('click', handleFilePickerAction);
    document.addEventListener('change', handleFileAutoSubmit);
    window.addEventListener('popstate', handlePopState);

    // Set initial state
    history.replaceState({ page: currentPage }, '', window.location.href);
    updateActiveNavigation(currentPage);
    initializeDocumentFilterToggles();
    runPageInitializers(currentPage, getMainContentRoot());

    initMobileNav();
}

// ---------------------------------------------------------------------
// Mobile drawer navigation (hamburger toggle + overlay)
// ---------------------------------------------------------------------
function setNavOpen(open) {
    document.body.classList.toggle('nav-open', open);
    const toggle = document.querySelector('.nav-toggle');
    if (toggle) toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
}

function initMobileNav() {
    const toggle = document.querySelector('.nav-toggle');
    const overlay = document.querySelector('.nav-overlay');
    if (!toggle) return;

    toggle.addEventListener('click', function (e) {
        e.stopPropagation();
        setNavOpen(!document.body.classList.contains('nav-open'));
    });

    if (overlay) {
        overlay.addEventListener('click', function () { setNavOpen(false); });
    }

    // Close the drawer after a nav link is tapped
    document.querySelectorAll('.side-nav a').forEach(function (link) {
        link.addEventListener('click', function () { setNavOpen(false); });
    });

    // Escape closes the drawer
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && document.body.classList.contains('nav-open')) {
            setNavOpen(false);
        }
    });

    // Reset state when resizing up to desktop
    window.addEventListener('resize', function () {
        if (window.innerWidth > 900) setNavOpen(false);
    });
}

// Initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initialize);
} else {
    initialize();
}
