=== Teatatu Data ===
Contributors: teatatu
Tags: master data, reference data, data governance, multisite
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Central master and reference data for the Teatatu plugins, with schemas, a data dictionary, quality checks and change history.

== Description ==

Teatatu Data is the one place shared data is loaded, described, checked and maintained for Teatatu Events, Teatatu News and future Teatatu plugins. It follows DAMA-DMBOK data management practice.

Planned data sets:

* **Neighbourhoods** and street rules
* **Venues / places** with structured NZ addresses
* **Organisations / sources**
* **Tags / categories**

Version 0.1.0 is the scaffold: roles, settings, the GitHub updater and an empty data dictionary. Data sets arrive in later releases (see SPEC.md in the repository).

* Uses **only standard WordPress tables**.
* On multisite, data is edited on one **Master Site** and read by every other site.
* A propose-only **Data Agent** role for AI agents. Stewards (Administrators and Editors) approve changes.

== Installation ==

1. Copy the `teatatu-data` folder to `wp-content/plugins/`.
2. Activate it (or **Network Activate** on multisite).
3. Settings: **Settings > Teatatu Data** (single site) or **Network Admin > Settings > Teatatu Data** (multisite) — Master Site, GitHub updates, data on uninstall.
4. Open **Teatatu Data** in the admin menu to see the data dictionary.

== Roles and capabilities ==

* **Administrator / Editor** (Data Stewards): `read_`, `propose_`, `edit_`, `approve_`, `import_` and `manage_teatatu_data`.
* **Data Agent** (`teatatu_data_agent`): `read_teatatu_data` and `propose_teatatu_data` only. It can never apply a change.

== Uninstall ==

Always removes the Data Agent role, steward capabilities and plugin settings on every site. Master data is deleted only if "Delete all Teatatu Data master data when this plugin is uninstalled" is ticked. Teatatu Events and News data is never touched.

== Changelog ==

= 0.1.0 =
* Scaffold: plugin header, roles and capabilities, Master Site setting, GitHub Releases updater, uninstall, data set registry and data dictionary screen.
