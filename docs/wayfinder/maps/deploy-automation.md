---
title: Plugin deploy automation
label: wayfinder:map
status: open
created: 2026-08-08
---

## Destination

Replace the current manual deploy process (zip `arrahma-inschrijvingen.php` locally → log into wp-admin → upload via the plugin installer) with an automated one, so code changes made in this project reach the live WordPress site without that repeated manual step.

## Notes

- Constraint: no SFTP/SSH/hosting-panel access — only WordPress wp-admin login and a private GitHub repo.
- Domain: deploy/tooling workflow, not a plugin feature — doesn't touch `arrahma-inschrijvingen.php`'s behavior.

## Decisions so far

_(none yet — the destination itself, i.e. which deploy method, is the open question; see the ticket)_

## Not yet specified

_(none — the question is already sharp; see the open ticket)_

## Out of scope

- SFTP/SSH-based deploy scripts — ruled out, no host/SSH access available.
