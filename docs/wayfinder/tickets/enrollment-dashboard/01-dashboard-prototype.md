---
title: What should the enrollment dashboard look like?
label: wayfinder:prototype
status: closed
map: ../../maps/enrollment-dashboard.md
assignee: claude
blocked_by: []
created: 2026-07-31
resolved: 2026-08-02
---

## Question

Raise the fidelity of the "visual, filterable/groupable overview" destination into something concrete to react to. Build a rough prototype (via `/prototype`) of the enrollment dashboard covering:

- Layout: where it lives in wp-admin (new top-level page vs sub-page under "Inschrijvingen"), overall page structure.
- Visualization: how categorie/niveau/rooster breakdowns are shown — bar charts, stat tiles, a grouped/pivoted table, or a mix.
- Interaction: how filtering and grouping actually work (dropdowns, clickable chart segments, toggles between grouping dimensions).

Resolve by presenting the prototype to the user and iterating until there's a concrete direction to build against. The answer this ticket records is the agreed-on direction (with the prototype linked as an asset), not the final implementation.

## Answer

Resolved via grilling + one prototype iteration (confirmed without changes). Prototype asset: [prototype.html](prototype.html).

**Layout:** new sub-page under the existing "Inschrijvingen" top-level menu (`add_submenu_page()`), titled "Overzicht". The existing table page and its row-level actions (status update, delete, CSV export) are untouched.

**Visualization:** stat-tile + horizontal bar-list pattern, extending the existing plugin's visual language (same `#2d3a4a` brand color, card/tile styling as the current table page's totaal/nieuw/verwerkt/afgewezen tiles). No charting library — plain divs with width-percentage bars.

**Sections:**
- **Categorie** and **Niveau** are always visible — both dimensions are collected across all 5 categories.
- **Rooster** only renders once "Kinderen" is part of the active filter (rooster is blank/unused for the other 4 categories). Its bars read `count / 30` and get a red "Vol" badge at/over the 30-seat cap defined in the [signup-form-guardrails spec](../../../specs/signup-form-guardrails.md) — ties this view directly to that cap.

**Interaction:** click a bar to toggle it into an active filter; clicking an active bar again removes it. Filters **stack across dimensions** (AND logic) — e.g. Categorie=Kinderen + Niveau=Basisniveau narrows all sections (including Rooster) to that combination. Active filters render as removable chips with a "Wis alles" (clear all) action. A top stat tile shows the live filtered total.

**Not resolved here (left for the actual build):** exact charting/technical implementation is plain HTML/CSS as prototyped — no further technical decision needed. Real data wiring (PHP query grouping by categorie/niveau/rooster, respecting the same stacked-filter logic) is implementation work, not a decision.
