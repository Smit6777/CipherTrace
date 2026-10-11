/**
 * js/pages/test.js
 * Testing Lab Controller (Screen G from Figma Spec)
 */

document.addEventListener('DOMContentLoaded', () => {
  const menuToggle = document.getElementById('menu-toggle');
  const sidebar = document.getElementById('app-sidebar');
  if (menuToggle && sidebar) {
    menuToggle.addEventListener('click', () => sidebar.classList.toggle('open'));
  }

  // 1. Quick Scenario A: Missing GST
  document.getElementById('btn-load-scenario-a').addEventListener('click', () => {
    document.getElementById('thread-id').value = 'SCENARIO-A-' + Date.now();
    document.getElementById('physical-sender').value = 'pack-mailbox@controlled.example';
    document.getElementById('email-subject').value = 'BlueRiver Logistics - Vendor Onboarding Documents';
    document.getElementById('email-body').value = 
`On behalf of: BlueRiver Logistics <accounts@blueriver-logistics.example>
Hello Procurement Desk,

We are submitting our onboarding details. We have attached our Tax Certificate and Bank Details.
Note: Our GST Certificate is currently in application.

Regards,
BlueRiver Operations`;
    showToast('Loaded Scenario A: Missing GST Onboarding', 'info');
  });

  // 2. Quick Scenario B: ₹3,25,000 High-Value Purchase
  document.getElementById('btn-load-scenario-b').addEventListener('click', () => {
    document.getElementById('thread-id').value = 'SCENARIO-B-' + Date.now();
    document.getElementById('physical-sender').value = 'pack-mailbox@controlled.example';
    document.getElementById('email-subject').value = 'Procurement Request - IT Hardware Infrastructure';
    document.getElementById('email-body').value = 
`On behalf of: Apex Industrial Supplies <accounts@apex-industrial.example>
Procurement Team,

Please find our purchase requisition for office IT hardware infrastructure.
Amount: INR 3,25,000
Category: IT Hardware
Required by: 2026-11-15

Thank you,
Apex Sales`;
    showToast('Loaded Scenario B: ₹3,25,000 High-Value Approval', 'info');
  });

  // 3. Quick Scenario C: Blocked Vendor Safety Stop
  document.getElementById('btn-load-scenario-c').addEventListener('click', () => {
    document.getElementById('thread-id').value = 'SCENARIO-C-' + Date.now();
    document.getElementById('physical-sender').value = 'pack-mailbox@controlled.example';
    document.getElementById('email-subject').value = 'Procurement Request - Office Supplies';
    document.getElementById('email-body').value = 
`On behalf of: Dunmore Traders <accounts@dunmore-traders.example>
Attention Procurement,

Please process our purchase order for standard office supplies.
Amount: INR 10,000
Category: Office Supplies

Best,
Dunmore Desk`;
    showToast('Loaded Scenario C: Blocked Vendor Safety Stop', 'warning');
  });

  // Intake Form Submit
  const form = document.getElementById('intake-form');
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const submitBtn = document.getElementById('submit-btn');

    const threadId = document.getElementById('thread-id').value.trim();
    const physicalFrom = document.getElementById('physical-sender').value.trim();
    const subject = document.getElementById('email-subject').value.trim();
    const body = document.getElementById('email-body').value.trim();

    if (!threadId || !physicalFrom || !subject || !body) {
      showToast('All fields are required.', 'error');
      return;
    }

    try {
      setButtonLoading(submitBtn, true, 'Evaluating through CipherTrace...');

      const payload = {
        thread_id: threadId,
        from: physicalFrom,
        subject: subject,
        body: body
      };

      const result = await apiFetch('api/intake.php', {
        method: 'POST',
        body: JSON.stringify(payload)
      });

      renderDecisionResult(result);
      showToast(`Case ${result.case_id} generated successfully!`, 'success');
    } catch (err) {
      console.error('Intake evaluation error:', err);
      showToast(err.message || 'Intake failed', 'error');
    } finally {
      setButtonLoading(submitBtn, false);
    }
  });
});

function renderDecisionResult(res) {
  document.getElementById('result-empty').style.display = 'none';
  const container = document.getElementById('result-container');
  container.style.display = 'flex';

  document.getElementById('res-case-id').textContent = res.case_id || '—';

  const statusBadge = document.getElementById('res-status-badge');
  statusBadge.className = statusClass(res.status);
  statusBadge.textContent = statusLabel(res.status);

  document.getElementById('res-real-sender').textContent = (res.real_sender && res.real_sender.email) || res.real_sender_email || '—';
  
  const ruleEl = document.getElementById('res-rule');
  ruleEl.innerHTML = `<span class="rule-tag">${res.rule_id || 'AM-DEFAULT'}</span>`;

  document.getElementById('res-reason').textContent = res.reason || res.decision_reason || '—';
  document.getElementById('res-next-action').textContent = res.next_action || '—';

  const link = document.getElementById('res-evidence-link');
  link.href = `evidence.html?id=${encodeURIComponent(res.case_id)}`;
}
