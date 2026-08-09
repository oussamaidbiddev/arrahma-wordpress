---
title: Create the Google Form for parent-meeting slot selection
label: wayfinder:task
status: closed
map: ../../maps/parent-meeting-emails.md
assignee: user (checklist handed off by claude)
blocked_by: []
created: 2026-07-31
resolved: 2026-08-02
---

## Question

No decision to make — this is manual setup work that blocks the send-logic ticket, since it can't be built without knowing the Form's field structure and prefill entry IDs.

Create a Google Form with:

1. Two short-answer fields used purely as **prefill targets**, in this order: **"Ouder e-mail"** and **"Kind(eren)"** (comma-separated names if a parent has multiple enrolled children). These will be pre-populated via a prefilled URL when a parent opens the link from the email — Google Forms cannot make a prefilled field truly read-only without a paid add-on, so leave them as normal editable short-answer fields.
2. The actual question: a **multiple-choice field** with the two parent-meeting date/time options (exact text TBD — placeholder is fine for now, e.g. "Optie 1" / "Optie 2").

Then, using Google Forms' **"Get pre-filled link"** feature (fill in sample values for the two short-answer fields, generate the link), extract the `entry.XXXXXXX` query-parameter IDs for **both** short-answer fields from the generated URL.

## Answer

Done. Form created with the three fields as specified (two required short-answer prefill fields + one required multiple-choice question with placeholder option text).

- **Form ID:** `1FAIpQLSflfMJHypxN8eccuTc5ScD-UWaqzG941QJ2rvTKD-iK5l-q2g`
- **Base viewform URL:** `https://docs.google.com/forms/d/e/1FAIpQLSflfMJHypxN8eccuTc5ScD-UWaqzG941QJ2rvTKD-iK5l-q2g/viewform`
- **Prefill entry IDs:**
  - `entry.1335557813` → **Ouder e-mail**
  - `entry.366086080` → **Kind(eren)**
- **Response spreadsheet:** `https://docs.google.com/spreadsheets/d/1553hcYjG08jdBlf6ohZ-sQL4mAPoiV8H_S2WrgvR2_U/edit?resourcekey#gid=2060157318`

Prefilled link shape for the next ticket to build per-parent:
`https://docs.google.com/forms/d/e/1FAIpQLSflfMJHypxN8eccuTc5ScD-UWaqzG941QJ2rvTKD-iK5l-q2g/viewform?usp=pp_url&entry.1335557813=<urlencoded parent email>&entry.366086080=<urlencoded comma-separated child names>`

Still TBD (not blocking): the actual meeting date/time text on the multiple-choice question — currently placeholder "Optie 1" / "Optie 2".
