# Decisions

| ID | Decision | State |
|---|---|---|
| ADR-001 | Keep code in existing DBVC repository and distribution initially | Accepted 2026-09-16 |
| ADR-002 | Connector and hub are independently gated add-ons | Accepted |
| ADR-003 | DBVC owns execution; hub owns coordination; VE stays optional | Accepted |
| ADR-004 | Hub-mediated outbound HTTPS; no simultaneous peer mode | Accepted |
| ADR-005 | First pilot observes/reports; apply comes after baseline/preparation gates | Accepted |
| ADR-006 | Dedicated queue/receipt storage with atomic uniqueness/leases | Design requirement; actual schema pending local evidence |
| ADR-007 | Preserve lineage but rotate environment enrollment on clone/restore | Accepted |
| ADR-008 | No new frontend framework or external queue for M1/M2 | Accepted |
| ADR-009 | WP Application Password per enrolled environment preferred for evaluation | Proposed; verify current auth owners and constraints |
| ADR-010 | Single-site WordPress pilot; multisite unsupported until designed | Proposed MVP boundary |
| ADR-011 | Coalesced drift reporting is not a complete audit log | Accepted |
| ADR-012 | Sender origin never suppresses review without trusted receipt correlation | Accepted |

Record changes with rationale, source evidence and affected contracts. Do not silently promote proposed decisions into implemented capability claims.
