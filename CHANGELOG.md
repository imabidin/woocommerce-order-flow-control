# Changelog

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
