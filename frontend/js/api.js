/**
 * Online Digital Service - Ultra-High-Speed Unified API Client & Utilities
 * Features: Zero-Lag In-Memory & Storage Cache, Stale-While-Revalidate (SWR), Instant Response
 */

// Backend origin. Local dev auto-detects; any other host uses window.APP_CONFIG.apiOrigin,
// which is set by js/config.js (swap that one file per environment).
const LOCAL_HOSTS = ['localhost', '127.0.0.1', ''];
// When Laravel itself serves the page (port 8000) stay same-origin, which the Content-Security-Policy requires.
const BACKEND_ORIGIN = LOCAL_HOSTS.includes(window.location.hostname)
  ? (window.location.port === '8000' ? window.location.origin : 'http://127.0.0.1:8000')
  : ((window.APP_CONFIG && window.APP_CONFIG.apiOrigin) || window.location.origin);

const API_BASE_URL = BACKEND_ORIGIN + '/api';
const STORAGE_BASE_URL = BACKEND_ORIGIN + '/storage';

// Pages are served without the .html extension in production. Normalise the
// location so every "is this page X.html" check below keeps working on both
// /login and /login.html.
function currentPagePath() {
  const p = window.location.pathname;
  if (/\.[a-z0-9]+$/i.test(p)) return p;
  if (p === '/' || p.endsWith('/')) return p + 'index.html';
  return p + '.html';
}
function getAppPath(path) {
  const isFrontendDir = currentPagePath().includes('/frontend/');
  const clean = path.startsWith('/') ? path.substring(1) : path;
  return isFrontendDir ? `/frontend/${clean}` : `/${clean}`;
}

const memoryCache = new Map();

const API = {
  // Cache Management
  cache: {
    get(key) {
      // 1. Memory Cache
      if (memoryCache.has(key)) {
        const item = memoryCache.get(key);
        if (Date.now() < item.expiry) {
          return item.data;
        }
        memoryCache.delete(key);
      }

      // 2. Session Storage fallback
      try {
        const stored = sessionStorage.getItem(`utkal_cache_${key}`);
        if (stored) {
          const item = JSON.parse(stored);
          if (Date.now() < item.expiry) {
            memoryCache.set(key, item);
            return item.data;
          }
          sessionStorage.removeItem(`utkal_cache_${key}`);
        }
      } catch (e) { }

      return null;
    },

    set(key, data, ttlSeconds = 60) {
      const item = {
        data,
        expiry: Date.now() + ttlSeconds * 1000,
      };
      memoryCache.set(key, item);
      try {
        sessionStorage.setItem(`utkal_cache_${key}`, JSON.stringify(item));
      } catch (e) { }
    },

    invalidate(pattern) {
      for (const key of memoryCache.keys()) {
        if (!pattern || key.includes(pattern)) {
          memoryCache.delete(key);
        }
      }
      try {
        for (let i = sessionStorage.length - 1; i >= 0; i--) {
          const k = sessionStorage.key(i);
          if (k && k.startsWith('utkal_cache_') && (!pattern || k.includes(pattern))) {
            sessionStorage.removeItem(k);
          }
        }
      } catch (e) { }
    }
  },

  getToken() {
    return localStorage.getItem('utkal_token');
  },

  setToken(token) {
    localStorage.setItem('utkal_token', token);
  },

  getUser() {
    const user = localStorage.getItem('utkal_user');
    return user ? JSON.parse(user) : null;
  },

  setUser(user) {
    localStorage.setItem('utkal_user', JSON.stringify(user));
  },

  clearAuth() {
    localStorage.removeItem('utkal_token');
    localStorage.removeItem('utkal_user');
    try { sessionStorage.removeItem('login_popup_seen'); } catch (e) {}
    this.cache.invalidate();
  },

  // File URLs carry no credentials. Clicks on them (and <img data-auth-src>) are resolved
  // to a 5-minute signed link fetched with the Authorization header - see bottom of this file.
  getDownloadUrl(orderId, type = 'delivery', inline = false, file = null) {
    const params = new URLSearchParams();
    if (inline) params.set('inline', '1');
    if (file) params.set('file', file);
    const qs = params.toString();
    return `${API_BASE_URL}/orders/${orderId}/download/${type}${qs ? '?' + qs : ''}`;
  },

  getWalletProofUrl(txId) {
    return `${API_BASE_URL}/admin/wallet-requests/${txId}/proof`;
  },

  isProtectedFileUrl(url) {
    return typeof url === 'string' && url.startsWith(API_BASE_URL + '/') &&
      (/^\/orders\/\d+\/download\//.test(url.slice(API_BASE_URL.length)) ||
       /^\/admin\/wallet-requests\/\d+\/proof(\?|$)/.test(url.slice(API_BASE_URL.length)));
  },

  async resolveFileUrl(url) {
    const endpoint = url.slice(API_BASE_URL.length)
      .replace(/^(\/orders\/\d+)\/download\//, '$1/download-link/')
      .replace(/^(\/admin\/wallet-requests\/\d+)\/proof/, '$1/proof-link');
    const res = await this.request(endpoint);
    return BACKEND_ORIGIN + res.url;
  },

  async request(endpoint, options = {}) {
    const url = `${API_BASE_URL}${endpoint}`;
    const token = this.getToken();

    const headers = {
      'Accept': 'application/json',
      ...(options.headers || {})
    };

    if (token) {
      headers['Authorization'] = `Bearer ${token}`;
    }

    if (options.body && !(options.body instanceof FormData)) {
      headers['Content-Type'] = 'application/json';
      options.body = JSON.stringify(options.body);
    }

    try {
      const response = await fetch(url, {
        ...options,
        headers
      });

      const data = await response.json().catch(() => ({}));

      if (!response.ok) {
        if (response.status === 401 && !endpoint.includes('/login')) {
          const isAdmin = currentPagePath().includes('/admin/');
          this.clearAuth();
          if (!currentPagePath().includes('login.html') && !currentPagePath().includes('admin.html')) {
            window.location.href = getAppPath(isAdmin ? 'admin.html' : 'login.html');
          }
        }

        if (response.status === 429) {
          throw new Error('Too many requests! Please wait a moment and try again.');
        }

        const message = data.message || (data.errors ? Object.values(data.errors).flat().join('\n') : 'Request failed');
        throw new Error(message);
      }

      return data;
    } catch (error) {
      console.error(`API Error [${endpoint}]:`, error);
      throw error;
    }
  },

  // Convenience HTTP methods
  async get(endpoint, options = {}) {
    return await this.request(endpoint, {
      method: 'GET',
      ...options
    });
  },

  async post(endpoint, body, options = {}) {
    return await this.request(endpoint, {
      method: 'POST',
      body,
      ...options
    });
  },

  // Auth Endpoints
  async resetPassword(data) {
    return await this.request('/auth/reset-password', {
      method: 'POST',
      body: data
    });
  },

  async login(credentials) {
    const res = await this.request('/auth/login', {
      method: 'POST',
      body: credentials
    });
    this.setToken(res.token);
    this.setUser(res.user);
    try { sessionStorage.removeItem('login_popup_seen'); } catch (e) {}
    this.cache.invalidate();
    return res;
  },

  async sendOtp(data) {
    return await this.request('/auth/send-otp', {
      method: 'POST',
      body: data
    });
  },

  async verifyOtp(data) {
    return await this.request('/auth/verify-otp', {
      method: 'POST',
      body: data
    });
  },

  async loginWithOtp(data) {
    const res = await this.request('/auth/login-otp', {
      method: 'POST',
      body: data
    });
    this.setToken(res.token);
    this.setUser(res.user);
    try { sessionStorage.removeItem('login_popup_seen'); } catch (e) {}
    this.cache.invalidate();
    return res;
  },

  async register(data) {
    const res = await this.request('/auth/register', {
      method: 'POST',
      body: data
    });
    this.setToken(res.token);
    this.setUser(res.user);
    try { sessionStorage.removeItem('login_popup_seen'); } catch (e) {}
    this.cache.invalidate();
    return res;
  },

  async logout() {
    const isAdmin = currentPagePath().includes('/admin/');
    try {
      await this.request('/auth/logout', { method: 'POST' });
    } catch (e) { }
    this.clearAuth();
    window.location.href = getAppPath(isAdmin ? 'admin.html' : 'login.html');
  },

  async getProfile() {
    const res = await this.request('/auth/me');
    if (res.user) this.setUser(res.user);
    return res.user;
  },

  async updateProfile(data) {
    const res = await this.request('/auth/profile', {
      method: 'PUT',
      body: data
    });
    if (res.user) this.setUser(res.user);
    this.cache.invalidate();
    return res;
  },

  async changePassword(data) {
    return await this.request('/auth/change-password', {
      method: 'PUT',
      body: data
    });
  },

  // Public & User Services
  async getServiceCategories() {
    const cacheKey = 'service_categories_list';
    const cached = this.cache.get(cacheKey);

    const fetchPromise = this.request('/service-categories').then(res => {
      this.cache.set(cacheKey, res, 60);
      return res;
    });

    return cached ? Promise.resolve(cached) : fetchPromise;
  },

  async getServices(category = '') {
    return await this.request(`/services${category ? '?category=' + encodeURIComponent(category) : ''}`);
  },

  async getPublicSettings() {
    const cacheKey = 'public_settings';
    const cached = this.cache.get(cacheKey);

    const fetchPromise = this.request('/settings/public').then(res => {
      this.cache.set(cacheKey, res, 300);
      return res;
    });

    return cached ? Promise.resolve(cached) : fetchPromise;
  },

  // Orders (with Cache Invalidation on Mutation)
  async createOrder(formData) {
    const res = await this.request('/orders', {
      method: 'POST',
      body: formData
    });
    this.cache.invalidate('orders');
    this.cache.invalidate('documents');
    this.cache.invalidate('wallet');
    return res;
  },

  async getOrders(params = {}) {
    const qs = new URLSearchParams(params).toString();
    const cacheKey = `orders_${qs}`;
    const cached = this.cache.get(cacheKey);

    const fetchPromise = this.request(`/orders${qs ? '?' + qs : ''}`).then(res => {
      this.cache.set(cacheKey, res, 15);
      return res;
    });

    return cached ? Promise.resolve(cached) : fetchPromise;
  },

  async getOrder(id) {
    return await this.request(`/orders/${id}`);
  },

  async submitMissingDocument(orderId, formData) {
    const res = await this.request(`/orders/${orderId}/submit-document`, {
      method: 'POST',
      body: formData
    });
    this.cache.invalidate('orders');
    return res;
  },

  // Wallet
  async getWallet() {
    const cacheKey = 'wallet_txs';
    const cached = this.cache.get(cacheKey);

    const fetchPromise = this.request('/wallet').then(res => {
      this.cache.set(cacheKey, res, 15);
      return res;
    });

    return cached ? Promise.resolve(cached) : fetchPromise;
  },

  async getWalletTransactions() {
    return await this.getWallet();
  },

  // Razorpay Online Payments (the only way to add money to the wallet)
  async createRazorpayWalletOrder(amount) {
    return await this.request('/payment/razorpay/create-wallet-order', {
      method: 'POST',
      body: { amount }
    });
  },

  async verifyRazorpayPayment(data) {
    const res = await this.request('/payment/razorpay/verify', {
      method: 'POST',
      body: data
    });
    this.cache.invalidate('wallet');
    this.cache.invalidate('wallet_txs');
    this.cache.invalidate('orders');
    return res;
  },

  loadRazorpayScript() {
    return new Promise((resolve, reject) => {
      if (window.Razorpay) {
        return resolve(true);
      }
      const existingScript = document.querySelector('script[src*="checkout.razorpay.com"]');
      if (existingScript) {
        existingScript.onload = () => resolve(true);
        return;
      }
      const script = document.createElement('script');
      script.src = 'https://checkout.razorpay.com/v1/checkout.js';
      script.async = true;
      script.onload = () => resolve(true);
      script.onerror = () => reject(new Error('Failed to load Razorpay payment gateway SDK.'));
      document.body.appendChild(script);
    });
  },

  // Documents
  async getDocuments() {
    const cacheKey = 'documents_list';
    const cached = this.cache.get(cacheKey);

    const fetchPromise = this.request('/documents').then(res => {
      this.cache.set(cacheKey, res, 30);
      return res;
    });

    return cached ? Promise.resolve(cached) : fetchPromise;
  },

  // Complaints
  async getComplaints() {
    const cacheKey = 'complaints_list';
    const cached = this.cache.get(cacheKey);

    const fetchPromise = this.request('/complaints').then(res => {
      this.cache.set(cacheKey, res, 30);
      return res;
    });

    return cached ? Promise.resolve(cached) : fetchPromise;
  },

  async createComplaint(data) {
    const res = await this.request('/complaints', {
      method: 'POST',
      body: data
    });
    this.cache.invalidate('complaints');
    return res;
  },

  // Admin APIs
  admin: {
    async getStats() {
      return await API.request('/admin/stats');
    },

    async getOrders(params = {}) {
      const qs = new URLSearchParams(params).toString();
      return await API.request(`/admin/orders${qs ? '?' + qs : ''}`);
    },

    async verifyPayment(orderId, action, data = {}) {
      const res = await API.request(`/admin/orders/${orderId}/verify-payment`, {
        method: 'POST',
        body: { action, ...data }
      });
      API.cache.invalidate('orders');
      return res;
    },

    // Approve / reject go through verify-payment: it is the one endpoint that also
    // performs the automatic wallet refund when an order is rejected.
    async approveOrder(orderId) {
      return await API.admin.verifyPayment(orderId, 'approve');
    },

    async rejectOrder(orderId, rejectionReason = '') {
      return await API.admin.verifyPayment(orderId, 'reject', { rejection_reason: rejectionReason });
    },

    async fulfillOrder(orderId, formData) {
      const res = await API.request(`/admin/orders/${orderId}/fulfill`, {
        method: 'POST',
        body: formData
      });
      API.cache.invalidate('orders');
      API.cache.invalidate('documents');
      return res;
    },

    async markOrderPrinted(orderId, adminNotes = '') {
      const res = await API.request(`/admin/orders/${orderId}/mark-printed`, {
        method: 'POST',
        body: { admin_notes: adminNotes || 'Printed successfully. Collect from our center.' }
      });
      API.cache.invalidate('orders');
      return res;
    },

    async requestDocument(orderId, docName, message) {
      const res = await API.request(`/admin/orders/${orderId}/request-document`, {
        method: 'POST',
        body: { doc_name: docName, message }
      });
      API.cache.invalidate('orders');
      return res;
    },

    async getWalletRequests() {
      return await API.request('/admin/wallet-requests');
    },

    async processWalletRequest(txId, action, rejectionReason = '') {
      const res = await API.request(`/admin/wallet-requests/${txId}/process`, {
        method: 'POST',
        body: { action, rejection_reason: rejectionReason }
      });
      API.cache.invalidate('wallet');
      return res;
    },

    async getUsers() {
      return await API.request('/admin/users');
    },

    async toggleUserStatus(userId) {
      return await API.request(`/admin/users/${userId}/toggle-status`, {
        method: 'POST'
      });
    },

    async adjustUserBalance(userId, amount, type, description) {
      const res = await API.request(`/admin/users/${userId}/adjust-balance`, {
        method: 'POST',
        body: { amount, type, description }
      });
      API.cache.invalidate('wallet');
      return res;
    },

    async getServices() {
      return await API.request('/admin/services');
    },

    async getServiceCategories() {
      return await API.request('/admin/services/categories');
    },

    async getCategories() {
      return await API.request('/admin/service-categories');
    },

    async saveCategory(data) {
      const isFormData = data instanceof FormData;
      const isEdit = isFormData ? !!data.get('id') : !!data.id;
      const id = isFormData ? data.get('id') : data.id;
      const res = await API.request(isEdit ? `/admin/service-categories/${id}` : '/admin/service-categories', {
        method: 'POST',
        body: data
      });
      API.cache.invalidate('service_categories');
      API.cache.invalidate('services');
      return res;
    },

    async toggleCategory(id) {
      const res = await API.request(`/admin/service-categories/${id}/toggle`, { method: 'POST' });
      API.cache.invalidate('service_categories');
      return res;
    },

    async saveService(data) {
      const isFormData = data instanceof FormData;
      const isEdit = isFormData ? !!data.get('id') : !!data.id;
      const id = isFormData ? data.get('id') : data.id;
      const res = await API.request(isEdit ? `/admin/services/${id}` : '/admin/services', {
        method: 'POST',
        body: data
      });
      API.cache.invalidate('services');
      return res;
    },

    async toggleService(id) {
      const res = await API.request(`/admin/services/${id}/toggle`, { method: 'POST' });
      API.cache.invalidate('services');
      return res;
    },

    async getComplaints() {
      return await API.request('/admin/complaints');
    },

    async replyComplaint(id, reply, status = 'resolved') {
      const res = await API.request(`/admin/complaints/${id}/reply`, {
        method: 'POST',
        body: { reply, status }
      });
      API.cache.invalidate('complaints');
      return res;
    },

    async getSettings() {
      return await API.request('/admin/settings');
    },

    async updateSettings(formData) {
      const res = await API.request('/admin/settings', {
        method: 'POST',
        body: formData
      });
      API.cache.invalidate('public_settings');
      return res;
    },

    async getLandingPage() {
      return await API.request('/admin/landing-page');
    },

    async updateLandingPage(data) {
      const res = await API.request('/admin/landing-page', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
      });
      API.cache.invalidate('landing_page_content');
      return res;
    },

    async resetLandingPage() {
      const res = await API.request('/admin/landing-page/reset', {
        method: 'POST'
      });
      API.cache.invalidate('landing_page_content');
      return res;
    },

    // Review Moderation
    async getReviews(params = {}) {
      const query = new URLSearchParams(params).toString();
      return await API.request(`/admin/reviews${query ? '?' + query : ''}`);
    },

    async toggleReview(id) {
      const res = await API.request(`/admin/reviews/${id}/toggle`, { method: 'POST' });
      API.cache.invalidate('landing_page_content');
      return res;
    },

    async deleteReview(id) {
      const res = await API.request(`/admin/reviews/${id}`, { method: 'DELETE' });
      API.cache.invalidate('landing_page_content');
      return res;
    },

    async blockReviewUser(id, action = 'block') {
      return await API.request(`/admin/reviews/${id}/block-user`, {
        method: 'POST',
        body: { action }
      });
    },

    async blockAndDeleteReview(id) {
      const res = await API.request(`/admin/reviews/${id}/block-and-delete`, {
        method: 'POST'
      });
      API.cache.invalidate('landing_page_content');
      return res;
    }
  },

  async getLandingPageContent() {
    return await this.request('/landing-page', { useCache: true, cacheTtl: 60 });
  },

  // Alias for admin dashboard
  async getAdminDashboardStats() {
    return await this.admin.getStats();
  },

  async submitReview(data) {
    return await this.request('/reviews', {
      method: 'POST',
      body: JSON.stringify(data)
    });
  },

  async getReviews(limit = 10) {
    return await this.request(`/reviews?limit=${limit}`);
  }
};

// Toast Alert System
function showToast(message, type = 'success') {
  let container = document.getElementById('toast-container');
  if (!container) {
    container = document.createElement('div');
    container.id = 'toast-container';
    document.body.appendChild(container);
  }

  const toast = document.createElement('div');
  toast.className = `toast-msg toast-${type}`;

  const icon = type === 'success' ? 'fa-check-circle' : (type === 'error' ? 'fa-triangle-exclamation' : 'fa-info-circle');
  toast.innerHTML = `<i class="fa-solid ${icon}"></i> <span>${escapeHtml(message)}</span>`;

  container.appendChild(toast);

  setTimeout(() => {
    toast.style.animation = 'slideIn 0.3s ease reverse forwards';
    setTimeout(() => toast.remove(), 300);
  }, 3500);
}

// Utility: Format currency in INR
function formatINR(val) {
  const num = parseFloat(val) || 0;
  return '₹' + num.toLocaleString('en-IN', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
}

// Utility: Format Date
function formatDate(dateStr) {
  if (!dateStr) return '-';
  const d = new Date(dateStr);
  return d.toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

// Utility: Escape HTML string to prevent XSS and rendering breakages
// Utility: Render one admin-defined service input field inside a customer order form
function renderServiceFormField(f) {
  const name = escapeHtml(f.name);
  const req = f.required ? 'required' : '';
  const placeholder = escapeHtml(f.placeholder || '');
  const label = `<label class="form-label">${escapeHtml(f.label)} ${f.required ? '<span style="color:red">*</span>' : ''}</label>`;
  let control;

  switch (f.type) {
    case 'file':
      // The real input stays in the layout (visually hidden) so the browser can point at it when a required upload is missing
      control = `
        <div class="file-dropzone" onclick="this.querySelector('input[type=file]').click()" style="position: relative; border: 2px dashed #cbd5e1; border-radius: 12px; padding: 18px; text-align: center; cursor: pointer; background: #ffffff;">
          <i class="fa-solid fa-cloud-arrow-up upload-icon" style="font-size: 1.8rem; color: #0066cc; margin-bottom: 6px; display: block;"></i>
          <div style="font-size: 0.88rem; font-weight: 700; color: #1e293b;">Click to browse or drop file</div>
          <small style="color: #64748b; font-size: 0.74rem;">Supports PDF, JPG, PNG (Max 10MB)</small>
          <input type="file" name="${name}" ${req} accept=".pdf,image/*" style="position: absolute; left: 50%; bottom: 0; width: 1px; height: 1px; opacity: 0;" onchange="const f=this.files[0]; if(f){ this.previousElementSibling.textContent = 'Selected: ' + f.name; this.previousElementSibling.style.color='#03a93a'; }">
        </div>`;
      break;
    case 'textarea':
      control = `<textarea name="${name}" class="form-control" rows="3" placeholder="${placeholder}" ${req}></textarea>`;
      break;
    case 'select':
      control = `
        <select name="${name}" class="form-control" ${req}>
          <option value="">${placeholder || 'Select an option'}</option>
          ${(f.options || []).map(o => `<option value="${escapeHtml(o)}">${escapeHtml(o)}</option>`).join('')}
        </select>`;
      break;
    default: {
      const type = ['text', 'number', 'tel', 'email', 'date'].includes(f.type) ? f.type : 'text';
      const extra = type === 'tel' ? 'inputmode="numeric" maxlength="15"' : '';
      control = `<input type="${type}" name="${name}" class="form-control" placeholder="${placeholder}" ${extra} ${req}>`;
    }
  }

  return `<div class="form-group" style="margin-bottom: 16px;">${label}${control}</div>`;
}

function escapeHtml(str) {
  if (!str) return '';
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

// Utility: Restrict values placed in class/style attributes or inline handlers (icon classes, colours)
function safeToken(str) {
  return String(str || '').replace(/[^\w\s#%.,()-]/g, '');
}

// Protected files (order documents, payment proofs) open through short-lived signed links
document.addEventListener('click', (e) => {
  const link = e.target.closest ? e.target.closest('a[href]') : null;
  if (!link || !API.isProtectedFileUrl(link.href)) return;
  e.preventDefault();

  const opensInTab = !link.hasAttribute('download') && /[?&]inline=1/.test(link.href);
  const win = opensInTab ? window.open('about:blank', '_blank') : null;

  API.resolveFileUrl(link.href)
    .then(signedUrl => {
      if (win) win.location.href = signedUrl;
      else window.location.href = signedUrl;
    })
    .catch(err => {
      if (win) win.close();
      showToast(err.message || 'Unable to open this file.', 'error');
    });
});

function hydrateProtectedImages(root = document) {
  root.querySelectorAll('img[data-auth-src]').forEach(img => {
    const src = img.getAttribute('data-auth-src');
    img.removeAttribute('data-auth-src');
    API.resolveFileUrl(src)
      .then(signedUrl => { img.src = signedUrl; })
      .catch(() => { img.alt = 'Preview unavailable'; });
  });
}

document.addEventListener('DOMContentLoaded', () => {
  hydrateProtectedImages();
  new MutationObserver(() => hydrateProtectedImages()).observe(document.body, { childList: true, subtree: true });
});

