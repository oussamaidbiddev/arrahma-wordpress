---
title: Choose a plugin deploy method
label: wayfinder:task
status: open
map: ../../maps/deploy-automation.md
assignee: null
blocked_by: []
created: 2026-08-08
---

## Question

Which deploy method to set up, replacing the manual zip-and-upload-via-wp-admin flow? **Parked on 2026-08-08 — no decision made yet, revisit whenever there's appetite.**

**Constraints:** WordPress wp-admin access only — no SFTP, no SSH, no hosting control panel. The project is tracked in a **private** GitHub repository.

**Options researched (2026-08-08):**

1. **Git Updater** (`afragen/git-updater`, actively maintained — 3.3k★, 129 releases, latest `13.0.1` released 2026-06-05, last code push 2026-07-05). Installs once via wp-admin "Upload Plugin," then deploys are one-click ("Pull") or fully automatic on every push, using the plugin's own `GitHub Plugin URI:` header convention to associate the installed plugin with the repo. Public repos work free; **private repos need authenticated GitHub API calls, which require a paid license: US$19.99/year, unlimited sites**, with a 14-day free trial. Price confirmed directly from https://git-updater.com/store/ on 2026-08-08.
2. **Make the repo public + use the free "GitHub Updater" plugin.** Sidesteps the authentication requirement entirely, since public repos don't need authenticated API calls. Checked: `arrahma-inschrijvingen.php` contains no actual secrets — `ARRAHMA_OUDERAVOND_FORM_ID` and the two entry IDs are already effectively public (visible in the prefilled Google Form links emailed to parents). So going public is low-risk *content-wise*, but it's still a deliberate, permanent visibility change to the repo — needs an explicit decision, not a default.
3. **DIY self-hosted updater.** No third-party plugin — hook WordPress's native update-check filters (`pre_set_site_transient_update_plugins` / `plugins_api`) against a small JSON manifest + a downloadable zip, per the classic self-hosted-updates pattern (see https://anchor.host/using-github-to-self-host-updates-for-wordpress-plugins/, which references Misha Rudrastyh's original guide). Zero recurring cost, repo stays private. Needs: ~100-150 lines of custom PHP in the plugin, plus a way to authenticate the zip download from a private repo (e.g. a GitHub Action that builds a release on push + a token-authenticated `wp_remote_get()` fetch). Real code to write and maintain going forward — no third party to lean on if it breaks.
4. **Status quo** — keep manually zipping and uploading via wp-admin. Free, zero setup, but is the exact friction this ticket exists to remove.

**Where the conversation left off:** leaning toward option 1 (Git Updater) given $19.99/year is cheap against the ongoing maintenance cost of the DIY route, and it's the only option that's both actively maintained *and* keeps the repo private without custom code. But not committed — explicitly parked before starting the free trial.

## Answer

_(not yet resolved)_
