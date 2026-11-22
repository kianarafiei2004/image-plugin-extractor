# Image Plugin Extractor for WooCommerce & WordPress

[![Version](https://img.shields.io/badge/version-1.0.1-blue.svg)](https://github.com/kianarafiei2004/image-plugin-extractor/releases)
[![WordPress](https://img.shields.io/badge/WordPress-5.6%2B-21759b.svg)](https://wordpress.org)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-5.0%2B-96588a.svg)](https://woocommerce.com)
[![PHP](https://img.shields.io/badge/PHP-7.4%20--%208.3%2B-777bb4.svg)](https://php.net)
[![License](https://img.shields.io/badge/license-GPLv2-green.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
[![Author](https://img.shields.io/badge/author-kiana-orange.svg)](https://github.com/kianarafiei2004)

An enterprise-grade, lightweight, and high-performance WordPress & WooCommerce plugin for automated catalog extraction, high-resolution media management, and intelligent internal linking SEO audits with Persian and Arabic NLP word-boundary matching.

---

## 🚀 Key Features

- **Asynchronous AJAX Batch Processing:**
  Audits 1,000+ products in chunked batches of 25 items (`IPE_BATCH_SIZE = 25`). Guarantees zero execution timeouts (`max_execution_time`) and maintains memory consumption under 32 MB even on shared hosting environments.

- **Advanced Persian & Arabic NLP Normalization:**
  Automatically unifies letter variants (`ي` → `ی`, `ك` → `ک`), strips diacritics/tanween/tashkeel, purges invisible zero-width non-joiners (ZWNJ `\u200c`), and cleans typographical variations between articles and product titles.

- **Unicode Word Boundary Lock:**
  Uses strict Unicode lookbehind/lookahead regular expressions (`(?<![\p{L}\p{N}])TERM(?![\p{L}\p{N}])/u`) to prevent false-positive partial matches (e.g., matching a short keyword inside a longer composite word).

- **Context Snippet Extraction (60 Chars):**
  Extracts 60 characters before and after the matched keyword in blog post bodies, rendering an inline context preview so copywriters and SEO specialists can instantly locate sentences in the editor.

- **Active Link Anchor Text Extraction:**
  Scans existing links pointing to product URLs and extracts the exact anchor text used, reporting both active internal links and unlinked opportunities.

- **WooCommerce Product Tags as Secondary Synonyms:**
  Automatically falls back to `product_tag` terms if the full product name is not found in an article, maximizing internal link equity discovery.

- **Safe 1-Click Direct Gutenberg Linking (No-Auto-Linker Policy):**
  Avoids risky automated database alterations and regex replacements that break Gutenberg block comments (`<!-- wp:... -->`). Instead, provides a direct 1-click edit button (`admin_url('post.php?post=X&action=edit')`) for safe human review.

- **Dual-Layer Transient Caching:**
  - **1-Hour Blog Post Index Transient (`IPE_TEMP_POSTS_TRANSIENT`):** Eliminates redundant post queries across chunk cycles.
  - **7-Day Audit Results Transient (`IPE_RESULTS_TRANSIENT`):** Enables sub-second dashboard reload times without re-scanning.

- **Direct High-Resolution Image Downloads:**
  One-click full-resolution image downloads directly from the data table without entering the WordPress Media Library.

- **Smooth 4.2x Hover Zoom:**
  Pure CSS hardware-accelerated 4.2x zoom on product thumbnails for rapid visual asset verification.

- **Export Standards (Excel CSV & JSON):**
  - Instant streaming CSV with UTF-8 Byte Order Mark (`\xEF\xBB\xBF`) for clean rendering in Microsoft Excel without character corruption.
  - Complete structured JSON export for LLM and automated AI pipelines.

- **Nordic Teal & Crimson UI Design System:**
  Built with isolated CSS scoping (`.ipe-dashboard-wrap`): Light Cream canvas (`#EAEAE8`), Deep Pine Teal cards (`#0E2931`), Muted Teal accents (`#2B7574`), and Crimson Red status indicators (`#861211`).

---

## 📁 Repository Structure

```text
image-plugin-extractor/
├── image-plugin-extractor.php       # Core OOP plugin (Singleton Bootstrap, Scanner, Admin & Exporter)
├── README.md                        # Official repository documentation & specification
├── PLUGIN-SPECIFICATION.md          # Technical architecture dossier & engineering specifications
├── readme.txt                       # Standard WordPress.org repository documentation
└── preview-dashboard.html           # Standalone interactive browser preview dashboard
```

---

## 💻 Installation & Usage

### 1. WordPress Installation
1. Download `image-plugin-extractor.zip` from the [Latest Release](https://github.com/kianarafiei2004/image-plugin-extractor/releases/latest).
2. In your WordPress admin panel, navigate to **Plugins > Add New > Upload Plugin**.
3. Choose the ZIP file and click **Install Now**, then click **Activate Plugin**.
4. Access the dashboard from the admin sidebar under **Image & SEO Extractor**.

### 2. Running an Audit
1. Click **Start Batch Scan**.
2. The asynchronous batch scanner will iterate through the WooCommerce catalog in 25-item chunks, displaying live progress.
3. Switch between tabs: **All Products**, **Only SEO Opportunities**, and **Active Links**.
4. Inspect the context snippet and click **Edit in Gutenberg** to open the post and insert internal links safely.

### 3. Exporting Reports & Assets
- Click **Excel (CSV)** to download the BOM-encoded spreadsheet.
- Click **JSON Archive** for structured data integration.
- Click the download icon next to any thumbnail to save the original high-resolution image.

---

## ⚙️ Technical Specifications & Server Protections

| Architecture Component | Implementation Details | Operational Server Impact |
| :--- | :--- | :--- |
| **Batch Processing** | `IPE_BATCH_SIZE = 25` | Prevents PHP timeout (`max_execution_time`) |
| **Object Cache Guard** | `wp_suspend_cache_addition(true)` | Prevents Redis/Memcached RAM exhaustion during bulk scans |
| **Garbage Collection** | `unset()` & `gc_collect_cycles()` | Keeps memory usage under 32 MB across thousands of products |
| **Role & Capability Check**| `current_user_can('manage_options')` | Restricts access exclusively to site administrators |
| **Request Security** | `check_ajax_referer` / WP Nonce | Prevents Cross-Site Request Forgery (CSRF) |

---

## 📝 Changelog

### v1.0.1 (Current Release)
- Completely fixed Status column layout (`.ipe-status-th`, `.ipe-status-cell`) with vector icons and text wrap prevention.
- Added 60-character context snippet extraction (`extract_snippet`).
- Added anchor text detection for active internal links.
- Added direct 1-click full-resolution image downloads.
- Enhanced pure-CSS thumbnail hover zoom to 4.2x.
- Registered author meta as `kiana` with GitHub repository integration.

### v1.0.0
- Initial production release featuring Singleton architecture, 25-item AJAX chunking, Gutenberg direct edit integration, and dual-layer transient caching.

---

## 👤 Author & Maintainer

Developed and maintained by **kiana** — [GitHub Profile](https://github.com/kianarafiei2004)

## 📄 License

This project is open-source and licensed under the **GNU General Public License v2 or later** (GPLv2+).
