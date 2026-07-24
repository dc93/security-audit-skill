# security-audit

A coding-agent skill that turns your agent into a security auditor. It orchestrates multiple parallel agents through a six-phase pipeline -- recon, hunting, validation, reporting, structured output, and independent verification -- to find exploitable vulnerabilities with real impact.

This is the skill that seeded Cloudflare's vulnerability discovery harness, described in [Build your own vulnerability harness](https://blog.cloudflare.com/build-your-own-vulnerability-harness). The harness grew into a multi-stage, fleet-wide system; this skill is the single-repo starting point it evolved from.

## What it does

The skill runs a structured audit in six phases:

1. **Recon** -- parallel research agents map the application's architecture, trust boundaries, and input surfaces. Produces `architecture.md`.
2. **Hunt** -- parallel general agents attack the codebase from different angles (injection, access control, business logic, cryptography, feature abuse, chained attacks, and a wildcard). Each agent can spawn sub-agents to dig deeper.
3. **Validate** -- separate agents try to *disprove* each finding. Adversarial review kills false positives.
4. **Report** -- produces `REPORT.md` (human-readable) and `FINDINGS-DETAIL.md` (detailed traces for MEDIUM+ findings).
5. **Structured output** -- writes `findings.json` conforming to `report-schema.json`, validated by `validate-findings.cjs`.
6. **Independent verification** -- fresh agents verify every factual claim in the structured output against the actual source code.

Multiple runs against the same repo are additive. Each run explores different code paths; the skill reads prior `findings.json` files to skip known issues and target gaps.

## Files

| File | Purpose |
|------|---------|
| `SKILL.md` | Setup, core principles, platform terminology, workflow overview, and audit anti-patterns |
| `RECONNAISSANCE.md` | Phase 1 reconnaissance prompts and synthesis instructions |
| `HUNTING.md` | Phase 2 orchestration, hunting methodology, and validation rules |
| `ATTACK-CLASSES.md` | Core, wildcard, and obvious-things attack prompts |
| `MEMORY-SAFETY-AND-BINARY.md` | Memory-safety, binary, and kernel hunting classes for native targets |
| `AI-AND-LLM.md` | Prompt-injection, agent/tool, and output-handling hunting classes for LLM-backed targets |
| `WEB-PROTOCOL-AND-AUTH.md` | HTTP request-framing, cache, and authentication-protocol hunting classes for HTTP-protocol and auth targets |
| `CLIENT-SIDE.md` | DOM-injection, messaging-trust, UI-redress, and prototype-pollution hunting classes for client-side/browser targets |
| `SERVER-SIDE-WEB-FRAMEWORK.md` | Deserialization, ORM-identifier, template-to-code, extension-as-code, and mass-assignment hunting classes for dynamic-language web frameworks (PHP forums/CMSes, Rails/Django/Node monoliths) |
| `IPS-COMMUNITY-NOTES.md` | Worked stack instantiation of `SERVER-SIDE-WEB-FRAMEWORK.md` for Invision Community / IPS (4.x sink/source map, grep set, canonical chains, v4↔v5 differential notes) |
| `VBULLETIN-NOTES.md` | Worked stack instantiation for vBulletin (4.x/5.x); built from disclosed CVEs and public research since vBulletin is closed source, flagged to verify against a licensed copy |
| `DIFFERENTIAL-AUDIT.md` | Differential hunting mode for when two versions of the same target are in scope (legacy line vs rewrite): regressions, un-backported fixes, moved trust boundaries, half-migrated code |
| `VALIDATION-AND-REPORTING.md` | Phases 3–6 validation, reporting, and verification |
| `report-schema.json` | JSON schema for `findings.json` (confirmed and rejected finding structures) |
| `validate-findings.cjs` | Zero-dependency Node.js validator that checks `findings.json` against the schema |

## Installation

Install the skill with the [Skills CLI](https://skills.sh):

```bash
npx skills add https://github.com/cloudflare/security-audit-skill \
  --skill security-audit
```

Use `--global` for a user-level installation:

```bash
npx skills add https://github.com/cloudflare/security-audit-skill \
  --skill security-audit \
  --global
```

Run `npx skills --help` for agent-selection and non-interactive options.

## Usage

Start your coding agent in (or pointed at) the codebase you want to audit, then ask it to do a security audit:

```
security audit this codebase
```

```
find security vulnerabilities in ./src
```

```
do a security review, output to ~/audits/my-project
```

The skill activates automatically when the request matches its trigger (security audit, find vulnerabilities, pen-test the code, etc.). It will ask for an output directory if you don't specify one, defaulting to `~/security-audit-skill/<repo-name>/run-<N>`.

## Requirements

- A coding agent with a model that supports tool use and parallel sub-agents
- Node.js (for `validate-findings.cjs` schema validation in Phase 5)

## Design principles

- **Only report what you can exploit.** Every finding needs a concrete attack scenario, not "an attacker could theoretically..."
- **Adversarial validation.** The agent that checks a finding is never the agent that found it.
- **Severity requires impact.** Likelihood x impact, not deviation from a checklist.
- **Defense-in-depth gaps are not vulnerabilities.** If Layer A prevents the attack, the absence of Layer B is a hardening note.
- **Multiple runs improve coverage.** Testing shows a single run finds roughly half the total vulnerabilities across multiple runs.

## Evaluation

`skills/security-audit/eval/` holds a small ground-truth corpus and a zero-dependency scorer (`score.cjs`) for measuring what a run actually catches — a regression net for changes to the methodology. The corpus pairs single-defect files (identifier SQLi, object injection, template-to-code RCE, mass assignment, loose-comparison auth bypass, LFI, SSRF) with deliberate safe look-alikes that measure false positives. Run the skill against `eval/corpus`, then `node eval/score.cjs <output-dir>/findings.json` reports recall and precision; `--min-recall`/`--max-fp` turn it into a pass/fail gate. See `eval/README.md`.

## Contact

Questions, feedback, or comparing notes on AI-driven security tooling: security-ai-research@cloudflare.com

## License

MIT -- see [LICENSE](LICENSE).
