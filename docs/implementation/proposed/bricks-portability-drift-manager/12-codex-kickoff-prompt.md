# 12 · Codex Kickoff Prompt

Use this as the initial implementation prompt for Codex.

---

Open and follow the repository's main agent instructions first (`AGENTS.md` if present), then inspect the DBVC plugin structure, especially the Bricks add-on, admin architecture, storage helpers, logging utilities, backup/history patterns, REST patterns, and any existing drift/governance concepts.

Goal: implement a new lightweight DBVC Bricks add-on feature named **Bricks Portability & Drift Manager**.

Primary outcome:
- export selected Bricks settings domains from Site A into a portable zip
- upload that zip on Site B
- compare against Site B's current Bricks settings
- surface drift in a bulk-friendly review workbench
- let the user approve add/replace/keep/skip decisions
- backup touched options before apply
- apply selected changes safely
- support rollback

Use the docs in this folder as the source of truth for product and architecture direction.

Required engineering constraints:
1. Keep logic modular. Do not build this in one monolithic file.
2. Centralize domain definitions in a registry.
3. Normalize before compare.
4. Compare by domain and object identity rules, not just raw JSON equality.
5. Apply by rebuilding domain payloads in memory, then writing updated options once.
6. Backup only affected option names before apply.
7. No destructive delete sync in MVP.
8. Expose a usable workbench table, not card-per-object UI.

Initial MVP domains:
- Bricks Settings
- Color Palettes
- Global Classes
- Global CSS Variables
- Pseudo Classes
- Theme Styles
- Components
- Breakpoints (only if canonical storage is safely verified)

Likely raw option names involved:
- bricks_global_settings
- bricks_color_palette
- bricks_global_classes
- bricks_global_classes_categories
- bricks_global_pseudo_classes
- bricks_theme_styles
- bricks_global_variables
- bricks_global_variables_categories
- bricks_components
- plus verified canonical breakpoint storage

Ignore or treat as non-canonical metadata unless needed for backup/debug:
- bricks_panel_width
- bricks_remote_templates
- bricks_breakpoints_last_generated
- bricks_global_classes_changes
- bricks_global_classes_locked
- bricks_global_classes_timestamp
- bricks_global_classes_user
- bricks_global_classes_trash
- bricks_pinned_elements

Deliverables:
- module/classes for registry, export, import, normalize, diff, apply, backup, rollback, job history
- admin UI screen inside DBVC Bricks
- REST endpoints if appropriate
- internal docs or inline docs where useful
- migration/install routine for any custom tables used
- initial QA notes / test checklist

Work in phases:
1. inspect current DBVC architecture
2. create registry and package spec implementation
3. build export/import compare engine
4. build review workbench
5. add backup/apply/rollback
6. refine risk handling and history

Before writing code, produce a concise implementation checklist against the real codebase so we can verify naming, screen placement, and reuse opportunities.
