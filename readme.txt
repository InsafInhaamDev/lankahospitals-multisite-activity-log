=== Lanka Hospitals Multisite Activity Log ===
Contributors: weblankan
Tags: activity log, audit log, multisite, security, network
Requires at least: 5.6
Tested up to: 6.7
Requires PHP: 7.2
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Network-wide activity / audit log for WordPress Multisite. Records what users do across every site in one searchable place.

== Description ==

Lanka Hospitals Multisite Activity Log records important events from every site in a WordPress Multisite network into a single, shared, searchable log accessible from the Network Admin.

It also works on a standard single-site install.

**Events tracked**

* Logins, failed logins, logouts, password resets
* Posts / pages / custom post types — created, updated, published, trashed, deleted
* Media uploaded and deleted
* Users — created, updated, deleted, role changes
* Plugins activated / deactivated (per-site and network-wide)
* Plugin / theme installs and updates, and WordPress core updates
* Plugin / theme file edits via the built-in editor
* Theme switches
* Navigation menus created and updated
* Widget changes (classic and block-based)
* Terms — categories, tags and custom taxonomies created / updated / deleted
* Tracked site settings updated (site title, admin email, permalinks, etc.)
* Comments — created and status changes (approved / spam / trash)
* Content export and import runs
* REST API (Application Password) and XML-RPC authentication
* Sites added to or deleted from the network

**Features**

* Single shared log table — one place to see activity across all network sites
* Filter by site, event type, severity, date range, and free-text search
* Sortable, paginated table using the native WordPress admin UI
* Records user, role, and IP address for each event
* Configurable retention period with automatic daily purge
* Delete individual entries or clear the whole log
* Developer-friendly: `LH_AL_Logger::log()` API plus `lh_al_skip_event`, `lh_al_pre_insert`, `lh_al_tracked_options`, and `lh_al_logged` filters/actions

== Installation ==

1. Upload the `lankahospitals-multisite-activity-log` folder to `/wp-content/plugins/`.
2. **Multisite:** Network Activate the plugin from **Network Admin → Plugins**.
   **Single site:** Activate from **Plugins**.
3. Open **Activity Log** in the Network Admin (or main admin) menu.

== Frequently Asked Questions ==

= Where is the data stored? =
In a single custom table (`{base_prefix}lh_activity_log`) shared by the whole network, so you get a unified view.

= Does it work on single-site WordPress? =
Yes. On single site it stores the log in the normal table prefix and shows the menu in the regular admin.

= How do I log my own events? =
`LH_AL_Logger::log( 'my_event', array( 'object_name' => 'Thing', 'message' => 'Something happened', 'severity' => 'notice' ) );`

== Changelog ==

= 1.0.0 =
* Initial release.
