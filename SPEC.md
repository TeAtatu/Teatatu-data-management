# Teatatu Data — Design Spec

Status: **draft for review (0.1.0 scaffold).** The plugin skeleton in `teatatu-data/` has the plugin header, roles and capabilities, Master Site setting, GitHub updater, uninstall and an empty data-set registry with a data dictionary screen. No data sets have been built yet. Section 11 lists the open questions to settle before Phase 2.

---

## 1. Purpose

Teatatu Events and Teatatu News each hold their own copy of shared data: places, neighbourhoods, organisations, tags. Teatatu Data is the **one place that data is loaded, described, checked and maintained**. Other Teatatu plugins read it from here.

It follows the **DAMA-DMBOK** (Data Management Body of Knowledge) approach. Every data set has a named owner and steward, a declared schema, a published definition, quality rules, a change history and a defined lifecycle. The aim is data that is well structured *and* understood, by people and by AI agents.

## 2. Decisions so far

| Decision | Choice |
|---|---|
| Data sets | Neighbourhoods + street rules, Venues / places, Organisations / sources, Tags / categories |
| Relationship to Events / News | **Migrate into Teatatu Data.** Events and News read through Teatatu Data's API, and keep a fallback so they still work when it is inactive |
| Multisite | **Master Site, shared.** Edited on one Master Site, read network-wide through `switch_to_blog()` |
| Maintenance | Admin screens, CSV/JSON import and export, external sources (e.g. OSM, LINZ), REST for AI agents (propose-only) |
| Governance | Schema per data set, data dictionary, quality checks, change history with approval, guided by DAMA-DMBOK |
| Identity | Folder `teatatu-data/`, prefix `teatatu_data_`, text domain `teatatu-data`, repo `TeAtatu/Teatatu-data-management`, version 0.1.0 |
| Kept from Events / News | Standard WordPress tables only, link-only images, draft/propose-only agent role, multisite activation pattern, plain `admin-post.php` handlers, security baseline, GitHub Releases updater |

**Change to an existing invariant.** Events' spec says it runs "with no shared code, data, hooks or dependency" on News. Migrating into Teatatu Data deliberately changes that for Events and News: both become *optional* consumers of Teatatu Data. They must still work without it (Section 9), so the change is soft.

## 3. DAMA-DMBOK mapping

How each DMBOK knowledge area applies to this plugin. Areas marked "—" are out of scope.

| DMBOK knowledge area | What Teatatu Data does |
|---|---|
| **Data Governance** | Each data set names a **Data Owner** (accountable) and **Data Steward** (maintains it). Roles: Steward (Administrator/Editor), Data Agent (propose only). Changes from agents and imports need steward approval. Policies live in this spec and in the data dictionary |
| **Data Architecture** | One system of record per data set: the Master Site, or an external source for imported sets. Consumers read, never write. Data flow documented per data set (source → Teatatu Data → Events/News) |
| **Data Modeling & Design** | A declared schema per data set (Section 4): fields, types, required, allowed values, references between sets, examples |
| **Data Storage & Operations** | Standard WordPress tables only (taxonomies, term meta, options, a log post type). Export for backup. Retention rules on uninstall |
| **Data Security** | Capabilities (Section 8), nonces, escaping, sanitising. Personal data kept to a minimum: organisations hold public contact details only (Section 11, Q5) |
| **Data Integration & Interoperability** | CSV/JSON import and export, external source connectors, REST API, schema.org mapping (`Place`, `PostalAddress`, `Organization`) |
| **Document & Content Management** | — (content stays in Events/News) |
| **Reference & Master Data** | The core of the plugin. **Reference data** (controlled vocabularies: Neighbourhoods, Tags, Categories) and **master data** (entities: Venues, Organisations). Stable identifiers, match/merge of duplicates, a "golden record" per entity, crosswalks to external IDs |
| **Data Warehousing & BI** | — |
| **Metadata Management** | Data dictionary (admin screen + REST/JSON) generated from the registry: business definitions, field meanings, owner, steward, source, consumers, lineage |
| **Data Quality** | Quality rules per data set, scored on the DMBOK dimensions (Section 6) with a Data Quality screen of open issues |

## 4. The data set registry

Every data set registers itself through the `teatatu_data_datasets` filter (`includes/registry.php`). The registry is the only description of a data set. The dictionary, validation, import/export, quality checks and REST all read from it, so nothing is described twice.

A data set declares:

| Property | Meaning |
|---|---|
| `key` | Stable machine key, e.g. `venue` |
| `label`, `description` | Business name and definition |
| `kind` | `master` (entities) or `reference` (controlled vocabulary) |
| `owner`, `steward` | Accountable owner, day-to-day steward |
| `source` | System of record or external source (e.g. "OpenStreetMap, ODbL") |
| `consumers` | Plugins that read it |
| `fields` | Field key → `label`, `type`, `required`, `description`, `enum`, `max_length`, `pattern`, `references`, `example` |

Field types: `string`, `text`, `integer`, `number`, `boolean`, `date`, `url`, `email`, `phone`, `enum`, `reference`, `geo`.

**Every record also carries standard fields:**

| Field | Meaning |
|---|---|
| `uuid` | Stable identifier, never reused, the same on every site. Consumers store this, not a term ID |
| `status` | Lifecycle: `proposed` → `active` → `deprecated` → `retired` |
| `replaced_by` | For merged/deprecated records, the `uuid` that replaces it |
| `external_ids` | Crosswalk to external keys (OSM ID, LINZ address ID, NZBN, Charities Register number) |
| `source`, `verified_at` | Where the record came from, and when a steward last confirmed it |
| `created_at/by`, `updated_at/by` | Audit fields |

**One validator** checks every save, from every path (admin form, import, REST, migration), against the schema. A save that fails validation is refused with field-level messages. Nothing writes data without going through it.

## 5. Data sets

Detail for each is designed in its own phase. Summary:

| Data set | Kind | Moves from | Notes |
|---|---|---|---|
| **Neighbourhoods** | reference | Events `teatatu_events_neighbourhood`, street rules, `neighbourhoods.geojson`, OSM build tool | Fixed set of three. Resolver `teatatu_data_resolve_neighbourhood()` |
| **Street rules** | reference | Events network option `teatatu_events_nbhd_street_rules` | Street, side, number range → neighbourhood |
| **Venues / places** | master | Events `teatatu_events_venue` + address term meta | Structured NZ address (schema.org `PostalAddress`), aliases, coordinates, neighbourhood. Address parser and formatter move here |
| **Organisations / sources** | master | Events and News `*_source` taxonomies | Organisers, groups, news sources. Website, feed URLs, default image URL, external IDs (NZBN, Charities number) |
| **Tags / categories** | reference | Events `teatatu_events_tag`, `*_category`; News `teatatu_news_category` | Controlled vocabularies, each its own scheme. Synonyms for import matching |

## 6. Data quality

Quality rules are declared per data set and grouped by DMBOK dimension:

| Dimension | Example rule |
|---|---|
| Completeness | Venue has street + city or a suburb; organisation has a website or contact |
| Validity | Postcode is 4 digits; URL resolves to a valid format; enum values allowed |
| Uniqueness | No two active venues with the same normalised address; no duplicate tag names |
| Consistency | Venue's neighbourhood agrees with the street rules; references point to active records |
| Timeliness | `verified_at` older than N months flags the record for review |
| Accuracy | Coordinates fall inside the stated suburb |

A scheduled job scores each data set and lists open issues on a **Data Quality** screen. Each issue has a fix action (edit, merge, mark verified, dismiss with a reason). Scores are shown per data set and per dimension.

**Match and merge.** Duplicate candidates (fuzzy name + normalised address/website) are shown side by side. A merge keeps one golden record, marks the other `deprecated` with `replaced_by`, and repoints consumers.

## 7. Change history and approval

- Every change is logged as a `teatatu_data_change` post (standard tables): data set, record `uuid`, field-level before/after, who, when, why, and the channel (admin, import, REST, migration).
- Changes from the **Data Agent** role and from imports are stored as **proposed** changes. A steward approves (applies) or rejects them on a **Proposed changes** screen. Stewards' own admin edits apply straight away and are logged.
- Any record's history can be viewed, and a field can be restored to an earlier value.

## 8. Roles and capabilities

| Capability | Data Agent | Steward (Admin/Editor) |
|---|---|---|
| `read_teatatu_data` | ✓ | ✓ |
| `propose_teatatu_data` | ✓ | ✓ |
| `edit_teatatu_data` | | ✓ |
| `approve_teatatu_data` | | ✓ |
| `import_teatatu_data` | | ✓ |
| `manage_teatatu_data` | | ✓ |

Schema-level structure (Master Site, external source settings, neighbourhood street rules) needs `manage_network_options` on multisite. The agent role can never apply a change; that is permanent.

## 9. Integration with Events and News

- Teatatu Data exposes a PHP API (`teatatu_data_get()`, `teatatu_data_find()`, `teatatu_data_match_venue()`, `teatatu_data_resolve_neighbourhood()`, `teatatu_data_format_address()`…) and REST routes under `/wp-json/teatatu-data/v1/`.
- Consumer plugins check `function_exists( 'teatatu_data_get' )`. If Teatatu Data is active they use it; if not, they fall back to their own built-in data.
- **Proposed sync model** (Section 11, Q1): Events and News keep their own taxonomies for querying and URLs, but each term stores the master record's `uuid` (`teatatu_data_ref` term meta) and is kept in sync from the Master Site. This keeps `tax_query`, archive URLs and REST in those plugins unchanged.
- Migration is a one-off, steward-run job per data set: read existing Events/News terms on every site, match and merge them into master records, write the `uuid` back, and report anything it couldn't match.

## 10. Delivery phases

| Phase | Scope |
|---|---|
| 1 | **Scaffold** (done): repo, plugin header, roles, settings (Master Site), updater, uninstall, empty registry, dictionary screen |
| 2 | Core framework: standard fields, validator, change log, proposed-change approval, REST read + propose, JSON/CSV export and import |
| 3 | Neighbourhoods + street rules (move from Events, including the OSM build tool) |
| 4 | Venues / places (address parser, formatter, matching) |
| 5 | Organisations / sources |
| 6 | Tags / categories |
| 7 | Data quality checks, match and merge, quality screen |
| 8 | Events and News integration + migration |
| 9 | External source connectors (LINZ addresses, Charities Register, NZBN) |

## 11. Open questions

1. **Sync model.** Keep local taxonomy terms in Events/News linked by `uuid` (proposed), or have them query the Master Site directly?
2. **Storage of master records.** Taxonomies with term meta (consistent with Events today) or a custom post type per data set (gives revisions and statuses for free)?
3. **Owners and stewards.** Who is the Data Owner for each data set — a named person, or a role?
4. **External sources.** Which ones in the first pass — OSM only, or LINZ / Charities Register / NZBN as well? Any API keys involved?
5. **Personal data.** Will organisations hold contact people (names, emails, phones)? If so, privacy rules (NZ Privacy Act 2020) and export/erasure tools are needed.
6. **Public visibility.** Should the data dictionary and any data sets be public (shortcodes / public REST), or admin-only?
7. **Timeliness.** How often should records be re-verified (default 12 months)?
