# Signup form guardrails

Resolved via grilling on 2026-07-31. No wayfinder map — fully specified, small enough for one implementation session.

**Status: built 2026-08-02, hardened 2026-08-02.** Capacity cap: new public `GET /wp-json/arrahma/v1/rooster-counts` endpoint (`arrahma_get_rooster_counts()`) + client-side fetch/apply in `index.html` (`fetchRoosterCounts()`/`applyRoosterCounts()`), disabling full radio cards/options with a "Vol" badge. Age guard: `ageFromDutchDate()` wired into single-mode step 2 validation (Kinderen category only) and bulk mode's `validateChildren()` (always). Verified in-browser: capacity disabling + Vol badge render correctly, age boundaries (6/11/12) compute correctly including same-day-birthday edge cases, non-Kinderen categories correctly skip the age check.

**Update:** the capacity cap's "client-side only" scope call was revisited the same day — real usage surfaced the exact race condition the original note flagged ("revisit if bypass turns out to matter in practice"), plus an unrelated bug where the generic card-click handler bypassed the native `disabled` state entirely (any radio card, not just rooster, could be force-selected via click regardless of its disabled attribute — fixed for all radio-card groups). **Capacity is now also server-side enforced**: both `arrahma_handle_submission()` and `arrahma_handle_bulk_submission()` re-check the DB count against `ARRAHMA_ROOSTER_CAP` immediately before insert (bulk mode pre-validates *all* requested slots across every child before inserting any of them, avoiding a half-inserted family). A full slot returns `WP_Error('rooster_full', ..., ['status' => 409, 'rooster' => $value])`, which the frontend detects and reacts to by clearing the stale selection, invalidating and refetching the cached counts, and navigating back to the Lesdagen/Kinderen step with an explanatory alert. The age guard remains client-side only, unchanged, per original scope.

**Not yet tested against a live WordPress instance** — no PHP interpreter available in this environment, so all PHP (including the new server-side capacity checks) was only verified by structural/static checks (brace balance, function cross-references) and by mocking the fetch layer in-browser to simulate the 409 response — not executed against a real WordPress + MySQL stack.

## Scope

Two independent guardrails on the enrollment form (`index.html` + `arrahma-inschrijvingen.php`):

1. **Capacity cap** — disable a Lesdagen/rooster slot once it has 30 enrollments.
2. **Age guard** — reject signups for children outside the 6–11 age range on the Kinderen category.

Both are **client-side only** (JS). No server-side (PHP) enforcement in this pass — a deliberate scope choice, not an oversight; revisit if bypass turns out to matter in practice.

## 1. Capacity cap

- **Cap:** 30 enrollments per `rooster` value (`za_zo_blok1`, `za_zo_blok2`, `za_zo_blok3`, `ma_wo`, `di_do`).
- **Counting rule:** count *all* rows with that rooster value, regardless of `status` (nieuw/verwerkt/afgewezen all count). No status filtering.
- **Scope:** applies everywhere the rooster field appears — single-mode step 4 "Lesdagen" (already Kinderen-only) and bulk mode's per-child rooster `<select>` (bulk mode is already Kinderen-only).
- **Data source:** needs a new **public GET REST endpoint** (e.g. `GET /wp-json/arrahma/v1/rooster-counts`) returning `{ rooster_value: count }` for all 5 slots. Mirrors the existing POST endpoint's `permission_callback => __return_true` (public, unauthenticated) since it's called from the embedded public form.
- **Fetch timing:** fetch once, when the category "Kinderen" is selected on step 1 (or when bulk mode is entered). Cache for the rest of that form session — no polling, no refetch on step re-entry.
- **UI when full:** the radio card / `<option>` stays **visible**, becomes disabled/unselectable, and gets a **"Vol"** (full) badge/label. Does not disappear from the list.

## 2. Age guard

- **Range:** reject if age < 6 or age > 11 (inclusive bounds: 6–11 allowed).
- **Reference date:** today's date (not school-year start). `age = floor(months between geboortedatum and today / 12)` or equivalent whole-years calculation.
- **Scope:**
  - Single mode: only enforced when `selectedCategory === 'kinderen'`. Other categories (tieners, zusters 18+, broeders 18+) get no age check, same as today.
  - Bulk mode: always enforced (bulk mode is Kinderen-only by construction already).
- **Where to hook in:** the existing `geboortedatum` validation in step 2 (single mode, already masked `dd-mm-jjjj` text input with `dutchDateToISO()` conversion) and the per-child `geboortedatum` field in bulk mode's child-card validation.

## Not in scope

- No cleanup/flagging of existing over-age enrollments already in the database — prevention only.
- No server-side (PHP) enforcement of either guard in this pass.
- No live/polling refresh of capacity counts.
