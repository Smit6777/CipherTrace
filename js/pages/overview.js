/**
 * js/pages/overview.js
 * Drives the Executive Operations Overview Dashboard (Screen A)
 */

document.addEventListener('DOMContentLoaded', () => {
  // Mobile sidebar toggle
  const menuToggle = document.getElementById('menu-toggle');
  const sidebar = document.getElementById('app-sidebar');
  if (menuToggle && sidebar) {
    menuToggle.addEventListener('click', () => {
      sidebar.classList.toggle('open');
    });
  }

  loadOverviewData();
});

async function loadOverviewData() {
  try {
    const data = await apiFetch('api/overview.php');
    renderStats(data.stats);
    renderRecentCases(data.recent_cases);
    renderPendingQueue(data.pending_queue);
    renderFollowUps(data.follow_ups);
  } catch (err) {
    console.error('Failed to load overview data:', err);
    showToast('Failed to load dashboard metrics.', 'error');
  }
}

function renderStats(stats) {
  if (!stats) return;
  document.getElementById('stat-total').textContent = stats.total || 0;
  document.getElementById('stat-pending-docs').textContent = stats.pending_docs || 0;
  document.getElementById('stat-pending-approval').textContent = stats.pending_approval || 0;
  document.getElementById('stat-manual-review').textContent = stats.manual_review || 0;
  document.getElementById('stat-auto-processed').textContent = stats.auto_processed || 0;
}

function renderRecentCases(cases) {
  const tbody = document.getElementById('recent-cases-tbody');
  tbody.innerHTML = '';

  if (!cases || cases.length === 0) {
    const tr = document.createElement('tr');
    const td = document.createElement('td');
    td.colSpan = 4;
    td.style.textAlign = 'center';
    td.style.padding = '2rem';
    td.style.color = '#64748b';
    td.textContent = 'No cases found.';
    tr.appendChild(td);
    tbody.appendChild(tr);
    return;
  }

  cases.forEach(c => {
    const tr = document.createElement('tr');

    const tdId = document.createElement('td');
    tdId.className = 'case-id-cell';
    tdId.textContent = c.case_id;

    const tdVendor = document.createElement('td');
    tdVendor.textContent = c.vendor_name || c.real_sender_email || '—';

    const tdStatus = document.createElement('td');
    const badge = document.createElement('span');
    badge.className = statusClass(c.status);
    badge.textContent = statusLabel(c.status);
    tdStatus.appendChild(badge);

    const tdAction = document.createElement('td');
    const link = document.createElement('a');
    link.href = `evidence.html?id=${encodeURIComponent(c.case_id)}`;
    link.className = 'btn btn-secondary btn-sm';
    link.textContent = 'Evidence Card';
    tdAction.appendChild(link);

    tr.appendChild(tdId);
    tr.appendChild(tdVendor);
    tr.appendChild(tdStatus);
    tr.appendChild(tdAction);
    tbody.appendChild(tr);
  });
}

function renderPendingQueue(queue) {
  const container = document.getElementById('pending-queue-container');
  const countBadge = document.getElementById('queue-count-badge');
  container.innerHTML = '';

  const count = queue ? queue.length : 0;
  countBadge.textContent = `${count} Pending`;

  if (!queue || queue.length === 0) {
    const empty = document.createElement('div');
    empty.style.textAlign = 'center';
    empty.style.padding = '2rem';
    empty.style.color = '#64748b';
    empty.textContent = 'No pending approvals in queue. All clean!';
    container.appendChild(empty);
    return;
  }

  queue.forEach(item => {
    const card = document.createElement('div');
    card.style.border = '1px solid var(--border)';
    card.style.borderRadius = 'var(--radius-md)';
    card.style.padding = '0.875rem 1rem';
    card.style.backgroundColor = '#ffffff';
    card.style.display = 'flex';
    card.style.justifyContent = 'space-between';
    card.style.alignItems = 'center';
    card.style.gap = '1rem';

    const left = document.createElement('div');
    const title = document.createElement('div');
    title.style.fontWeight = '700';
    title.style.fontSize = '0.875rem';
    title.textContent = `${item.case_id} — ${item.vendor_name || item.real_sender_email}`;

    const sub = document.createElement('div');
    sub.style.fontSize = '0.75rem';
    sub.style.color = 'var(--text-muted)';
    sub.textContent = `${item.category || 'General'} • Amount: ${formatCurrency(item.amount, 'INR')} • Approver: ${item.owner}`;

    left.appendChild(title);
    left.appendChild(sub);

    const right = document.createElement('a');
    right.href = `evidence.html?id=${encodeURIComponent(item.case_id)}`;
    right.className = 'btn btn-primary btn-sm';
    right.textContent = 'Sign / Review';

    card.appendChild(left);
    card.appendChild(right);
    container.appendChild(card);
  });
}

function renderFollowUps(followUps) {
  const tbody = document.getElementById('followup-tbody');
  tbody.innerHTML = '';

  if (!followUps || followUps.length === 0) {
    const tr = document.createElement('tr');
    const td = document.createElement('td');
    td.colSpan = 5;
    td.style.textAlign = 'center';
    td.style.padding = '2rem';
    td.style.color = '#64748b';
    td.textContent = 'No pending document follow-ups scheduled.';
    tr.appendChild(td);
    tbody.appendChild(tr);
    return;
  }

  followUps.forEach(f => {
    const tr = document.createElement('tr');

    const tdId = document.createElement('td');
    tdId.className = 'case-id-cell';
    tdId.textContent = f.case_id;

    const tdVendor = document.createElement('td');
    tdVendor.textContent = f.vendor_name || f.real_sender_email;

    const tdDue = document.createElement('td');
    const dueBadge = document.createElement('span');
    dueBadge.className = 'badge badge-warning';
    dueBadge.textContent = f.follow_up_due_at || 'Pending';
    tdDue.appendChild(dueBadge);

    const tdAction = document.createElement('td');
    tdAction.style.fontSize = '0.8125rem';
    tdAction.textContent = f.next_action || 'Follow up with vendor for missing compliance';

    const tdLink = document.createElement('td');
    const link = document.createElement('a');
    link.href = `evidence.html?id=${encodeURIComponent(f.case_id)}`;
    link.className = 'btn btn-secondary btn-sm';
    link.textContent = 'Open Case';
    tdLink.appendChild(link);

    tr.appendChild(tdId);
    tr.appendChild(tdVendor);
    tr.appendChild(tdDue);
    tr.appendChild(tdAction);
    tr.appendChild(tdLink);
    tbody.appendChild(tr);
  });
}
