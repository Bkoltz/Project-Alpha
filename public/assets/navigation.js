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
        // Clear cache when loading a new page
        contentCache.clear();

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

            // Extract main content and scripts from the response
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            const newMainContent = doc.querySelector('.main-content');

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
                // If no main content found, use the full response (fallback)
                return { html, scripts: [] };
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
                // content may be an object with html and scripts
                const html = (typeof content === 'string') ? content : content.html;
                const scripts = (typeof content === 'string') ? [] : content.scripts || [];

                mainContent.innerHTML = html;

                // Execute extracted scripts (external and inline)
                scripts.forEach(s => {
                    try {
                        if (s.src) {
                            const scr = document.createElement('script');
                            scr.src = s.src;
                            scr.async = false;
                            document.body.appendChild(scr);
                        } else if (s.code) {
                            const scr = document.createElement('script');
                            scr.textContent = s.code;
                            document.body.appendChild(scr);
                        }
                    } catch (err) {
                        // ignore individual script errors
                        console.error('Error executing page script', err);
                    }
                });

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
        // Initialize client search boxes
        const clientSearchBoxes = document.querySelectorAll('[id^="clientSearchBox"]');
        clientSearchBoxes.forEach(box => {
            const suggestId = box.id.replace('Box', 'Suggest');
            const sug = document.getElementById(suggestId);
            if (!sug) return;
            
            // Remove old event listeners
            box.replaceWith(box.cloneNode(true));
            const newBox = document.getElementById(box.id);
            
            newBox.addEventListener('input', function() {
                const t = this.value.trim();
                if (!t) { sug.style.display = 'none'; sug.innerHTML = ''; return; }
                
                fetch('/?page=clients-search&term=' + encodeURIComponent(t))
                    .then(r => r.json())
                    .then(list => {
                        if (!Array.isArray(list) || list.length === 0) { 
                            sug.style.display = 'none'; 
                            sug.innerHTML = ''; 
                            return; 
                        }
                        sug.innerHTML = list.map(x => 
                            `<div data-id="${x.id}" data-name="${x.name}" style="padding:8px 10px;cursor:pointer">${x.name}</div>`
                        ).join('');
                        
                        Array.from(sug.children).forEach(el => {
                            el.addEventListener('click', function() {
                                // Handle client selection based on page type
                                const currentPage = getCurrentPage();
                                if (currentPage === 'client/clients-list') {
                                    window.location = '/?page=client/clients-list&selected_client_id=' + this.dataset.id;
                                } else {
                                    // For quote/invoice/contract creation pages
                                    newBox.value = this.dataset.name;
                                    const clientIdInput = document.querySelector('input[name="client_id"]');
                                    if (clientIdInput) {
                                        clientIdInput.value = this.dataset.id;
                                    }
                                    sug.style.display = 'none';
                                }
                            });
                        });
                        sug.style.display = 'block';
                    })
                    .catch(() => { sug.style.display = 'none'; });
            });
        });

        // Initialize line item handling for quotes/contracts/invoices
        const itemsContainer = document.querySelector('.items-container');
        if (itemsContainer) {
            const addItemBtn = document.querySelector('.add-item-btn');
            if (addItemBtn) {
                // Remove old event listener
                addItemBtn.replaceWith(addItemBtn.cloneNode(true));
                const newAddItemBtn = document.querySelector('.add-item-btn');
                
                newAddItemBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    const template = document.querySelector('.item-template');
                    if (template) {
                        const newRow = template.content.cloneNode(true);
                        itemsContainer.appendChild(newRow);
                        
                        // Initialize the new row's event listeners
                        initializeItemRow(itemsContainer.lastElementChild);
                    }
                });
                
                // Initialize existing rows
                document.querySelectorAll('.item-row').forEach(initializeItemRow);
            }
        }

        // Reinitialize document specific handlers
        initializeDocumentHandlers();
    }
    
    function initializeItemRow(row) {
        if (!row) return;
        
        const qtyInput = row.querySelector('input[name*="[quantity]"]');
        const priceInput = row.querySelector('input[name*="[price]"]');
        const totalSpan = row.querySelector('.line-total');
        const removeBtn = row.querySelector('.remove-item');
        
        function updateTotal() {
            const qty = parseFloat(qtyInput.value) || 0;
            const price = parseFloat(priceInput.value) || 0;
            const total = qty * price;
            totalSpan.textContent = total.toFixed(2);
            
            // Update document total
            updateDocumentTotal();
        }
        
        if (qtyInput && priceInput) {
            qtyInput.addEventListener('input', updateTotal);
            priceInput.addEventListener('input', updateTotal);
        }
        
        if (removeBtn) {
            removeBtn.addEventListener('click', function(e) {
                e.preventDefault();
                row.remove();
                updateDocumentTotal();
            });
        }
    }
    
    function updateDocumentTotal() {
        const subtotalElement = document.querySelector('.subtotal-amount');
        const taxPercentInput = document.querySelector('input[name="tax_percent"]');
        const discountTypeSelect = document.querySelector('select[name="discount_type"]');
        const discountValueInput = document.querySelector('input[name="discount_value"]');
        const totalElement = document.querySelector('.total-amount');
        
        if (!subtotalElement || !totalElement) return;
        
        // Calculate subtotal
        let subtotal = 0;
        document.querySelectorAll('.line-total').forEach(span => {
            subtotal += parseFloat(span.textContent) || 0;
        });
        
        // Apply discount
        let discountAmount = 0;
        if (discountTypeSelect && discountValueInput) {
            const discountValue = parseFloat(discountValueInput.value) || 0;
            if (discountTypeSelect.value === 'percent') {
                discountAmount = subtotal * (discountValue / 100);
            } else if (discountTypeSelect.value === 'fixed') {
                discountAmount = discountValue;
            }
        }
        
        // Apply tax
        let taxAmount = 0;
        if (taxPercentInput) {
            const taxPercent = parseFloat(taxPercentInput.value) || 0;
            taxAmount = (subtotal - discountAmount) * (taxPercent / 100);
        }
        
        // Update display
        subtotalElement.textContent = subtotal.toFixed(2);
        if (document.querySelector('.discount-amount')) {
            document.querySelector('.discount-amount').textContent = discountAmount.toFixed(2);
        }
        if (document.querySelector('.tax-amount')) {
            document.querySelector('.tax-amount').textContent = taxAmount.toFixed(2);
        }
        totalElement.textContent = (subtotal - discountAmount + taxAmount).toFixed(2);
    }
    
    function initializeDocumentHandlers() {
        // Initialize discount type changes
        const discountTypeSelect = document.querySelector('select[name="discount_type"]');
        const discountValueInput = document.querySelector('input[name="discount_value"]');
        const taxPercentInput = document.querySelector('input[name="tax_percent"]');
        
        if (discountTypeSelect && discountValueInput) {
            discountTypeSelect.addEventListener('change', updateDocumentTotal);
            discountValueInput.addEventListener('input', updateDocumentTotal);
        }
        
        if (taxPercentInput) {
            taxPercentInput.addEventListener('input', updateDocumentTotal);
        }
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
        
        event.preventDefault();
        
            const url = new URL(link.href);
            const page = url.searchParams.get('page');
            if (page) {
                // Include any additional parameters from the URL
                const fullPage = Array.from(url.searchParams)
                    .filter(([key]) => key !== 'page')
                    .reduce((acc, [key, value]) => `${acc}&${key}=${value}`, page);
                navigateToPage(fullPage);
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
        
        // Initialize scripts for the initial page load
        initializePageScripts();
        
        console.log('Client-side navigation initialized');
    }
    
    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize);
    } else {
        initialize();
    }
    
})();