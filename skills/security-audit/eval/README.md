# Eval harness

A small ground-truth corpus and a deterministic scorer for measuring what a
skill run actually catches. Use it as a **regression net**: when you change the
methodology (a companion file, a validation rule, a prompt), run the skill
against this corpus before and after and compare recall and false positives.
It answers the question the skill can't answer about itself — "did that change
help or hurt?" — with numbers instead of vibes.

## What's here

- `corpus/vuln/*.php` — small, self-contained files each carrying exactly one
  real, exploitable defect (identifier SQLi, object injection, template-to-code
  RCE, mass assignment, loose-comparison auth bypass, LFI, SSRF).
- `corpus/safe/*.php` — deliberate look-alikes of some vuln files that are
  **not** vulnerable (allowlisted sort + bound values, escaped output, a
  fillable allowlist). These measure false positives — the thing the skill's
  anti-patterns section cares most about.
- `manifest.json` — the ground truth. The corpus files carry **no labels**, so
  a run can't cheat by reading the answer; all expectations live here.
- `score.cjs` — zero-dependency scorer. Matches a run's `findings.json` against
  the manifest by file basename and reports TP / FN / FP, recall, and precision.

The corpus files are intentionally-vulnerable teaching fixtures (DVWA-style),
not deployable code and not weaponized exploits. They exist only to be found.

## Running it

Point the skill at the corpus directory as if it were a target, and have it
write `findings.json` as usual (Phase 5):

```
security audit ./corpus  →  <output-dir>/findings.json
```

Then score:

```
node score.cjs <output-dir>/findings.json
```

Optionally gate a run (for CI or a pre/post comparison):

```
node score.cjs findings.json --min-recall 85 --max-fp 0
```

`--min-recall` is a percentage; `--max-fp` is a count. The scorer exits non-zero
if either threshold is violated, and 0 otherwise.

## How scoring works (and its limits)

A corpus file is "flagged" if any **confirmed** finding references its basename
in a trace step, in `root_cause`, or in a remediation `file_name`. Vulnerable
file flagged = true positive; safe file flagged = false positive. Matching is by
**location**, not vulnerability class — the `keywords` in the manifest only drive
an informational `class✓` / `class?` note, so a run that finds the right file
for the wrong reason still scores the TP but is visibly flagged for review.

This is a coarse proxy, deliberately: it rewards finding the right code and
punishes flagging clean code, without trying to grade the prose. It does **not**
verify the exploit is correctly described — that's Phase 6's job, not the
scorer's. Treat the numbers as a regression signal between runs of the same
methodology, not as an absolute grade of audit quality. Expand the corpus over
time; a handful of files is a smoke test, not a benchmark.
