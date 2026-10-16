# Teatatu Data — Reusable AI Development Prompt

This file distills the design contract behind the Teatatu Data WordPress plugin into a prompt you can hand to an AI coding assistant for **any future change** — a new data set, a feature, a bug fix — so the result stays consistent with how the plugin was built.

## How to use this

1. Paste the **"Prompt template"** section below into a fresh AI session.
2. Fill in the `<describe the change>` blank with what you actually want.
3. Point the AI at the `teatatu-data/` folder and `SPEC.md` in this repo. It should read the relevant `includes/*.php` files before changing them, not regenerate the plugin from scratch.

---

## Prompt template

```
You are extending an existing WordPress plugin called "Teatatu Data" (folder: teatatu-data/, prefix: teatatu_data_). It is the central master and reference data plugin for the Teatatu plugins (Teatatu Events, Teatatu News). Read SPEC.md and the existing code before changing anything — do not regenerate files from scratch.

CHANGE REQUESTED: <describe the change>

Preserve these invariants unless the change explicitly asks to alter them:

1. Standard WordPress tables only. No custom database tables, ever.

2. Everything is prefixed teatatu_data_ — functions, hooks, meta keys, options, capabilities, REST namespace (teatatu-data/v1).

3. The registry is the single description of a data set. Every data set registers through the teatatu_data_datasets filter (includes/registry.php) with its label, definition, kind (master/reference), owner, steward, source, consumers and field schema. The data dictionary, validation, import/export, quality checks and REST read from the registry — never describe a field a second time anywhere else.

4. One validator for every write path. Admin forms, imports, REST, migrations and external connectors all save through the same validation against the registry schema. Nothing writes master data directly.

5. Stable identity. Every record has a uuid that never changes or gets reused and is the same on every site. Consumers store the uuid, never a site-specific term or post ID. Merged records are deprecated with replaced_by, not deleted.

6. Governance (DAMA-DMBOK):
   - Every change is logged field by field (who, when, why, channel).
   - teatatu_data_agent can read and propose only. It never gets edit_, approve_, import_ or manage_teatatu_data. Agent and import changes are proposals a steward approves. This is permanent.
   - Records follow the lifecycle proposed → active → deprecated → retired.

7. Multisite: master data lives on the Master Site (teatatu_data_get_master_site_id()) and every read from another site goes through teatatu_data_on_master(). Roles are provisioned per site via the activation hook's own $network_wide flag plus wp_initialize_site. Admin-post.php form handlers always post to the plain admin_url( 'admin-post.php' ) — never network_admin_url( 'admin-post.php' ), which does not exist in core.

7a. Nothing is public. Data set post types are registered public => false, publicly_queryable => false, show_in_rest => false. No shortcodes, no front-end output, no unauthenticated REST — every teatatu-data/v1 route requires read_teatatu_data. User data is described by structure only in the dictionary; never copy, display or edit user values — that stays in the standard WordPress Users screens. No schema may hold a named individual's personal details.

7b. Storage: one private custom post type per data set on the Master Site; lifecycle = custom post statuses. Events/News keep their own taxonomy terms linked to master records by the teatatu_data_ref term meta (uuid), synced from the Master Site.

7c. External connectors (OSM, LINZ, Charities Register, NZBN) only ever create proposed changes. Their API keys are network settings handled like the GitHub token (never echoed back, clearable, deleted on uninstall).

8. Consumers degrade gracefully. Teatatu Events and News must keep working when Teatatu Data is inactive: they check function_exists() and fall back to their own data.

9. Images are link-only (URLs stored and served as-is, never downloaded), as in Events and News.

10. Security baseline on every admin/REST path: nonces, the narrowest current_user_can() check, verify an ID belongs to our data set before acting on it, esc_* on output, sanitize_* on input.

11. Auto-updates stay GitHub-Releases-based (includes/updater.php), using only wp_remote_get() and core update filters.

11a. Neighbourhoods: exactly three, fixed by the boundary key enum (matipo/beach/harbourview); consumers store slugs/uuids, never keys. teatatu_data_resolve_neighbourhood() is the only resolver — never call a geocoding service. The bundled assets/data files only seed and top up; the database records are what is used. Data set modules live in includes/datasets/ and register on teatatu_data_datasets; multi-field rules go in the data set's `validate` callback and are described in its `rules`.

11b. Venues: teatatu_data_format_address() is the only place address fields are joined; teatatu_data_match_venue() is the only matcher; derived fields (neighbourhood, match key) are worked out by the data set's `derive` callback on every write and are never set directly. Coordinates are never geocoded.

11c. Organisations: public organisation contacts only — no field for a person's name, email or phone may be added. NZBN/charity numbers live in external_ids (nzbn, charity). Bring-ins from Events/News go through includes/bring-in.php and always end as one batch of proposals, never direct writes, and never propose the same source twice.

11d. Tags and categories: each vocabulary is its own data set, defined in includes/datasets/vocabularies.php (add new ones on teatatu_data_vocabularies, don't copy the code). teatatu_data_match_terms() is the only term matcher and never creates terms. Names and slugs are unique within a vocabulary.

11e. Data quality: checks are declared, not hard-coded in screens — built-ins in includes/quality.php, data-set-specific ones via `quality_checks`, `duplicate_keys` and `recommended` fields. Issue IDs must stay stable (check + records + detail) so dismissals last. Merges go only through teatatu_data_merge(), which uses the write path for every step and repoints every reference.

11f. Connections: Teatatu Data pushes into Events'/News' own storage (includes/connections.php); they never call into Teatatu Data except through their small bridge files (function_exists checks), so both keep working without it. Lists are only managed once a steward switches them on. The sync links before it creates, never deletes an unlinked term, and only deletes a term after moving its posts to a merged record's replacement.

12. Uninstall never deletes master data unless the "Delete all Teatatu Data master data" setting is on, and never touches Events or News data.

When you finish: update SPEC.md (and readme.txt) for anything the change decides or alters, bump the version in teatatu-data.php if releasing, and list any new options/meta in uninstall.php.
```
