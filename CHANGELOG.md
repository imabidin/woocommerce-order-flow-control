# Changelog

## [3.0.0] - 2026-04-18

### Changed
- **Prevention instead of revert**: Switched from `woocommerce_order_status_changed` (post-change revert) to `woocommerce_before_order_object_save` (pre-save prevention). Blocked transitions no longer trigger emails, stock adjustments, or timestamp changes.
- **Settings save hardening**: Added explicit nonce verification, sanitized all POST keys with `sanitize_key()`, removed `phpcs:ignore` comments
- **REST detection**: Replaced `defined('REST_REQUEST')` with `wp_is_serving_rest_request()` / `wp_is_json_request()`
- **Asset loading**: Moved inline CSS/JS from settings page to external files (`assets/admin/settings.css`, `assets/admin/settings.js`) with proper `wp_enqueue_style`/`wp_enqueue_script`
- **Option autoload**: Transition rules option set to `autoload=false` (only loaded when needed)

### Added
- **Audit logging**: Every blocked transition is logged via `wc_get_logger()` (source: `wofc`) with order ID, from/to status, user ID, and request context
- **Request context detection**: `detect_context()` helper classifies requests as cli, cron, ajax, rest, admin, or frontend
- **Real test suite**: PHPUnit + Brain Monkey tests that exercise the actual `WOFC_Transition_Manager` class (replaces standalone pseudo-tests)
- **WordPress.org readme**: Added `readme.txt` in standard WordPress.org format

### Removed
- `enforce_transition()` method and `$reverting` guard flag (replaced by `prevent_transition()`)
- `woocommerce_order_status_changed` hook registration (replaced by `woocommerce_before_order_object_save`)
- Standalone test file `tests/test-transitions.php`

### Technical
- New methods: `prevent_transition()`, `get_original_status()`, `clear_status_transition()`, `detect_context()`
- Uses `Closure::bind` to read original status from `WC_Data::$data` and clear `WC_Order::$status_transition`
- REST API enforcement also logs blocked attempts via `wc_get_logger()`

## [2.1.0] - 2026-04-17

### Added
- **Rollback Lock column**: Explicit per-status checkbox in the transition matrix to mark a status as locked
- **Dead-end support**: A locked status with no checked targets is now a dead end (e.g. Refunded) — previously lost on save
- **Live row highlighting**: Toggling the Rollback Lock checkbox instantly updates the yellow row indicator via JS

### Fixed
- **Critical save bug**: Saving the settings page with no target checkboxes checked would silently unlock the status, making it unrestricted instead of a dead end. The new Rollback Lock checkbox decouples locking from target selection.

### Changed
- Save logic uses `wofc_lock[]` flags instead of inferring locked state from `wofc_matrix[]` presence
- Removed hidden `wofc_locked_statuses[]` inputs — no longer needed
- Updated description texts to explain Rollback Lock behavior

## [2.0.0] - 2026-04-16

### Added
- **WooCommerce Settings Tab**: Neuer Tab "Order Control" unter WooCommerce > Einstellungen mit konfigurierbarer Transition-Matrix
- **Enable/Disable Toggle**: Flow Control kann ueber die Settings aktiviert/deaktiviert werden
- **Transition Matrix UI**: Visuelle Tabelle zum Konfigurieren erlaubter Status-Uebergaenge — Zeilen hinzufuegen/entfernen per Klick
- **Bulk-Action-Schutz**: Ungueltige Status-Aenderungen bei Bulk-Aktionen werden pro Bestellung geprueft und uebersprungen
- **Admin Notices**: Klare Fehlermeldungen bei blockierten Transitions (Einzel- und Bulk-Aktionen)
- **Plugin Action Link**: Direkter "Settings" Link auf der Plugins-Seite
- **Extensibility Hooks**: `wofc_allowed_transitions`, `wofc_is_transition_allowed`, `wofc_transition_blocked`, `wofc_transitions_updated`
- **REST API Schutz**: Ungueltige Transitions via REST API werden mit HTTP 403 abgelehnt
- **Uninstall Cleanup**: Saubere Entfernung aller Plugin-Optionen bei Deinstallation
- **i18n Support**: Alle Strings uebersetzbar (Text Domain: `wofc`)

### Changed
- **OOP Architektur**: Kompletter Umbau von prozeduralem Code zu Klassen-basierter Struktur
- **Konfigurierbare Rules**: Transition-Regeln werden in `wp_options` gespeichert statt hardcoded
- **HPOS Kompatibilitaet**: Offiziell deklariert via `FeaturesUtil::declare_compatibility()`

### Technical
- Neue Klasse `WOFC_Transition_Manager` — zentrale Logik mit Static Cache
- Neue Klasse `WOFC_Settings` (extends `WC_Settings_Page`) — nativer WooCommerce Settings Tab
- Neue Klasse `WOFC_Admin` — Admin UI, Notices, Bulk-Action-Handling
- Guard-Flag `$reverting` verhindert Endlos-Schleifen bei Status-Revert
- 72 Unit-Tests fuer Transitions-Logik bestanden

## [1.0.0] - 2026-04-16

### Added
- **Initiale Version**: Einweg-Status-Flow ab "In Bearbeitung" (processing)
- **Programmatische Blockierung**: Ungueltige Status-Aenderungen werden automatisch revertiert
- **REST API Schutz**: Blockierung auch ueber die WooCommerce REST API
- **Admin Dropdown Filter**: Status-Dropdown zeigt nur erlaubte Uebergaenge

### Default Flow
- `processing` → ready-production, in-production, completed, refunded
- `ready-production` → in-production, completed, refunded
- `in-production` → completed, refunded
- `completed` → refunded
- `refunded` → (Sackgasse)

---

**Semantic Versioning**: MAJOR.MINOR.PATCH
