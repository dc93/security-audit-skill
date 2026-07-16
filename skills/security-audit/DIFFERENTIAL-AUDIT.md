# Differential Audit Mode

#### When to use this mode

Use this mode when **two versions of the same codebase are in scope at once**: two supported major lines (e.g. a 4.x and a 5.x), a legacy branch and its rewrite, a fork and its upstream, or a before/after around a large refactor. It is not a replacement for the six-phase workflow — it is an *additional* hunting axis that runs alongside Phase 2 and feeds the same Phase 3–6 validation. Auditing each version in isolation finds each version's bugs; auditing the *delta between them* finds a class the isolated audits miss: bugs the refactor introduced, fixes that didn't get carried across, and trust boundaries that quietly moved.

The delta is high-value precisely because it is where the code changed and the review attention didn't keep up. A rewrite re-implements a guard from memory and gets it subtly wrong. A fix lands in one line and never gets backported to the other. A check that lived in a controller in the old version moves into middleware in the new one — and one route forgets to opt in. These are the "sad path" and "mid-migration" states the hunting methodology already prizes, made concrete by having both sides to compare.

## Setup

Establish both trees and the mapping between them before hunting:
- **Obtain both versions** as separate working trees (two checkouts, two tags, or two directories). Record the exact versions/commits in `architecture.md`.
- **Establish the correspondence.** These are rarely a clean `git diff` — a rewrite renames files, moves namespaces, and restructures directories. Map subsystem-to-subsystem, not file-to-file: "the old `sources/Login/` maps to the new auth module", "the old ORM `where()` maps to the new query builder". Where a real commit range exists (same history), capture it; where it doesn't (independent trees), the map is the recon deliverable.
- **Pull the security-relevant changelog and fix history** if available. Public advisories, changelog entries mentioning "security"/"fixed", and reverted commits in either version's history point straight at the interesting deltas — and at fixes that may exist in only one line.

## What the delta reveals — hunt these classes

Run these as dedicated `general` agents, each given the *same subsystem from BOTH versions* in its prompt so it can compare directly:

- **Regressions and un-backported fixes.** A vulnerability fixed in one version but still present (or reintroduced) in the other. Take each known fix in the newer line and check whether the older line ever received it; take each hardening in the older line and check whether the rewrite preserved it. Reverted or commented-out security fixes in either history are direct leads.
- **Moved trust boundaries.** A validation, permission check, escaping call, or CSRF guard that changed *location* between versions — from controller to middleware, from write-time to read-time, from the app to the framework. Wherever a check moved, look for the call site that didn't move with it: the route that skips the new middleware, the writer that assumed the old write-time sanitization still runs.
- **Half-migrated and coexisting code.** A rewrite rarely lands atomically. Old and new code paths coexist; a legacy controller stays reachable behind a compat route; a deprecated API version lingers; a converter imports old-format data into the new schema without the new validation. Dead-but-reachable legacy code carries the old bugs into the new deployment.
- **Changed defaults, formats, and assumptions.** A default that flipped (a feature now on/off, a permission now looser), a serialization or token format that changed (old tokens/sessions/cache entries still accepted by a compat path), an escaping or hashing scheme swapped. The compatibility shim that accepts *both* formats is where the weaker one gets in.
- **New surface without the old guards.** Endpoints, parameters, tools, or import paths that exist only in the newer version and were built without a control the older version had learned to apply. Net-new code got the least adversarial review; diff the new-only routes against the mature version's guard set.

## Orchestration

Layer this onto the standard workflow:
1. **Phase 1 (recon), run per version.** Produce the subsystem correspondence map alongside the usual `architecture.md`. Note where the two versions diverge structurally — those divergences are the Phase 2 targets.
2. **Phase 2 (hunt): add differential agents.** Alongside the normal per-class hunters on each version, launch differential agents scoped to changed subsystems, each handed the corresponding code from *both* trees and told to find the delta bug (regression, moved-check gap, half-migration, changed default). Keep the standard hunters too — the differential axis complements them, it doesn't replace per-version depth.
3. **Phases 3–6 unchanged.** Validate, report, structure, and independently verify exactly as normal. In `findings.json` and `REPORT.md`, state which version(s) each finding affects and, for a regression, cite both the fixed side and the vulnerable side. A bug present in one version and fixed in the other is a *stronger* finding — you have the patch as proof of both the defect and its fix.

## Validation rules (apply before reporting ANY differential finding)

1. **Confirm the bug in the version you claim it affects.** "It's fixed in 5.x so 4.x must be vulnerable" is a hypothesis, not a finding — read the actual 4.x code path and confirm the defect is present and reachable there. The other version's patch is corroboration, never a substitute for tracing the claimed-vulnerable code.
2. **Prove reachability in each affected version separately.** Routing, defaults, and guards differ between versions; a sink reachable in one may be gated in the other. State the version each claim applies to and verify the entry point exists there.
3. **A refactor that changes code is not a bug by itself.** Only report a delta where the change (or the failure to change) produces a concrete, exploitable defect. "These differ" is an observation; "this route lost the auth check the other version has, and here is the request" is a finding.
4. **Return ONLY confirmed findings** naming the affected version(s), the delta (regression / moved boundary / half-migration / changed default / new-unguarded surface), and the concrete impact — or "No exploitable version-delta issues found" if that's honest.
