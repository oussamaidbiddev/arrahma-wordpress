---
title: Parent meeting email campaign
label: wayfinder:map
status: fully specified — built, not yet smoke-tested
created: 2026-07-31
resolved: 2026-08-02
---

## Destination

An admin-triggered, one-time bulk email — reusing the existing branded template shell (`arrahma_email_wrap()`) — sent to every currently-enrolled parent (deduplicated by email, one email covering all their enrolled children), containing a personalized Google Form link pre-filled with parent email + child name(s), so meeting-slot responses in the Form can be correlated back to the enrollment(s) without manual lookup.

## Notes

- Domain: WordPress admin (PHP), `arrahma-inschrijvingen.php`. External dependency: Google Forms (prefilled-URL feature).
- Skills: `/grilling` if further decisions surface; this is mostly Task-shaped work once the Google Form exists.
- Standing preference: reuse the existing email template shell and `wp_mail()` pattern already used by `arrahma_send_confirmation()` / `arrahma_send_group_confirmation()` — do not build a new email design system for this.
- The actual meeting date/time text is content to fill in later, not a decision blocking this map.

## Decisions so far

- [Recipients & trigger](../tickets/parent-meeting-emails/) — all current enrollments, sent via a manual admin action (no automatic trigger tied to signup).
- [Per-child or per-parent](../tickets/parent-meeting-emails/) — one email per unique parent email address, covering all their enrolled children (not one email per enrollment row).
- [Metadata carrier](../tickets/parent-meeting-emails/) — two prefilled short-answer fields on the Google Form ("Ouder e-mail", "Kind(eren)"), not a single opaque ID.
- [Send safeguards](../tickets/parent-meeting-emails/) — preview the deduplicated recipient list before sending. No "already sent" dedup-tracking (re-running the trigger re-emails everyone) — a deliberate choice, not an oversight.
- [Create the Google Form for parent-meeting slot selection](../tickets/parent-meeting-emails/01-create-google-form.md) — Form built, field IDs known (`entry.1335557813`=Ouder e-mail, `entry.366086080`=Kind(eren)), response spreadsheet linked. Unblocks the send-logic ticket.
- [Build the admin-triggered parent-meeting invite send](../tickets/parent-meeting-emails/02-build-invite-send.md) — implemented in `arrahma-inschrijvingen.php` v1.5.0: new "Ouderavond" sub-page, grouped-by-email preview + confirm, sends via `arrahma_send_ouderavond_email()` reusing the branded template. Not yet smoke-tested against a live WordPress instance — do that before relying on it.

## Not yet specified

_(none — both tickets resolved; see Decisions so far)_

## Out of scope

- Automatic dedup-tracking to prevent re-sending on a second trigger — considered and declined (see Send safeguards).
