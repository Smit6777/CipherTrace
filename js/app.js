/**
 * CipherTrace (by CipherSight) - Shared Enterprise Utilities
 * Strictly compliant with OWASP, XSS safety, and Lucide SVG standards.
 */

const LUCIDE_ICONS = {
  'layout-dashboard': '<rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/>',
  'clipboard-list': '<rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M12 11h4"/><path d="M12 16h4"/><path d="M8 11h.01"/><path d="M8 16h.01"/>',
  'file-check': '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="m9 15 2 2 4-4"/>',
  'git-branch': '<line x1="6" x2="6" y1="3" y2="15"/><circle cx="18" cy="6" r="3"/><circle cx="6" cy="18" r="3"/><path d="M18 9a9 9 0 0 1-9 9"/>',
  'building-2': '<path d="M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18Z"/><path d="M6 12H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2"/><path d="M18 9h2a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-2"/><path d="M10 6h4"/><path d="M10 10h4"/><path d="M10 14h4"/><path d="M10 18h4"/>',
  'history': '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l4 2"/>',
  'flask-conical': '<path d="M10 2v7.31L4.1 19.34A2 2 0 0 0 5.76 22h12.48a2 2 0 0 0 1.66-2.66L14 9.31V2"/><line x1="8.5" x2="15.5" y1="2" y2="2"/><line x1="8.5" x2="15.5" y1="14" y2="14"/>',
  'check-circle-2': '<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>',
  'alert-triangle': '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" x2="12" y1="9" y2="13"/><line x1="12" x2="12.01" y1="17" y2="17"/>',
  'alert-circle': '<circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/>',
  'clock': '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
  'shield-alert': '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/>',
  'x-circle': '<circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/>',
  'search': '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
  'bell': '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
  'arrow-right': '<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>',
  'chevron-right': '<path d="m9 18 6-6-6-6"/>',
  'external-link': '<path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
  'layers': '<path d="m12.83 2.18a2 2 0 0 0-1.66 0L2.6 6.08a1 1 0 0 0 0 1.83l8.58 3.9a2 2 0 0 0 1.66 0l8.58-3.9a1 1 0 0 0 0-1.83Z"/><path d="m22 17.65-9.17 4.16a2 2 0 0 1-1.66 0L2 17.65"/><path d="m22 12.65-9.17 4.16a2 2 0 0 1-1.66 0L2 12.65"/>',
  'zap': '<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>'
};

function getLucideSvg(name, size = 16, strokeWidth = 2, extraClass = '') {
  const inner = LUCIDE_ICONS[name] || LUCIDE_ICONS['alert-circle'];
  return `<svg class="lucide-icon ${extraClass}" width="${size}" height="${size}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="${strokeWidth}" stroke-linecap="round" stroke-linejoin="round">${inner}</svg>`;
}

/**
 * Perform safe API fetch with UI handling
 */
async function apiFetch(endpoint, options = {}) {
    const defaultHeaders = {
        'Accept': 'application/json'
    };

    if (options.body && typeof options.body === 'string') {
        try {
            JSON.parse(options.body);
            defaultHeaders['Content-Type'] = 'application/json';
        } catch(e) {}
    }

    options.headers = { ...defaultHeaders, ...options.headers };

    try {
        const response = await fetch(endpoint, options);
        let data = null;
        const contentType = response.headers.get("content-type");
        
        if (contentType && contentType.includes("application/json")) {
            data = await response.json().catch(() => null);
        } else {
            const text = await response.text().catch(() => null);
            if (text) {
                try {
                    data = JSON.parse(text);
                } catch(e) {
                    data = { message: text };
                }
            }
        }

        if (!response.ok) {
            const err = new Error(data && data.message ? data.message : `HTTP ${response.status}`);
            err.status = response.status;
            err.data = data;
            throw err;
        }

        return data;
    } catch (error) {
        if (error.name !== 'AbortError') {
            console.error('API Fetch Error:', error);
        }
        throw error;
    }
}

/**
 * Toast Notification Utility
 */
function showToast(message, type = 'info') {
    let container = document.getElementById('toast-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toast-container';
        container.className = 'toast-container';
        document.body.appendChild(container);
    }

    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    
    const iconName = type === 'success' ? 'check-circle-2' : (type === 'error' ? 'x-circle' : 'alert-circle');
    const iconSpan = document.createElement('span');
    iconSpan.style.display = 'inline-flex';
    iconSpan.style.alignItems = 'center';
    iconSpan.innerHTML = getLucideSvg(iconName, 16, 2);
    
    const textNode = document.createElement('span');
    textNode.textContent = message;
    
    const closeBtn = document.createElement('button');
    closeBtn.className = 'toast-close';
    closeBtn.style.background = 'none';
    closeBtn.style.border = 'none';
    closeBtn.style.color = 'inherit';
    closeBtn.style.cursor = 'pointer';
    closeBtn.style.opacity = '0.7';
    closeBtn.innerHTML = '&times;';
    closeBtn.setAttribute('aria-label', 'Close notification');
    closeBtn.onclick = () => toast.remove();
    
    toast.appendChild(iconSpan);
    toast.appendChild(textNode);
    toast.appendChild(closeBtn);
    container.appendChild(toast);
    
    setTimeout(() => {
        if (document.body.contains(toast)) {
            toast.remove();
        }
    }, 5000);
}

/**
 * Button loading state
 */
function setButtonLoading(button, isLoading, loadingText = 'Loading...') {
    if (!button) return;
    if (isLoading) {
        button.disabled = true;
        button.dataset.originalText = button.innerHTML;
        button.innerHTML = `<span style="display:inline-flex;align-items:center;gap:0.5rem;">${getLucideSvg('clock', 14, 2)} ${loadingText}</span>`;
    } else {
        button.disabled = false;
        if (button.dataset.originalText) {
            button.innerHTML = button.dataset.originalText;
        }
    }
}

/**
 * Format date time
 */
function formatDateTime(value) {
    if (!value) return '—';
    try {
        const d = new Date(value);
        if (isNaN(d.getTime())) return value;
        return d.toLocaleString(undefined, { 
            year: 'numeric', month: 'short', day: 'numeric',
            hour: '2-digit', minute: '2-digit'
        });
    } catch (e) {
        return value;
    }
}

/**
 * Format currency
 */
function formatCurrency(value, currency = 'INR') {
    if (value === null || value === undefined) return '—';
    try {
        return new Intl.NumberFormat(undefined, { style: 'currency', currency: currency }).format(value);
    } catch(e) {
        return `${currency} ${value}`;
    }
}

/**
 * Normalize status strings
 */
function normalizeStatus(value) {
    if (!value) return 'Unknown';
    return value;
}

/**
 * Get CSS class for status badge
 */
function statusClass(value) {
    if (!value) return 'badge badge-gray';
    const s = value.toLowerCase();
    if (s.includes('pending documents')) return 'badge badge-amber';
    if (s.includes('pending approval')) return 'badge badge-blue';
    if (s.includes('approved/auto-processed') || s === 'approved') return 'badge badge-green';
    if (s.includes('manual review') || s.includes('escalated')) return 'badge badge-red';
    if (s.includes('rejected')) return 'badge badge-red';
    if (s === 'new') return 'badge badge-gray';
    return 'badge badge-gray';
}

/**
 * Get display label for status
 */
function statusLabel(value) {
    if (!value) return 'Unknown';
    return value;
}

/**
 * Create safe DOM element
 */
function createSafeElement(tag, text = '', className = '') {
    const el = document.createElement(tag);
    if (text !== null && text !== undefined) {
        el.textContent = text;
    }
    if (className) {
        el.className = className;
    }
    return el;
}
