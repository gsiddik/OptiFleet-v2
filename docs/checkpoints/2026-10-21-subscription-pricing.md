# Subscription pricing and tenant access checkpoint

Baseline: main (3a80898fd0dcf9dc2e372a7e601e3cf50e20de8e).

Scope: add-on details/limit deletion; tenant assignment refresh and provisioning prerequisites; clipboard fallback; explicit effective pricing selection for FLAT and five per-unit types; subscription item snapshots and totals; exact-key Nexus limits on Fleet subscription.

USAGE and TIERED are deferred pending owner decision. Keep the existing requirement for one base plan; add-ons are additional subscription items. Do not merge without owner instruction.

Validation: frontend tests/build locally, backend regression tests through CI (PHP/Composer unavailable in this environment). Deployment script must preserve existing data and target main only after the PRs are merged.

Progress: first implementation checkpoint published. Nexus PR #16 and Fleet PR #39 are drafts while regression tests and review are in progress. Frontend targeted copy/add-on/pricing tests pass locally. Backend validation runs in GitHub Actions; no deployment or merge performed.
