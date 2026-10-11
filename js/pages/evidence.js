/**
 * Novalite Evidence Card Logic
 */

let currentCaseId = null;

const missingIdState = document.getElementById('missing-id-state');
const loadingState = document.getElementById('loading-state');
const errorState = document.getElementById('error-state');
const errorMessage = document.getElementById('error-message');
const evidenceContent = document.getElementById('evidence-content');
const retryBtn = document.getElementById('retry-btn');

function init() {
    const params = new URLSearchParams(window.location.search);
    currentCaseId = params.get('id');

    if (!currentCaseId) {
        setViewState('missing');
        return;
    }

    if (retryBtn) {
        retryBtn.addEventListener('click', loadEvidence);
    }

    loadEvidence();
}

function setViewState(state) {
    missingIdState.classList.add('hidden');
    loadingState.classList.add('hidden');
    errorState.classList.add('hidden');
    evidenceContent.classList.add('hidden');

    if (state === 'missing') missingIdState.classList.remove('hidden');
    else if (state === 'loading') loadingState.classList.remove('hidden');
    else if (state === 'error') errorState.classList.remove('hidden');
    else if (state === 'data') evidenceContent.classList.remove('hidden');
}

async function loadEvidence() {
    setViewState('loading');
    try {
        const data = await apiFetch(`api/case.php?id=${encodeURIComponent(currentCaseId)}`);
        if (!data || !data.evidence_card) {
            throw new Error("Invalid response format");
        }
        renderEvidence(data);
        setViewState('data');
    } catch (err) {
        console.error(err);
        if (err.status === 404) {
            errorMessage.textContent = 'This workflow case could not be found.';
        } else {
            errorMessage.textContent = 'Unable to load this Evidence Card. Check that the Novalite API is running.';
        }
        setViewState('error');
    }
}

function renderEvidence(data) {
    const ec = data.evidence_card || {};
    const c = data.case || {};
    const v = data.vendor || {};
    const req = data.procurement_request || {};
    const docs = data.documents || [];
    const audit = data.audit_trail || [];

    // Header
    const hCaseId = document.getElementById('header-case-id');
    const hSubtitle = document.getElementById('header-subtitle');
    const hBadge = document.getElementById('header-badge-container');

    hCaseId.textContent = ec.case_id || currentCaseId;
    
    let vName = 'Unmatched Vendor';
    if (ec.vendor && ec.vendor.vendor_name) vName = ec.vendor.vendor_name;
    else if (v && v.name) vName = v.name;
    
    let rType = ec.request_type || c.request_type || 'Unknown Request';
    hSubtitle.textContent = `${vName} â€¢ ${rType}`;

    hBadge.innerHTML = '';
    const statusVal = ec.status || c.status;
    const badgeEl = createSafeElement('span', statusLabel(statusVal), statusClass(statusVal));
    hBadge.appendChild(badgeEl);

    // Evidence Card Core
    document.getElementById('ec-decision').textContent = ec.decision || 'No decision recorded.';
    
    const whyList = document.getElementById('ec-why');
    whyList.innerHTML = '';
    if (ec.why && Array.isArray(ec.why)) {
        ec.why.forEach(reason => {
            const li = document.createElement('li');
            // Remove prefix numbering if already exists (e.g. "1. Reason" -> "Reason")
            let text = reason;
            const match = text.match(/^\d+\.\s*(.*)/);
            if (match) text = match[1];
            li.textContent = text;
            whyList.appendChild(li);
        });
    } else {
        whyList.appendChild(createSafeElement('li', 'No detailed reasoning provided.'));
    }

    document.getElementById('ec-rule').textContent = ec.rule_applied || 'Rule details are available in the configured approval and compliance policy.';
    document.getElementById('ec-next-action').textContent = ec.next_action || 'None';
    
    const isHumanNeeded = ec.human_needed;
    const humanEl = document.getElementById('ec-human-needed');
    if (isHumanNeeded) {
        humanEl.textContent = 'Yes. ' + (c.owner ? `Owner: ${c.owner}` : 'A procurement or compliance owner must review this case.');
    } else {
        humanEl.textContent = 'No';
    }

    document.getElementById('ec-audit-timestamp').textContent = formatDateTime(ec.audit_timestamp || c.updated_at);

    // Part 3: Approval Action
    const approvalContainer = document.getElementById('approval-action-container');
    approvalContainer.innerHTML = '';
    const normStatus = normalizeStatus(ec.status || c.status).toLowerCase();
    
    if (normStatus === 'pending approval') {
        const panel = document.createElement('div');
        panel.className = 'approval-panel card mt-4 mb-4';
        
        const title = createSafeElement('h3', 'Approval Decision Required', 'text-danger');
        title.style.color = 'var(--primary)';
        
        const ownerEmail = c.owner || '';
        const ownerText = ownerEmail ? `Assigned to: ${ownerEmail}` : 'A valid configured approver is required before this decision can be submitted.';
        const ownerP = createSafeElement('p', ownerText, 'mb-2');
        
        panel.appendChild(title);
        panel.appendChild(ownerP);
        
        const controlsDiv = document.createElement('div');
        controlsDiv.className = 'approval-controls';
        
        const emailInput = document.createElement('input');
        emailInput.type = 'email';
        emailInput.placeholder = 'Approver Email';
        emailInput.value = (ownerEmail && ownerEmail.includes('@')) ? ownerEmail : '';
        emailInput.style.maxWidth = '300px';
        emailInput.style.marginRight = '1rem';
        
        const btnApprove = document.createElement('button');
        btnApprove.textContent = 'Approve';
        btnApprove.className = 'btn-success mr-2';
        btnApprove.style.marginRight = '0.5rem';
        
        const btnReject = document.createElement('button');
        btnReject.textContent = 'Reject';
        btnReject.className = 'btn-danger';
        
        if (!emailInput.value) {
            btnApprove.disabled = true;
            btnReject.disabled = true;
        }

        emailInput.addEventListener('input', () => {
            const isValid = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(emailInput.value.trim());
            btnApprove.disabled = !isValid;
            btnReject.disabled = !isValid;
        });

        controlsDiv.appendChild(emailInput);
        controlsDiv.appendChild(btnApprove);
        controlsDiv.appendChild(btnReject);
        
        panel.appendChild(controlsDiv);
        approvalContainer.appendChild(panel);
        
        btnApprove.addEventListener('click', () => showApprovalModal(currentCaseId, 'APPROVE', emailInput.value.trim(), btnApprove));
        btnReject.addEventListener('click', () => showApprovalModal(currentCaseId, 'REJECT', emailInput.value.trim(), btnReject));
    }

    // Vendor Summary
    document.getElementById('vendor-name').textContent = vName;
    document.getElementById('vendor-gst').textContent = (ec.vendor && ec.vendor.gst_id) || '—';
    document.getElementById('vendor-email').textContent = (ec.vendor && ec.vendor.vendor_email) || v.email || 'â€”';
    document.getElementById('vendor-status').textContent = (ec.vendor && ec.vendor.vendor_status) || v.status || 'â€”';
    document.getElementById('vendor-risk').textContent = (ec.vendor && ec.vendor.risk_level) || v.risk_level || 'â€”';

    // Procurement Request
    const reqContainer = document.getElementById('request-content');
    reqContainer.innerHTML = '';
    if (req && Object.keys(req).length > 0) {
        const grid = document.createElement('div');
        grid.className = 'evidence-grid';

        const addReqItem = (lbl, val) => {
            const item = document.createElement('div');
            item.className = 'evidence-item';
            item.appendChild(createSafeElement('span', lbl, 'label'));
            item.appendChild(createSafeElement('span', val, 'value'));
            grid.appendChild(item);
        };

        addReqItem('Request ID', req.request_id || req.id || 'â€”');
        addReqItem('Category', req.category || 'â€”');
        addReqItem('Amount', formatCurrency(req.amount, req.currency || 'INR'));
        addReqItem('Status', req.status || 'â€”');
        addReqItem('Required By', formatDateTime(req.required_by_date) || 'â€”');

        reqContainer.appendChild(grid);
    } else {
        reqContainer.appendChild(createSafeElement('p', 'No linked procurement request is available for this workflow case.', 'text-muted'));
    }

    // Documents Checklist
    const docContainer = document.getElementById('documents-content');
    docContainer.innerHTML = '';
    if (docs && docs.length > 0) {
        docs.forEach(doc => {
            const item = document.createElement('div');
            item.className = 'doc-item';
            
            const name = createSafeElement('div', doc.document_type || doc.type || 'Document', 'doc-name');
            if (doc.expiry_date) {
                const exp = createSafeElement('span', ` (Expires: ${formatDateTime(doc.expiry_date).split(',')[0]})`, 'text-muted');
                exp.style.fontSize = '0.875rem';
                exp.style.fontWeight = 'normal';
                name.appendChild(exp);
            }

            const status = document.createElement('div');
            status.className = 'doc-status';
            const sVal = (doc.verification_status || doc.status || '').toLowerCase();
            if (sVal === 'verified') {
                status.classList.add('doc-verified');
                status.textContent = 'âœ” Verified';
            } else if (sVal === 'missing') {
                status.classList.add('doc-missing');
                status.textContent = 'âœ– Missing';
            } else if (sVal === 'expired') {
                status.classList.add('doc-expired');
                status.textContent = 'âœ– Expired';
            } else {
                status.classList.add('doc-pending');
                status.textContent = 'âš  Pending';
            }

            item.appendChild(name);
            item.appendChild(status);
            docContainer.appendChild(item);
        });
    } else {
        docContainer.appendChild(createSafeElement('p', 'No documents found.', 'text-muted'));
    }

    // Audit Timeline
    const timelineContainer = document.getElementById('audit-timeline');
    timelineContainer.innerHTML = '';
    if (audit && audit.length > 0) {
        audit.forEach(entry => {
            const ev = document.createElement('div');
            ev.className = 'timeline-event';
            
            const marker = document.createElement('div');
            marker.className = 'timeline-marker';
            
            const content = document.createElement('div');
            content.className = 'timeline-content';
            
            const head = document.createElement('div');
            head.className = 'timeline-header';
            head.appendChild(createSafeElement('span', entry.action || 'ACTION', 'timeline-action'));
            head.appendChild(createSafeElement('span', formatDateTime(entry.created_at || entry.timestamp), 'timeline-time'));
            
            content.appendChild(head);
            
            if (entry.actor) {
                content.appendChild(createSafeElement('span', `Actor: ${entry.actor}`, 'timeline-actor'));
            }
            
            if (entry.decision_reason || entry.reason) {
                const reasonP = createSafeElement('p', entry.decision_reason || entry.reason);
                reasonP.style.marginBottom = '0.5rem';
                content.appendChild(reasonP);
            }

            if (entry.rule_id) {
                const ruleP = createSafeElement('p', `Rule: ${entry.rule_id}`);
                ruleP.style.fontSize = '0.875rem';
                ruleP.style.color = 'var(--text-muted)';
                content.appendChild(ruleP);
            }

            ev.appendChild(marker);
            ev.appendChild(content);
            timelineContainer.appendChild(ev);
        });
    } else {
        timelineContainer.appendChild(createSafeElement('p', 'No audit events have been recorded for this case.', 'text-muted'));
    }
}

document.addEventListener('DOMContentLoaded', init);

// --- Part 3: Approval Modal Logic ---

function showApprovalModal(caseId, action, approverEmail, triggerBtn) {
    let modal = document.getElementById('approval-modal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'approval-modal';
        modal.className = 'modal-backdrop hidden';
        modal.setAttribute('role', 'dialog');
        modal.setAttribute('aria-modal', 'true');
        modal.setAttribute('aria-labelledby', 'modal-title');
        
        modal.innerHTML = `
            <div class="modal-content">
                <h3 id="modal-title"></h3>
                <p id="modal-desc" class="mb-4"></p>
                <div class="modal-actions" style="display: flex; gap: 1rem; justify-content: flex-end;">
                    <button id="modal-cancel" class="btn-secondary">Cancel</button>
                    <button id="modal-confirm" class="btn-primary"></button>
                </div>
            </div>
        `;
        document.body.appendChild(modal);

        modal.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && !modal.classList.contains('hidden')) {
                closeModal();
            }
        });
    }

    const titleEl = modal.querySelector('#modal-title');
    const descEl = modal.querySelector('#modal-desc');
    const btnCancel = modal.querySelector('#modal-cancel');
    const btnConfirm = modal.querySelector('#modal-confirm');

    titleEl.textContent = action === 'APPROVE' ? `Approve case ${caseId}?` : `Reject case ${caseId}?`;
    descEl.textContent = `Action: ${action}\nApprover: ${approverEmail}`;
    btnConfirm.textContent = action === 'APPROVE' ? 'Confirm Approval' : 'Confirm Rejection';
    
    if (action === 'APPROVE') {
        btnConfirm.className = 'btn-success';
    } else {
        btnConfirm.className = 'btn-danger';
    }

    const closeModal = () => {
        modal.classList.add('hidden');
        if (triggerBtn) triggerBtn.focus();
    };

    const confirmAction = async () => {
        setButtonLoading(btnConfirm, true);
        btnCancel.disabled = true;
        try {
            await apiFetch('api/approve.php', {
                method: 'POST',
                body: JSON.stringify({
                    case_id: caseId,
                    action: action,
                    approver: approverEmail
                })
            });
            showToast(`Case ${caseId} ${action.toLowerCase()}d successfully.`, 'success');
            closeModal();
            loadEvidence(); // fully re-render
        } catch (err) {
            showToast(err.message || 'Failed to submit approval decision.', 'error');
        } finally {
            setButtonLoading(btnConfirm, false);
            btnCancel.disabled = false;
        }
    };

    btnCancel.onclick = closeModal;
    btnConfirm.onclick = confirmAction;

    modal.classList.remove('hidden');
    btnConfirm.focus();
}

