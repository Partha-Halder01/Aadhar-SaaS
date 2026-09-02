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
  const isAuthPage = currentPath.includes('login.html') || currentPath.includes('register.html');
  const isUserPage = currentPath.includes('/user/');
  const isAdminPage = currentPath.includes('/admin/');

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
      window.location.replace(getAppPath('login.html'));
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
    document.querySelectorAll('.user-name-display').forEach(el => el.textContent = user.name || 'Member');
    document.querySelectorAll('.user-email-display').forEach(el => el.textContent = user.email || '');
    document.querySelectorAll('.user-phone-display').forEach(el => el.textContent = user.phone || '');
    document.querySelectorAll('.user-wallet-display').forEach(el => el.textContent = formatINR(user.wallet_balance || 0));

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

    // Set tooltip titles on all sidebar links for icon-only mode
    portalSidebar.querySelectorAll('.sidebar-link').forEach(link => {
      const label = link.querySelector('span');
      if (label && !link.getAttribute('title')) {
        link.setAttribute('title', label.textContent.trim());
      }
    });

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

    // Clicking anywhere on the collapsed bar header expands it back out
    portalSidebar.addEventListener('click', (e) => {
      if (portalSidebar.classList.contains('collapsed') && !e.target.closest('.sidebar-link')) {
        portalSidebar.classList.remove('collapsed');
        localStorage.setItem('utkal_sidebar_collapsed', 'false');
        if (collapseBtn) {
          collapseBtn.title = 'Collapse Navigation Bar';
        }
      }
    });

    // Mobile Sidebar & Backdrop Controller
    // Inject mobile backdrop if not present
    let backdrop = document.querySelector('.portal-sidebar-backdrop');
    if (!backdrop) {
      backdrop = document.createElement('div');
      backdrop.className = 'portal-sidebar-backdrop';
      document.body.appendChild(backdrop);
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

    // Close sidebar when clicking any sidebar link on mobile
    portalSidebar.querySelectorAll('.sidebar-link').forEach(link => {
      link.addEventListener('click', () => {
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
    const isPrint = currentPath.includes('print-list.html');
    const isPan = currentPath.includes('pan-find.html');
    const isWallet = currentPath.includes('wallet.html');
    const isProfile = currentPath.includes('profile.html');

    const bottomBar = document.createElement('nav');
    bottomBar.className = 'mobile-bottom-bar';
    bottomBar.innerHTML = `
      <a href="dashboard.html" class="bottom-nav-item ${isDashboard ? 'active' : ''}">
        <i class="fa-solid fa-house"></i>
        <span>Home</span>
      </a>
      <a href="print-list.html" class="bottom-nav-item ${isPrint ? 'active' : ''}">
        <i class="fa-solid fa-id-card"></i>
        <span>Print</span>
      </a>
      <a href="pan-find.html" class="bottom-nav-item ${isPan ? 'active' : ''}">
        <i class="fa-solid fa-magnifying-glass-location"></i>
        <span>PAN Find</span>
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
});
