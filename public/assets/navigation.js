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

function getCurrentPage() {
    const urlParams = new URLSearchParams(window.location.search);
    return urlParams.get('page') || 'home';
}

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

    const idx = page.indexOf('&');
    const pagePart = idx === -1 ? page : page.substring(0, idx);
    const rest = idx === -1 ? '' : page.substring(idx + 1);
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

        if (!newMainContent) {
            console.error('ERROR: .main-content not found in response!');
            console.log('Document body:', document.body ? document.body.innerHTML.substring(0, 500) : 'No body');
            console.log('Checking for section tag:', doc.querySelector('section') ? 'Found' : 'Not found');
        }

        if (newMainContent) {
            const scripts = Array.from(newMainContent.querySelectorAll('script'));
            const inlineScripts = scripts.map(s => ({
                src: s.src || null,
                code: s.src ? null : s.textContent
            }));

            // Remove scripts from the HTML fragment to avoid duplicate execution when inserted
            scripts.forEach(s => s.remove());

            // Cache the content
            contentCache.set(page, newMainContent.innerHTML);
            return { html: newMainContent.innerHTML, scripts: inlineScripts };
        } else {
            // Response has no .main-content — this is an auth/public page (login, logout, etc.)
            // Force a full page reload so the browser renders the correct layout
            return null;
        }
    } catch (error) {
        console.error('Failed to load page content:', error);
        // Fall back to full page reload
        return null;
    }
}

async function navigateToPage(page, updateHistory = true) {
    if (page === currentPage && !page.includes('selected_client_id')) {
        return; // Already on this page
    }

    console.log("navigatyinh");

    //Removed previously added scripts
    cachedScripts.forEach(s => {
        s.remove();
    });

    cachedScripts.length = 0;

    try {
        const content = await loadPageContent(page);

        if (content === null) {
            // Fallback to full page reload using canonical builder
            window.location.href = buildUrlFromPageString(page);
            return;
        }

        // Update main content
        const mainContent = document.querySelector('.main-content');
        if (mainContent) {
            // content may be an object with html and scripts
            const html = (typeof content === 'string') ? content : content.html;
            const scripts = (typeof content === 'string') ? [] : content.scripts;

            mainContent.innerHTML = html;

            // Execute extracted scripts (external and inline)
            scripts.forEach((s, idx) => {
                try {
                    const scr = document.createElement('script');
                    cachedScripts.push(scr);

                    if (s.src) {
                        scr.src = s.src;
                        scr.async = false;
                    } else if (s.code) {
                        scr.textContent = s.code;
                    }

                    document.body.appendChild(scr);
                } catch (err) {
                    // ignore individual script errors
                    console.error('Error executing page script', err);
                }
            });

            // Dispatch an event so per-page assets (e.g., client-create.js) can re-initialize
            try { document.dispatchEvent(new Event('pageLoaded')); } catch (err) { /* ignore */ }
        }

        // Update browser history
        if (updateHistory) {
            // Use same URL builder so history uses the canonical query string
            const absolute = buildUrlFromPageString(page);
            const rel = absolute.replace(window.location.origin, '');
            history.pushState({ page }, '', rel);
        }

        // Update navigation state
        updateActiveNavigation(page);
        currentPage = page;

        // Update page title if needed
        updatePageTitle(page);

    } catch (error) {
        console.error('Navigation error:', error);
        // Fallback to full page reload
        window.location.href = buildUrlFromPageString(page);
    }
}

function updatePageTitle(page) {
    const pageTitles = {
        'home': 'Dashboard',
        'client/clients-list': 'List Clients',
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
        'api-keys': 'API Keys',
        'settings': 'Settings',
        'financial/financial-dashboard': 'Financial Dashboard',
        'financial/audit': 'Audit'
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

    // Allow normal navigation for PDF/print/download/serve-upload/auth links
    if (pageName.includes('-print') ||
        pageName.includes('-pdf') ||
        pageName.includes('serve-upload') ||
        pageName === 'logout' ||
        pageName === 'login' ||
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

    navigateToPage(fullPage);
}

// Handle browser back/forward buttons
function handlePopState(event) {
    const page = event.state?.page || getCurrentPage();
    navigateToPage(page, false); // Don't update history since this is from history
}

// Initialize client-side navigation
function initialize() {
    // Set up event listeners
    document.addEventListener('click', handleNavigation);
    window.addEventListener('popstate', handlePopState);

    // Set initial state
    history.replaceState({ page: currentPage }, '', window.location.href);
    updateActiveNavigation(currentPage);
}

// Initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initialize);
} else {
    initialize();
}