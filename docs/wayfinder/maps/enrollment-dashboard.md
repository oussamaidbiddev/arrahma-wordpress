---
title: Enrollment dashboard
label: wayfinder:map
status: built — not yet smoke-tested
created: 2026-07-31
resolved: 2026-08-02
built: 2026-08-02
---

## Destination

A new wp-admin view, alongside the existing "Inschrijvingen" table, presenting enrollment counts groupable/filterable by **categorie**, **niveau**, and **rooster** in visual (chart/summary) form. The existing flat table stays as-is for row-level status/delete actions and CSV export.

## Notes

- Domain: WordPress admin (PHP), `arrahma-inschrijvingen.php`.
- Skills: `/prototype` for the first ticket (visual/behavioral design). `/grilling` if further decisions surface.
- Data source: existing `wp_arrahma_inschrijvingen` table — no schema changes anticipated, this is a read/reporting surface only.
- Standing preference: don't touch the existing table page's behavior (status update, delete, CSV export) — this is additive.

## Decisions so far

- [Dashboard dimensions](../tickets/enrollment-dashboard/) — group/filter by categorie + niveau + rooster (not status, betaalwijze, signup-date trend, or family/groep_id grouping — see Out of scope).
- [Replace vs supplement](../tickets/enrollment-dashboard/) — new admin page/tab alongside the existing table, not a replacement.
- [What should the enrollment dashboard look like?](../tickets/enrollment-dashboard/01-dashboard-prototype.md) — sub-page under "Inschrijvingen"; stat-tile + bar-list visuals matching existing brand styling; click-to-drill-down with stacking filter chips; Rooster section gated behind a Kinderen filter, showing X/30 with a "Vol" badge at the capacity cap. Prototype confirmed without changes. **Built 2026-08-02** as `arrahma_dashboard_page()` in `arrahma-inschrijvingen.php` — reuses the existing label-helper functions and `ARRAHMA_ROOSTER_CAP` constant as single sources of truth, embeds a PII-free `{categorie, niveau, rooster}` row dataset as JSON, and ports the prototype's exact filter/render logic. Not yet smoke-tested against a live WordPress instance (no PHP interpreter available in this environment).

## Not yet specified

_(none — the last open ticket resolved the remaining fog; see Decisions so far)_

## Out of scope

- Filtering/grouping by status or betaalwijze — considered and declined when the dimensions were chosen.
- Signup-date trend/timeline view — considered and declined.
- Family (groep_id) grouping view — considered and declined.
