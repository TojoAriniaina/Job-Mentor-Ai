(function () {
  const API_BASE = window.API_BASE || '/api';
  let currentUserId = null;
  let allUsers = [];
  let pendingDeleteId = null;

  function esc(str) {
    const d = document.createElement('div');
    d.textContent = str == null ? '' : String(str);
    return d.innerHTML;
  }

  function initials(name) {
    if (!name) return '?';
    const parts = name.trim().split(/\s+/);
    const chars = parts.length > 1 ? parts[0][0] + parts[1][0] : parts[0].slice(0, 2);
    return chars.toUpperCase();
  }

  function avatarColor(userId) {
    const palette = ['#8b7cf6', '#5b8a91', '#c77d4f', '#4f8ac7', '#9a7cf6', '#6fae7a'];
    return palette[userId % palette.length];
  }

  function avatarHTML(u) {
    if (u.photo) {
      return `<img class="user-avatar" src="${u.photo}" alt="" style="object-fit:cover" onerror="this.replaceWith(Object.assign(document.createElement('div'),{className:'user-avatar',style:'background:${avatarColor(u.id)}',textContent:'${initials(u.name)}'}))" />`;
    }
    return `<div class="user-avatar" style="background:${avatarColor(u.id)}">${initials(u.name)}</div>`;
  }

  function formatDate(iso) {
    if (!iso) return '—';
    const d = new Date(iso.replace(' ', 'T'));
    if (isNaN(d.getTime())) return iso;
    return d.toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric' });
  }

  /* ── Stats ──────────────────────────────────────────────── */
  async function loadStats() {
    const res = await fetch(`${API_BASE}/admin/stats`, { credentials: 'include' });
    const data = await res.json();
    if (!data.success) return;
    const s = data.stats;

    const cards = [
      { label: 'Utilisateurs', value: s.total_users, icon: 'fa-users', color: '#8b7cf6' },
      { label: 'Comptes actifs', value: s.active_users, icon: 'fa-user-check', color: '#22c55e' },
      { label: 'Inscrits (7j)', value: s.new_users_7j, icon: 'fa-user-plus', color: '#4f8ac7' },
    ];
    document.getElementById('admin-stats').innerHTML = cards.map(c => `
      <div class="glass-card admin-stat-card animate-fade-up">
        <div class="admin-stat-icon" style="background:${c.color}22;color:${c.color}">
          <i class="fa-solid ${c.icon}"></i>
        </div>
        <div>
          <div class="admin-stat-value">${c.value}</div>
          <div class="admin-stat-label">${c.label}</div>
        </div>
      </div>
    `).join('');

    /* Barre de répartition d'usage par module */
    const modules = [
      { label: 'CV', value: s.total_cv, color: '#8b7cf6' },
      { label: 'Lettres', value: s.total_lettres, color: '#4f8ac7' },
      { label: 'Entretiens', value: s.total_entretiens, color: '#c77d4f' },
      { label: 'Oral', value: s.total_oral, color: '#6fae7a' },
    ];
    const total = modules.reduce((sum, m) => sum + m.value, 0);
    const bar = document.getElementById('usage-bar');
    const legend = document.getElementById('usage-legend');

    if (total === 0) {
      bar.innerHTML = `<div class="usage-bar-seg" style="width:100%;background:rgba(255,255,255,0.08)"></div>`;
      legend.innerHTML = `<span style="color:var(--text-secondary)">Aucune activité pour le moment</span>`;
      return;
    }
    bar.innerHTML = modules.map(m => {
      const pct = (m.value / total * 100).toFixed(1);
      return `<div class="usage-bar-seg" style="width:${pct}%;background:${m.color}" title="${m.label}: ${m.value}"></div>`;
    }).join('');
    legend.innerHTML = modules.map(m => `
      <div class="usage-legend-item">
        <span class="usage-legend-dot" style="background:${m.color}"></span>
        ${m.label} <strong style="color:var(--text-primary)">${m.value}</strong>
      </div>
    `).join('');
  }

  /* ── Table utilisateurs ─────────────────────────────────── */
  function rowHTML(u) {
    const isActive = Number(u.is_active) === 1;
    const isSelf = String(u.id) === String(currentUserId);
    return `
      <tr data-user-id="${u.id}">
        <td>
          <div class="user-cell">
            ${avatarHTML(u)}
            <div class="user-name-col">
              <div>${esc(u.name)}</div>
              <div class="user-email-sub">${esc(u.email)}</div>
            </div>
          </div>
        </td>
        <td>${esc(u.secteur || '—')}</td>
        <td><span class="role-pill ${u.role}">${u.role === 'admin' ? 'Admin' : 'Utilisateur'}</span></td>
        <td><span class="status-dot ${isActive ? 'active' : 'inactive'}"></span>${isActive ? 'Actif' : 'Désactivé'}</td>
        <td>${formatDate(u.created_at)}</td>
        <td>
          <div class="admin-actions">
            ${isSelf ? '<span style="font-size:0.75rem;color:var(--text-muted)">(vous)</span>' : `
              <button data-action="toggle-status" title="${isActive ? 'Désactiver' : 'Activer'}">
                <i class="fa-solid ${isActive ? 'fa-user-slash' : 'fa-user-check'}"></i>
              </button>
              <button data-action="toggle-role" title="${u.role === 'admin' ? 'Rétrograder' : 'Promouvoir admin'}">
                <i class="fa-solid ${u.role === 'admin' ? 'fa-user-minus' : 'fa-user-shield'}"></i>
              </button>
              <button data-action="delete" class="danger" title="Supprimer">
                <i class="fa-solid fa-trash"></i>
              </button>
            `}
          </div>
        </td>
      </tr>
    `;
  }

  function renderTable() {
    const tbody = document.getElementById('admin-users-tbody');
    const search = document.getElementById('admin-search').value.trim().toLowerCase();
    const roleFilter = document.getElementById('admin-filter-role').value;
    const statusFilter = document.getElementById('admin-filter-status').value;

    let filtered = allUsers.filter(u => {
      if (search && !(`${u.name} ${u.email}`.toLowerCase().includes(search))) return false;
      if (roleFilter && u.role !== roleFilter) return false;
      if (statusFilter === 'active' && Number(u.is_active) !== 1) return false;
      if (statusFilter === 'inactive' && Number(u.is_active) !== 0) return false;
      return true;
    });

    document.getElementById('admin-count-badge').textContent =
      `${filtered.length} / ${allUsers.length} utilisateur${allUsers.length > 1 ? 's' : ''}`;

    if (!filtered.length) {
      tbody.innerHTML = `
        <tr class="admin-empty-row"><td colspan="6">
          <i class="fa-solid fa-user-slash"></i>
          Aucun utilisateur ne correspond à ces critères
        </td></tr>`;
      return;
    }
    tbody.innerHTML = filtered.map(rowHTML).join('');
  }

  async function loadUsers() {
    const res = await fetch(`${API_BASE}/admin/users`, { credentials: 'include' });
    const data = await res.json();
    if (!data.success) {
      document.getElementById('admin-users-tbody').innerHTML =
        `<tr><td colspan="6" style="color:var(--red)">Erreur de chargement</td></tr>`;
      return;
    }
    allUsers = data.users;
    renderTable();
  }

  /* ── Modale de confirmation ─────────────────────────────── */
  function openConfirmModal(userName) {
    document.getElementById('admin-confirm-text').textContent =
      `Le compte de ${userName} et toutes ses données (CV, lettres, entretiens, oral) seront supprimés définitivement.`;
    document.getElementById('admin-confirm-overlay').classList.add('show');
  }
  function closeConfirmModal() {
    document.getElementById('admin-confirm-overlay').classList.remove('show');
    pendingDeleteId = null;
  }

  /* ── Actions ────────────────────────────────────────────── */
  async function handleTableClick(e) {
    const btn = e.target.closest('button[data-action]');
    if (!btn) return;
    const tr = btn.closest('tr');
    const userId = tr.dataset.userId;
    const action = btn.dataset.action;
    const user = allUsers.find(u => String(u.id) === String(userId));

    try {
      if (action === 'toggle-status') {
        const currentlyActive = Number(user.is_active) === 1;
        const res = await fetch(`${API_BASE}/admin/users/${userId}/status`, {
          method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'include',
          body: JSON.stringify({ active: !currentlyActive })
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Échec de la mise à jour');
        showToast(!currentlyActive ? 'Compte activé' : 'Compte désactivé', 'success');
        await Promise.all([loadUsers(), loadStats()]);
      } else if (action === 'toggle-role') {
        const isAdmin = user.role === 'admin';
        const res = await fetch(`${API_BASE}/admin/users/${userId}/role`, {
          method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'include',
          body: JSON.stringify({ role: isAdmin ? 'user' : 'admin' })
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Échec de la mise à jour');
        showToast(isAdmin ? 'Rôle repassé à Utilisateur' : 'Compte promu Administrateur', 'success');
        await Promise.all([loadUsers(), loadStats()]);
      } else if (action === 'delete') {
        pendingDeleteId = userId;
        openConfirmModal(user.name);
      }
    } catch (err) {
      showToast(err.message || 'Une erreur est survenue', 'error');
    }
  }

  async function confirmDelete() {
    if (!pendingDeleteId) return;
    const id = pendingDeleteId;
    closeConfirmModal();
    try {
      const res = await fetch(`${API_BASE}/admin/users/${id}`, { method: 'DELETE', credentials: 'include' });
      const data = await res.json();
      if (!data.success) throw new Error(data.error || 'Échec de la suppression');
      showToast('Compte supprimé', 'success');
      await Promise.all([loadUsers(), loadStats()]);
    } catch (err) {
      showToast(err.message || 'Une erreur est survenue', 'error');
    }
  }

  async function refreshAll(btn) {
    btn.classList.add('spinning');
    await Promise.all([loadUsers(), loadStats()]);
    setTimeout(() => btn.classList.remove('spinning'), 300);
  }

  /* ── Init ───────────────────────────────────────────────── */
  async function init() {
    try {
      const res = await fetch(`${API_BASE}/auth/check`, { credentials: 'include' });
      const data = await res.json();

      if (!data.success) {
        window.location.href = (window.FRONTEND_BASE || '') + '/pages/login.html';
        return;
      }

      currentUserId = data.user.id;

      if (data.user.role !== 'admin') {
        document.getElementById('admin-denied').style.display = 'block';
        return;
      }

      document.getElementById('admin-content').style.display = 'block';
      document.getElementById('admin-users-tbody').addEventListener('click', handleTableClick);
      document.getElementById('admin-search').addEventListener('input', renderTable);
      document.getElementById('admin-filter-role').addEventListener('change', renderTable);
      document.getElementById('admin-filter-status').addEventListener('change', renderTable);
      document.getElementById('admin-refresh-btn').addEventListener('click', (e) => refreshAll(e.currentTarget));
      document.getElementById('admin-confirm-cancel').addEventListener('click', closeConfirmModal);
      document.getElementById('admin-confirm-ok').addEventListener('click', confirmDelete);
      document.getElementById('admin-confirm-overlay').addEventListener('click', (e) => {
        if (e.target.id === 'admin-confirm-overlay') closeConfirmModal();
      });

      await Promise.all([loadStats(), loadUsers()]);
    } catch (e) {
      console.error('Erreur init admin:', e);
      showToast('Erreur de connexion au serveur', 'error');
    }
  }

  document.addEventListener('DOMContentLoaded', init);
})();
