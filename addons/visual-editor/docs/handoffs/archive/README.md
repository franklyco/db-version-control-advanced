# Archived handoff / resume prompts

These files drove specific slices that have since shipped and been fully
documented in their release notes + EVIDENCE-LOG entries. They are kept here
for historical continuity (a curious reader can trace how a slice was scoped
and resumed across sessions), but they are **not authoritative** — do not
copy any of their fenced-block resume prompts into a new session, and do
not treat their "next steps" bullets as current work.

For the current boundary + fresh resume prompt, read:

- `../DBVC_VISUAL_EDITOR_HANDOFF.md` (current authoritative handoff).
- `../DBVC_R4C_RESUME_PROMPT.md` (fresh copy-paste resume for the next
  session, when one exists).

## Files

| File | Slice it drove | Shipped | Where its checkpoint lives now |
|---|---|---|---|
| `DBVC_VISUAL_EDITOR_HANDOFF_2026_05_24.md` | Original Visual Editor R0/R1 planning snapshot | 2026-05-24+ | `../DBVC_VISUAL_EDITOR_HANDOFF.md` (rolling successor); R1/R2 release docs |
| `DBVC_MEDIA_MANAGER_RESUME_PROMPT.md` | Frontend Media Manager R1/R2 resume | R1 + R2 fully shipped | `docs/dropins/.../releases/R1-...`, `R2-...`, `R2F/G/H-...`, `MEDIA-MANAGER-RELEASE-NOTES-AND-ROLLBACK.md` |
| `SESSION-RESUME-R2H-2026-08-19.md` | Persistent Media Index (R2-H) Phase 1 resume | 2026-08-19 (Slices 1–5) | `releases/R2H-PERSISTENT-MEDIA-INDEX-PHASE-1.md`; E-073..E-082 |
| `DBVC_R3_RESUME_PROMPT.md` | Registry-Backed Brand Control Center (R3-A → R3-D) | 2026-08-23..29; core ship-ready | `releases/R3-REGISTRY-BACKED-BRAND-CONTROL-CENTER.md`; `BRAND-CONTROL-CENTER-RELEASE-NOTES-AND-ROLLBACK.md`; E-088..E-099 |

## Restoring

If you ever need to reference the operational shape of a past slice's
resume prompt (green baselines, read order, constraints), open the file in
place — nothing depends on its path. If you decide these are permanent
dead weight, `rm -rf` this whole `archive/` folder; nothing links into it.
