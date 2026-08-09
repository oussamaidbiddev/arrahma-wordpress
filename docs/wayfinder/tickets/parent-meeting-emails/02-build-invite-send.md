---
title: Build the admin-triggered parent-meeting invite send
label: wayfinder:task
status: closed
map: ../../maps/parent-meeting-emails.md
assignee: claude
blocked_by: [01-create-google-form.md]
created: 2026-07-31
resolved: 2026-08-02
---

## Question

Build the admin action that sends the parent-meeting invite. Blocked on [Create the Google Form](01-create-google-form.md) — needs the Form's `entry.XXXXXXX` field IDs to construct prefilled links.

Scope (per the map's resolved decisions):

1. Query all current enrollments from `wp_arrahma_inschrijvingen`, group by `email` (parent email — for bulk/family rows this is the shared contact email already stored per row).
2. For each unique email, build a prefilled Google Form URL: `https://docs.google.com/forms/d/e/<FORM_ID>/viewform?entry.<ID_1>=<urlencoded parent email>&entry.<ID_2>=<urlencoded comma-separated child names>`.
3. Add an admin-only trigger (e.g. a button on the "Inschrijvingen" page or a new sub-page) that first **previews** the deduplicated recipient list (email + child names + count) before sending — per the map's Send safeguards decision. Requires an explicit confirm step.
4. On confirm, send one email per parent via `wp_mail()`, reusing `arrahma_email_wrap()` for the branded shell (same pattern as `arrahma_send_confirmation()`), with new inner content: greeting, explanation of the two parent-meeting dates (placeholder text until real dates are supplied), and a button/link to that parent's prefilled Google Form URL.
5. No "already sent" tracking — re-running the trigger re-sends to everyone currently in the table (deliberate, per the map).

## Answer

Built directly in `arrahma-inschrijvingen.php` (v1.4.0 → v1.5.0, no schema change):

- **Trigger location:** new wp-admin sub-page **"Ouderavond"** under the existing "Inschrijvingen" menu (`add_submenu_page()`, slug `arrahma-ouderavond`, capability `manage_options`), rendered by `arrahma_ouderavond_page()`.
- **Grouping:** `arrahma_ouderavond_recipients()` — `SELECT voornaam, achternaam, email FROM wp_arrahma_inschrijvingen WHERE email != ''`, grouped in PHP by lower-cased/trimmed email into `[ email => { email, names[] } ]`.
- **Prefill link:** `arrahma_ouderavond_prefill_url()` builds `https://docs.google.com/forms/d/e/<FORM_ID>/viewform?usp=pp_url&entry.1335557813=<rawurlencoded email>&entry.366086080=<rawurlencoded comma-separated names>`, using the real Form ID and entry IDs recorded in [ticket 01](01-create-google-form.md).
- **Preview + confirm:** the page always renders a table of the deduplicated recipients (email, child names, count) with a total, and a nonce-protected `<form method="post">` with a JS `confirm()` dialog before submission — matches the map's Send safeguards decision.
- **Send:** on confirmed POST, loops recipients and calls `arrahma_send_ouderavond_email()` per parent, which reuses `arrahma_email_wrap()` for the branded shell (same pattern as `arrahma_send_confirmation()`) with new content: a generic greeting naming the enrolled child(ren) (no reliable parent first-name exists in the schema for non-bulk rows, so this was used instead of "Beste [naam]"), explanation text, and a button linking to that parent's prefilled Form URL. Meeting date/time text stays inside the Google Form's own multiple-choice question (still placeholder "Optie 1"/"Optie 2" per ticket 01 — not blocking).
- **No dedup tracking**, as decided — re-visiting the page and re-confirming re-sends to everyone currently in the table.

**Testing:** verified via static structural checks only (brace balance 74/74, 25 functions, no typos across new function/constant names) — no PHP interpreter or WordPress instance available in this environment to execute the code or send a real test email. **This should be smoke-tested on staging before relying on it** — specifically: load the "Ouderavond" page, confirm the preview table looks right, send to a test account, and open the resulting link to confirm the two fields on the real Google Form arrive pre-filled correctly.
