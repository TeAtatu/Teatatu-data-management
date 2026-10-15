# Teatatu Data

A WordPress plugin that is the central place to load, describe, check and maintain the master and reference data shared by the Teatatu plugins: **Neighbourhoods and street rules**, **Venues and places**, **Organisations and sources**, and **Tags and categories**.

It follows the **DAMA-DMBOK** approach. Every data set has an owner and steward, a declared schema, a data dictionary entry, quality rules, a change history and a lifecycle.

> **Status: 0.1.0 scaffold.** The plugin installs and activates, with roles, settings, updater and an empty data dictionary. Data sets are built in later phases. See [`SPEC.md`](SPEC.md).

The plugin folder ([`teatatu-data/`](teatatu-data/)) is a complete, self-contained WordPress plugin. Drop it into `wp-content/plugins/` on any WordPress 6.0+ / PHP 7.4+ site (single-site or multisite) and activate it.

## What it will do

- **Data set registry**: each data set declares its schema once. The dictionary, validation, import/export, quality checks and REST all read it.
- **Data dictionary**: an admin screen and REST/JSON describing every data set and field, readable by people and AI agents.
- **Quality checks**: completeness, validity, uniqueness, consistency, timeliness and accuracy, with a screen of open issues and match/merge for duplicates.
- **Change history**: every change logged field by field. Agent and import changes wait for a steward to approve them.
- **Import/export**: CSV and JSON, plus external sources (OpenStreetMap, LINZ…).
- **Multisite**: edited on one Master Site and read across the network.
- **Integration**: Teatatu Events and News read their shared data from here, and fall back to their own when this plugin isn't active.
- Only **standard WordPress tables**. Self-updates from [GitHub Releases](https://github.com/TeAtatu/Teatatu-data-management/releases).

## Getting started

1. Copy or symlink [`teatatu-data/`](teatatu-data/) into `wp-content/plugins/`.
2. Activate it (or **Network Activate** on multisite).
3. On multisite, choose the **Master Site** under **Network Admin > Settings > Teatatu Data**.
4. Open **Teatatu Data** in the admin menu to see the data dictionary.

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
    registry.php           data set registry (schemas)
    admin-page.php         Teatatu Data screen (data dictionary)
    updater.php            GitHub Releases updater
SPEC.md                    design spec, DAMA-DMBOK mapping, phases, open questions
prompt.md                  reusable AI prompt for extending the plugin
README.md                  this file
LICENSE                    GPL-3.0
```

## Extending this plugin with AI

[`prompt.md`](prompt.md) captures the plugin's architecture and invariants. Paste it into a fresh AI coding session along with the change you want, so updates stay consistent with how the plugin was built.

## License

GPL-3.0 — see [LICENSE](LICENSE).
