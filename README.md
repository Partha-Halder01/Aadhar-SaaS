# 🇮🇳 Utkal Print Portal

> **Next-Gen Citizen Print & Digital PAN Recovery SaaS Platform**  
> High-definition Aadhaar PVC Card formatting, instant lost PAN recovery by Aadhaar number, Voter ID prints, and seamless zero-delay UPI wallet checkout built for retailers and cyber cafes.

---

## 📑 Table of Contents

- [Overview](#-overview)
- [Key Features](#-key-features)
  - [User Portal](#-user-portal)
  - [Admin Console](#-admin-console)
  - [UI & UX Innovations](#-ui--ux-innovations)
- [Tech Stack](#-tech-stack)
- [Project Architecture](#-project-architecture)
- [Quick Start & Installation](#-quick-start--installation)
  - [Prerequisites](#prerequisites)
  - [Backend Setup](#1-backend-setup)
  - [Frontend Setup](#2-frontend-setup)
  - [Default Test Credentials](#3-default-test-credentials)
- [API Endpoints Overview](#-api-endpoints-overview)
- [Performance & Caching Strategy](#-performance--caching-strategy)
- [Directory Structure](#-directory-structure)
- [License](#-license)

---

## 🌟 Overview

**Utkal Print Portal** is a production-ready, full-stack web application designed for cyber cafe operators, digital service retailers, and citizens across India. It streamlines Aadhaar PVC printing, document formatting, instant lost PAN number lookups, and prepaid wallet recharges through automated UPI QR codes.

---

## 🚀 Key Features

### 👤 User Portal
- **Zero-Lag Dashboard**: Instant 0ms hydration of orders, completed counts, pending tasks, and live wallet balance via local session caching.
- **Digital Print Services**: Order HD 300 DPI Aadhaar PVC cards, Voter ID cards, and official citizen prints with document upload.
- **Instant PAN Recovery**: Submit Aadhaar number to retrieve and download lost PAN card numbers and records.
- **Prepaid UPI Wallet**:
  - Dynamic QR code generation matching the configured admin UPI ID (`upi://pay?pa=...`).
  - Quick top-up preset chips (₹50, ₹100, ₹200, ₹500, ₹1,000, ₹2,000).
  - UTR/Transaction Reference submission with real-time status tracking (Approved, Pending, Rejected).
- **Document Locker**: Instant preview and download for all completed delivery files and print-ready PDFs.
- **Help Desk & Complaints**: Raise support tickets with file attachments and receive real-time admin resolution replies.
- **Profile & Security**: Update retailer details, phone numbers, and securely update passwords.

### 🛡️ Admin Console
- **Platform Analytics**: High-level telemetry covering total platform revenue, pending recharge requests, active print orders, and total registered retailers.
- **Order Lifecycle Management**: Inspect order submissions, customer attachments, update statuses (Pending, Processing, Completed, Rejected), and upload final delivery files (PDF/PNG).
- **Wallet Top-Up Approvals**: Review pending customer UPI recharge proofs, verify UTR reference numbers, and approve with automatic atomic wallet balance credit.
- **Service Catalog Configurator**: Create, edit, toggle, or re-price print and search services with custom fee structures.
- **Customer Management**: View registered users, view individual wallet balances, and manage account statuses.
- **Support Resolution Hub**: Answer user inquiries and resolve service-related tickets.
- **System Settings**: Configure administrative UPI ID, UPI display name, custom QR code image upload, and public Notice Board announcements.

### 🎨 UI & UX Innovations
- **Two-Tone Modern Aesthetic**: High-contrast midnight slate (`#0b1120`) sidebar paired with an ultra-clean, frosted-glass topbar and vibrant Tiranga accents (`#ff6b00` saffron & `#10b981` green).
- **Universal Mini-Rail Mode ("Arouse" Toggle)**: One-click collapse button in the top-right of the navigation bar that compresses the menu into a 78px icon-only rail (or 74px on mobile) with smooth 60fps spring transitions.
- **Custom Floating Glass Tooltips**: Custom CSS tooltips with frosted glass blur and arrow pointers in collapsed mode, replacing clumsy native browser popups.
- **Hardware-Accelerated Micro-Interactions**: Smooth `transform: translateZ(0)` GPU-accelerated hover glides and subtle tactile button compression (`scale(0.98)`).
- **100% Mobile Responsive**: Dedicated slide-out drawer, mobile backdrop overlay, and bottom navigation bar for on-the-go mobile devices.

---

## 💻 Tech Stack

| Layer | Technology | Description |
|---|---|---|
| **Frontend** | Vanilla JavaScript (ES6+), HTML5, CSS3 | Zero-dependency, lightweight, ultra-fast client layer |
| **Icons & Typography** | FontAwesome 6, Plus Jakarta Sans, Outfit | Modern, readable, high-DPI typography |
| **Backend Framework** | Laravel 11.x (PHP 8.2+) | Modular MVC REST API architecture |
| **Database** | SQLite / MySQL | Relational data persistence with foreign keys & transactions |
| **Authentication** | Token-Based API Authentication | High-performance SHA-256 token hashing with role-based middleware |
| **Caching** | Client Memory Cache + Laravel Application Cache | Stale-While-Revalidate client caching + server-side config/route caching |

---

## 🏗️ Project Architecture

```
Utkal Print Portal/
├── backend/                  # Laravel 11 REST API Backend
│   ├── app/
│   │   ├── Http/
│   │   │   ├── Controllers/Api/   # Auth, Orders, Wallet, Services, Complaints
│   │   │   │   └── Admin/         # Admin management endpoints
│   │   │   └── Middleware/        # AuthenticateApiToken, RoleMiddleware
│   │   └── Models/                # User, ServiceOrder, WalletTransaction, etc.
│   ├── database/migrations/       # Database schemas & seeders
│   └── routes/api.php             # Protected & Public REST routes
│
├── frontend/                 # Client-side SPA / Multi-page Application
│   ├── admin/                # Admin views (dashboard, orders, wallet-requests, etc.)
│   ├── user/                 # Retailer views (dashboard, print-list, pan-find, wallet, etc.)
│   ├── css/style.css         # Unified design system & responsive layout
│   ├── js/api.js             # API client, HTTP interceptors & SWR caching
│   ├── js/auth.js            # Auth guard, sidebar collapse & mobile controllers
│   ├── index.html            # Landing page
│   ├── login.html            # User login page
│   ├── admin-login.html      # Administrator login console
│   └── register.html         # User onboarding
```

---

## ⚡ Quick Start & Installation

### Prerequisites
- **PHP** >= 8.2
- **Composer**
- **Web Server** (Built-in PHP server, Apache, or Nginx)

---

### 1. Backend Setup

```bash
# Navigate to the backend directory
cd backend

# Install PHP dependencies
composer install

# Copy environment file
cp .env.example .env

# Generate application encryption key
php artisan key:generate

# Run database migrations and seed default data
php artisan migrate --seed

# Start the Laravel backend API server (runs on port 8000)
php artisan serve --host=127.0.0.1 --port=8000
```

---

### 2. Frontend Setup

In a new terminal window, serve the `frontend` directory using PHP's built-in web server:

```bash
# From the project root directory
php -S 127.0.0.1:3000 -t frontend
```

Now open **[http://127.0.0.1:3000](http://127.0.0.1:3000)** in your web browser.

---

### 3. Default Test Credentials

| Role | Email | Password | Access URL |
|---|---|---|---|
Email ID	admin@utkalprint.com
Registered Mobile	9876543210 (can also be used as Identifier)
Password	Admin@123
| **Retailer / User** | `retailer@utkalprint.com` | `password123` | `http://127.0.0.1:3000/login.html` |

*(You can also register a new user account directly via `register.html`)*

---

## 📡 API Endpoints Overview

### Public Endpoints
- `POST /api/register` - New user registration
- `POST /api/login` - User & admin authentication (returns Bearer token)
- `GET /api/settings/public` - Public platform settings (UPI ID, notice board, QR code)

### Protected User Endpoints (`auth:api`)
- `GET  /api/profile` - Current user profile & live wallet balance
- `PUT  /api/profile` - Update profile & password
- `GET  /api/services` - List active print and PAN recovery services
- `GET  /api/orders` - Filter user orders by category/status
- `POST /api/orders` - Submit a new service order (Wallet or Direct UPI)
- `GET  /api/wallet` - User wallet balance and transaction statement
- `POST /api/wallet/topup` - Submit wallet top-up request with UTR proof
- `GET  /api/documents` - Download completed customer delivery files
- `GET  /api/complaints` - List user support tickets
- `POST /api/complaints` - Create a new support ticket

### Protected Admin Endpoints (`auth:api` + `role:admin`)
- `GET  /api/admin/dashboard` - Platform revenue, pending order count, active users
- `GET  /api/admin/orders` - Master order list with filtering
- `PUT  /api/admin/orders/{id}/status` - Approve, reject, or deliver orders
- `GET  /api/admin/wallet-requests` - Pending UPI top-up requests
- `POST /api/admin/wallet-requests/{id}/action` - Approve (auto-credit) or reject top-up
- `GET  /api/admin/services` - Manage service catalog & fees
- `GET  /api/admin/users` - User directory & wallet management
- `GET  /api/admin/complaints` - Support tickets requiring admin attention
- `PUT  /api/admin/settings` - Update platform UPI ID, QR code image, and notice board

---

## ⚡ Performance & Caching Strategy

The frontend implements a **Stale-While-Revalidate (SWR)** caching layer inside `api.js`:
- **Instant Local Render (0ms)**: Reads directly from local cache on page load so UI components render without any visual layout shift or spinning loaders.
- **Non-Blocking Background Fetch**: Silently contacts the API in the background and re-renders if fresh data has changed.
- **Cache Invalidation on Mutation**: Creating an order or requesting a wallet top-up automatically purges cached keys (`orders`, `wallet_txs`, `documents`), ensuring users always see live data immediately after performing an action.

---

## 📁 Directory Structure

```
├── backend/
│   ├── app/
│   │   ├── Http/Controllers/Api/
│   │   │   ├── Admin/
│   │   │   │   ├── AdminComplaintController.php
│   │   │   │   ├── AdminDashboardController.php
│   │   │   │   ├── AdminOrderController.php
│   │   │   │   ├── AdminServiceController.php
│   │   │   │   ├── AdminSettingController.php
│   │   │   │   ├── AdminUserController.php
│   │   │   │   └── AdminWalletController.php
│   │   │   ├── AuthController.php
│   │   │   ├── ComplaintController.php
│   │   │   ├── DocumentController.php
│   │   │   ├── OrderController.php
│   │   │   ├── ServiceController.php
│   │   │   ├── SettingController.php
│   │   │   └── WalletController.php
│   │   └── Middleware/
│   │       ├── AuthenticateApiToken.php
│   │       └── RoleMiddleware.php
│   └── database/
│       ├── migrations/
│       └── seeders/
├── frontend/
│   ├── admin/
│   │   ├── complaints.html
│   │   ├── dashboard.html
│   │   ├── orders.html
│   │   ├── services.html
│   │   ├── settings.html
│   │   ├── users.html
│   │   └── wallet-requests.html
│   ├── user/
│   │   ├── complaint.html
│   │   ├── dashboard.html
│   │   ├── documents.html
│   │   ├── pan-find.html
│   │   ├── print-list.html
│   │   ├── profile.html
│   │   └── wallet.html
│   ├── css/
│   │   └── style.css
│   ├── js/
│   │   ├── api.js
│   │   └── auth.js
│   ├── index.html
│   ├── login.html
│   └── register.html
└── README.md
```

---

## 📄 License

This project is open-sourced under the **MIT License**.
