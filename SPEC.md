# Teatatu Data — Design Spec

Status: **0.8.0 — Phases 1–8 built.** The core framework (§12), every planned data set (§13–§16), data quality with match and merge (§17), and the connections to Teatatu Events and News (§18) are in place. External source connectors (Phase 9) are next. Section 11 records the decisions on the open questions.

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
| Storage | **One private custom post type per data set** on the Master Site; consumers keep **local taxonomy terms linked by `uuid`** (§9) |
| Visibility | **Nothing public.** Master data, the dictionary, history and quality are visible only in wp-admin and over authenticated REST. Front-end display is left to the consumer plugins |
| User data | Described **by structure only** (fields, roles, capabilities) in the dictionary. User values stay in `wp_users`/`wp_usermeta` and are viewed and edited only in the standard WordPress Users screens |
| External sources | OpenStreetMap, LINZ addresses, Charities Register, NZBN |
| Identity | Folder `teatatu-data/`, prefix `teatatu_data_`, text domain `teatatu-data`, repo `TeAtatu/Teatatu-data-management`, version 0.8.0 |
| Kept from Events / News | Standard WordPress tables only, link-only images, draft/propose-only agent role, multisite activation pattern, plain `admin-post.php` handlers, security baseline, GitHub Releases updater |

**Change to an existing invariant.** Events' spec says it runs "with no shared code, data, hooks or dependency" on News. Migrating into Teatatu Data deliberately changes that for Events and News: both become *optional* consumers of Teatatu Data. They must still work without it (Section 9), so the change is soft.

## 3. DAMA-DMBOK mapping

How each DMBOK knowledge area applies to this plugin. Areas marked "—" are out of scope.

| DMBOK knowledge area | What Teatatu Data does |
|---|---|
| **Data Governance** | Each data set names a **Data Owner** (accountable) and **Data Steward** (maintains it). Roles: Steward (Administrator/Editor), Data Agent (propose only). Changes from agents and imports need steward approval. Policies live in this spec and in the data dictionary |
| **Data Architecture** | One system of record per data set: the Master Site, or an external source for imported sets. Consumers read, never write. Data flow documented per data set (source → Teatatu Data → Events/News) |
| **Data Modeling & Design** | A declared schema per data set (Section 4): fields, types, required, allowed values, references between sets, examples |
| **Data Storage & Operations** | Standard WordPress tables only: one private custom post type per data set (`wp_posts`/`wp_postmeta`), options, a change-log post type. Export for backup. Retention rules on uninstall |
| **Data Security** | Capabilities (Section 8), nonces, escaping, sanitising. Nothing public: every screen is in wp-admin and every REST route needs an authenticated user with `read_teatatu_data`. Personal data kept to a minimum: organisations hold public organisation contacts only, never named individuals. User values are never copied or edited here |
| **Data Integration & Interoperability** | CSV/JSON import and export, external source connectors, REST API, schema.org mapping (`Place`, `PostalAddress`, `Organization`) |
| **Document & Content Management** | — (content stays in Events/News) |
| **Reference & Master Data** | The core of the plugin. **Reference data** (controlled vocabularies: Neighbourhoods, Tags, Categories) and **master data** (entities: Venues, Organisations). Stable identifiers, match/merge of duplicates, a "golden record" per entity, crosswalks to external IDs |
| **Data Warehousing & BI** | — |
| **Metadata Management** | Data dictionary (admin screen + authenticated REST/JSON) generated from the registry: business definitions, field meanings, owner, steward, source, consumers, lineage. Also describes the structure of WordPress user data used by the Teatatu plugins, without any values |
| **Data Quality** | Quality rules per data set, scored on the DMBOK dimensions (Section 6) with a Data Quality screen of open issues |

## 4. The data set registry

Every data set registers itself through the `teatatu_data_datasets` filter (`includes/registry.php`). The registry is the only description of a data set. The dictionary, validation, import/export, quality checks and REST all read from it, so nothing is described twice.

A data set declares:

| Property | Meaning |
|---|---|
| `key` | Stable machine key, e.g. `venue` |
| `label`, `description` | Business name and definition |
| `kind` | `master` (entities) or `reference` (controlled vocabulary) |
| `owner`, `steward` | Accountable owner and day-to-day steward: each a **named WordPress user** on the Master Site, chosen on the data set's settings and editable. The registry gives the default role; the chosen users are stored with the review interval in the network option `teatatu_data_dataset_settings` (Data set settings tab) |
| `review_interval` | Months before a record's `verified_at` is stale. Default **12**, editable per data set |
| `post_type` | The private custom post type holding the records. Default `ttd_<key>`; WordPress limits post type names to 20 characters |
| `title_field` | The field used as the record's display name (default `name`); mirrored into `post_title` for search |
| `source` | System of record or external source (e.g. "OpenStreetMap, ODbL") |
| `consumers` | Plugins that read it |
| `fields` | Field key → `label`, `type`, `required`, `description`, `enum`, `max_length`, `pattern`, `references`, `example` |

Field types: `string`, `text`, `integer`, `number`, `boolean`, `date`, `url`, `email`, `phone`, `enum`, `reference`, `geo`.

**Every record also carries standard fields:**

| Field | Meaning |
|---|---|
| `uuid` | Stable identifier, never reused, the same on every site. Consumers store this, not a term ID |
| `status` | Lifecycle: `proposed` → `active` → `deprecated` → `retired`, stored as custom post statuses (`ttd_proposed`, `ttd_active`, `ttd_deprecated`, `ttd_retired`) |
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
| **Organisations / sources** | master | Events and News `*_source` taxonomies | Organisers, groups, news sources. Website, feed URLs, default image URL, external IDs (NZBN, Charities number). **Public organisation contacts only** (general email, phone, address) — never named individuals |
| **Tags / categories** | reference | Events `teatatu_events_tag`, `*_category`; News `teatatu_news_category` | Controlled vocabularies, each its own scheme. Synonyms for import matching |
| **Users** (structure only) | metadata | — | A dictionary entry describing the user fields, roles and capabilities the Teatatu plugins rely on. No records, no values, no editing: users are viewed and edited only in the standard WordPress Users screens |

Every data set's post type is registered with `public => false`, `publicly_queryable => false`, `exclude_from_search => true` and `show_in_rest => false`. Records are reached only through the Teatatu Data admin screens and the authenticated `teatatu-data/v1` routes.

## 6. Data quality

Quality rules are declared per data set and grouped by DMBOK dimension:

| Dimension | Example rule |
|---|---|
| Completeness | Venue has street + city or a suburb; organisation has a website or contact |
| Validity | Postcode is 4 digits; URL resolves to a valid format; enum values allowed |
| Uniqueness | No two active venues with the same normalised address; no duplicate tag names |
| Consistency | Venue's neighbourhood agrees with the street rules; references point to active records |
| Timeliness | `verified_at` older than the data set's `review_interval` (default 12 months) flags the record for review |
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

- Teatatu Data exposes a PHP API (`teatatu_data_get()`, `teatatu_data_find()`, `teatatu_data_match_venue()`, `teatatu_data_resolve_neighbourhood()`, `teatatu_data_format_address()`…) and REST routes under `/wp-json/teatatu-data/v1/`. Every route requires an authenticated user with `read_teatatu_data`; there is no public read.
- Consumer plugins check `function_exists( 'teatatu_data_get' )`. If Teatatu Data is active they use it; if not, they fall back to their own built-in data.
- **Sync model:** Events and News keep their own taxonomies for querying, archive URLs and REST, but each term stores the master record's `uuid` (`teatatu_data_ref` term meta). When a master record changes, a background job updates the linked terms on every site (name, slug, mirrored meta). A deprecated record with `replaced_by` repoints the linked terms' posts to the replacement's term. Consumer terms are read-only for mirrored fields while Teatatu Data is active. What a consumer shows on its front end is that plugin's decision; Teatatu Data shows nothing publicly.
- Migration is a one-off, steward-run job per data set: read existing Events/News terms on every site, match and merge them into master records, write the `uuid` back, and report anything it couldn't match.

## 10. Delivery phases

| Phase | Scope |
|---|---|
| 1 | **Scaffold** (done): repo, plugin header, roles, settings (Master Site), updater, uninstall, empty registry, dictionary screen |
| 2 | **Core framework** (done, 0.2.0): standard fields, validator, change log, proposed-change approval, REST, JSON/CSV export and import, admin screens (§12) |
| 3 | **Neighbourhoods + street rules** (done, 0.3.0): data sets, resolver, geometry, OSM build tool, seeding from Events (§13) |
| 4 | **Venues / places** (done, 0.4.0): data set, parser, formatter, matcher, derived neighbourhood, bring-in from Events (§14) |
| 5 | **Organisations / sources** (done, 0.5.0): data set, matcher, bring-in from Events and News sources (§15) |
| 6 | **Tags / categories** (done, 0.6.0): Event tags, Event categories, News categories as controlled vocabularies, matcher, bring-in (§16) |
| 7 | **Data quality** (done, 0.7.0): checks in six dimensions, scores, Data quality tab, dismissals, match and merge (§17) |
| 8 | **Events and News integration** (done, 0.8.0): push sync into their storage, linking existing terms, read-only lists while managed, fallback (§18) |
| 9 | External source connectors: LINZ addresses (Venues), Charities Register and NZBN (Organisations). OpenStreetMap arrives with Phase 3 |

## 11. Decisions on open questions

Settled 2026-10-15, before Phase 2.

1. **Sync model → local terms linked by `uuid`.** Events and News keep their taxonomies; each term stores `teatatu_data_ref` and is synced from the Master Site (§9). Their `tax_query`, archive URLs and REST stay unchanged.
2. **Storage → one private custom post type per data set.** Lifecycle maps to custom post statuses, the change log is the record history (fields are post meta, which WordPress revisions don't track), and relationships between sets are `uuid` references in post meta. Post types are never public (§5).
3. **Owners and stewards → named WordPress users, editable.** Chosen per data set from users on the Master Site, shown in the dictionary. The registry gives a default role used until someone is chosen.
4. **External sources → all four in scope:**

   | Source | Feeds | Access |
   |---|---|---|
   | OpenStreetMap (Overpass) | Neighbourhood street rules, venue coordinates | No key. ODbL attribution |
   | LINZ addresses (LINZ Data Service) | Venue address validation and `external_ids.linz` | Free API key |
   | Charities Register (Charities Services) | Organisation details, `external_ids.charity` | Public API |
   | NZBN (MBIE) | Organisation details, `external_ids.nzbn` | API subscription key |

   API keys are network settings stored like the GitHub token: password field, never echoed back, a "clear" checkbox, deleted on uninstall. Connector results arrive as **proposed changes** for a steward to approve, never written directly.
5. **Personal data → public organisation contacts only.** No named individuals in any data set, so no erasure/export tooling is needed. No schema may declare a field for a person's name or personal contact details; field descriptions tell editors to use general organisation contacts only.
6. **Visibility → nothing public.** No shortcodes, no public REST, no front-end output, no public post types. The dictionary is admin-only too. User data is described by structure only; user values stay in `wp_users`/`wp_usermeta` and are viewed and edited only in the standard WordPress Users screens.
7. **Timeliness → per data set, default 12 months** (`review_interval`).

## 12. Core framework (Phase 2, 0.2.0)

How the framework was built. Data set phases build on these and don't repeat them.

**Files** (`teatatu-data/includes/`): `registry.php` (data sets, standard fields, lifecycle, per-data-set settings, dictionary) · `records.php` (post types and statuses, reads) · `validation.php` (the one validator) · `changes.php` (the write path, change log, proposals) · `rest-api.php` · `import-export.php` · `admin-page.php`.

**Registering a data set.** Add it on the `teatatu_data_datasets` filter. Field types: `string`, `text`, `integer`, `number`, `boolean`, `date`, `url`, `email`, `phone`, `enum`, `reference` (with `references` and optional `multiple`) and `geo` (`{lat, lng}`). Field rules: `required`, `unique`, `enum`, `pattern`, `max_length`, `min`/`max`. Keys `uuid`, `status`, `replaced_by`, `external_ids`, `source`, `verified_at`, `created_*` and `updated_*` are reserved for the standard fields.

**Storage.** Each record is one post of the data set's private post type on the Master Site. Fields are stored as `_ttd_f_<field>` meta and standard fields as `_ttd_<field>` meta (`_ttd_uuid`, `_ttd_replaced_by`, `_ttd_external_ids`, `_ttd_source`, `_ttd_verified_at`, `_ttd_updated_by`). Created and updated times come from the post dates, and the creator is the post author.

**PHP API.**

| Function | Does |
|---|---|
| `teatatu_data_get( $key, $uuid )` | One record, from any site |
| `teatatu_data_find( $key, $args )` | Search: `search`, `status` (default `active`, or `all`), `where` (field ⇒ value), `per_page`, `page` |
| `teatatu_data_apply( $key, $uuid\|null, $values, $args )` | **The only write.** Validates, stores and logs a field-by-field diff. A missing key leaves a field unchanged; `null`, `''` or `[]` clears it |
| `teatatu_data_propose( … )` | Validates and stores a pending change. Only fields that differ from the record are kept, along with their values at proposal time (`base`), so a steward can see conflicts |
| `teatatu_data_approve_change()` / `_reject_change()` / `_revert_change()` | Decide a proposal (it becomes its own log entry), or undo an applied update through the write path |
| `teatatu_data_get_changes( $args )` | The log: filter by `dataset`, `record`, `state`, `batch`, `author` |

Hooks: `teatatu_data_record_changed` (key, uuid, diff, operation) for term sync and quality checks in later phases; `teatatu_data_change_proposed`.

**Validation rules.** Unknown and plugin-kept fields are refused. Required fields must have a value when a record is created and can't be cleared afterwards. Unique fields are checked against proposed, active and deprecated records. New or changed references must point at an existing record that isn't retired, and never at the record itself; references that haven't changed aren't re-checked, so a record whose reference was later retired can still be edited. Errors come back as a `WP_Error` (`teatatu_data_invalid`) whose data holds `fields` ⇒ message.

**Change log.** Every change is one `teatatu_data_change` post (status `private`). Its meta records the data set, the record UUID, the operation (`create`/`update`), the state (`pending`/`applied`/`rejected`), the channel (`admin`, `import`, `rest`, `migration`, `connector`), the proposed values, the `base` values, the diff, the import batch, and who decided it, when and with what note. The post author is whoever made or proposed the change, and the post content holds the reason.

**REST** (`teatatu-data/v1`, all routes need a logged-in user):

| Route | Capability |
|---|---|
| `GET /dictionary` | `read_teatatu_data` |
| `GET /datasets/<key>/records` (`search`, `status`, `page`, `per_page` ≤ 100), `GET …/records/<uuid>`, `GET …/records/<uuid>/history` | `read_teatatu_data` |
| `POST /datasets/<key>/records`, `PATCH …/records/<uuid>` (`{values, reason}`) | `edit_teatatu_data` |
| `POST /datasets/<key>/proposals` (`{uuid?, values, reason}`) | `propose_teatatu_data` |
| `GET /proposals` (`state`, `dataset`, `batch`), `GET /proposals/<id>`: agents see only their own | `propose_teatatu_data` |
| `POST /proposals/<id>/approve`, `/reject` (`{note}`) | `approve_teatatu_data` |

Reads work on any site. Writes made on a site other than the Master Site get a `409 teatatu_data_not_master` error, with `master_url` in the error data.

**Import and export.** Export (CSV with a UTF-8 BOM, or JSON) includes every record in every status, with the columns `uuid`, `status`, the schema fields, `replaced_by`, `external_ids` (as JSON), `source` and `verified_at`. Import reads the same shape:
- An absent column leaves the field unchanged, and an empty cell clears it.
- A known `uuid` updates that record, an unknown valid `uuid` creates a record with that ID, and no `uuid` creates a new one.
- A cell can hold several references separated by `;`, and coordinates are written as `lat,lng`.
- Unknown columns, or a JSON export of a different data set, reject the whole file.
- Every row becomes a proposal in one batch. Stewards can approve the batch, or tick "Apply now". Rows that refer to records created earlier in the same batch fail until that batch is applied.
- Files are limited to 5 MB and are read from the upload's temporary file, never stored.

**Admin** (Teatatu Data menu, with a count of pending changes for stewards) has these tabs:
- **Dictionary.**
- **One tab per data set:** search, status filter, a "due for review" flag, a form built from the schema, and the record's history with Revert.
- **Proposed changes:** a now-vs-proposed table with conflict flags, Approve/Reject with a note, and batch approval.
- **Import / Export.**
- **Data set settings:** named owner, named steward and review interval.

Stewards' saves apply straight away. A user who can only propose sends a proposal instead. Sites other than the Master Site show everything read-only.

**Verified** on the Local multisite `teatatu-news-plugin` with a test-only data set covering every field type: 88 checks on the API, validation, proposals, REST permissions, multisite reads/writes, import/export round trips and admin rendering, plus 11 checks on the admin form handlers. No PHP warnings.

**Added in 0.3.0** (for data set modules):
- Data sets can declare `rules`: plain-language rules shown in the dictionary.
- A `validate` callback, `( $merged, $clean, $current ) => field => message`, checks rules that involve several fields. It runs after the per-field checks.
- `list_columns` chooses the admin list's columns.
- The `teatatu_data_records_list_before` action adds tools above a data set's list.

## 13. Neighbourhoods and street rules (Phase 3, 0.3.0)

Moved from Teatatu Events §3.2b. The rules and boundaries are unchanged; what changes is where the data lives and how it is governed. Code: `includes/datasets/neighbourhoods.php`. Pure helpers shared with the build tool: `includes/address-core.php`. Data: `assets/data/`. Build tool: `tools/build-neighbourhood-streets.php`.

**`neighbourhood`** (reference data, post type `ttd_neighbourhood`):

| Field | Type | Rules |
|---|---|---|
| Name (`name`) | string | required, unique |
| Boundary key (`key`) | enum `matipo` · `beach` · `harbourview` | required, unique, never changes |
| Slug (`slug`) | string `[a-z0-9-]` | required, unique, not `outside` |
| Old slugs (`aliases`) | comma-separated slugs | old links keep working |
| Description (`description`) | text | |

Because the key is an enum and must be unique, there can only ever be three neighbourhoods; a fourth can't be added.

**`street_rule`** (reference data, post type `ttd_street_rule`):

| Field | Type | Rules |
|---|---|---|
| Street (`street`) | string | required; matched after folding macrons and expanding Rd/St/Ave… |
| Side (`side`) | enum `any` · `odd` · `even` | required |
| From number (`min`), To number (`max`) | integer | blank means open-ended; From ≤ To |
| Neighbourhood (`neighbourhood`) | reference → `neighbourhood` | either this… |
| Not on the peninsula (`outside`) | boolean | …or this, not both |
| Order (`position`) | integer | a street's rules are checked in this order; the first match wins |

There are 128 rules: 111 streets wholly in one neighbourhood, 5 boundary streets split by number range, and the Te Atatū South end of Te Atatū Road marked "not on the peninsula".

**Resolver.** `teatatu_data_resolve_neighbourhood( {number, street, suburb, lat, lng} )` returns `{key, slug, uuid, name, via, needs}`. `via` is `street` (the whole street is in one neighbourhood), `road` (placed by a number range) or `coords`. It tries, in order:
1. Another suburb → none.
2. The active street rules.
3. The coordinates against `assets/data/neighbourhoods.geojson`.
4. Otherwise none. `needs` is true when the address is on the peninsula.

Retired or proposed rules and neighbourhoods are ignored. A consumer's own manual override (on a venue or event) comes before all of this and stays in the consumer. The answer is computed from an index of active records, cached network-wide and rebuilt after any master-data change. `teatatu_data_neighbourhood_by_slug()` also matches old slugs. REST: `GET /teatatu-data/v1/neighbourhoods/resolve` (`read_teatatu_data`). No geocoding service is ever called.

**Seeding** runs once on the Master Site, at activation or on the next admin page load. It is recorded in the change log under the `migration` channel, and the network option `teatatu_data_nbhd_seeded` marks it done.
- **Neighbourhoods** that are missing by key are created. If Teatatu Events is installed, its renamed slugs, old slugs and term names are used.
- **Street rules** are loaded only if there are none at all. They come from Events' stored rules (`teatatu_events_nbhd_street_rules`), so on-screen edits made in Events carry over; otherwise from the bundled `neighbourhood-streets.json`.
- **Errors** are kept in `teatatu_data_nbhd_seed_errors` and shown on the Street rules tab.
- **"Add missing streets from the bundled list"** adds bundled rules only for streets that have no rules in any status. A street a steward retired doesn't come back.

**Admin.** The Street rules tab explains the resolution order and has the top-up button. The Neighbourhoods tab has "Test an address".

**What Phase 8 still has to do in Events:**
- Read through Teatatu Data, falling back to its own copy.
- Sync its `teatatu_events_neighbourhood` terms by `uuid`.
- Re-resolve venues and events when rules change. It can hook `teatatu_data_record_changed` for both data sets.
- Carry a slug rename made in Teatatu Data through to Events' terms, shortcodes and redirects, which Events' own slug-rename routine already does.

Until then, the two copies can drift apart if someone edits the rules in Events.

**Verified** on the Local multisite `teatatu-news-plugin`:
- Network activation seeded 3 neighbourhoods and Events' 128 rules with no errors.
- Teatatu Data and Events resolve all 15,852 test addresses identically. These cover every street × 22 house numbers (including the boundary cut-offs and the gaps) × 5 suburbs, plus a coordinate grid across the peninsula and beyond.
- Data-set rules, renames with old slugs, reverts, retiring, the top-up, REST, reads from site 2 and the admin screens: 47 checks in all.
- The Phase 2 suites still pass (88 + 11 checks).
- The build tool hasn't been run, because it needs the live Overpass API.

## 14. Venues (Phase 4, 0.4.0)

Moved from Teatatu Events §3.2 (the `teatatu_events_venue` taxonomy and its term meta). Code: `includes/datasets/venues.php`. The parser and match key live in `includes/address-core.php`.

**`venue`** (master data, post type `ttd_venue`):

| Field | Type | Rules / meaning |
|---|---|---|
| Location (`name`) | string | required |
| Also known as (`aliases`) | comma-separated | used for matching |
| Unit / level (`unit`), Street number (`number`), Street (`street`), Suburb (`suburb`), Town / city (`city`), Region (`region`) | string | number pattern `12`, `12A`, `75-79` |
| Postcode (`postcode`) | string | 4 digits |
| Country (`country`) | string | required, default `NZ`, upper-cased, 2 letters |
| Coordinates (`location`) | geo | never looked up automatically |
| Website (`website`), Map link (`map_url`), Default image (`image_url`) | url | images are links only |
| Neighbourhood override (`neighbourhood_override`) | reference → neighbourhood | |
| Neighbourhood (`neighbourhood`), Neighbourhood from (`neighbourhood_via`), Needs neighbourhood (`needs_neighbourhood`), Match key (`match_key`) | **derived** | worked out by the plugin |

Rules: a venue needs a suburb or a town/city, and a street number needs a street.

**Framework additions** (usable by any data set):
- Fields can be `derived`. A data set's `derive` callback works them out on every write, after validation, so they appear in the diff and the log. People, imports and agents can't set them. The form shows them read-only, exports include them, and imports skip them.
- Fields can have a `default` (applied when a record is created) and a `case` (`upper`/`lower`).
- `teatatu_data_rederive( $key )` works out every record's derived fields again, logging changes on the new `system` channel.
- The `teatatu_data_record_form_before` action adds helpers above an edit form.

**Neighbourhood.** The override if set, otherwise `teatatu_data_resolve_neighbourhood()` on the address. A change to any street rule or neighbourhood queues a one-off WP-Cron job (`teatatu_data_rederive_venues`, 30 s later) that works out every venue's neighbourhood again.

**API:**

| Function | Does |
|---|---|
| `teatatu_data_format_address( $parts, $style )` | The only place address fields are joined (`single_line`, `multi_line`) |
| `teatatu_data_venue_postal_address( $record )` | schema.org PostalAddress |
| `teatatu_data_venue_map_url( $record )` | The venue's map link, or a map search for its coordinates or address |
| `teatatu_data_match_venue( $location, $address, $lat, $lng )` | Matches by name, then alias, then address (number + street, and suburb, city or postcode), then coordinates within 50 m (`teatatu_data_venue_match_distance` filter). A deprecated venue with `replaced_by` resolves to its replacement |
| `teatatu_data_parse_address_text( $text )` | The free-text parser used by imports and "Paste address" |

REST, for any signed-in user with `read_teatatu_data`:
- `GET /venues/match?location=&address=&lat=&lng=` returns `{venue, parsed, neighbourhood}`.
- `GET /venues/parse?text=` returns the parsed address.

**Parser fixes compared with Events.** On every other test address the parser, formatter and match key give the same results as Events. Events would need these two fixes in Phase 8 to stay identical:
- "Unit 2, 15 Smith St", with a comma after the unit, was read as a place called "Unit 2". The unit now joins the number.
- "Level 1 22 Queen St" lost the word "Level" and was printed as "1/22 Queen St". A level, suite or shop now keeps its word ("Level 1, 22 Queen St"). A unit or flat is still just its number ("2/15").

**Bringing in Teatatu Events venues** ("Bring in venues from Teatatu Events" on the Venues tab, `import_teatatu_data`). It reads every site's `teatatu_events_venue` terms straight from the tables, so it works whether or not Events is active, and turns each term into a venue:
- The neighbourhood override slug maps to the matching neighbourhood.
- The source term is kept as `external_ids.events_s<site> = <term id>`, for Phase 8 to link back.

It then matches each one against existing venues and against those already gathered:
- **Already a venue here:** proposes adding the Events ID and any new name as an alias.
- **Duplicate across sites:** merged into one new venue with both IDs and the other spellings as aliases. Aliases that differ only by case or macrons are kept once.
- **Otherwise:** proposes a new venue.

Everything goes into one batch (`migration` channel) for a steward to approve. It is safe to run again: Events venues already linked come back as "already up to date", and ones waiting for review are skipped.

**Admin.** The Venues tab has the bring-in button and "Find a venue", which shows what a name, address or coordinates match and how. The edit form has "Paste address", which calls the parse route and fills the fields for checking.

**Verified** on the Local multisite: 57 venue checks. The bring-in turned the 7 Events venues on 2 sites into 5 venues: 2 cross-site duplicates were merged, the overrides carried over, re-runs proposed nothing new, and after approval each venue was linked to its Events terms. The checks also cover derivation and re-derivation after a street rule change, validation, formatting and parsing against Events, matching by name, alias, address and coordinates and through replacements, REST, CSV round trips and the admin screens. All earlier suites still pass (88 + 11 + 47). The Local site was left with the neighbourhoods seeded and no venues: the bring-in is for a steward to run and review.

## 15. Organisations (Phase 5, 0.5.0)

Replaces the `teatatu_events_source` and `teatatu_news_source` taxonomies (name, description, parent, `*_source_default_image`) with one shared list. Code: `includes/datasets/organisations.php`. The shared bring-in helpers are in `includes/bring-in.php`.

**`organisation`** (master data, post type `ttd_organisation`):

| Field | Type | Rules / meaning |
|---|---|---|
| Name (`name`) | string | required |
| Also known as (`aliases`) | comma-separated | used for matching |
| Type (`type`) | enum | community group, sports club, school, early childhood centre, church or faith group, marae, charity, business, business association, council or local board, government agency, news or media, listings site, other |
| Part of (`parent`) | reference → organisation | no loops, at any depth |
| Description (`description`) | text | |
| Website (`website`) | url | also used for matching |
| General email (`email`), General phone (`phone`) | email, phone | the organisation's public contacts only (§11 Q5) |
| Based at (`venue`) | reference → venue | |
| Default image (`image_url`) | url | link only; used for its events and news when they have none |

External IDs: `nzbn` (13 digits) and `charity` (CC followed by 5–6 digits), with their formats checked. The source terms are kept as `events_s<site>` / `news_s<site>`.

**Matching.** `teatatu_data_match_organisation( $name, $website )` matches by name or alias (ignoring case, accents and punctuation), then by website. Websites are compared without the scheme, `www.`, query or trailing slash, but with the path kept, so two groups on facebook.com don't look alike. A deprecated organisation with `replaced_by` resolves to its replacement. REST: `GET /organisations/match?name=&website=`.

**Bringing in sources** ("Bring in sources from Teatatu Events and Teatatu News" on the Organisations tab). Both plugins' source terms are read from every site's tables, then:
- **Already an organisation here:** proposes adding the source ID, any new name as an alias, and an image or description if the organisation has none.
- **Same source in several plugins or sites:** merged into one new organisation with all the IDs, combining the source plugins.
- **Otherwise:** proposes a new organisation.
- **Parents:** a parent that already exists is linked straight away. A parent that is new in the same batch is linked by the next run, after approval, because a proposal can't refer to a record that doesn't exist yet. The report says how many are waiting.

Like venues, everything is one batch of `migration` proposals. Running it again proposes nothing twice.

**Shared helpers** (`bring-in.php`): `teatatu_data_read_terms_everywhere( $taxonomy, $meta_prefix )`, `teatatu_data_merge_aliases()` and `teatatu_data_error_text()`. Venues now use them too. As part of that, an Events venue's own alias list is now split into separate aliases when merged; it used to be kept as one long alias.

**Verified** on the Local multisite with 38 checks:
- **Bring-in:** the real sources (1 in Events on site 1, 6 in News on site 2) became 7 organisations with their IDs and images. Re-runs, before and after approval, proposed nothing new.
- **Made-up sources:** the same source in both plugins merged into one record; existing records were linked; a parent was linked at once when it already existed, or on the second run when it was new.
- **Rules and matching:** NZBN and charity number formats, parent loops, and matching by name, alias, website and through replacements.
- **REST and the admin screens.**

All earlier suites still pass (88 + 11 + 47 + 57). The Local site was left with no organisations; the bring-in is for a steward to run.

## 16. Tags and categories (Phase 6, 0.6.0)

Controlled vocabularies, each its own scheme and its own data set, all built from one definition in `includes/datasets/vocabularies.php`:

| Data set | From | Shape |
|---|---|---|
| `event_tag` (Event tags) | `teatatu_events_tag` (+ `tag_color` meta) | flat, with a badge colour |
| `event_category` (Event categories) | `teatatu_events_category` | nested |
| `news_category` (News categories) | `teatatu_news_category` | nested |

Event and News categories stay separate schemes, as §5 decided. Merging them into one shared list would be a product decision for Phase 8, not a data one. A plugin can add a vocabulary on the `teatatu_data_vocabularies` filter (label, nesting, colour, source taxonomy and plugin).

**Fields:**

| Field | Type | Rules |
|---|---|---|
| Name (`name`) | string | required, unique within the vocabulary |
| Slug (`slug`) | `[a-z0-9-]` | required, unique within the vocabulary; what consumers use in URLs, filters and shortcodes |
| Synonyms (`synonyms`) | comma-separated | other words that mean this, for matching incoming text |
| Description (`description`) | text | |
| Parent (`parent`, nested vocabularies only) | reference to the same vocabulary | no loops, at any depth |
| Badge colour (`color`, tags only) | `#rrggbb` | lower-cased |

**Curated.** `teatatu_data_match_terms( $key, $values )` maps incoming words or comma lists to existing terms. It matches by name, by slug (with hyphens read as spaces) or by synonym, ignoring case, accents and punctuation. Unknown words are ignored, and nothing is ever created; this keeps Events' "imports only use tags that exist" rule and extends it to synonyms. A deprecated term resolves to its replacement, and a retired one never matches. REST: `GET /vocabularies/<key>/match?values=a,b`.

**Bringing terms in** ("Bring in from Teatatu Events / News" on each vocabulary's tab) works the same way as for organisations:
- **Already a term here** (same name, slug or synonym): proposes adding the source ID, the other spelling as a synonym, and a description or colour if the term has none.
- **Same term on several sites:** merged into one.
- **Parents:** linked straight away if the parent already exists, otherwise on the next run after approval.

Everything is one batch of `migration` proposals, and nothing is proposed twice.

**Verified** on the Local multisite with 36 checks:
- **Bring-in:** the real terms (category "Event" in Events on site 1; "RSS" and "Community News" under it in News on site 2) came through with their IDs and slugs. The parent was linked on the second run, and the third run had nothing to do.
- **Made-up tags:** the same slug on two sites merged, keeping the other spelling as a synonym; a synonym linked a tag that already existed; colours were filled in.
- **Rules:** unique names and slugs within a vocabulary (the same name is allowed in another one), slug and colour formats, parent loops, no parent from another vocabulary, and no parent on a flat tag list.
- **Matching:** by name, slug and synonym, ignoring accents; unknown words ignored; through replacements; never retired terms.
- **REST and the admin screens.**

All earlier suites still pass (88 + 11 + 47 + 57 + 38). The Local site was left with no tags or categories; the bring-ins are for a steward to run.

**What's next:**
- **Phase 7 (data quality):** quality checks, a quality screen, and match/merge across the data sets.
- **Phase 8:** connecting Teatatu Events and News, using the `events_s<site>` / `news_s<site>` external IDs that every bring-in has recorded.

## 17. Data quality, match and merge (Phase 7, 0.7.0)

Code: `includes/quality.php` (checks, scores, scans), `includes/merge.php`, `includes/admin-quality.php` (tab, merge screen, handlers). Data-set-specific checks live in each data set's module.

**Checks.** Only active records are checked, except that a deprecated record's replacement must itself be active.

| Dimension | Built in (every data set) | Data set specific |
|---|---|---|
| Validity | The record still passes today's schema and rules (catches rule changes after saving, and edits made outside the plugin) | — |
| Completeness | Fields marked `recommended` are filled in | Venue on the peninsula that no rule places ("needs neighbourhood") |
| Consistency | References point to records that exist and aren't retired; a reference to a replaced record offers **Point to the replacement**; a replaced record's replacement is active | Street rules that overlap on the same side and give different answers |
| Timeliness | Verified within the data set's review interval (never verified counts) | — |
| Uniqueness | No two active records share a duplicate key; by default the title, ignoring case, accents and punctuation | Venues: name or alias, number + street + suburb (or city), coordinates within about 10 m. Organisations: name or alias, website, NZBN, charity number. Tags and categories: any shared word (name, slug or synonym). Street rules: the same street, side and range |
| Accuracy | — | Venue coordinates outside New Zealand; coordinates on or off the peninsula when the suburb says otherwise |

Recommended fields:
- Venues: town or city, postcode and coordinates.
- Organisations: type and website.
- Neighbourhoods: description.

New registry properties: `recommended` (fields); `quality_checks`, `duplicate_keys`, `mergeable` and `merge_combine` (data sets).

**Scores.** A dimension's score is the percentage of active records with no open issue in that dimension. The overall score is the average of the dimension scores.

**Running the checks.** Results are kept in the Master Site option `teatatu_data_quality`. A daily WP-Cron job (`teatatu_data_quality_scan`) checks everything again. "Check now" does so at once, and every fix rechecks the data set it touched. Deactivating the plugin clears its scheduled jobs.

**Issues.** Each issue has a stable ID, built from the check, the records and the detail, so dismissing it lasts across checks. The **Data quality** tab shows the scores, then the open issues, filtered by data set, dimension, or open or dismissed. The fixes are:
- **Open** the record.
- **It is correct — mark verified** (timeliness). When the list is filtered to one data set's timeliness issues, a single button marks them all verified after asking for confirmation.
- **Point to the replacement** (a reference to a replaced record).
- **Compare and merge** (possible duplicates).
- **Dismiss** with a required reason, which records who and when. A dismissed issue can be reopened.

REST: `GET /quality?dataset=` (`read_teatatu_data`).

**Merge.** `teatatu_data_merge( $key, $keep, $merge, $choices, $reason )`. Every step goes through the write path, so it is validated and logged:
1. The merged record becomes `deprecated` with `replaced_by` set to the kept record. Matchers then send its names to the kept record.
2. The kept record takes the values the steward chose. `merge_combine` fields (aliases, synonyms) and external IDs are combined, the other name becomes an alias, and the newer verified date is kept. If this step fails, step 1 is undone.
3. Every reference to the merged record in any data set is pointed at the kept record, including `replaced_by` chains.
4. `teatatu_data_records_merged` fires, for Phase 8.

Two validation changes make merging work:
- A record merged into another hands over its unique values: the uniqueness check ignores deprecated records whose `replaced_by` is the record being saved, so the kept record can take over a slug.
- A record being deprecated or retired isn't held to its data set's rules that involve several fields.

Neighbourhoods are `mergeable => false`.

The merge screen (**Compare and merge**) shows the two records side by side. The steward picks which one to keep (the older one by default) and, where they differ, which value to use. It defaults to the kept record's value, or whichever is filled in.

**Verified** on the Local multisite with 45 checks:
- **Seeded data:** neighbourhoods clean; street rules with only "never verified" issues, and no overlaps or duplicates in Events' 128 rules.
- **Every dimension on test venues:** missing postcode, stale verification, the same address with and without a postcode, coordinates in Sydney, a pin off the peninsula, a venue that needs a neighbourhood, and a stored value made invalid behind the plugin's back. The scores per dimension came out as expected.
- **Fixes:** references to retired and replaced records, with the repoint applied; dismissing needs a reason and lasts across checks, and reopening works; mark verified, singly and in bulk.
- **Merges:** a venue merge with a chosen value, combined IDs, the other name kept as an alias, and a reference elsewhere repointed; a tag merge that hands over its slug; and the refused merges.
- **REST, the daily schedule, and the admin screens.**

All earlier suites still pass (88 + 11 + 47 + 57 + 38 + 36).

On the real Local data after restoring: neighbourhoods score 100%. The 128 street rules score 83%: every one is flagged "never verified", which a steward clears with the bulk button once they have checked them.

## 18. Connecting Teatatu Events and News (Phase 8, 0.8.0)

**Approach** (decided 2026-10-16): Teatatu Data **pushes** its records into Events' and News' own storage, so those plugins keep reading their own tables and their queries, archive URLs, shortcodes and REST are unchanged. This is the "local terms linked by uuid" model from §9 and §11 Q1. Code: `includes/connections.php` here, plus a small `includes/teatatu-data-bridge.php` in each consumer.

**What maps where:**

| Teatatu Data | Teatatu Events | Teatatu News |
|---|---|---|
| Neighbourhoods | the three `teatatu_events_neighbourhood` terms (linked by boundary key; name, description) and the network options `teatatu_events_nbhd_slugs` / `_slug_aliases` | — |
| Street rules | network option `teatatu_events_nbhd_street_rules` (Events' row format), with `teatatu_events_nbhd_rules_ver` bumped so every site re-resolves | — |
| Venues | `teatatu_events_venue` terms and their address, link, alias, image and override meta, then Events' own `teatatu_events_refresh_venue()` | — |
| Organisations | `teatatu_events_source` (+ default image) | `teatatu_news_source` (+ default image) |
| Event tags | `teatatu_events_tag` (+ colour) | — |
| Event categories | `teatatu_events_category` | — |
| News categories | — | `teatatu_news_category` |

**Switched on per list.** On the Connections tab (`manage_teatatu_data`), a steward ticks which lists Teatatu Data manages in each plugin. Nothing is managed until then, so you can bring the data in and approve it first. The setting is the network option `teatatu_data_managed`.

**Sync** (`teatatu_data_sync()`) runs on the Master Site and writes to every site where the plugin is active. It runs about 15 seconds after a managed record changes (the `teatatu_data_sync` WP-Cron job), when the setting is saved, and on "Sync now". Each site's terms go through these steps:
1. **Link.** A term is linked to its record by `teatatu_data_ref`; else by the `events_s<site>` / `news_s<site>` external ID the bring-ins recorded; else (active records only) by slug or name. Linking writes `teatatu_data_ref` on the term: this is the write-back.
2. **Create and update.** Missing active records become terms. Names, descriptions, slugs (vocabularies only, so venue and source URLs don't move) and meta are kept in step. Events' venue refresh runs only for venues that actually changed.
3. **Parents** are set from the record's parent.
4. **Merges.** A deprecated record with an active replacement has its posts moved to the replacement's term, and then its own term is deleted. Retired records keep their term, marked `teatatu_data_status = retired`.
5. **Report.** Terms that can't be linked are left exactly as they are and listed, and so are terms whose record no longer exists. The report is kept in `teatatu_data_sync_report` and shown on the Connections tab.

At network level, a neighbourhood slug change is passed to Events' own `teatatu_events_rename_neighbourhood_slug()` when it is loaded. That routine renames the slug everywhere and keeps the old one as an alias; otherwise the options are updated directly. Consumer taxonomies that aren't registered in the request (the plugin active on a site but not on the Master Site) are registered as a minimal stand-in so the writes work. The sync sets `teatatu_data_is_syncing()` while it writes.

**In Events and News, while a list is managed** (`teatatu_data_manages_taxonomy()`):
- New terms are refused on every path: `pre_insert_term`, so admin forms, term screens, REST, agents and imports. Each bridge only guards its own taxonomies.
- Editing and deleting terms are refused through `map_meta_cap` (`edit_term` / `delete_term` → `do_not_allow`).
- The plugin's own save and delete handlers are refused before they run.
- The tab shows a read-only list with "Open in Teatatu Data".
- The sync is exempt from all of these, including Events' own guard on curated lists.

**Fallback.** Without Teatatu Data, or with a list switched off, everything in Events and News works and is editable as before, using the last synced data. The `teatatu_data_ref` meta is harmless if left behind.

**Verified** on the Local multisite, with the repo versions of Events and News symlinked in, starting from a database backup. The run used 39 integration checks plus a separate fallback run.
- **Bring-in:** everything came in, and with nothing switched on Events and News stayed editable and no sync was queued.
- **First sync, site 1:** the 5 venues were linked to their existing terms (term IDs kept), and EventFinda was linked with the other 6 organisations created as Events sources.
- **First sync, site 2:** 2 venues were linked and 3 created, with the right addresses and neighbourhoods. The 6 News sources were linked and 1 created. The News categories were linked and their parent kept.
- **Neighbourhoods and rules:** neighbourhood terms were linked on both sites and the 128 street rules written, with no errors.
- **Second sync:** changed nothing.
- **Read-only:** creating terms (Events and News), editing and deleting through capabilities, REST create and edit, and the admin handlers were all refused; unrelated handlers were untouched.
- **Changes flowing through:** a rename reached both sites; a street rule change reached Events' resolver and back; a slug rename went through Events' routine with the old slug kept.
- **Merge:** the event moved to EventFinda's term and the duplicate term was removed.
- **Switching off and fallback:** switching a list off made it editable again with the data kept. With Teatatu Data deactivated, the Events lists were editable, the synced data stayed, the resolver used the synced rules, and the normal tabs were back.

All earlier suites still pass (88 + 11 + 47 + 57 + 38 + 36 + 45). The Local site was then restored from the backup taken before Phase 8. Nothing is managed there yet.
