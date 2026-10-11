/**
 * js/pages/evidence.js
 * Evidence Card Controller (Screen C - Signature Screen)
 * Clean enterprise styling with Lucide SVG icons, zero corrupt unicode, safe DOM operations.
 */

let currentCaseId = null;

document.addEventListener('DOMContentLoaded', () => {
    const menuToggle = document.getElementById('menu-toggle');
    const sidebar = document.getElementById('app-sidebar');
    if (menuToggle && sidebar) {
        menuToggle.addEventListener('click', () => {
            sidebar.classList.toggle('open');
        });
    }

    const urlParams = new URLSearchParams(window.location.search);
    currentCaseId = urlParams.get('id');

    if (!currentCaseId) {
        showState('missing-id');
        return;
    }

    const retryBtn = document.getElementById('retry-btn');
    if (retryBtn) {
        retryBtn.addEventListener('click', () => fetchEvidenceCard(currentCaseId));
    }

    fetchEvidenceCard(currentCaseId);
});

function showState(state) {
    const missingEl = document.getElementById('missing-id-state');
    const loadingEl = document.getElementById('loading-state');
    const errorEl = document.getElementById('error-state');
    const contentEl = document.getElementById('evidence-content');

    if (missingEl) missingEl.style.display = state === 'missing-id' ? 'block' : 'none';
    if (loadingEl) loadingEl.style.display = state === 'loading' ? 'block' : 'none';
    if (errorEl) errorEl.style.display = state === 'error' ? 'block' : 'none';
    if (contentEl) contentEl.style.display = state === 'content' ? 'block' : 'none';
}

async function fetchEvidenceCard(caseId) {
    showState('loading');
    try {
        const data = await apiFetch(`api/case.php?id=${encodeURIComponent(caseId)}`);
        if (!data || !data.case) {
            throw new Error('Case not found');
        }
        renderEvidenceCard(data);
        showState('content');
    } catch (err) {
        console.error('Fetch Evidence Card error:', err);
        const errMsg = document.getElementById('error-message');
        if (errMsg) {
            errMsg.textContent = err.data && err.data.message ? err.data.message : 'Unable to load Evidence Card.';
        }
        showState('error');
    }
}

function renderEvidenceCard(data) {
    const c = data.case || {};
    const ec = data.evidence_card || {};
    const v = data.vendor || {};
    const req = data.procurement_request || {};
    const docs = data.documents || [];
    const audit = data.audit_trail || [];

    // Header
    const hCaseId = document.getElementById('header-case-id');
    const hSubtitle = document.getElementById('header-subtitle');
    const hBadge = document.getElementById('header-badge-container');

    if (hCaseId) hCaseId.textContent = ec.case_id || c.case_id || currentCaseId;
    
    let vName = 'Unmatched Vendor';
    if (ec.vendor && ec.vendor.vendor_name) vName = ec.vendor.vendor_name;
    else if (v && v.vendor_name) vName = v.vendor_name;
    else if (v && v.name) vName = v.name;
    
    const rType = ec.request_type || c.request_type || 'Procurement Request';
    if (hSubtitle) hSubtitle.textContent = `${vName} • ${rType}`;

    if (hBadge) {
        hBadge.innerHTML = '';
        const statusVal = ec.status || c.status || c.current_status || 'New';
        const badgeEl = createSafeElement('span', statusLabel(statusVal), statusClass(statusVal));
        hBadge.appendChild(badgeEl);
    }

    // Evidence Core
    const ecDecision = document.getElementById('ec-decision');
    if (ecDecision) ecDecision.textContent = ec.decision || c.current_status || 'No decision recorded.';
    
    const whyList = document.getElementById('ec-why');
    if (whyList) {
        whyList.innerHTML = '';
        if (ec.why && Array.isArray(ec.why)) {
            ec.why.forEach(reason => {
                const li = document.createElement('li');
                let text = reason;
                const match = text.match(/^\d+\.\s*(.*)/);
                if (match) text = match[1];
                li.textContent = text;
                whyList.appendChild(li);
            });
        } else {
            const li = document.createElement('li');
            li.textContent = ec.decision_reason || 'Verified through deterministic procurement policy.';
            whyList.appendChild(li);
        }
    }

    const ecRule = document.getElementById('ec-rule');
    if (ecRule) ecRule.textContent = ec.rule_applied || ec.rule_id || c.rule_id || 'AM-DEFAULT';

    const ecNext = document.getElementById('ec-next-action');
    if (ecNext) ecNext.textContent = ec.next_action || c.next_action || 'None';
    
    const isHumanNeeded = ec.human_needed || inArray(c.current_status, ['Pending Approval', 'Manual Review']);
    const humanEl = document.getElementById('ec-human-needed');
    if (humanEl) {
        if (isHumanNeeded) {
            humanEl.textContent = 'Yes — ' + (c.owner ? `Assigned to: ${c.owner}` : 'Compliance or Procurement Owner required.');
        } else {
            humanEl.textContent = 'No — Fully automated under approved policy rules.';
        }
    }

    const tsEl = document.getElementById('ec-audit-timestamp');
    if (tsEl) tsEl.textContent = formatDateTime(ec.audit_timestamp || c.updated_at || c.created_at);

    // Approval Action Panel (Only if Pending Approval)
    renderApprovalPanel(c, ec);

    // Vendor Summary
    const vNameEl = document.getElementById('vendor-name');
    if (vNameEl) vNameEl.textContent = vName;

    const vGstEl = document.getElementById('vendor-gst');
    if (vGstEl) vGstEl.textContent = (ec.vendor && ec.vendor.gst_id) || v.gst_id || 'Missing / Not Provided';

    const vEmailEl = document.getElementById('vendor-email');
    if (vEmailEl) vEmailEl.textContent = (ec.vendor && ec.vendor.vendor_email) || v.vendor_email || c.real_sender_email || '—';

    const vStatusEl = document.getElementById('vendor-status');
    if (vStatusEl) vStatusEl.textContent = (ec.vendor && ec.vendor.vendor_status) || v.vendor_status || '—';

    const vRiskEl = document.getElementById('vendor-risk');
    if (vRiskEl) vRiskEl.textContent = (ec.vendor && ec.vendor.risk_level) || v.risk_level || 'Medium';

    // Procurement Request
    renderProcurementRequest(req);

    // Documents Checklist
    renderDocumentsChecklist(docs);

    // Audit Timeline
    renderAuditTimeline(audit);
}

function inArray(needle, haystack) {
    return Array.isArray(haystack) && haystack.includes(needle);
}

function renderApprovalPanel(c, ec) {
    const approvalContainer = document.getElementById('approval-action-container');
    if (!approvalContainer) return;
    approvalContainer.innerHTML = '';
    
    const statusStr = (ec.status || c.current_status || '').toLowerCase();
    if (statusStr.includes('pending approval')) {
        const panel = document.createElement('div');
        panel.className = 'card';
        panel.style.backgroundColor = 'var(--primary-light)';
        panel.style.borderColor = 'var(--primary-border)';
        panel.style.marginBottom = '1.25rem';
        
        const title = document.createElement('h3');
        title.style.fontSize = '0.9375rem';
        title.style.fontWeight = '700';
        title.style.color = 'var(--primary)';
        title.style.marginBottom = '0.25rem';
        title.textContent = 'Human Approval Required';
        
        const ownerEmail = c.owner || '';
        const ownerP = document.createElement('p');
        ownerP.style.fontSize = '0.75rem';
        ownerP.style.color = 'var(--text-body)';
        ownerP.style.marginBottom = '0.75rem';
        ownerP.textContent = ownerEmail 
            ? `Requisition requires authorization from ${ownerEmail}.` 
            : 'Requisition requires authorization from an authorized procurement approver.';
        
        panel.appendChild(title);
        panel.appendChild(ownerP);
        
        const controlsDiv = document.createElement('div');
        controlsDiv.style.display = 'flex';
        controlsDiv.style.gap = '0.5rem';
        controlsDiv.style.flexWrap = 'wrap';
        
        const emailInput = document.createElement('input');
        emailInput.type = 'email';
        emailInput.value = ownerEmail || 'procurement.manager@novalite.example';
        emailInput.placeholder = 'Approver email address';
        emailInput.className = 'form-control';
        emailInput.style.flex = '1';
        emailInput.style.minWidth = '240px';
        
        const btnApprove = document.createElement('button');
        btnApprove.className = 'btn btn-success';
        btnApprove.innerHTML = `${getLucideSvg('check-circle-2', 14, 2)} <span>Authorize & Approve</span>`;
        
        const btnReject = document.createElement('button');
        btnReject.className = 'btn btn-danger';
        btnReject.innerHTML = `${getLucideSvg('x-circle', 14, 2)} <span>Reject</span>`;
        
        controlsDiv.appendChild(emailInput);
        controlsDiv.appendChild(btnApprove);
        controlsDiv.appendChild(btnReject);
        panel.appendChild(controlsDiv);
        approvalContainer.appendChild(panel);
        
        btnApprove.addEventListener('click', () => submitApproval('APPROVE', emailInput.value.trim(), btnApprove));
        btnReject.addEventListener('click', () => submitApproval('REJECT', emailInput.value.trim(), btnReject));
    }
}

async function submitApproval(action, approver, btn) {
    if (!approver || !/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(approver)) {
        showToast('Valid approver email required', 'error');
        return;
    }

    if (!confirm(`Are you sure you want to submit: ${action} for case ${currentCaseId}?`)) {
        return;
    }

    try {
        setButtonLoading(btn, true, 'Submitting...');
        const payload = {
            case_id: currentCaseId,
            action: action,
            approver: approver,
            comment: `${action} decision submitted via Evidence Card`
        };

        const res = await apiFetch('api/approve.php', {
            method: 'POST',
            body: JSON.stringify(payload)
        });

        showToast(`Case ${currentCaseId} ${action === 'APPROVE' ? 'Approved' : 'Rejected'} successfully!`, 'success');
        fetchEvidenceCard(currentCaseId);
    } catch (err) {
        console.error('Approval submission error:', err);
        showToast(err.message || 'Action failed', 'error');
    } finally {
        setButtonLoading(btn, false);
    }
}

function renderProcurementRequest(req) {
    const reqContainer = document.getElementById('request-content');
    if (!reqContainer) return;
    reqContainer.innerHTML = '';

    if (req && Object.keys(req).length > 0) {
        const grid = document.createElement('div');
        grid.style.display = 'flex';
        grid.style.flexDirection = 'column';
        grid.style.gap = '0.625rem';
        grid.style.fontSize = '0.8125rem';

        const addRow = (label, val, isAmount = false) => {
            const row = document.createElement('div');
            row.style.display = 'flex';
            row.style.justifyContent = 'space-between';
            row.style.borderBottom = '1px solid var(--border-light)';
            row.style.paddingBottom = '0.375rem';

            const lbl = document.createElement('span');
            lbl.style.color = 'var(--text-muted)';
            lbl.textContent = label;

            const v = document.createElement('span');
            v.style.fontWeight = isAmount ? '700' : '600';
            if (isAmount) v.style.color = 'var(--primary)';
            v.textContent = val;

            row.appendChild(lbl);
            row.appendChild(v);
            grid.appendChild(row);
        };

        addRow('Request Reference', req.request_id ? `#${req.request_id}` : '—');
        addRow('Category', req.category || 'General');
        addRow('Requisition Amount', formatCurrency(req.amount, req.currency || 'INR'), true);
        addRow('Current Status', req.status || 'Active');
        addRow('Required By Date', formatDateTime(req.required_by_date) || '—');

        reqContainer.appendChild(grid);
    } else {
        const empty = document.createElement('p');
        empty.style.color = 'var(--text-muted)';
        empty.style.fontSize = '0.8125rem';
        empty.textContent = 'No linked procurement request is available for this case.';
        reqContainer.appendChild(empty);
    }
}

function renderDocumentsChecklist(docs) {
    const docContainer = document.getElementById('documents-content');
    if (!docContainer) return;
    docContainer.innerHTML = '';

    if (docs && docs.length > 0) {
        docs.forEach(doc => {
            const item = document.createElement('div');
            item.className = 'checklist-item';
            
            const left = document.createElement('div');
            left.style.display = 'flex';
            left.style.alignItems = 'center';
            left.style.gap = '0.5rem';

            const name = document.createElement('span');
            name.className = 'checklist-doc-name';
            name.textContent = doc.document_type || doc.type || 'Document';

            left.appendChild(name);

            if (doc.expiry_date) {
                const exp = document.createElement('span');
                exp.style.fontSize = '0.75rem';
                exp.style.color = 'var(--text-muted)';
                exp.textContent = `(Expires: ${formatDateTime(doc.expiry_date).split(',')[0]})`;
                left.appendChild(exp);
            }

            const right = document.createElement('div');
            right.style.display = 'flex';
            right.style.alignItems = 'center';
            right.style.gap = '0.375rem';

            const sVal = (doc.verification_status || doc.status || '').toLowerCase();
            let iconSvg = '';
            let badgeClass = 'badge badge-gray';
            let labelText = 'Pending';

            if (sVal === 'verified') {
                iconSvg = getLucideSvg('check-circle-2', 14, 2);
                badgeClass = 'badge badge-green';
                labelText = 'Verified';
            } else if (sVal === 'missing') {
                iconSvg = getLucideSvg('alert-triangle', 14, 2);
                badgeClass = 'badge badge-amber';
                labelText = 'Missing';
            } else if (sVal === 'expired') {
                iconSvg = getLucideSvg('x-circle', 14, 2);
                badgeClass = 'badge badge-red';
                labelText = 'Expired';
            } else {
                iconSvg = getLucideSvg('clock', 14, 2);
                badgeClass = 'badge badge-gray';
                labelText = 'Awaiting Verification';
            }

            right.innerHTML = `<span class="${badgeClass}">${iconSvg} ${labelText}</span>`;

            item.appendChild(left);
            item.appendChild(right);
            docContainer.appendChild(item);
        });
    } else {
        const empty = document.createElement('p');
        empty.style.color = 'var(--text-muted)';
        empty.style.fontSize = '0.8125rem';
        empty.textContent = 'No compliance documents attached.';
        docContainer.appendChild(empty);
    }
}

function renderAuditTimeline(audit) {
    const timelineContainer = document.getElementById('audit-timeline');
    if (!timelineContainer) return;
    timelineContainer.innerHTML = '';

    if (audit && audit.length > 0) {
        audit.forEach(entry => {
            const ev = document.createElement('div');
            ev.className = 'timeline-item';

            const header = document.createElement('div');
            header.className = 'timeline-header';

            const action = document.createElement('span');
            action.className = 'timeline-action';
            action.textContent = entry.action || 'STATE_CHANGE';

            const actor = document.createElement('span');
            actor.className = 'timeline-actor';
            actor.textContent = `by ${entry.actor || 'System'}`;

            const date = document.createElement('span');
            date.className = 'timeline-date';
            date.textContent = formatDateTime(entry.created_at || entry.timestamp);

            header.append(action, actor, date);

            const reason = document.createElement('div');
            reason.className = 'timeline-reason';
            reason.textContent = entry.decision_reason || entry.reason || 'Decision recorded.';

            if (entry.rule_id) {
                const ruleTag = document.createElement('span');
                ruleTag.className = 'rule-tag';
                ruleTag.style.marginLeft = '0.5rem';
                ruleTag.textContent = entry.rule_id;
                reason.appendChild(ruleTag);
            }

            ev.append(header, reason);
            timelineContainer.appendChild(ev);
        });
    } else {
        const empty = document.createElement('p');
        empty.style.color = 'var(--text-muted)';
        empty.style.fontSize = '0.8125rem';
        empty.textContent = 'No audit log entries recorded yet.';
        timelineContainer.appendChild(empty);
    }
}
