/**
 * Utkal Print Portal - Auth Guard, Mobile Navigation & Page Decorator
 */

function getAppPath(path) {
  const isFrontendDir = window.location.pathname.includes('/frontend/');
  const clean = path.startsWith('/') ? path.substring(1) : path;
  return isFrontendDir ? `/frontend/${clean}` : `/${clean}`;
}

(function initAuthGuard() {
  const currentPath = window.location.pathname;
  const isAuthPage = currentPath.includes('login.html') || currentPath.includes('admin.html') || currentPath.includes('register.html');
  const isUserPage = currentPath.includes('/user/');
  const isAdminPage = currentPath.includes('/admin/') && !currentPath.includes('/admin/login.html') && !currentPath.includes('/admin/index.html');

  const token = API.getToken();
  const user = API.getUser();

  // Instant redirect before DOM parsing if invalid
  if (isAuthPage && token && user) {
    window.location.replace(user.role === 'admin' ? getAppPath('admin/dashboard.html') : getAppPath('user/dashboard.html'));
    return;
  }

  if (isUserPage && (!token || !user)) {
    window.location.replace(getAppPath('login.html'));
    return;
  }

  if (isAdminPage) {
    if (!token || !user) {
      window.location.replace(getAppPath('admin.html'));
      return;
    }
    if (user.role !== 'admin') {
      window.location.replace(getAppPath('user/dashboard.html'));
      return;
    }
  }
})();

document.addEventListener('DOMContentLoaded', () => {
  const token = API.getToken();
  const user = API.getUser();

  // 1. Instant local render (0ms delay)
  if (token && user) {
    const initials = (function(name) {
      if (!name) return 'UP';
      const parts = name.trim().split(/\s+/);
      return parts.length >= 2 ? (parts[0][0] + parts[1][0]).toUpperCase() : name.substring(0, 2).toUpperCase();
    })(user.name);

    document.querySelectorAll('.user-name-display').forEach(el => el.textContent = user.name || 'Member');
    document.querySelectorAll('.user-email-display').forEach(el => el.textContent = user.email || '');
    document.querySelectorAll('.user-phone-display').forEach(el => el.textContent = user.phone || '');
    document.querySelectorAll('.user-wallet-display').forEach(el => el.textContent = formatINR(user.wallet_balance || 0));
    document.querySelectorAll('.user-initials-display').forEach(el => el.textContent = initials);
    document.querySelectorAll('.user-role-display').forEach(el => el.textContent = user.role === 'admin' ? 'Root Admin' : 'Active User');

    // 2. Non-blocking background sync (Stale-While-Revalidate)
    API.getProfile().then(refreshed => {
      if (refreshed) {
        document.querySelectorAll('.user-wallet-display').forEach(el => el.textContent = formatINR(refreshed.wallet_balance || 0));
        document.querySelectorAll('.user-name-display').forEach(el => el.textContent = refreshed.name || 'Member');
      }
    }).catch(() => {});
  }

  // Handle logout buttons
  document.querySelectorAll('.btn-logout-action').forEach(btn => {
    btn.addEventListener('click', (e) => {
      e.preventDefault();
      if (confirm('Are you sure you want to log out?')) {
        API.logout();
      }
    });
  });

  // Navigation Bar Sidebar Controller
  const sidebarToggle = document.querySelector('.sidebar-toggle');
  const portalSidebar = document.querySelector('.portal-sidebar');

  if (portalSidebar) {
    // Desktop Mini-Sidebar Rail Collapse & Expand Controller
    const sidebarHeader = portalSidebar.querySelector('.sidebar-header');
    let collapseBtn = portalSidebar.querySelector('.sidebar-collapse-btn');

    if (sidebarHeader && !collapseBtn) {
      collapseBtn = document.createElement('button');
      collapseBtn.type = 'button';
      collapseBtn.className = 'sidebar-collapse-btn';
      collapseBtn.id = 'sidebarCollapseBtn';
      collapseBtn.title = 'Collapse/Expand Navigation Bar';
      collapseBtn.setAttribute('aria-label', 'Toggle Navigation Bar Rail');
      collapseBtn.innerHTML = '<i class="fa-solid fa-chevron-left" id="collapseIcon"></i>';
      sidebarHeader.appendChild(collapseBtn);
    }

    // Set data-tooltip on all sidebar links for smooth custom tooltip in collapsed mode
    portalSidebar.querySelectorAll('.sidebar-link').forEach(link => {
      const label = link.querySelector('span');
      if (label) {
        link.removeAttribute('title');
        link.setAttribute('data-tooltip', label.textContent.trim());
      }
    });

    // Set data-tooltip on sidebar bottom actions (Logout & Avatar)
    const sidebarLogout = portalSidebar.querySelector('.btn-logout-action');
    if (sidebarLogout) {
      const logoutTitle = sidebarLogout.getAttribute('title') || 'Logout';
      sidebarLogout.removeAttribute('title');
      sidebarLogout.setAttribute('data-tooltip', logoutTitle);
    }

    const sidebarAvatar = portalSidebar.querySelector('.topbar-avatar');
    if (sidebarAvatar) {
      const name = (user && user.name) ? user.name : (portalSidebar.querySelector('.user-name-display')?.textContent?.trim() || 'My Account');
      sidebarAvatar.setAttribute('data-tooltip', name);
    }

    const isCollapsedSaved = localStorage.getItem('utkal_sidebar_collapsed') === 'true';
    if (isCollapsedSaved) {
      portalSidebar.classList.add('collapsed');
      if (collapseBtn) {
        collapseBtn.title = 'Expand Navigation Bar';
      }
    }

    if (collapseBtn) {
      collapseBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        const isNowCollapsed = portalSidebar.classList.toggle('collapsed');
        localStorage.setItem('utkal_sidebar_collapsed', isNowCollapsed ? 'true' : 'false');
        collapseBtn.title = isNowCollapsed ? 'Expand Navigation Bar' : 'Collapse Navigation Bar';
      });
    }

    // Clicking on the collapsed sidebar expands it back out (excluding links, action buttons, and collapse toggle)
    portalSidebar.addEventListener('click', (e) => {
      if (portalSidebar.classList.contains('collapsed') && 
          !e.target.closest('.sidebar-link') && 
          !e.target.closest('.btn-logout-action') &&
          !e.target.closest('.sidebar-collapse-btn')) {
        portalSidebar.classList.remove('collapsed');
        localStorage.setItem('utkal_sidebar_collapsed', 'false');
        if (collapseBtn) {
          collapseBtn.title = 'Collapse Navigation Bar';
        }
      }
    });

    // Mobile Sidebar & Backdrop Controller
    let backdrop = document.querySelector('.portal-sidebar-backdrop');
    if (!backdrop) {
      backdrop = document.createElement('div');
      backdrop.className = 'portal-sidebar-backdrop';
      document.body.appendChild(backdrop);
    }

    // Add mobile close button to sidebar header if not present
    if (sidebarHeader && !sidebarHeader.querySelector('.sidebar-mobile-close-btn')) {
      const mobileCloseBtn = document.createElement('button');
      mobileCloseBtn.type = 'button';
      mobileCloseBtn.className = 'sidebar-mobile-close-btn';
      mobileCloseBtn.setAttribute('aria-label', 'Close Menu');
      mobileCloseBtn.innerHTML = '<i class="fa-solid fa-xmark"></i>';
      mobileCloseBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        closeSidebar();
      });
      sidebarHeader.appendChild(mobileCloseBtn);
    }

    const openSidebar = () => {
      portalSidebar.classList.add('show');
      backdrop.classList.add('active');
      document.body.classList.add('sidebar-open');
    };

    const closeSidebar = () => {
      portalSidebar.classList.remove('show');
      backdrop.classList.remove('active');
      document.body.classList.remove('sidebar-open');
    };

    if (sidebarToggle) {
      sidebarToggle.addEventListener('click', (e) => {
        e.stopPropagation();
        if (portalSidebar.classList.contains('show')) {
          closeSidebar();
        } else {
          openSidebar();
        }
      });
    }

    backdrop.addEventListener('click', closeSidebar);

    // Escape key closes mobile drawer
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && portalSidebar.classList.contains('show')) {
        closeSidebar();
      }
    });

    // Handle clicking sidebar navigation links
    portalSidebar.querySelectorAll('.sidebar-link').forEach(link => {
      link.addEventListener('click', (e) => {
        const href = link.getAttribute('href');
        if (href) {
          const currentPage = window.location.pathname.split('/').pop() || 'dashboard.html';
          if (href === currentPage || href === './' + currentPage) {
            e.preventDefault();
            window.scrollTo({ top: 0, behavior: 'smooth' });
            if (window.innerWidth <= 991) {
              closeSidebar();
            }
            return;
          }
        }
        if (window.innerWidth <= 991) {
          closeSidebar();
        }
      });
    });
  }

  // Inject Mobile Bottom Navigation for User Portal
  const isUserPortal = window.location.pathname.includes('/user/');
  if (isUserPortal && !document.querySelector('.mobile-bottom-bar')) {
    const currentPath = window.location.pathname;
    const isDashboard = currentPath.includes('dashboard.html');
    const isServices = currentPath.includes('services.html') || currentPath.includes('print-list.html') || currentPath.includes('pan-find.html');
    const isOrders = currentPath.includes('orders.html');
    const isWallet = currentPath.includes('wallet.html');
    const isProfile = currentPath.includes('profile.html');

    const bottomBar = document.createElement('nav');
    bottomBar.className = 'mobile-bottom-bar';
    bottomBar.innerHTML = `
      <a href="dashboard.html" class="bottom-nav-item ${isDashboard ? 'active' : ''}">
        <i class="fa-solid fa-house"></i>
        <span>Home</span>
      </a>
      <a href="services.html" class="bottom-nav-item ${isServices ? 'active' : ''}">
        <i class="fa-solid fa-layer-group"></i>
        <span>Services</span>
      </a>
      <a href="orders.html" class="bottom-nav-item ${isOrders ? 'active' : ''}">
        <i class="fa-solid fa-clock-rotate-left"></i>
        <span>Orders</span>
      </a>
      <a href="wallet.html" class="bottom-nav-item ${isWallet ? 'active' : ''}">
        <i class="fa-solid fa-wallet"></i>
        <span>Wallet</span>
      </a>
      <a href="profile.html" class="bottom-nav-item ${isProfile ? 'active' : ''}">
        <i class="fa-solid fa-user"></i>
        <span>Account</span>
      </a>
    `;
    document.body.appendChild(bottomBar);
  }

  // Inject Mobile Bottom Navigation for Admin Portal
  const isAdminPortal = window.location.pathname.includes('/admin/');
  if (isAdminPortal && !document.querySelector('.mobile-bottom-bar')) {
    const currentPath = window.location.pathname;
    const isDashboard = currentPath.includes('dashboard.html');
    const isOrders = currentPath.includes('orders.html');
    const isWalletReq = currentPath.includes('wallet-requests.html');
    const isServices = currentPath.includes('services.html');
    const isSettings = currentPath.includes('settings.html');

    const bottomBar = document.createElement('nav');
    bottomBar.className = 'mobile-bottom-bar admin-bottom-bar';
    bottomBar.innerHTML = `
      <a href="dashboard.html" class="bottom-nav-item ${isDashboard ? 'active' : ''}">
        <i class="fa-solid fa-gauge-high"></i>
        <span>Admin</span>
      </a>
      <a href="orders.html" class="bottom-nav-item ${isOrders ? 'active' : ''}">
        <i class="fa-solid fa-list-check"></i>
        <span>Orders</span>
      </a>
      <a href="wallet-requests.html" class="bottom-nav-item ${isWalletReq ? 'active' : ''}">
        <i class="fa-solid fa-money-bill-transfer"></i>
        <span>Requests</span>
      </a>
      <a href="services.html" class="bottom-nav-item ${isServices ? 'active' : ''}">
        <i class="fa-solid fa-gears"></i>
        <span>Services</span>
      </a>
      <a href="settings.html" class="bottom-nav-item ${isSettings ? 'active' : ''}">
        <i class="fa-solid fa-sliders"></i>
        <span>Settings</span>
      </a>
    `;
    document.body.appendChild(bottomBar);
  }
});
