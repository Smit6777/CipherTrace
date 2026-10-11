/**
 * js/pages/cases.js
 * Workflow Cases Table Controller (Screen B)
 * Fully hardened, debounced search, clean pagination, zero innerHTML injection.
 */

let currentAbortController = null;
let currentPage = 1;
let totalPages = 1;
let debounceTimeout = null;

document.addEventListener('DOMContentLoaded', () => {
  const menuToggle = document.getElementById('menu-toggle');
  const sidebar = document.getElementById('app-sidebar');
  if (menuToggle && sidebar) {
    menuToggle.addEventListener('click', () => {
      sidebar.classList.toggle('open');
    });
  }

  bindEvents();
  syncFiltersFromURL();
  fetchCases();
});

function bindEvents() {
  const searchInput = document.getElementById('search-input');
  const statusFilter = document.getElementById('status-filter');
  const sortSelect = document.getElementById('sort-select');
  const clearBtn = document.getElementById('clear-filters-btn');
  const prevBtn = document.getElementById('prev-page-btn');
  const nextBtn = document.getElementById('next-page-btn');

  if (searchInput) {
    searchInput.addEventListener('input', () => {
      clearTimeout(debounceTimeout);
      debounceTimeout = setTimeout(() => {
        currentPage = 1;
        fetchCases();
      }, 300);
    });
  }

  if (statusFilter) {
    statusFilter.addEventListener('change', () => {
      currentPage = 1;
      fetchCases();
    });
  }

  if (sortSelect) {
    sortSelect.addEventListener('change', () => {
      currentPage = 1;
      fetchCases();
    });
  }

  if (clearBtn) {
    clearBtn.addEventListener('click', () => {
      if (searchInput) searchInput.value = '';
      if (statusFilter) statusFilter.value = '';
      if (sortSelect) sortSelect.value = 'desc';
      currentPage = 1;
      fetchCases();
    });
  }

  if (prevBtn) {
    prevBtn.addEventListener('click', () => {
      if (currentPage > 1) {
        currentPage--;
        fetchCases();
      }
    });
  }

  if (nextBtn) {
    nextBtn.addEventListener('click', () => {
      if (currentPage < totalPages) {
        currentPage++;
        fetchCases();
      }
    });
  }
}

function syncFiltersFromURL() {
  const params = new URLSearchParams(window.location.search);
  const searchInput = document.getElementById('search-input');
  const statusFilter = document.getElementById('status-filter');
  const sortSelect = document.getElementById('sort-select');

  if (params.has('search') && searchInput) searchInput.value = params.get('search');
  if (params.has('status') && statusFilter) statusFilter.value = params.get('status');
  if (params.has('sort') && sortSelect) sortSelect.value = params.get('sort');
  if (params.has('page')) {
    const p = parseInt(params.get('page'), 10);
    if (!isNaN(p) && p > 0) currentPage = p;
  }
}

function updateURL() {
  const searchInput = document.getElementById('search-input');
  const statusFilter = document.getElementById('status-filter');
  const sortSelect = document.getElementById('sort-select');

  const params = new URLSearchParams();
  if (searchInput && searchInput.value.trim()) params.set('search', searchInput.value.trim());
  if (statusFilter && statusFilter.value) params.set('status', statusFilter.value);
  if (sortSelect && sortSelect.value !== 'desc') params.set('sort', sortSelect.value);
  if (currentPage > 1) params.set('page', currentPage);

  const newUrl = `${window.location.pathname}${params.toString() ? '?' + params.toString() : ''}`;
  window.history.replaceState({}, '', newUrl);
}

async function fetchCases() {
  if (currentAbortController) {
    currentAbortController.abort();
  }
  currentAbortController = new AbortController();

  updateURL();

  const searchInput = document.getElementById('search-input');
  const statusFilter = document.getElementById('status-filter');
  const sortSelect = document.getElementById('sort-select');
  const resultsSummary = document.getElementById('results-summary');
  const tbody = document.getElementById('cases-tbody');

  if (resultsSummary) resultsSummary.textContent = 'Fetching cases...';

  const params = new URLSearchParams();
  if (searchInput) params.set('search', searchInput.value.trim());
  if (statusFilter) params.set('status', statusFilter.value);
  if (sortSelect) params.set('sort', sortSelect.value || 'desc');
  params.set('page', currentPage);

  try {
    const data = await apiFetch(`api/cases.php?${params.toString()}`, {
      signal: currentAbortController.signal
    });
    renderData(data);
  } catch (err) {
    if (err.name === 'AbortError') return;
    console.error('Fetch cases error:', err);
    if (tbody) {
      tbody.innerHTML = `<tr><td colspan="7" style="text-align: center; color: var(--danger-text); padding: 2rem;">Error loading cases. Check API connection.</td></tr>`;
    }
    if (resultsSummary) resultsSummary.textContent = 'Failed to load cases.';
    showToast('Failed to load workflow cases', 'error');
  }
}

function renderData(data) {
  const rows = data.rows || data.cases || [];
  totalPages = data.totalPages || 1;
  const total = data.total !== undefined ? data.total : rows.length;

  const resultsSummary = document.getElementById('results-summary');
  const tbody = document.getElementById('cases-tbody');
  const prevBtn = document.getElementById('prev-page-btn');
  const nextBtn = document.getElementById('next-page-btn');
  const paginationInfo = document.getElementById('pagination-info');

  if (resultsSummary) {
    resultsSummary.textContent = `Showing ${rows.length} of ${total} cases (Page ${currentPage} of ${totalPages})`;
  }

  if (paginationInfo) {
    paginationInfo.textContent = `Page ${currentPage} of ${totalPages} (${total} total records)`;
  }

  if (prevBtn) prevBtn.disabled = currentPage <= 1;
  if (nextBtn) nextBtn.disabled = currentPage >= totalPages;

  if (!tbody) return;
  tbody.innerHTML = '';

  if (rows.length === 0) {
    const tr = document.createElement('tr');
    const td = document.createElement('td');
    td.colSpan = 7;
    td.style.textAlign = 'center';
    td.style.padding = '3rem 1rem';
    td.style.color = 'var(--text-muted)';
    td.textContent = 'No matching workflow cases found.';
    tr.appendChild(td);
    tbody.appendChild(tr);
    return;
  }

  rows.forEach(row => {
    const caseId = row.case_id || row.case_ref || '—';
    const rawVendor = row.vendor_name || row.vendorName;
    const vendor = rawVendor || 'Unmatched vendor';
    const sender = row.real_sender_email || row.sender_email || '—';
    const type = row.request_type || '—';
    const status = normalizeStatus(row.current_status || row.status);
    const created = formatDateTime(row.created_at);

    const tr = document.createElement('tr');

    const tdId = document.createElement('td');
    tdId.className = 'case-id-cell';
    tdId.textContent = caseId;

    const tdVendor = document.createElement('td');
    tdVendor.style.fontWeight = '600';
    tdVendor.textContent = vendor;

    const tdSender = document.createElement('td');
    tdSender.style.color = 'var(--text-muted)';
    tdSender.textContent = sender;

    const tdType = document.createElement('td');
    tdType.textContent = type;

    const tdStatus = document.createElement('td');
    const badge = document.createElement('span');
    badge.className = statusClass(status);
    badge.textContent = statusLabel(status);
    tdStatus.appendChild(badge);

    const tdDate = document.createElement('td');
    tdDate.style.fontSize = '0.75rem';
    tdDate.style.color = 'var(--text-muted)';
    tdDate.textContent = created;

    const tdAction = document.createElement('td');
    const link = document.createElement('a');
    link.href = `evidence.html?id=${encodeURIComponent(caseId)}`;
    link.className = 'btn btn-secondary btn-sm';
    link.innerHTML = `<span>Evidence Card</span> ${getLucideSvg('chevron-right', 12, 2)}`;
    tdAction.appendChild(link);

    tr.append(tdId, tdVendor, tdSender, tdType, tdStatus, tdDate, tdAction);
    tbody.appendChild(tr);
  });
}
