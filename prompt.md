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

8. Consumers degrade gracefully. Teatatu Events and News must keep working when Teatatu Data is inactive: they check function_exists() and fall back to their own data.

9. Images are link-only (URLs stored and served as-is, never downloaded), as in Events and News.

10. Security baseline on every admin/REST path: nonces, the narrowest current_user_can() check, verify an ID belongs to our data set before acting on it, esc_* on output, sanitize_* on input.

11. Auto-updates stay GitHub-Releases-based (includes/updater.php), using only wp_remote_get() and core update filters.

12. Uninstall never deletes master data unless the "Delete all Teatatu Data master data" setting is on, and never touches Events or News data.

When you finish: update SPEC.md (and readme.txt) for anything the change decides or alters, bump the version in teatatu-data.php if releasing, and list any new options/meta in uninstall.php.
```
