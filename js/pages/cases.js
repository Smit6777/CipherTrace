/**
 * Novalite Cases Page Logic
 */

let currentAbortController = null;

// DOM Elements
const searchInput = document.getElementById('search-input');
const statusFilter = document.getElementById('status-filter');
const sortSelect = document.selectElementById || document.getElementById('sort-select'); // fallback just in case
const clearBtn = document.getElementById('clear-filters-btn');
const retryBtn = document.getElementById('retry-btn');
const resultsSummary = document.getElementById('results-summary');

const loadingState = document.getElementById('loading-state');
const errorState = document.getElementById('error-state');
const emptyState = document.getElementById('empty-state');
const tableContainer = document.getElementById('desktop-table-container');
const tbody = document.getElementById('cases-tbody');
const mobileCardsContainer = document.getElementById('mobile-cards-container');
const paginationContainer = document.getElementById('pagination-container');

const prevBtn = document.getElementById('prev-page');
const nextBtn = document.getElementById('next-page');
const pageNumbers = document.getElementById('page-numbers');

let currentPage = 1;
let totalPages = 1;
let debounceTimeout = null;

/**
 * Initialize
 */
function init() {
    bindEvents();
    syncFiltersFromURL();
    fetchCases();
}

/**
 * Bind DOM events
 */
function bindEvents() {
    searchInput.addEventListener('input', () => {
        clearTimeout(debounceTimeout);
        debounceTimeout = setTimeout(() => {
            currentPage = 1;
            fetchCases();
        }, 300);
    });

    statusFilter.addEventListener('change', () => {
        currentPage = 1;
        fetchCases();
    });

    sortSelect.addEventListener('change', () => {
        currentPage = 1;
        fetchCases();
    });

    clearBtn.addEventListener('click', () => {
        searchInput.value = '';
        statusFilter.value = '';
        sortSelect.value = 'desc';
        currentPage = 1;
        fetchCases();
    });

    retryBtn.addEventListener('click', () => {
        fetchCases();
    });

    prevBtn.addEventListener('click', () => {
        if (currentPage > 1) {
            currentPage--;
            fetchCases();
        }
    });

    nextBtn.addEventListener('click', () => {
        if (currentPage < totalPages) {
            currentPage++;
            fetchCases();
        }
    });
}

/**
 * Sync state with URL params
 */
function syncFiltersFromURL() {
    const params = new URLSearchParams(window.location.search);
    if (params.has('search')) searchInput.value = params.get('search');
    if (params.has('status')) statusFilter.value = params.get('status');
    if (params.has('sort')) sortSelect.value = params.get('sort');
    if (params.has('page')) currentPage = parseInt(params.get('page'), 10) || 1;
}

/**
 * Update URL from state
 */
function updateURL() {
    const params = new URLSearchParams();
    if (searchInput.value.trim()) params.set('search', searchInput.value.trim());
    if (statusFilter.value) params.set('status', statusFilter.value);
    if (sortSelect.value !== 'desc') params.set('sort', sortSelect.value);
    if (currentPage > 1) params.set('page', currentPage);
    
    const newUrl = `${window.location.pathname}${params.toString() ? '?' + params.toString() : ''}`;
    window.history.replaceState({}, '', newUrl);
}

/**
 * Show view logic
 */
function setViewState(state) {
    loadingState.classList.add('hidden');
    errorState.classList.add('hidden');
    emptyState.classList.add('hidden');
    tableContainer.classList.add('hidden');
    mobileCardsContainer.classList.add('hidden');
    paginationContainer.classList.add('hidden');
    resultsSummary.textContent = '';

    if (state === 'loading') {
        loadingState.classList.remove('hidden');
    } else if (state === 'error') {
        errorState.classList.remove('hidden');
    } else if (state === 'empty') {
        emptyState.classList.remove('hidden');
    } else if (state === 'data') {
        tableContainer.classList.remove('hidden');
        mobileCardsContainer.classList.remove('hidden');
        paginationContainer.classList.remove('hidden');
    }
}

/**
 * Fetch Cases
 */
async function fetchCases() {
    if (currentAbortController) {
        currentAbortController.abort();
    }
    currentAbortController = new AbortController();

    updateURL();
    setViewState('loading');
    
    const params = new URLSearchParams();
    params.set('search', searchInput.value.trim());
    params.set('status', statusFilter.value);
    params.set('sort', sortSelect.value || 'desc');
    params.set('page', currentPage);

    try {
        const data = await apiFetch(`api/cases.php?${params.toString()}`, {
            signal: currentAbortController.signal
        });
        
        renderData(data);
    } catch (err) {
        if (err.name === 'AbortError') return;
        const msg = document.getElementById('error-message');
        if (err.data && err.data.message) {
            msg.textContent = `Error: ${err.data.message}`;
        } else {
            msg.textContent = 'Unable to load workflow cases. Check that Apache and the Novalite API are running.';
        }
        setViewState('error');
        showToast('Failed to load workflow cases', 'error');
    }
}

/**
 * Render data to table and mobile view
 */
function renderData(data) {
    const rows = data.rows || data.cases || [];
    totalPages = data.totalPages || 1;
    // Handle backend returning different structure
    if (data.cases && !data.rows) {
        const limit = data.limit || 20;
        totalPages = Math.ceil((data.total || rows.length) / limit);
        if(totalPages < 1) totalPages = 1;
    }
    
    const total = data.total !== undefined ? data.total : rows.length;

    if (rows.length === 0) {
        setViewState('empty');
        return;
    }

    resultsSummary.textContent = `Showing ${rows.length} of ${total} cases`;
    
    tbody.innerHTML = '';
    mobileCardsContainer.innerHTML = '';

    rows.forEach(row => {
        // Safe access
        const caseId = row.case_id || row.case_ref || '—';
        const rawVendor = row.vendor_name || row.vendorName;
        const vendor = rawVendor ? rawVendor : 'Unmatched vendor';
        const sender = row.real_sender_email || row.sender_email || '—';
        const type = row.request_type || '—';
        const status = normalizeStatus(row.current_status || row.status);
        const created = formatDateTime(row.created_at);
        
        // Desktop Table Row
        const tr = document.createElement('tr');
        
        const tdId = createSafeElement('td', caseId);
        const tdVendor = createSafeElement('td', vendor);
        const tdSender = createSafeElement('td', sender);
        const tdType = createSafeElement('td', type);
        
        const tdStatus = document.createElement('td');
        const badge = createSafeElement('span', statusLabel(status), statusClass(status));
        tdStatus.appendChild(badge);
        
        const tdDate = createSafeElement('td', created);
        
        const tdAction = document.createElement('td');
        const link = document.createElement('a');
        link.href = `evidence.html?id=${encodeURIComponent(caseId)}`;
        link.textContent = 'View Evidence';
        link.className = 'btn-secondary';
        link.style.textDecoration = 'none';
        link.style.padding = '0.4rem 0.8rem';
        link.style.display = 'inline-block';
        link.style.borderRadius = '4px';
        link.style.fontSize = '0.875rem';
        tdAction.appendChild(link);
        
        tr.append(tdId, tdVendor, tdSender, tdType, tdStatus, tdDate, tdAction);
        tbody.appendChild(tr);

        // Mobile Card
        const card = document.createElement('div');
        card.className = 'mobile-card';
        
        const createRow = (label, elOrText) => {
            const r = document.createElement('div');
            r.className = 'mobile-card-row';
            const l = createSafeElement('div', label, 'mobile-card-label');
            const v = document.createElement('div');
            v.className = 'mobile-card-value';
            if (typeof elOrText === 'string') {
                v.textContent = elOrText;
            } else {
                v.appendChild(elOrText);
            }
            r.append(l, v);
            return r;
        };

        const mBadge = createSafeElement('span', statusLabel(status), statusClass(status));
        const mLink = link.cloneNode(true);
        mLink.style.width = '100%';
        mLink.style.textAlign = 'center';
        
        card.append(
            createRow('Case ID', caseId),
            createRow('Vendor', vendor),
            createRow('Sender', sender),
            createRow('Type', type),
            createRow('Status', mBadge),
            createRow('Date', created)
        );
        
        const actionRow = document.createElement('div');
        actionRow.style.marginTop = '0.5rem';
        actionRow.appendChild(mLink);
        card.appendChild(actionRow);

        mobileCardsContainer.appendChild(card);
    });

    renderPagination();
    setViewState('data');
}

/**
 * Render pagination controls
 */
function renderPagination() {
    prevBtn.disabled = currentPage <= 1;
    nextBtn.disabled = currentPage >= totalPages;
    
    pageNumbers.innerHTML = '';
    
    // Compact pagination logic (max 5 visible buttons)
    let startPage = Math.max(1, currentPage - 2);
    let endPage = Math.min(totalPages, startPage + 4);
    
    if (endPage - startPage < 4) {
        startPage = Math.max(1, endPage - 4);
    }

    for (let i = startPage; i <= endPage; i++) {
        const btn = document.createElement('button');
        btn.textContent = i;
        btn.className = 'btn-secondary';
        if (i === currentPage) {
            btn.classList.add('active');
        }
        btn.addEventListener('click', () => {
            currentPage = i;
            fetchCases();
        });
        pageNumbers.appendChild(btn);
    }
}

document.addEventListener('DOMContentLoaded', init);
