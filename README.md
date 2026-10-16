# Teatatu Data

A WordPress plugin that is the central place to load, describe, check and maintain the master and reference data shared by the Teatatu plugins: **Neighbourhoods and street rules**, **Venues and places**, **Organisations and sources**, and **Tags and categories**.

It follows the **DAMA-DMBOK** approach. Every data set has an owner and steward, a declared schema, a data dictionary entry, quality rules, a change history and a lifecycle.

> **Status: 0.8.0.** The core framework, every planned data set, data quality with merging, and the **connections to Teatatu Events and News** are built. Teatatu Data keeps their lists up to date on every site; they keep working on their own without it. External source connectors come next. See [`SPEC.md`](SPEC.md).

The plugin folder ([`teatatu-data/`](teatatu-data/)) is a complete, self-contained WordPress plugin. Drop it into `wp-content/plugins/` on any WordPress 6.0+ / PHP 7.4+ site (single-site or multisite) and activate it.

## What it will do

- **Data set registry**: each data set declares its schema once. The dictionary, validation, import/export, quality checks and REST all read it.
- **Data dictionary**: an admin screen and REST/JSON describing every data set and field, readable by people and AI agents.
- **Quality checks**: completeness, validity, uniqueness, consistency, timeliness and accuracy, scored per data set, with a Data quality tab of open issues and fixes, and side-by-side merging of duplicates.
- **Change history**: every change logged field by field. Agent and import changes wait for a steward to approve them.
- **Import/export**: CSV and JSON, plus external sources (OpenStreetMap, LINZ…).
- **Multisite**: edited on one Master Site and read across the network.
- **Integration**: Teatatu Data keeps Teatatu Events' and News' lists up to date on every site (switched on per list); they keep working on the last copy when this plugin isn't active.
- Only **standard WordPress tables**. Self-updates from [GitHub Releases](https://github.com/TeAtatu/Teatatu-data-management/releases).

## Getting started

1. Copy or symlink [`teatatu-data/`](teatatu-data/) into `wp-content/plugins/`.
2. Activate it (or **Network Activate** on multisite).
3. On multisite, choose the **Master Site** under **Network Admin > Settings > Teatatu Data**.
4. Open **Teatatu Data** in the admin menu to see the data dictionary and, under **Data set settings**, name each data set's owner and steward.

## Repo layout

```
teatatu-data/              the plugin — drop this folder into wp-content/plugins/
  teatatu-data.php         plugin header, activation, provisioning
  uninstall.php            retention-aware cleanup (never touches Events or News)
  readme.txt               documentation (WordPress.org format)
  SKILL.md                 short agent workflow
  includes/
    capabilities.php       Steward and Data Agent roles
    settings.php           network/site settings (Master Site, updates, uninstall)
    multisite.php          Master Site lookup, switch_to_blog() helper, cache version
    registry.php           data set registry, standard fields, lifecycle, dictionary
    records.php            private post types and statuses, record reads
    validation.php         the one validator
    changes.php            the single write path, change log, proposals
    rest-api.php           teatatu-data/v1 routes
    import-export.php      CSV/JSON import and export
    admin-page.php         Teatatu Data screen (dictionary, records, proposals, import/export, settings)
    quality.php            quality checks, scores, daily check, dismissals
    merge.php              merging duplicate records, repointing references
    admin-quality.php      Data quality tab and merge screen
    connections.php        sync into Teatatu Events / News, Connections tab
    address-core.php       pure address/geometry helpers (shared with tools/)
    bring-in.php           reading Events/News terms on every site, merging aliases
    datasets/
      neighbourhoods.php   Neighbourhoods + Street rules data sets, resolver, seeding
      venues.php           Venues data set, formatter, matcher, bring-in from Events
      organisations.php    Organisations data set, matcher, bring-in from Events/News sources
      vocabularies.php     Event tags, Event/News categories, term matcher, bring-in
    updater.php            GitHub Releases updater
  assets/data/             bundled street rules + boundary geometry, from OpenStreetMap
  tools/                   build-neighbourhood-streets.php (regenerates assets/data)
SPEC.md                    design spec, DAMA-DMBOK mapping, phases, open questions
prompt.md                  reusable AI prompt for extending the plugin
README.md                  this file
LICENSE                    GPL-3.0
```

## Rebuilding the neighbourhood data

```
cd teatatu-data && php tools/build-neighbourhood-streets.php
```

Fetches Te Atatū Peninsula address points and the boundary roads from OpenStreetMap (Overpass API) and rewrites `assets/data/`. It fails if a street can't be described by clean house-number ranges. The bundled list only seeds a new install and powers "Add missing streets"; the street rules a site uses are the records in Teatatu Data. Data © OpenStreetMap contributors (ODbL).

## Extending this plugin with AI

[`prompt.md`](prompt.md) captures the plugin's architecture and invariants. Paste it into a fresh AI coding session along with the change you want, so updates stay consistent with how the plugin was built.

## License

GPL-3.0 — see [LICENSE](LICENSE).
