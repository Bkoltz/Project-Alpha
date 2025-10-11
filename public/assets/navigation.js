/**
 * Client-side navigation for Project Alpha
 * Prevents full page reloads and provides SPA-like experience
 */
(function() {
    'use strict';
    
    // Track current page to prevent unnecessary updates
    let currentPage = getCurrentPage();
    
    // Cache for loaded content
    const contentCache = new Map();
    
    function getCurrentPage() {
        const urlParams = new URLSearchParams(window.location.search);
        return urlParams.get('page') || 'home';
    }
    
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
    
    function showLoadingState() {
        const mainContent = document.querySelector('.main-content');
        if (mainContent) {
            mainContent.style.opacity = '0.7';
            mainContent.style.pointerEvents = 'none';
        }
    }
    
    function hideLoadingState() {
        const mainContent = document.querySelector('.main-content');
        if (mainContent) {
            mainContent.style.opacity = '1';
            mainContent.style.pointerEvents = 'auto';
        }
    }
    
    async function loadPageContent(page) {
        // Check cache first
        if (contentCache.has(page)) {
            return contentCache.get(page);
        }
        
        try {
            const response = await fetch(`/?page=${encodeURIComponent(page)}`, {
                method: 'GET',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            
            const html = await response.text();
            
            // Extract main content from the response
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            const newMainContent = doc.querySelector('.main-content');
            
            if (newMainContent) {
                // Cache the content
                contentCache.set(page, newMainContent.innerHTML);
                return newMainContent.innerHTML;
            } else {
                // If no main content found, use the full response (fallback)
                return html;
            }
        } catch (error) {
            console.error('Failed to load page content:', error);
            // Fall back to full page reload
            return null;
        }
    }
    
    async function navigateToPage(page, updateHistory = true) {
        if (page === currentPage) {
            return; // Already on this page
        }
        
        showLoadingState();
        
        try {
            const content = await loadPageContent(page);
            
            if (content === null) {
                // Fallback to full page reload
                window.location.href = `/?page=${encodeURIComponent(page)}`;
                return;
            }
            
            // Update main content
            const mainContent = document.querySelector('.main-content');
            if (mainContent) {
                mainContent.innerHTML = content;
                
                // Re-initialize any JavaScript that might be needed for the new content
                initializePageScripts();
            }
            
            // Update browser history
            if (updateHistory) {
                const url = page === 'home' ? '/' : `/?page=${encodeURIComponent(page)}`;
                history.pushState({page}, '', url);
            }
            
            // Update navigation state
            updateActiveNavigation(page);
            currentPage = page;
            
            // Update page title if needed
            updatePageTitle(page);
            
        } catch (error) {
            console.error('Navigation error:', error);
            // Fallback to full page reload
            window.location.href = `/?page=${encodeURIComponent(page)}`;
        } finally {
            hideLoadingState();
        }
    }
    
    function updatePageTitle(page) {
        const pageTitles = {
            'home': 'Dashboard',
            'clients-list': 'List Clients',
            'clients-create': 'Create Client',
            'quotes-list': 'List Quotes',
            'quotes-create': 'Create Quote',
            'contracts-list': 'List Contracts',
            'contracts-create': 'Create Contract',
            'invoices-list': 'List Invoices',
            'invoices-create': 'Create Invoice',
            'payments-list': 'List Payments',
            'payments-create': 'Record Payment',
            'projects-list': 'Projects',
            'api-keys': 'API Keys',
            'settings': 'Settings'
        };
        
        const pageTitle = pageTitles[page] || 'Project Alpha';
        document.title = `${pageTitle} · Project Alpha`;
    }
    
    function initializePageScripts() {
        // Re-initialize any JavaScript components that might be needed
        // This function can be expanded based on what scripts your pages use
        
        // Example: Re-initialize form handlers, event listeners, etc.
        initializeFormHandlers();
    }
    
    function initializeFormHandlers() {
        // Add any form initialization logic here
        // This will be called after each page load
    }
    
    function handleNavigation(event) {
        const link = event.target.closest('a[data-page]');
        if (!link) return;
        
        // Don't interfere with external links or links with special attributes
        if (link.hostname !== window.location.hostname || 
            link.hasAttribute('target') || 
            event.metaKey || event.ctrlKey) {
            return;
        }
        
        event.preventDefault();
        
        const page = link.getAttribute('data-page');
        if (page) {
            navigateToPage(page);
        }
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
        history.replaceState({page: currentPage}, '', window.location.href);
        updateActiveNavigation(currentPage);
        
        console.log('Client-side navigation initialized');
    }
    
    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize);
    } else {
        initialize();
    }
    
})();