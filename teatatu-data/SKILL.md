# Teatatu Data — Agent Skill

## Purpose

Lets an AI agent read Teatatu master and reference data (neighbourhoods, venues, organisations, tags) and **propose** changes for a human Data Steward to approve, through the WordPress REST API.

> **Status: 0.1.0 scaffold.** The REST routes below are planned for Phase 2 (see `SPEC.md`). Only the role exists so far.

**You never apply changes.** Your role (`teatatu_data_agent`) can read and propose. It can't edit, approve or import. WordPress enforces this; it isn't a temporary restriction.

## Authentication

HTTP Basic Auth with a WordPress **Application Password** for a user with the **Data Agent** role: `Authorization: Basic base64(username:application_password)`. On multisite, use the **Master Site's** `https://<site>/wp-json/` base URL.

## Workflow (planned)

1. **Read the dictionary** — `GET /wp-json/teatatu-data/v1/dictionary` to learn every data set, its fields, types and allowed values.
2. **Search before proposing** — `GET /wp-json/teatatu-data/v1/<dataset>?search=…` to avoid duplicates. Refer to records by `uuid`.
3. **Propose** — `POST /wp-json/teatatu-data/v1/<dataset>/proposals` with the new record or the changed fields and a `reason`. A response with validation errors lists each failing field; fix and resend.
4. **Stop.** A steward reviews it under **Teatatu Data > Proposed changes**.
