# WooCommerce Order Flow Control

Erzwingt einen Einweg-Status-Flow für WooCommerce-Bestellungen — wichtig für Buchhaltungsintegrität (z.B. DATEV-Exporte).

> **Sobald eine Bestellung einen konfigurierten Status erreicht (z.B. "In Bearbeitung"), kann sie nur noch vorwärts bewegt werden. Rückwärts-Änderungen werden blockiert. Gutschriften bleiben jederzeit möglich.**

## Features

- Konfigurierbarer Einweg-Status-Flow über eine visuelle Matrix
- Blockierung auf 3 Ebenen: Admin UI, programmatisch, REST API
- Bulk-Action-Schutz bei Massenänderungen
- Enable/Disable Toggle ohne Plugin-Deaktivierung
- HPOS (High-Performance Order Storage) kompatibel
- Erweiterbar über Filter und Actions
- Keine Abhängigkeiten außer WooCommerce

## Voraussetzungen

- WordPress 6.0+
- WooCommerce 8.0+
- PHP 7.4+

## Installation

1. Plugin als ZIP herunterladen
2. WordPress Admin > Plugins > Installieren > Plugin hochladen
3. Plugin aktivieren
4. WooCommerce > Einstellungen > Order Control

## Konfiguration

### Status Transition Matrix

Unter **WooCommerce > Einstellungen > Order Control** findest du die Transition-Matrix:

- Jede Zeile repräsentiert einen "Von"-Status
- Jede Spalte einen "Nach"-Status
- Häkchen = Übergang erlaubt
- Zeilen ohne Häkchen = keine Einschränkung (unrestricted)

### Standard-Konfiguration

| Von | Erlaubte Übergänge |
|---|---|
| In Bearbeitung | Wartet auf Freigabe, In Produktion, Fertig und versandt, Rückerstattet |
| Wartet auf Freigabe | In Produktion, Fertig und versandt, Rückerstattet |
| In Produktion | Fertig und versandt, Rückerstattet |
| Fertig und versandt | Rückerstattet |
| Rückerstattet | — (Sackgasse) |

## Schutzebenen

| Ebene | Mechanismus |
|---|---|
| **Admin UI** | Status-Dropdown zeigt nur erlaubte Übergänge |
| **Programmatisch** | Ungültige Änderungen werden automatisch revertiert |
| **REST API** | HTTP 403 bei ungültigen Transitions |
| **Bulk Actions** | Ungültige Orders werden übersprungen |

## Erweiterbarkeit

### Filter

```php
// Transition-Rules zur Laufzeit modifizieren
add_filter( 'wofc_allowed_transitions', function( $rules ) {
    $rules['on-hold'] = [ 'processing', 'cancelled' ];
    return $rules;
});

// Einzelne Transition überschreiben
add_filter( 'wofc_is_transition_allowed', function( $allowed, $from, $to, $order_id ) {
    if ( current_user_can( 'manage_options' ) ) return true; // Admins dürfen alles
    return $allowed;
}, 10, 4 );
```

### Actions

```php
// Reagieren wenn eine Transition blockiert wird
add_action( 'wofc_transition_blocked', function( $order_id, $from, $to ) {
    error_log( "Order $order_id: $from → $to blocked" );
}, 10, 3 );
```

## Mitmachen

Contributions sind willkommen! Siehe [CONTRIBUTING.md](CONTRIBUTING.md) für Details.

## Autor & Copyright

Entwickelt von Abidin Alkilinc für [badspiegel.de](https://www.badspiegel.de) — Badspiegel und Spiegelschränke nach Maß.

Copyright (c) 2026 Abidin Alkilinc. Alle Rechte vorbehalten.

## Lizenz

Dieses Plugin ist unter der GPL-2.0+ Lizenz veröffentlicht. Du darfst es frei verwenden und einsetzen. Wenn du Verbesserungen vornimmst, trage sie bitte per Pull Request zum Original-Projekt bei, anstatt einen eigenen Fork zu pflegen — so profitiert die gesamte Community. Siehe [LICENSE](LICENSE) für Details.
