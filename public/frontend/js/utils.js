/* ── Toggle mot de passe (œil) ─────────────────────────────── */
function togglePassword(inputId, btn) {
  const input = document.getElementById(inputId);
  const icon = btn.querySelector('i');
  if (input.type === 'password') {
    input.type = 'text';
    icon.className = 'fa-solid fa-eye-slash';
    if (btn.style) btn.style.color = 'var(--purple-light)';
  } else {
    input.type = 'password';
    icon.className = 'fa-solid fa-eye';
    if (btn.style) btn.style.color = 'var(--text-secondary)';
  }
}

/* ── Toast notifications ───────────────────────────────────── */
function showToast(message, type = 'info', duration = 3500) {
  let container = document.getElementById('toast-container');
  if (!container) {
    container = document.createElement('div');
    container.id = 'toast-container';
    container.className = 'toast-container';
    document.body.appendChild(container);
  }

  const icons = { 
    info: '<i class="fa-solid fa-lightbulb"></i>', 
    success: '<i class="fa-solid fa-check"></i>', 
    error: '<i class="fa-solid fa-xmark"></i>', 
    warning: '<i class="fa-solid fa-triangle-exclamation"></i>' 
  };
  const toast = document.createElement('div');
  toast.className = 'toast';
  toast.innerHTML = `<span style="font-size:1.1rem">${icons[type] || icons.info}</span><span>${message}</span>`;
  container.appendChild(toast);
  setTimeout(() => toast.remove(), duration + 400);
}

/* ── Loading state ─────────────────────────────────────────── */
function setLoading(btn, loading, loadingText = 'Chargement...') {
  if (loading) {
    btn._originalHTML = btn.innerHTML;
    btn.innerHTML = `<span class="spinner"></span> ${loadingText}`;
    btn.disabled = true;
  } else {
    btn.innerHTML = btn._originalHTML || btn.innerHTML;
    btn.disabled = false;
  }
}

/* ── Tabs ──────────────────────────────────────────────────── */
function initTabs(container) {
  const buttons = container.querySelectorAll('.tab-btn');
  const contents = container.querySelectorAll('.tab-content');

  buttons.forEach(btn => {
    btn.addEventListener('click', () => {
      const target = btn.dataset.tab;
      buttons.forEach(b => b.classList.remove('active'));
      contents.forEach(c => c.classList.remove('active'));
      btn.classList.add('active');
      const targetContent = container.querySelector(`[data-tab-content="${target}"]`);
      if (targetContent) targetContent.classList.add('active');
    });
  });
}

/* ── Score ring ────────────────────────────────────────────── */
function createScoreRing(score, color = '#8b5cf6', size = 100, strokeWidth = 8) {
  const r = (size / 2) - strokeWidth;
  const circ = 2 * Math.PI * r;
  const dash = (score / 100) * circ;

  return `
<div class="score-ring" style="width:${size}px;height:${size}px">
  <svg width="${size}" height="${size}" viewBox="0 0 ${size} ${size}">
    <circle cx="${size / 2}" cy="${size / 2}" r="${r}" fill="none"
      stroke="rgba(255,255,255,0.08)" stroke-width="${strokeWidth}"/>
    <circle cx="${size / 2}" cy="${size / 2}" r="${r}" fill="none"
      stroke="${color}" stroke-width="${strokeWidth}"
      stroke-linecap="round"
      stroke-dasharray="${dash} ${circ}"
      style="transition: stroke-dasharray 1s cubic-bezier(0.4,0,0.2,1)"/>
  </svg>
  <div class="score-ring-value" style="color:${color}">${score}%</div>
</div>`;
}

/* ── Navbar active link ────────────────────────────────────── */
function setActiveNav() {
  const path = window.location.pathname;
  document.querySelectorAll('.nav-links a').forEach(link => {
    link.classList.remove('active');
    if (link.href && link.href.includes(path.split('/').pop())) {
      link.classList.add('active');
    }
  });
  if (path.endsWith('/') || path.endsWith('index.html')) {
    const homeLink = document.querySelector('.nav-links a[href*="index"]');
    if (homeLink) homeLink.classList.add('active');
  }
}

/* ── Mobile nav ────────────────────────────────────────────── */
function initMobileNav() {
  const toggle = document.getElementById('nav-toggle');
  const links = document.getElementById('nav-links');
  if (!toggle || !links) return;
  toggle.addEventListener('click', () => {
    links.classList.toggle('show');
    toggle.classList.toggle('active');
  });
  links.querySelectorAll('a').forEach(a => {
    a.addEventListener('click', () => {
      links.classList.remove('show');
      toggle.classList.remove('active');
    });
  });
}

/* ── Particles logic moved to particles.js ───────────────────────────── */

/* ── Scroll reveal ─────────────────────────────────────────── */
function initScrollReveal() {
  const observer = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
      if (entry.isIntersecting) {
        entry.target.classList.add('animate-fade-up');
        observer.unobserve(entry.target);
      }
    });
  }, { threshold: 0.1 });

  document.querySelectorAll('.reveal').forEach(el => observer.observe(el));
}

/* ── Copy to clipboard ─────────────────────────────────────── */
async function copyToClipboard(text, btn) {
  try {
    await navigator.clipboard.writeText(text);
    const original = btn.innerHTML;
    btn.innerHTML = '<i class="fa-solid fa-check"></i> Copié !';
    btn.style.color = 'var(--teal-light)';
    setTimeout(() => { btn.innerHTML = original; btn.style.color = ''; }, 2000);
    showToast('Copié dans le presse-papier !', 'success');
  } catch {
    showToast('Impossible de copier', 'error');
  }
}

/* ── Format score color ────────────────────────────────────── */
function scoreColor(score) {
  if (score >= 80) return 'var(--teal)';
  if (score >= 60) return 'var(--amber)';
  return '#ef4444';
}

/* ── Authentication & Settings ───────────────────────────────── */
let selectedProvider = 'openrouter';

/* ── Clés localStorage isolées par utilisateur ──────────────── */
// Toutes les données sont préfixées par l'ID utilisateur.
// Quand un nouvel utilisateur se connecte, on purge les données
// de l'ancien utilisateur afin qu'aucune donnée ne soit partagée.

function _getCurrentUserId() {
  try {
    const u = JSON.parse(localStorage.getItem('jm_current_user') || 'null');
    return u ? String(u.id) : null;
  } catch { return null; }
}

// Préfixe universel : toutes les clés applicatives passent par ici
function jmKey(key) {
  const uid = _getCurrentUserId();
  return uid ? `jm_u${uid}_${key}` : `jm_guest_${key}`;
}

// Purge toutes les données applicatives d'un utilisateur donné
function _purgeUserData(userId) {
  if (!userId) return;
  const prefix = `jm_u${userId}_`;
  const guestPrefix = `jm_guest_`;
  const toDelete = [];
  for (let i = 0; i < localStorage.length; i++) {
    const k = localStorage.key(i);
    if (k && (k.startsWith(prefix) || k.startsWith(guestPrefix))) {
      toDelete.push(k);
    }
  }
  toDelete.forEach(k => localStorage.removeItem(k));
}

// Purge TOUT le localStorage applicatif (toutes clés jm_u* et jm_guest_*)
function _purgeAllAppData() {
  const toDelete = [];
  for (let i = 0; i < localStorage.length; i++) {
    const k = localStorage.key(i);
    if (k && (k.startsWith('jm_u') || k.startsWith('jm_guest_') ||
              k === 'jm_profile' || k === 'jm_user' ||
              k === 'jm_last_cv' || k === 'jm_last_analysis' ||
              k === 'jm_last_adaptation' || k === 'jm_last_lettre' ||
              k === 'jm_last_lettre_correction' || k === 'jm_last_chat' ||
              k === 'jm_last_notes' || k === 'jm_last_oral' ||
              k === 'jm_last_oral_text' || k === 'jm_current_user')) {
      toDelete.push(k);
    }
  }
  toDelete.forEach(k => localStorage.removeItem(k));
}

function getStoredProfile() {
  try {
    return JSON.parse(localStorage.getItem(jmKey('profile')) || '{}');
  } catch {
    return {};
  }
}

function saveAuthProfile(user, profile) {
  if (!user && !profile) return;

  const existingProfile = getStoredProfile();
  // La photo peut être explicitement vidée ('') par le serveur (suppression) :
  // on utilise ?? pour ne retomber sur l'existant que si rien n'est fourni du tout.
  const nextProfile = {
    nom:     profile?.name || user?.name || existingProfile.nom || '',
    email:   profile?.email || user?.email || existingProfile.email || '',
    titre:   profile?.titre || existingProfile.titre || '',
    phone:   profile?.phone || existingProfile.phone || '',
    secteur: profile?.secteur || existingProfile.secteur || '',
    photo:   profile?.photo ?? user?.photo ?? existingProfile.photo ?? ''
  };

  if (user) {
    localStorage.setItem('jm_current_user', JSON.stringify({
      id: user.id,
      name: user.name || nextProfile.nom,
      email: user.email || nextProfile.email,
      photo: nextProfile.photo
    }));
    // Compatibilité ancienne clé (lecture seule, non utilisée en écriture)
    localStorage.setItem('jm_user', JSON.stringify({
      id: user.id,
      name: user.name || nextProfile.nom,
      email: user.email || nextProfile.email
    }));
  }
  localStorage.setItem(jmKey('profile'), JSON.stringify(nextProfile));
}

async function checkAuthStatus() {
  const isLoginPage = window.location.pathname.endsWith('login.html');
  
  // Masquer l'interface par défaut (sauf sur la page login)
  if (!isLoginPage) {
    document.body.classList.add('auth-waiting');
  }

  const btnConfig = document.getElementById('btn-config');
  const AUTH_API_URL = (window.API_BASE || '/api') + '/auth';

  try {
    const res = await fetch(AUTH_API_URL + '/check', { credentials: "include" });
    const data = await res.json();

    if (data.success) {
      // ── Détection changement d'utilisateur ──────────────────
      const previousUser = JSON.parse(localStorage.getItem('jm_current_user') || 'null');
      const newUserId = String(data.user.id);

      if (previousUser && String(previousUser.id) !== newUserId) {
        // Un utilisateur différent se connecte → purge des données précédentes
        _purgeUserData(String(previousUser.id));
        // Nettoyer aussi les anciennes clés non préfixées
        ['jm_profile','jm_user','jm_last_cv','jm_last_analysis',
         'jm_last_adaptation','jm_last_lettre','jm_last_lettre_correction',
         'jm_last_chat','jm_last_notes','jm_last_oral','jm_last_oral_text'
        ].forEach(k => localStorage.removeItem(k));
      }

      // Connecté
      document.body.classList.remove('auth-waiting');
      saveAuthProfile(data.user, data.profile);

      if (btnConfig) {
        setupProfileDropdown(btnConfig, data.user, AUTH_API_URL);
      }
    } else {
      // Déconnecté — purger les anciennes données non liées à un user
      _purgeAllAppData();
      if (!isLoginPage) {
        // Rediriger vers login si on n'y est pas déjà
        window.location.href = (window.FRONTEND_BASE || '') + '/pages/login.html';
      }
    }
  } catch (e) {
    console.warn("Erreur authentification", e);
    if (!isLoginPage) {
      document.body.classList.remove('auth-waiting'); // Éviter de bloquer l'UI en cas d'erreur réseau
    }
  }
}

/* ── Menu déroulant Profil (header) ────────────────────────── */
function _initials(name) {
  if (!name) return '?';
  const parts = name.trim().split(/\s+/);
  const chars = parts.length > 1 ? parts[0][0] + parts[1][0] : parts[0].slice(0, 2);
  return chars.toUpperCase();
}

function _avatarHTML(user, sizeClass) {
  const photo = (user && user.photo) || getStoredProfile().photo || '';
  if (photo) {
    return `<img class="profile-avatar ${sizeClass}" src="${photo}" alt="${user.name}" />`;
  }
  return `<span class="profile-avatar ${sizeClass}">${_initials(user.name)}</span>`;
}

function setupProfileDropdown(btnConfig, user, AUTH_API_URL) {
  // Empêche la double init si checkAuthStatus est rappelé
  let wrap = btnConfig.closest('.profile-trigger-wrap');
  if (!wrap) {
    wrap = document.createElement('div');
    wrap.className = 'profile-trigger-wrap';
    btnConfig.parentNode.insertBefore(wrap, btnConfig);
    wrap.appendChild(btnConfig);
  }

  btnConfig.className = 'btn-nav btn-nav-ghost profile-trigger';
  btnConfig.innerHTML =
    _avatarHTML(user, '') +
    '<span class="profile-trigger-name">' + user.name + '</span>' +
    '<i class="fa-solid fa-chevron-down profile-trigger-chevron"></i>';

  let dropdown = wrap.querySelector('.profile-dropdown');
  if (!dropdown) {
    dropdown = document.createElement('div');
    dropdown.className = 'profile-dropdown';
    wrap.appendChild(dropdown);
  }

  const profileExtra = getStoredProfile();
  dropdown.innerHTML = `
    <div class="profile-dropdown-header">
      ${_avatarHTML(user, 'profile-avatar-lg')}
      <div class="profile-dropdown-identity">
        <span class="profile-dropdown-name">${user.name}</span>
        <span class="profile-dropdown-email">${user.email || ''}</span>
        ${profileExtra.phone ? `<span class="profile-dropdown-phone"><i class="fa-solid fa-phone"></i> ${profileExtra.phone}</span>` : ''}
      </div>
    </div>
    <div class="profile-dropdown-divider"></div>
    <button type="button" class="dropdown-item" id="dropdown-item-edit-profile">
      <i class="fa-solid fa-user-pen dropdown-item-icon"></i> Modifier le profil
    </button>
    ${user.role === 'admin' ? `
    <button type="button" class="dropdown-item" id="dropdown-item-admin">
      <i class="fa-solid fa-shield-halved dropdown-item-icon"></i> Administration
    </button>` : ''}
    <button type="button" class="dropdown-item dropdown-item-danger" id="dropdown-item-logout">
      <i class="fa-solid fa-right-from-bracket dropdown-item-icon"></i> Déconnexion
    </button>
  `;

  let closeTimer = null;
  const openDropdown = () => {
    if (closeTimer) { clearTimeout(closeTimer); closeTimer = null; }
    dropdown.classList.add('is-open');
    btnConfig.setAttribute('aria-expanded', 'true');
  };
  const closeDropdown = () => {
    dropdown.classList.remove('is-open');
    btnConfig.setAttribute('aria-expanded', 'false');
  };
  const scheduleClose = () => {
    if (closeTimer) clearTimeout(closeTimer);
    closeTimer = setTimeout(closeDropdown, 220);
  };
  const toggleDropdown = (e) => {
    e.stopPropagation();
    dropdown.classList.contains('is-open') ? closeDropdown() : openDropdown();
  };

  // Survol : ouverture immédiate, fermeture différée (laisse le temps de
  // traverser l'espace vide entre le nom et le menu sans qu'il se referme).
  wrap.onmouseenter = openDropdown;
  wrap.onmouseleave = scheduleClose;
  // Clic gardé en secours pour le tactile (pas de survol sur mobile/tablette).
  btnConfig.onclick = toggleDropdown;

  dropdown.querySelector('#dropdown-item-edit-profile').onclick = () => {
    closeDropdown();
    openEditProfileModal(user, AUTH_API_URL);
  };
  const adminBtn = dropdown.querySelector('#dropdown-item-admin');
  if (adminBtn) {
    adminBtn.onclick = () => {
      closeDropdown();
      window.location.href = (window.FRONTEND_BASE || '') + '/pages/admin.html';
    };
  }
  dropdown.querySelector('#dropdown-item-logout').onclick = async () => {
    closeDropdown();
    const uid = _getCurrentUserId();
    await fetch(AUTH_API_URL + '/logout', { credentials: "include" });
    if (uid) _purgeUserData(uid);
    _purgeAllAppData();
    window.location.reload();
  };

  // Ferme le menu si on clique n'importe où ailleurs sur la page
  if (!wrap.dataset.outsideClickBound) {
    document.addEventListener('click', (e) => {
      if (!wrap.contains(e.target)) closeDropdown();
    });
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') closeDropdown();
    });
    wrap.dataset.outsideClickBound = 'true';
  }
}

/* ── Modal "Modifier le profil" (nom + photo) ─────────────────────────── */
function _resizeImageFile(file, maxSize = 320, quality = 0.82) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = (e) => {
      const img = new Image();
      img.onload = () => {
        let { width, height } = img;
        if (width > height && width > maxSize) {
          height = Math.round(height * (maxSize / width));
          width = maxSize;
        } else if (height >= width && height > maxSize) {
          width = Math.round(width * (maxSize / height));
          height = maxSize;
        }
        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;
        canvas.getContext('2d').drawImage(img, 0, 0, width, height);
        resolve(canvas.toDataURL('image/jpeg', quality));
      };
      img.onerror = reject;
      img.src = e.target.result;
    };
    reader.onerror = reject;
    reader.readAsDataURL(file);
  });
}

let _editPhotoBase64; // undefined = inchangée, '' = supprimée, 'data:...' = nouvelle photo
let _editProfileAuthUrl = '';

function injectEditProfileModal() {
  if (document.getElementById('edit-profile-modal')) return;

  const modalHTML = `
    <div class="modal-overlay" id="edit-profile-modal" onclick="if(event.target === this) closeEditProfileModal()">
      <div class="modal-container">
        <div class="modal-header">
          <h2 class="modal-title">Modifier le profil</h2>
          <button class="modal-close" onclick="closeEditProfileModal()">&times;</button>
        </div>

        <div class="text-center mb-4">
          <div class="avatar-picker">
            <input type="file" id="edit-photo-input" accept="image/*" style="display:none" onchange="previewEditPhoto(this)"/>
            <div class="avatar-picker-circle" id="edit-avatar-circle" onclick="document.getElementById('edit-photo-input').click()">
              <img id="edit-photo-preview" src="" alt="Photo" style="display:none" />
              <span id="edit-avatar-initials"></span>
            </div>
            <span class="avatar-picker-camera" onclick="document.getElementById('edit-photo-input').click()">
              <i class="fa-solid fa-camera"></i>
            </span>
          </div>
          <button type="button" id="edit-remove-photo-btn" class="avatar-remove-btn" onclick="removeEditPhoto()" style="display:none">
            ✕ Retirer la photo
          </button>
        </div>

        <div class="form-group-floating mb-3">
          <input class="form-control" id="edit-profile-name" type="text" placeholder=" " />
          <label for="edit-profile-name">Nom complet</label>
        </div>

        <div class="form-group-floating mb-3">
          <input class="form-control" id="edit-profile-email" type="email" placeholder=" " />
          <label for="edit-profile-email">Email</label>
        </div>

        <div class="form-group-floating mb-4">
          <input class="form-control" id="edit-profile-phone" type="tel" placeholder=" " />
          <label for="edit-profile-phone">Téléphone</label>
        </div>

        <div style="display:flex;align-items:center;gap:0.8rem">
          <button class="btn btn-primary btn-full" id="btn-save-profile" onclick="saveProfileEdits()">
            <i class="fa-solid fa-floppy-disk"></i> Enregistrer les modifications
          </button>
          <span class="saved-badge" id="profile-saved-badge"><i class="fa-solid fa-check"></i> Sauvegardé</span>
        </div>
      </div>
    </div>
  `;
  document.body.insertAdjacentHTML('beforeend', modalHTML);
}

function _refreshEditAvatarPreview(name, photo) {
  const preview = document.getElementById('edit-photo-preview');
  const initials = document.getElementById('edit-avatar-initials');
  const removeBtn = document.getElementById('edit-remove-photo-btn');
  if (photo) {
    preview.src = photo;
    preview.style.display = 'block';
    initials.style.display = 'none';
    removeBtn.style.display = 'inline-block';
  } else {
    preview.style.display = 'none';
    preview.src = '';
    initials.style.display = 'flex';
    initials.textContent = _initials(name);
    removeBtn.style.display = 'none';
  }
}

window.openEditProfileModal = function(user, authApiUrl) {
  injectEditProfileModal();
  _editProfileAuthUrl = authApiUrl || _editProfileAuthUrl;

  const modal = document.getElementById('edit-profile-modal');
  modal.classList.add('active');

  const profile = getStoredProfile();
  const name = user?.name || profile.nom || '';
  const email = user?.email || profile.email || '';
  const phone = profile.phone || '';
  const photo = (user && user.photo) || profile.photo || '';

  _editPhotoBase64 = undefined;
  document.getElementById('edit-profile-name').value = name;
  document.getElementById('edit-profile-email').value = email;
  document.getElementById('edit-profile-phone').value = phone;
  _refreshEditAvatarPreview(name, photo);
};

window.closeEditProfileModal = function() {
  const modal = document.getElementById('edit-profile-modal');
  if (modal) modal.classList.remove('active');
};

window.previewEditPhoto = async function(input) {
  const file = input.files[0];
  if (!file) return;
  try {
    _editPhotoBase64 = await _resizeImageFile(file);
    _refreshEditAvatarPreview(document.getElementById('edit-profile-name').value, _editPhotoBase64);
  } catch (e) {
    showToast("Impossible de lire l'image", 'error');
  }
};

window.removeEditPhoto = function() {
  _editPhotoBase64 = '';
  document.getElementById('edit-photo-input').value = '';
  _refreshEditAvatarPreview(document.getElementById('edit-profile-name').value, '');
};

window.saveProfileEdits = async function() {
  const btn = document.getElementById('btn-save-profile');
  const name = document.getElementById('edit-profile-name').value.trim();
  const email = document.getElementById('edit-profile-email').value.trim();
  const phone = document.getElementById('edit-profile-phone').value.trim();
  if (!name) { showToast('Le nom ne peut pas être vide', 'warning'); return; }
  if (!email) { showToast("L'email ne peut pas être vide", 'warning'); return; }

  const payload = { name, email, phone };
  if (_editPhotoBase64 !== undefined) payload.photo = _editPhotoBase64;

  const authUrl = _editProfileAuthUrl || (window.API_BASE || '/api') + '/auth';

  setLoading(btn, true, 'Enregistrement...');
  try {
    const res = await fetch(authUrl + '/update-profile', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'include',
      body: JSON.stringify(payload)
    });
    const data = await res.json();
    if (!data.success) throw new Error(data.error || 'Erreur lors de la mise à jour');

    saveAuthProfile(data.user, data.profile);
    showToast('Profil mis à jour !', 'success');

    const badge = document.getElementById('profile-saved-badge');
    if (badge) {
      badge.classList.add('show');
      setTimeout(() => badge.classList.remove('show'), 1800);
    }

    setTimeout(() => closeEditProfileModal(), 550);

    // Rafraîchir immédiatement le nom/avatar affichés dans le header
    const btnConfig = document.querySelector('.profile-trigger') || document.getElementById('btn-config');
    if (btnConfig) setupProfileDropdown(btnConfig, data.user, authUrl);
  } catch (e) {
    showToast(e.message || 'Erreur lors de la sauvegarde', 'error');
  } finally {
    setLoading(btn, false);
  }
};

/* ── Require API Wrapper ───────────────────────────────────── */
async function requireAPI(callback) {
  try {
    if (typeof callback === 'function') {
      await callback();
    }
  } catch (error) {
    console.error("Erreur API :", error);
    showToast(error.message || "Erreur réseau", 'error');
  }
}

/* ── Init on DOM ready ─────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', () => {
  // initParticles(); // Moved to particles.js
  initScrollReveal();
  setActiveNav();
  initMobileNav();
  checkAuthStatus();
  injectEditProfileModal();
});
