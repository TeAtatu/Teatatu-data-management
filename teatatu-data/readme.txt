=== Teatatu Data ===
Contributors: teatatu
Tags: master data, reference data, data governance, multisite
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.8.0
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

Version 0.6.0 has the core framework and every planned data set — **Neighbourhoods**, **Street rules**, **Venues**, **Organisations**, **Event tags**, **Event categories** and **News categories** — moved from Teatatu Events and Teatatu News. Version 0.7.0 adds **data quality**: checks, scores, and merging duplicates. Version 0.8.0 **connects Teatatu Events and Teatatu News**: Teatatu Data keeps their lists up to date on every site (see SPEC.md in the repository).

* **Data set registry** — each data set declares its schema once; the dictionary, validation, forms, import/export and REST read it.
* **Validation** — one validator for every way data arrives: types, required, unique, allowed values, patterns, ranges, references.
* **Lifecycle** — every record has a permanent UUID and a status: Proposed, Active, Deprecated (with "Replaced by") or Retired. It also has external IDs, a source and a "verified" date, and records not verified within the data set's review interval are flagged.
* **Change history** — every change logged field by field, with who, when, why and how it arrived. Applied changes can be reverted.
* **Proposed changes** — agents, contributors and imports propose; stewards approve or reject, singly or by import batch.
* **Import / export** — CSV and JSON, in the same shape both ways.
* **REST API** (`/wp-json/teatatu-data/v1/`) — signed-in users only.

* Uses **only standard WordPress tables**.
* On multisite, data is edited on one **Master Site** and read by every other site.
* A propose-only **Data Agent** role for AI agents. Stewards (Administrators and Editors) approve changes.

== Installation ==

1. Copy the `teatatu-data` folder to `wp-content/plugins/`.
2. Activate it (or **Network Activate** on multisite).
3. Settings: **Settings > Teatatu Data** (single site) or **Network Admin > Settings > Teatatu Data** (multisite) — Master Site, GitHub updates, data on uninstall.
4. Open **Teatatu Data** in the admin menu. Under **Data set settings**, name an owner and steward for each data set.

== Roles and capabilities ==

* **Administrator / Editor** (Data Stewards): `read_`, `propose_`, `edit_`, `approve_`, `import_` and `manage_teatatu_data`.
* **Data Agent** (`teatatu_data_agent`): `read_teatatu_data` and `propose_teatatu_data` only. It can never apply a change.

== The Teatatu Data screen ==

Tabs: **Dictionary** · one tab per data set (list, search, edit form, history) · **Proposed changes** · **Data quality** · **Import / Export** · **Data set settings** · **Connections**. On multisite, data is changed only on the Master Site; other sites show it read-only.

== REST API ==

Namespace `teatatu-data/v1`. Every route needs a signed-in user with `read_teatatu_data`.

* `GET /dictionary`
* `GET /datasets/<key>/records`, `GET /datasets/<key>/records/<uuid>`, `GET /datasets/<key>/records/<uuid>/history`
* `POST /datasets/<key>/records`, `PATCH /datasets/<key>/records/<uuid>` — stewards
* `POST /datasets/<key>/proposals` — agents and stewards
* `GET /proposals`, `GET /proposals/<id>`, `POST /proposals/<id>/approve`, `POST /proposals/<id>/reject`
* `GET /neighbourhoods/resolve?number=&street=&suburb=&lat=&lng=`
* `GET /venues/match?location=&address=&lat=&lng=`, `GET /venues/parse?text=`
* `GET /organisations/match?name=&website=`
* `GET /vocabularies/<event_tag|event_category|news_category>/match?values=a,b`
* `GET /quality?dataset=`

Writes must go to the Master Site's own REST URL.

== Neighbourhoods ==

Every Te Atatū Peninsula address is in exactly one of three neighbourhoods — **Matipo**, **Beach** and **Harbourview** — split along Te Atatū Road, Taikata Road and Harbour View Road.

* **Neighbourhoods** — the three records. Each has a fixed boundary key, and a slug that can be renamed (list the old one under Old slugs so existing links keep working).
* **Street rules** — 128 rules. 111 streets are wholly in one neighbourhood. Te Atatū, Taikata, Harbour View, Matipo and Wharf Roads are split by side of the road and house number, and the Te Atatū South end of Te Atatū Road is marked "not on the peninsula".
* An address is placed by the street rules, then by its coordinates against the bundled boundary lines. Otherwise a peninsula address is flagged "needs neighbourhood". No geocoding service is called.
* On first activation the data is copied from Teatatu Events, if it is installed, so edits made there carry over. Otherwise it comes from the bundled OpenStreetMap list.
* Street rules tab: **Add missing streets from the bundled list**. Neighbourhoods tab: **Test an address**.
* Rebuild the bundled list: `php tools/build-neighbourhood-streets.php` (OpenStreetMap, ODbL).

== Venues ==

Reusable places, each with a Location name and a structured New Zealand address (unit, number, street, suburb, town/city, region, postcode, country, coordinates), aliases, website, map link and a link-only image.

* The neighbourhood is worked out on every save (or set with an override), and worked out again for every venue when the street rules change.
* Matching (imports, agents, "Find a venue"): by name, other name, address, then coordinates within 50 m.
* **Bring in venues from Teatatu Events** (Venues tab) gathers every site's venues, merges duplicates, and proposes them as one batch to review.
* **Paste address** on the edit form splits a one-line address into the fields.

== Organisations ==

The groups, schools, businesses, councils and media that organise events or publish news (the "Source" in Events and News), as one shared list.

* Name, other names, type, "part of" (parent), description, website, the organisation's general email and phone (never a person's), "based at" (a venue) and a link-only default image.
* NZBN and Charities Register numbers are kept as external IDs, with their formats checked.
* Matching by name, other name or website.
* **Bring in sources from Teatatu Events and Teatatu News** (Organisations tab) gathers the Sources on every site, merges the same organisation from different places, and proposes them as one batch. Run it again after approving to link parent organisations.

== Tags and categories ==

Three controlled vocabularies, each with its own tab: **Event tags** (with a badge colour), **Event categories** and **News categories** (both can be nested).

* Each term has a name and slug (unique within its list), synonyms, a description and, for categories, a parent.
* Curated: words from feeds or agents are matched to existing terms by name, slug or synonym; unknown words are ignored, never added. "Test matching" on each tab shows what a list of words would match.
* **Bring in from Teatatu Events / News** on each tab gathers the list from every site, merges the same term from different sites, and proposes it as one batch. Run it again after approving to link parent categories.

== Data quality ==

Every active record is checked daily against six DAMA-DMBOK dimensions — completeness, validity, uniqueness, consistency, timeliness and accuracy — and each data set gets a score per dimension.

* **Data quality** tab: scores, then open issues with a fix for each — open the record, mark it verified (one by one or all at once), point a reference at a replacement, compare and merge duplicates, or dismiss with a reason (and reopen later).
* **Compare and merge** shows two records side by side: choose which to keep and which value to use where they differ. The other record is marked as replaced, its other names and external IDs are combined, and everything that pointed to it is pointed to the kept record.
* "Check now" runs the checks at once; fixing an issue rechecks its data set.

== Connections to Teatatu Events and News ==

On the **Connections** tab, choose which lists Teatatu Data manages in each plugin: in Events, neighbourhoods and street rules, venues, sources, event tags and categories; in News, sources and categories.

* Managed lists are copied into Events and News on every site where they are active, within a minute of a change (or with **Sync now**). Existing terms are linked, not replaced, so events, news items, URLs and shortcodes keep working.
* While a list is managed it is read-only in Events or News, which link to Teatatu Data instead.
* Merging two records here moves their events or news items to the surviving term.
* Turn a list off, or Teatatu Data itself, and Events and News carry on with the last copy, editable as before.
* Bring each list in and approve it before switching it on; terms that can't be linked are left as they are and listed in the sync report.

== Uninstall ==

Always removes the Data Agent role, steward capabilities and plugin settings on every site. Master data is deleted only if "Delete all Teatatu Data master data when this plugin is uninstalled" is ticked. Teatatu Events and News data is never touched.

== Changelog ==

= 0.8.0 =
* Connections: Teatatu Data keeps Events' and News' neighbourhoods, street rules, venues, sources, tags and categories up to date on every site, linking existing terms; managed lists are read-only there; merges move items to the surviving term; Connections tab with sync report.

= 0.7.0 =
* Data quality: checks in six dimensions, scores, daily check, Data quality tab with fixes (mark verified singly or in bulk, point to the replacement, dismiss with a reason, reopen), REST route.
* Merge: compare two records side by side, keep one, choose values; names and external IDs combined; references repointed everywhere.
* Venues, organisations and tags have recommended fields and duplicate keys; venues get accuracy checks for their coordinates; overlapping street rules are flagged.
* Deactivating the plugin clears its scheduled jobs.

= 0.6.0 =
* Event tags, Event categories and News categories as controlled vocabularies (unique names and slugs, synonyms, nested categories without loops, tag colours), matching by name, slug or synonym, "Bring in", "Test matching", REST match route.

= 0.5.0 =
* Organisations data set, replacing the Events and News Source lists: types, parent organisations (no loops), public contacts only, NZBN and charity numbers, matching by name, other name or website, "Bring in sources", "Find an organisation", REST match route.
* Bringing in Events venues now splits a venue's alias list into separate aliases.

= 0.4.0 =
* Venues data set, moved from Teatatu Events: structured NZ address, derived neighbourhood and match key, formatter, schema.org PostalAddress, matcher, "Bring in venues from Teatatu Events", "Find a venue", "Paste address", REST match and parse routes.
* Address parser: "Unit 2, 15 Smith St" and "Level 1, 22 Queen St" are now read correctly.
* Framework: derived fields, field defaults and upper/lower-casing, re-deriving a data set, a hook above edit forms.

= 0.3.0 =
* Neighbourhoods and Street rules data sets, moved from Teatatu Events (seeded from its current data), with the address resolver, boundary geometry, OpenStreetMap build tool, "Test an address", "Add missing streets" and a REST resolve route.
* Data sets can declare multi-field rules (shown in the dictionary and checked on every save) and their list columns.

= 0.2.0 =
* Core framework: record storage, standard fields and lifecycle, validator, change log with revert, proposed-change approval, REST API, CSV/JSON import and export, admin screens, per-data-set owner/steward/review interval.

= 0.1.0 =
* Scaffold: plugin header, roles and capabilities, Master Site setting, GitHub Releases updater, uninstall, data set registry and data dictionary screen.
