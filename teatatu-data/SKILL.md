# Teatatu Data — Agent Skill

## Purpose

Lets an AI agent read Teatatu master and reference data (neighbourhoods, venues, organisations, tags) and **propose** changes for a human Data Steward to approve, through the WordPress REST API.

> **Status: 0.8.0.** The routes below work. Read the dictionary first to see which data sets exist.

When a list is managed by Teatatu Data (venues, sources, tags, categories, neighbourhoods), Teatatu Events and News refuse new or changed terms in it — including over their REST APIs. Propose the change here instead; it is copied to them once approved.

**You never apply changes.** Your role (`teatatu_data_agent`) can read and propose. It can't edit, approve or import. WordPress enforces this; it isn't a temporary restriction.

## Authentication

HTTP Basic Auth with a WordPress **Application Password** for a user with the **Data Agent** role: `Authorization: Basic base64(username:application_password)`. On multisite, use the **Master Site's** `https://<site>/wp-json/` base URL.

## Workflow

1. **Read the dictionary** — `GET /wp-json/teatatu-data/v1/dictionary` to learn every data set, its fields, types and allowed values.
2. **Search before proposing** — `GET /wp-json/teatatu-data/v1/datasets/<key>/records?search=…&status=all` to avoid duplicates. Refer to records by `uuid`.
3. **Propose** — `POST /wp-json/teatatu-data/v1/datasets/<key>/proposals` with a JSON body:

   ```json
   { "uuid": "<record uuid, omit for a new record>", "values": { "field": "value" }, "reason": "Why, and your source" }
   ```

   Send only the fields you want to change. `null` or `""` clears a field. A `400 teatatu_data_invalid` response lists each failing field in `data.fields`; fix those and resend. A `400 teatatu_data_no_change` means the record already says that.
4. **Check status** — `GET /wp-json/teatatu-data/v1/proposals?state=all` lists your own proposals and whether they were applied or rejected (with the steward's note).
5. **Venues** — before proposing a venue, `GET /wp-json/teatatu-data/v1/venues/match?location=<name>&address=<one line>` (and `lat`/`lng` if you have them). If `venue` comes back, use its `uuid`; propose a new venue only when nothing matches. `GET /venues/parse?text=` splits an address into fields for your proposal.
6. **Organisations** — for an organiser or news source, `GET /wp-json/teatatu-data/v1/organisations/match?name=<name>&website=<url>`. Use the returned `uuid`; propose a new organisation only when nothing matches. Only ever propose the organisation's general email and phone — never a person's name, email or mobile.
7. **Tags and categories** — map your words to existing terms with `GET /wp-json/teatatu-data/v1/vocabularies/<event_tag|event_category|news_category>/match?values=word,word`. Use only what comes back; unknown words are ignored. If a term is genuinely missing, propose it (name, slug, synonyms) with your reason — never assume it will be accepted.
8. **Neighbourhoods** — to place an address, `GET /wp-json/teatatu-data/v1/neighbourhoods/resolve?number=12&street=Te Atatu Road&suburb=Te Atatu Peninsula` (or `lat`/`lng`). Don't guess; if `needs` is true, say so in your reason.
9. **Data quality** — `GET /wp-json/teatatu-data/v1/quality?dataset=<key>` lists open issues. You can propose fixes for them (e.g. a missing postcode) through step 3, citing your source; you cannot dismiss issues or merge records.
10. **Stop.** A steward reviews it under **Teatatu Data > Proposed changes**. You cannot approve.
