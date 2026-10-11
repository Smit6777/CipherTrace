/**
 * Novalite Settings / Approval Matrix Logic
 */

let matrixData = [];

const tbody = document.getElementById('matrix-tbody');
const form = document.getElementById('matrix-form');
const formTitle = document.getElementById('form-title');

const fRuleId = document.getElementById('f-rule-id');
const fMinAmount = document.getElementById('f-min-amount');
const fMaxAmount = document.getElementById('f-max-amount');
const fRisk = document.getElementById('f-risk');
const fCategory = document.getElementById('f-category');
const fApprovalReq = document.getElementById('f-approval-req');
const fApprover = document.getElementById('f-approver');

const errMin = document.getElementById('err-min');
const errMax = document.getElementById('err-max');
const errRisk = document.getElementById('err-risk');
const errCategory = document.getElementById('err-category');
const errApprover = document.getElementById('err-approver');

const btnSave = document.getElementById('btn-save');
const btnCancel = document.getElementById('btn-cancel');
const loadingState = document.getElementById('loading-state');

const regexAmount = /^\d{1,9}(\.\d{1,2})?$/;
const regexEmail = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;

function init() {
    loadMatrix();
    
    // Bind validations
    fMinAmount.addEventListener('input', validateForm);
    fMaxAmount.addEventListener('input', validateForm);
    fRisk.addEventListener('change', validateForm);
    fCategory.addEventListener('input', validateForm);
    fApprovalReq.addEventListener('change', validateForm);
    fApprover.addEventListener('input', validateForm);
    
    btnCancel.addEventListener('click', cancelEdit);
    form.addEventListener('submit', handleSave);
}

async function loadMatrix() {
    loadingState.classList.remove('hidden');
    tbody.innerHTML = '';
    
    try {
        const data = await apiFetch('api/matrix.php');
        matrixData = data.approval_matrix || [];
        renderMatrix();
    } catch (err) {
        showToast('Failed to load approval matrix.', 'error');
    } finally {
        loadingState.classList.add('hidden');
    }
}

function renderMatrix() {
    tbody.innerHTML = '';
    
    matrixData.forEach(row => {
        const tr = document.createElement('tr');
        
        const tdId = createSafeElement('td', row.rule_id);
        const tdMin = createSafeElement('td', formatCurrency(row.min_amount, 'INR'));
        const tdMax = createSafeElement('td', row.max_amount !== null ? formatCurrency(row.max_amount, 'INR') : 'No maximum');
        const tdRisk = createSafeElement('td', row.risk_level);
        const tdCat = createSafeElement('td', row.category);
        const tdEmail = createSafeElement('td', row.approver_email || '—');
        const tdReq = createSafeElement('td', parseInt(row.approval_required) === 1 ? 'Yes' : 'No');
        
        const tdEdit = document.createElement('td');
        const btnEdit = document.createElement('button');
        btnEdit.textContent = 'Edit';
        btnEdit.className = 'btn-secondary';
        btnEdit.style.padding = '0.25rem 0.5rem';
        btnEdit.onclick = () => startEdit(row);
        tdEdit.appendChild(btnEdit);
        
        tr.append(tdId, tdMin, tdMax, tdRisk, tdCat, tdEmail, tdReq, tdEdit);
        tbody.appendChild(tr);
    });
}

function startEdit(row) {
    formTitle.textContent = `Edit Approval Rule ${row.rule_id}`;
    fRuleId.value = row.rule_id;
    fMinAmount.value = row.min_amount;
    fMaxAmount.value = row.max_amount !== null ? row.max_amount : '';
    fRisk.value = row.risk_level;
    fCategory.value = row.category;
    fApprovalReq.value = row.approval_required;
    fApprover.value = row.approver_email || '';
    
    btnCancel.disabled = false;
    validateForm();
    form.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    fMinAmount.focus();
}

function cancelEdit() {
    formTitle.textContent = 'Select a rule to edit';
    form.reset();
    fRuleId.value = '';
    btnSave.disabled = true;
    btnCancel.disabled = true;
    clearErrors();
}

function clearErrors() {
    [errMin, errMax, errRisk, errCategory, errApprover].forEach(el => el.textContent = '');
}

function validateForm() {
    if (!fRuleId.value) {
        btnSave.disabled = true;
        return false;
    }

    clearErrors();
    let isValid = true;

    const minStr = fMinAmount.value.trim();
    if (!minStr || !regexAmount.test(minStr)) {
        errMin.textContent = 'Valid minimum amount is required.';
        isValid = false;
    }
    
    const maxStr = fMaxAmount.value.trim();
    if (maxStr && !regexAmount.test(maxStr)) {
        errMax.textContent = 'If provided, maximum amount must be valid.';
        isValid = false;
    }

    if (isValid && maxStr) {
        const minVal = parseFloat(minStr);
        const maxVal = parseFloat(maxStr);
        if (minVal > maxVal) {
            errMax.textContent = 'Maximum amount cannot be less than minimum amount.';
            isValid = false;
        }
    }

    if (!fRisk.value) {
        errRisk.textContent = 'Risk level is required.';
        isValid = false;
    }

    if (!fCategory.value.trim()) {
        errCategory.textContent = 'Category is required.';
        isValid = false;
    }

    if (fApprovalReq.value === '1') {
        const emailStr = fApprover.value.trim();
        // Since backend allows multiple emails separated by commas, the regex expects single. Let's validate first one if multi, or just split.
        // The instructions say "approver email is required and must match email regex."
        if (!emailStr || !regexEmail.test(emailStr)) {
            errApprover.textContent = 'Valid approver email is required when approval is required.';
            isValid = false;
        }
    }

    btnSave.disabled = !isValid;
    return isValid;
}

/**
 * Expected POST Request Payload for api/matrix.php
 * {
 *   "changed_by": "admin@novalite.example",
 *   "rules": [
 *     {
 *       "rule_id": 2,
 *       "min_amount": 100.00,
 *       "max_amount": null, // or float
 *       "risk_level": "Low",
 *       "category": "Any",
 *       "approver_email": "manager@novalite.example",
 *       "approval_required": 1
 *     }
 *   ]
 * }
 */
async function handleSave(e) {
    e.preventDefault();
    if (!validateForm()) return;

    setButtonLoading(btnSave, true, 'Saving...');
    
    const payload = {
        changed_by: 'admin@novalite.example', // Mock user
        rules: [
            {
                rule_id: parseInt(fRuleId.value, 10),
                min_amount: parseFloat(fMinAmount.value.trim()),
                max_amount: fMaxAmount.value.trim() ? parseFloat(fMaxAmount.value.trim()) : null,
                risk_level: fRisk.value,
                category: fCategory.value.trim(),
                approver_email: fApprover.value.trim(),
                approval_required: parseInt(fApprovalReq.value, 10)
            }
        ]
    };

    try {
        await apiFetch('api/matrix.php', {
            method: 'POST',
            body: JSON.stringify(payload)
        });
        showToast('Approval rule saved. Future Novalite decisions will use the updated policy.', 'success');
        cancelEdit();
        loadMatrix();
    } catch (err) {
        showToast('Approval rule could not be saved. Review the highlighted fields and try again.', 'error');
        if (err.data && err.data.message) {
            showToast(err.data.message, 'error');
        }
    } finally {
        setButtonLoading(btnSave, false);
    }
}

document.addEventListener('DOMContentLoaded', init);
