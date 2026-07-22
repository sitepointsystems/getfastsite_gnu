# Engine brand assets

Drop the FastSite brand files here and the engine UI (login page, dashboard
sidebar, and browser favicon) picks them up automatically — no code change,
graceful fallback to the text brand when absent.

Expected files (first match wins; `.png` or `.svg`):

- `fastsite-icon.png` — the square **F** mark. Used as the favicon and the
  small sidebar/login mark. A ~256×256 (or larger) transparent PNG is ideal;
  an `.svg` is even better (crisp at every size, tiny).
- `fastsite-logo.png` — the full **FastSite** wordmark (mark + text). Shown on
  the login/OAuth card and, on wide screens, the dashboard header.

The engine references them as `/brand/<file>?v=<mtime>` (mtime-busted so a
re-upload is never stuck behind the browser cache). These serve as ordinary
static files (dev: `public/router.php`; prod: nginx `try_files`).

This folder is for the ENGINE UI only — published sites get their favicon/logo
from their own shell (`shell/`), which agents design.
