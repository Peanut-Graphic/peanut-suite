# Firebase Ownership and End State

This document defines the production ownership boundary for Peanut Suite's shared Firebase backend. It is not authorization to authenticate, deploy, inspect Firestore or Storage, change Firebase configuration, or rotate credentials.

## Architectural role

The Firebase project declared by source is `peanut-suite`. This repository owns a shared backend for Peanut Account identity and cross-product Notebook, Booker, Festival, performer, venue, and booking data.

Current repository truth:

- `.firebaserc` names project `peanut-suite`.
- `firebase.json` declares Firestore rules/indexes, Storage rules, Functions codebase `peanut-api`, and a Hosting rewrite to function `api`.
- `functions/` contains the Node 20 Cloud Functions API.
- `firestore.rules`, `firestore.indexes.json`, and `storage.rules` are the reviewed policy sources.
- The direct Cloud Functions health endpoint has been observed healthy, but the deployed revision, live Node runtime, Firestore/Storage safeguards, backup/restore controls, and control-plane membership remain unverified.
- The default Hosting surface is declared in source but has not been verified as an active production endpoint. The direct Cloud Functions endpoint is the only currently observed health surface.
- Deployment is manual and has no CI gate, concurrency lock, exact-revision assertion, or tested rollback.

This project is separate from Peanut Festival's optional per-install Realtime Database and from the unspecified legacy Notebook Firestore project consumed by HULLABALOO's migration tooling.

## Ownership

| Concern | Accountable owner | Source of truth |
|---|---|---|
| Firebase project and billing | Peanut Graphic | Approved Firebase/Google Cloud account inventory |
| Functions API implementation and Node declaration | Peanut Graphic | `functions/` and `functions/package.json` |
| Firestore schema/access policy and indexes | Peanut Graphic | models, `firestore.rules`, and `firestore.indexes.json` |
| Storage access policy | Peanut Graphic | `storage.rules` |
| Authentication contract and caller identity | Peanut Graphic | Firebase Auth configuration plus `functions/src/utils/auth.ts` |
| Production data classification, retention, export, and restore | Peanut Graphic | Approved data runbook and recovery evidence |
| Backend deployment and rollback | Peanut Graphic | Future gated Firebase deployment contract; manual commands are not the target state |
| WordPress plugin package release | Peanut Graphic | Canonical signed plugin publisher, independent from Firebase promotion |

## End-state contract

The `peanut-suite` project remains the canonical shared Firebase backend. This repository is the source of truth for Functions, Firestore rules/indexes, and Storage rules. Console-only production edits are drift and must be reconciled to reviewed source.

The target deployment contract must:

1. Build and test the exact Functions lock and rules sources before credentials are available.
2. Name the exact Firebase project and reject aliases or an unexpected active project.
3. Separate Functions, Firestore rules/indexes, Storage rules, and Hosting into explicit promotion units.
4. Record the source SHA and deployed revision for each unit.
5. Require a current data export or equivalent tested recovery point before a schema/rules change.
6. Verify the direct API health surface and authorization behavior after promotion.
7. Provide a tested rollback for Functions and reviewed recovery actions for rules, indexes, and data.
8. Use concurrency protection so two production promotions cannot overlap.

WordPress package publication must not implicitly deploy Firebase. Firebase promotion must not implicitly package or release the WordPress plugin.

Until the Hosting decision is made and verified, consumers must use only the documented direct Cloud Functions endpoint. The declared Hosting rewrite must not be represented as healthy production. Either adopt it through separate approval and exact-revision evidence or remove it in a reviewed source change.

## Data and consumer inventory required

Before the production contract is considered complete, document without copying customer data:

- owning Google Cloud/Firebase organization, project administrators, billing owner, and break-glass owner;
- deployed Functions names, regions, Node runtimes, revisions, and consumers;
- Firestore collections, data classification, retention/deletion policy, index ownership, and export cadence;
- Storage buckets, object classes, lifecycle rules, and restore procedure;
- authentication providers, token/claim ownership, and service-account boundaries;
- last successful backup/export and restore rehearsal;
- whether the default Hosting surface is intentionally absent or an adopted production endpoint.

Unknown values stay `unverified`; a healthy public API does not prove the control plane, data safeguards, or rollback.

## Approval boundary

Source review, local tests, and documentation are safe. Firebase login, project or IAM inspection, Functions/rules/index/Hosting deployment, Firestore or Storage reads/writes, export/restore, credential or billing changes, and project deletion require fresh project-specific approval.
