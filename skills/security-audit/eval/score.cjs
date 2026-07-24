#!/usr/bin/env node

/**
 * Scores a skill run's findings.json against the eval manifest (ground truth).
 * Usage:
 *   node score.cjs <path-to-findings.json> [--manifest <path>] [--min-recall N] [--max-fp N]
 *
 * Metric (deterministic, file-based):
 *   A corpus file is "flagged" if any CONFIRMED finding references its basename
 *   in a trace step, in root_cause, or in a remediation code_change file_name.
 *   - vulnerable file flagged      => true positive (TP)
 *   - vulnerable file not flagged  => false negative (FN)
 *   - safe file flagged            => false positive (FP)
 *   - safe file not flagged        => true negative (TN)
 *
 * 'keywords' in the manifest drive only an informational class-match note; they
 * do not affect TP/FN/FP. Matching a vulnerable file by location is the metric —
 * whether the run also named the class right is reported separately.
 *
 * Zero dependencies. Exits 1 if --min-recall / --max-fp thresholds are violated,
 * else 0. Without thresholds it always exits 0 (it is a report, not a gate).
 */

const fs = require("fs");
const path = require("path");

function arg(flag, dflt) {
	const i = process.argv.indexOf(flag);
	return i !== -1 && process.argv[i + 1] ? process.argv[i + 1] : dflt;
}

const findingsPath = process.argv[2];
if (!findingsPath || findingsPath.startsWith("--")) {
	console.error("Usage: node score.cjs <findings.json> [--manifest <path>] [--min-recall N] [--max-fp N]");
	process.exit(1);
}
const manifestPath = arg("--manifest", path.join(__dirname, "manifest.json"));
const minRecall = arg("--min-recall", null);
const maxFp = arg("--max-fp", null);

function readJson(p, label) {
	try {
		return JSON.parse(fs.readFileSync(p, "utf8"));
	} catch (e) {
		console.error(`Failed to read ${label} (${p}): ${e.message}`);
		process.exit(1);
	}
}

const manifest = readJson(manifestPath, "manifest");
const corpus = manifest.corpus || manifest;
const findingsDoc = readJson(findingsPath, "findings");
const findings = Array.isArray(findingsDoc) ? findingsDoc : (findingsDoc.findings || []);

const confirmed = findings.filter((f) => f && f.verdict === "confirmed");

// Collect the set of file basenames each confirmed finding references, plus a
// blob of its text for informational keyword/class matching.
function base(p) {
	return typeof p === "string" ? p.split(/[\\/]/).pop() : null;
}

const findingRefs = confirmed.map((f) => {
	const files = new Set();
	if (Array.isArray(f.trace)) {
		for (const step of f.trace) if (step && step.file) files.add(base(step.file));
	}
	if (f.root_cause) for (const m of String(f.root_cause).matchAll(/([\w./-]+\.php)/g)) files.add(base(m[1]));
	if (f.remediation && Array.isArray(f.remediation.code_changes)) {
		for (const c of f.remediation.code_changes) if (c && c.file_name) files.add(base(c.file_name));
	}
	const text = JSON.stringify(f).toLowerCase();
	return { files, text, title: f.title || "(untitled)" };
});

let tp = 0, fn = 0, fp = 0, tn = 0;
const rows = [];
const matchedFindingIdx = new Set();

for (const entry of corpus) {
	const b = base(entry.file);
	const hits = findingRefs
		.map((r, i) => ({ r, i }))
		.filter(({ r }) => r.files.has(b));
	hits.forEach(({ i }) => matchedFindingIdx.add(i));
	const flagged = hits.length > 0;

	let status;
	if (entry.vulnerable && flagged) { status = "TP"; tp++; }
	else if (entry.vulnerable && !flagged) { status = "FN (MISS)"; fn++; }
	else if (!entry.vulnerable && flagged) { status = "FP"; fp++; }
	else { status = "TN"; tn++; }

	// Informational: did any matching finding mention a class keyword?
	let classNote = "";
	if (entry.vulnerable && flagged && Array.isArray(entry.keywords) && entry.keywords.length) {
		const anyKw = hits.some(({ r }) => entry.keywords.some((k) => r.text.includes(k.toLowerCase())));
		classNote = anyKw ? "class✓" : "class? (located but keyword not seen)";
	}
	rows.push({ status, file: entry.file, class: entry.class, classNote });
}

const unmatched = findingRefs
	.map((r, i) => ({ r, i }))
	.filter(({ i }) => !matchedFindingIdx.has(i))
	.map(({ r }) => r.title);

const recall = tp + fn ? tp / (tp + fn) : 1;
const precision = tp + fp ? tp / (tp + fp) : 1;

// --- Report ------------------------------------------------------------------
console.log(`Eval: ${findingsPath}`);
console.log(`Manifest: ${manifestPath}  (${corpus.length} corpus files, ${confirmed.length} confirmed findings)\n`);
for (const r of rows) {
	const pad = r.status.padEnd(9);
	console.log(`  ${pad} ${r.file}${r.classNote ? "  [" + r.classNote + "]" : ""}`);
}
console.log();
console.log(`  TP=${tp}  FN=${fn}  FP=${fp}  TN=${tn}`);
console.log(`  recall    = ${(recall * 100).toFixed(0)}%  (vulnerable files caught)`);
console.log(`  precision = ${(precision * 100).toFixed(0)}%  (of flagged corpus files that were truly vulnerable)`);
if (unmatched.length) {
	console.log(`\n  ${unmatched.length} confirmed finding(s) did not map to any corpus file (out-of-corpus or mislocated):`);
	for (const t of unmatched) console.log(`    - ${t}`);
}

// --- Threshold gate ----------------------------------------------------------
let failed = false;
if (minRecall !== null && recall < Number(minRecall) / (Number(minRecall) > 1 ? 100 : 1)) {
	console.error(`\nFAIL: recall ${(recall * 100).toFixed(0)}% below --min-recall ${minRecall}`);
	failed = true;
}
if (maxFp !== null && fp > Number(maxFp)) {
	console.error(`FAIL: ${fp} false positives exceed --max-fp ${maxFp}`);
	failed = true;
}
process.exit(failed ? 1 : 0);
