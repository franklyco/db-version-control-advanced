# Local agent WordPress auth reference

**Purpose.** Give local agents (Claude Code, Codex, or any scripted probe) a documented, safe way to authenticate against this repo's LocalWP site (`dbvc-codexchanges.local`) when a test needs a real WordPress session — for example the D-049 real-browser QA gate (repeated `wp.media` open memory profiling, Media Manager assign/replace flows, real Media Library upload).

**This doc contains no credentials.** The raw values live outside the repo, in `~/.config/dbvc-local-agent.env` (chmod 600), or in your macOS Keychain, or in your password manager. Both `.gitignore` and this doc's contract keep the raw values out of the working tree.

**If you have questions about the credentials themselves, ask the human maintainer.** Agents must not paste the raw values into chat, into commits, into other docs, or into any REST/HTTP request body that gets logged. Use them only through the load recipe below (HTTP Basic Auth header or an authenticated browser cookie).

## Environment variables the load recipe expects

| Variable | Purpose |
|---|---|
| `DBVC_LOCAL_WP_URL` | Base URL of the LocalWP site, e.g. `https://dbvc-codexchanges.local` (no trailing slash). |
| `DBVC_LOCAL_WP_USER` | The WordPress admin username created for local agent use. |
| `DBVC_LOCAL_WP_APP_PASSWORD` | The **application password** (not the login password) generated for this local agent under `wp-admin → Users → Profile → Application Passwords`. Application passwords are the WordPress-blessed mechanism for programmatic access; they can be revoked independently of the account password and never work for the interactive login form. |

The account's regular login password is **not** used by the local agent workflow — it is only used by the human maintainer during interactive `wp-admin` login. Do not put it in the env file or anywhere an agent could read it.

## One-time setup (human maintainer runs this)

```bash
umask 077
mkdir -p ~/.config
touch ~/.config/dbvc-local-agent.env
chmod 600 ~/.config/dbvc-local-agent.env
open -e ~/.config/dbvc-local-agent.env   # or: $EDITOR ~/.config/dbvc-local-agent.env
```

Then paste the following template into the file and fill in the values from your password manager. Do not commit this file. Do not paste its contents into chat.

```
DBVC_LOCAL_WP_URL=https://dbvc-codexchanges.local
DBVC_LOCAL_WP_USER=
DBVC_LOCAL_WP_APP_PASSWORD=
```

The application password from `wp-admin` is displayed with spaces (a WordPress-imposed formatting nicety) — keep the spaces or strip them, both work with WordPress's Basic Auth parser.

**Always single-quote each value.** `source` reads the file as shell script, so unquoted values containing spaces, `()`, `#`, `$`, `!`, or other shell metacharacters cause parse errors or silent truncation. Defensive quoting also protects future values from the same class of bug:

```
DBVC_LOCAL_WP_URL='https://dbvc-codexchanges.local'
DBVC_LOCAL_WP_USER='agentadminuser1'
DBVC_LOCAL_WP_APP_PASSWORD='<paste-here>'
```

**Only the application password belongs in this file.** WordPress's REST API rejects the account login password for Basic Auth as of WP 5.6+ — it accepts only application passwords for programmatic access. Putting the account password here does nothing useful and adds a second copy on disk to worry about.

## Load recipe (agents source this before any authenticated call)

```bash
set -a
source ~/.config/dbvc-local-agent.env
set +a
```

`set -a` exports every variable defined by the sourced file into the process environment. After the load, `curl`, `wp-cli`, or a Node script can reach the site using the exported names — never inline.

## Authenticated REST call — copy-paste template

```bash
curl -sS \
  --user "$DBVC_LOCAL_WP_USER:$DBVC_LOCAL_WP_APP_PASSWORD" \
  "$DBVC_LOCAL_WP_URL/wp-json/wp/v2/users/me"
```

Expected shape: JSON with the acting user's `id`, `name`, `roles`, capabilities. This is the smoke test — if it returns 401 the credentials or LocalWP site are wrong; if it returns 200 the auth is healthy.

Every subsequent authenticated request follows the same pattern: `--user "$DBVC_LOCAL_WP_USER:$DBVC_LOCAL_WP_APP_PASSWORD"` on `curl`, or an `Authorization: Basic <base64(user:app_password)>` header in scripted callers. **Never inline the raw values on the command line** — the shell history stores them.

## Browser session (for real-Chrome / real-Safari QA gates)

For D-049 gates that need an actual `wp.media` frame in a real browser (repeated open memory profiling, upload-tab focus, native modal layering), the recipe is:

1. Human maintainer opens Chrome, navigates to `$DBVC_LOCAL_WP_URL/wp-admin`, logs in interactively with the **account login password** (not the application password), and leaves the tab open.
2. Human maintainer tells the agent the session is live.
3. Agent uses the `claude-in-chrome` MCP tools against that already-authenticated tab. No password prompt reaches the agent.

Application passwords do not authenticate the interactive login form; only the account login password does. This split is intentional — it keeps the interactive-login secret out of the automation lane.

## Rotation

- **Rotate the account login password** on any suspicion it was disclosed (paste into a chat that persists, screenshot, transcript log, etc.). WordPress will invalidate every application password on the account when the account password is reset via `wp-admin`; you'll need to regenerate any application passwords you rely on afterward.
- **Revoke a specific application password** under `wp-admin → Users → Profile → Application Passwords` without touching the account login password. This is the right move when only the app password needs to change (agent decommissioned, laptop lost, etc.).
- After any rotation, update `~/.config/dbvc-local-agent.env` with the new application password. No repo commit is needed — the env file lives outside the repo.

## What agents must not do

- Write the raw values from `~/.config/dbvc-local-agent.env` into any repo file (docs, tests, fixtures, comments).
- Echo the raw values in scripted output, log messages, or `git commit` messages.
- Paste the raw values back into chat, screenshots, or issue comments.
- Enter the account login password into any login form. Only the human maintainer does the interactive login.
- Use the application password to authenticate against a site other than `$DBVC_LOCAL_WP_URL`.
- Persist the values in any location outside the env file / password manager (no `.env` in the repo, no `.zshrc` inline, no export in a script committed to git).

## Related residual gates

- **D-049 real-browser / AT QA** — recorded in `docs/dropins/dbvc-visual-editor-brand-controls-guide/tracking/RISK-REGISTER.md` and the canonical Visual Editor handoff. The load recipe above unblocks the REST-side pieces; the browser-side pieces still need a human-driven interactive login as described.
- **R2-F browser QA** — same load recipe covers the REST-side pieces (assign/replace endpoints under `dbvc/v1/visual-editor/media-manager/…`).
