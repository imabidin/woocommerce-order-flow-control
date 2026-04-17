=== WooCommerce Order Flow Control ===
Contributors: imabidin
Tags: woocommerce, order, status, workflow, accounting
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
WC requires at least: 8.0
WC tested up to: 9.6
Stable tag: 3.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Enforces one-way order status transitions for WooCommerce. Protects accounting integrity by preventing backward status changes.

== Description ==

**WooCommerce Order Flow Control** ensures that once an order reaches a locked status (e.g. "Processing"), it can only move forward through the configured workflow. Backward transitions are blocked at every level:

* **Admin UI** — the status dropdown only shows allowed targets.
* **Programmatic** — `$order->set_status()` changes are intercepted before the database write.
* **REST API** — invalid transitions return HTTP 403.
* **Bulk Actions** — invalid orders are skipped with a clear admin notice.

= Key Features =

* Visual transition matrix in WooCommerce Settings
* Rollback Lock per status — mark which statuses are restricted
* Dead-end support (e.g. "Refunded" can't go anywhere)
* Extensible via filters (`wofc_allowed_transitions`, `wofc_is_transition_allowed`)
* Audit logging via WooCommerce logger (source: `wofc`)
* HPOS (High-Performance Order Storage) compatible

= Default Flow =

* Processing → Ready for Production, In Production, Completed, Refunded
* Ready for Production → In Production, Completed, Refunded
* In Production → Completed, Refunded
* Completed → Refunded
* Refunded → (dead end)

Unlocked statuses (e.g. Pending, On Hold) remain unrestricted.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/woocommerce-order-flow-control/`
2. Activate via the Plugins screen
3. Go to WooCommerce → Settings → Order Control to configure rules

== Frequently Asked Questions ==

= Can I customize which statuses are locked? =

Yes. The transition matrix under WooCommerce → Settings → Order Control lets you configure Rollback Lock per status and choose which forward transitions are allowed.

= Does this work with custom order statuses? =

Yes. Any registered WooCommerce order status appears in the matrix automatically.

= Can an admin bypass the rules? =

An admin with `manage_woocommerce` can disable the plugin via the settings toggle. This is by design — the plugin is a guardrail against accidental changes, not a security vault.

= Does it support HPOS? =

Yes. The plugin declares full compatibility with WooCommerce High-Performance Order Storage.

== Changelog ==

= 3.0.0 =
* Switched enforcement from post-change revert to pre-save prevention via `woocommerce_before_order_object_save`
* Blocked transitions no longer trigger emails, stock adjustments, or timestamps
* Added audit logging via `wc_get_logger()` (source: wofc)
* Added explicit nonce verification for custom settings fields
* Sanitized all POST keys with `sanitize_key()`
* Replaced `defined('REST_REQUEST')` with `wp_is_serving_rest_request()`
* Moved inline CSS/JS to external asset files with proper enqueue
* Set `autoload=false` for transition rules option
* Added real PHPUnit test suite with Brain Monkey (replaces standalone tests)
* Added `readme.txt` for WordPress.org compatibility

= 2.1.0 =
* Added Rollback Lock column in transition matrix
* Added dead-end support for locked statuses with no targets
* Fixed critical save bug where empty targets silently unlocked a status

= 2.0.0 =
* Complete OOP refactor
* Added WooCommerce Settings tab with visual transition matrix
* Added bulk action protection, REST API blocking, admin notices
* Added extensibility hooks and HPOS compatibility

= 1.0.0 =
* Initial release with basic one-way status flow enforcement

== Upgrade Notice ==

= 3.0.0 =
Major architectural improvement: blocked transitions now prevent side effects (emails, stock changes) instead of reverting them after the fact.
