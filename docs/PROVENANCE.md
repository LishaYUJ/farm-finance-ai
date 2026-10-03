# Project provenance and contribution boundaries

This document separates inherited work from my own work. The classifications
below are based on the Git history present in this repository; commit hashes are
included so the claims can be audited.

## 1. Competition organiser starter code

The original Figured challenge application and scenario were introduced by:

- `be3ccc2` — **The Figured Great Code Muster — challenge app**
- `c0868aa` — **Restructure README, add judging criteria, restrict API key to app use**

These commits are authored by Ivan Czar / Figured. They contain the Laravel and
Vue application shell, seeded fictional practice data, five manual workflows,
the original challenge brief and the initial AI proxy example. I do not claim
this foundation as personal work.

## 2. Team competition contributions

The team selected stock reconciliation and built the parser experience together.
The repository history attributes the following work to teammates:

- Carson / RiverMeteor6711: collapsible stock-class UI, parser loading and error
  feedback, and integration/merge work (`533f9bd`, `ff3027b`, merge commits).
- Jack Preston / jpreston05: paper-trail parser and parser UI (`eaf7266`,
  `17227f6`, `697ba16`).

Merge commits and duplicated branch commits remain in the history. They are
team work and are not presented as my individual implementation.

## 3. My competition contribution

My competition contribution is the AI stock reconciliation report, authored as
`LishaYUJ <lyuj137@aucklanduni.ac.nz>`:

- `cdd75c9` / equivalent branch commit `e771ec3` — **Add AI stock reconciliation
  report**

That work added:

- deterministic reconciliation preparation in
  `resources/js/stockReconciliationReport.js`;
- separation of included proposals, review proposals, unresolved records and
  already-keyed movements;
- the constrained report prompt that tells the model not to invent balancing
  entries or alter calculations;
- the reconciliation-report UI in `StockReconciliation.vue`;
- the original implementation and verification notes in `plan.md`.

The two hashes represent the same patch on different branch histories, not two
separate pieces of work.

## 4. Post-event personal improvements

Changes after the competition merge base `c46890d` are portfolio hardening by
Lisha Yuj, assisted by Codex:

- restored missing Vue report state declarations;
- changed already-keyed detection from `type + quantity` to `type + quantity +
  normalized source note`;
- introduced strict server-side validation of model JSON, including source-ID
  existence and cross-item uniqueness;
- added JavaScript and PHP regression tests;
- added a zero-cost Demo mode backed by a seeded-data recorded response and
  deterministic report renderer while preserving the Live Anthropic path;
- added a disabled-by-default generic AI proxy, task-specific stock endpoints,
  per-IP throttling, daily request budgets, input limits and output-token caps;
- replaced the event brief README with this portfolio-facing documentation.

These changes improve robustness and presentation; they should not be confused
with the time-boxed team result demonstrated at the event.

## Attribution method

Useful audit commands:

```bash
git log --all --format='%h  %an <%ae>  %s' --reverse
git show --stat <commit>
git shortlog -sne --all
```

Authorship metadata is evidence of who committed a patch, not a complete record
of discussion, pairing, review or design input. The narrative therefore stays
conservative and credits team work explicitly.
