/**
 * Utkal Print Portal - Ultra-High-Speed Unified API Client & Utilities
 * Features: Zero-Lag In-Memory & Storage Cache, Stale-While-Revalidate (SWR), Instant Response
 */

const API_BASE_URL = 'http://127.0.0.1:8000/api';
const STORAGE_BASE_URL = 'http://127.0.0.1:8000/storage';

function getAppPath(path) {
  const isFrontendDir = window.location.pathname.includes('/frontend/');
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
      } catch (e) {}

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
      } catch (e) {}
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
      } catch (e) {}
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
    this.cache.invalidate();
  },

  getDownloadUrl(orderId, type = 'delivery', inline = false, file = null) {
    const token = this.getToken();
    let url = `${API_BASE_URL}/orders/${orderId}/download/${type}?token=${encodeURIComponent(token || '')}`;
    if (inline) url += '&inline=1';
    if (file) url += `&file=${encodeURIComponent(file)}`;
    return url;
  },

  getWalletProofUrl(txId) {
    const token = this.getToken();
    return `${API_BASE_URL}/admin/wallet-requests/${txId}/proof?token=${encodeURIComponent(token || '')}`;
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
          const isAdmin = window.location.pathname.includes('/admin/');
          this.clearAuth();
          if (!window.location.pathname.includes('login.html') && !window.location.pathname.includes('admin.html')) {
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

  // Auth Endpoints
  async login(credentials) {
    const res = await this.request('/auth/login', {
      method: 'POST',
      body: credentials
    });
    this.setToken(res.token);
    this.setUser(res.user);
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
    this.cache.invalidate();
    return res;
  },

  async logout() {
    const isAdmin = window.location.pathname.includes('/admin/');
    try {
      await this.request('/auth/logout', { method: 'POST' });
    } catch (e) {}
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

  async rechargeWallet(formData) {
    const res = await this.request('/wallet/recharge', {
      method: 'POST',
      body: formData
    });
    this.cache.invalidate('wallet');
    this.cache.invalidate('wallet_txs');
    return res;
  },

  async requestWalletTopup(formData) {
    return await this.rechargeWallet(formData);
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
  toast.innerHTML = `<i class="fa-solid ${icon}"></i> <span>${message}</span>`;

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
function escapeHtml(str) {
  if (!str) return '';
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

