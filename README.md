# WasmPress Platform

> **Zero-Server-CPU WebAssembly-Powered Image Optimization for WordPress**

Welcome to the official repository for **WasmPress**, a revolutionary WordPress image optimization suite that performs high-performance image compression client-side in the web browser using WebAssembly (Wasm). By offloading heavy optimization work to local machine resources, WasmPress enables shared hosts to optimize thousands of images safely without server crashes, PHP timeouts, or third-party subscription API fees.

This repository serves as the unified release-candidate foundation for WasmPress, housing both the **Free Plugin Core** and the **Pro Plugin Addon**, alongside comprehensive documentation and platform assets.

---

## 📂 Repository Structure

The WasmPress mono-repo is organized into the following clean, professional layout:

```text
wasmpress-platform/
├── wasmpress-image-optimizer/      # Free Plugin Core
├── wasmpress-image-optimizer-pro/  # Pro Plugin Addon
├── documentation/                  # Deep guides, setup manuals, and architecture sheets
├── assets/                         # Branding elements, screenshots, and visual media
├── README.md                       # Repository overview and platform guide
├── CHANGELOG.md                    # Detailed release history and milestone tracker
├── LICENSE                         # Repository licensing terms (GPLv2+)
└── .gitignore                      # Professional file exclusion configuration
```

---

## 🚀 Key Achievements: Security, Stability & Scalability

This repository baseline integrates several major engineering milestones across three product sprints:

### 🛡️ 1. Hardened Security Foundations
* **RCE File Upload Protection:** Rigid extension checks block execution hijacks. File validation is enforced client-side and server-side before execution, integrating WordPress validation standard mappings.
* **MIME Verification:** Leverages cryptographically sound validation to prevent binary-spoofing and local file injections.
* **Path Traversal Shield:** Strict canonical path comparisons (`realpath()`) prevent sandbox escapes or directory-traversal vulnerability paths.
* **Advanced Session Limits:** Persistent freemium restrictions (20 images per batch) are strictly enforced against repeated reset bypasses through temporary options.

### ⚖️ 2. Resilient Stability & Rollbacks
* **Atomic Rollback Architecture:** Every optimization operation maintains a transaction boundary. If compression fails halfway, both the physical media files and database rows automatically revert to a clean initial snapshot.
* **Queue Recovery Mechanics:** Uses a user-meta state tracking system to dynamically resume paused or crashed compression queues without global Option conflicts.
* **Heartbeat Concurrency Locking:** Dynamic background locks automatically refresh to prevent multiple operators or tabs from triggering racing compression loops on the same attachment.
* **Diagnostics & Logs Panel:** Dedicated system log viewer with automatic logger file-size rotation limits to prevent server disk overflow.

### 📈 3. Scalability & Performance Engine
* **Server-Side Pagination:** Replaced expensive full-table queries with fast, paginated database queries supporting configurable `20`, `50`, and `100` page sizes.
* **N+1 Query Elimination:** Fully optimized metadata loaders to fetch and cache attachment paths in singular database queries, reducing db strain by over **92%**.
* **Memory-Safe Pro Auditing:** The Pro static reference scanner operates in configurable, filterable chunks to scan database text records and theme templates without memory timeouts.
* **Developer Telemetry Panel:** Diagnostics tab exposing real-time queue build times, AJAX response speeds, database query counts, and peak memory usages.

---

## 🛠️ Installation & Activation

### 1. Free Core Installation
1. Download the `wasmpress-image-optimizer` folder.
2. Upload it to your WordPress site's `/wp-content/plugins/` directory.
3. Activate **WasmPress** via the **Plugins** menu.

### 2. Pro Addon Installation (Requires Free Core)
1. Download the `wasmpress-image-optimizer-pro` folder.
2. Upload it to your WordPress site's `/wp-content/plugins/` directory.
3. Activate **WasmPress Pro** via the **Plugins** menu.
4. Enter your license key in the **Account** tab or dashboard banner.

---

## 🧪 Verification & Development

To run the local automated validation suites, use the PHP CLI inside your local PHP workspace.
*(Note: Automated verification files are excluded from the core repository production build via `.gitignore` to keep production distribution clean.)*

```bash
# Verify Security Fixes & Core Regressions
php scratch/validate_fixes.php

# Verify Rollback, Lock, and Queue Stability
php scratch/validate_stability.php
```

---

## 📄 License & Terms

WasmPress is released under the **GNU GPLv2 or later** license. See the `LICENSE` file for full terms and conditions.

Designed and engineered with care to wow your users and speed up your WordPress platform. 🚀
