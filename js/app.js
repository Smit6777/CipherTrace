/**
 * Novalite App Shared Utilities
 */

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
    
    const textNode = document.createElement('span');
    textNode.textContent = message;
    
    const closeBtn = document.createElement('button');
    closeBtn.className = 'toast-close';
    closeBtn.innerHTML = '&times;';
    closeBtn.setAttribute('aria-label', 'Close notification');
    closeBtn.onclick = () => toast.remove();
    
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
        button.dataset.originalText = button.textContent;
        button.textContent = loadingText;
    } else {
        button.disabled = false;
        if (button.dataset.originalText) {
            button.textContent = button.dataset.originalText;
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
function formatCurrency(value, currency = 'USD') {
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
