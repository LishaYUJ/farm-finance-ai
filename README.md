# Farm Finance AI

An AI-assisted livestock reconciliation workflow for a fictional New Zealand
rural accounting practice. The application turns diary notes, sale dockets and
text messages into reviewable stock movements, calculates the reconciliation
deterministically, and uses an LLM only to explain the verified result.

This repository is my personal portfolio fork of a team project built during
the **Figured Great Code Muster**. It deliberately retains the event starter
application and the team's work so the full product context is reproducible.
See [Project provenance](docs/PROVENANCE.md) for an evidence-based breakdown of
starter code, team contributions, my competition work and post-event changes.

## Why this project

Livestock reconciliation looks simple on paper:

```text
opening + births + purchases - deaths - sales = closing
```

The difficult part is extracting trustworthy movements from inconsistent
records. The same transaction may appear twice, a later note may correct an
earlier one, or a record may use ambiguous language. This project keeps those
judgement calls visible instead of asking a model to silently force the numbers
to balance.

## Product flow

1. Load the seeded stock classes and raw paper trail.
2. Parse the evidence with either a saved demo response or the server-side
   Anthropic integration.
3. Validate every returned field against database truth.
4. Separate accepted movements from flagged and unresolved items.
5. Calculate closing stock in application code, never in the model.
6. Generate a plain-language report from the fixed calculations. Demo mode
   renders it deterministically; Live mode asks Anthropic to explain the same
   validated payload.

The wider starter application also includes bank coding, client email, monthly
reporting and invoice-entry screens. The portfolio case study focuses on stock
reconciliation.

## Engineering highlights

- **Human-in-the-loop AI:** duplicates, corrections and uncertain records are
  routed to review rather than silently included.
- **Defensive structured output:** Boolean, integer, confidence, enum and source
  record constraints are checked after every model response.
- **Deterministic accounting:** the LLM explains pre-calculated totals; it is not
  trusted with the ledger arithmetic.
- **Evidence-aware deduplication:** a movement is considered already keyed only
  when type, quantity and normalized source note match. Two legitimate sales of
  the same quantity remain separate.
- **Zero-cost public demo:** the default mode replays a recorded response based
  on the fictional seeded records, then runs it through the production
  validator. It never sends an external AI request.
- **Deployment safety:** Live AI is opt-in. Per-IP throttling, a daily request
  budget, prompt limits and an output-token cap protect the integration.
- **Automated tests:** PHP tests cover the trust boundary and endpoint safety;
  JavaScript tests cover proposal parsing, deduplication and reconciliation.

## Stack

- PHP 8.3 and Laravel 13
- Vue 3, Vite 8 and Tailwind CSS 4
- SQLite fixture data
- Anthropic Messages API through a server-side proxy
- PHPUnit 12 and the Node.js built-in test runner

## Local setup

Prerequisites: PHP 8.3+, Composer, Node 20.19+ and Yarn.

```bash
git clone https://github.com/LishaYUJ/farm-finance-ai.git
cd farm-finance-ai
composer run setup
composer run dev
```

Open <http://localhost:8000>. `composer run setup` installs dependencies,
creates `.env`, generates the application key, migrates and seeds SQLite, then
builds the frontend.

### Demo and Live modes

The checked-in default is a fully interactive, zero-cost Demo mode:

```dotenv
AI_DEMO_MODE=true
ANTHROPIC_API_KEY=
```

“Parse the paper trail” uses a recorded model response derived solely from the
fictional seeded stock data. The response still passes through
`StockProposalValidator`. “Generate reconciliation report” uses the same
client preparation and server validation as Live mode, then renders the
currently submitted figures deterministically. Demo responses are labelled in
both the API and UI and make no Anthropic HTTP requests.

The real Anthropic integration remains complete. To enable Live mode, set:

```dotenv
AI_DEMO_MODE=false
ANTHROPIC_API_KEY=your-own-key
```

Then clear Laravel's cached configuration:

```bash
php artisan config:clear
```

Both modes use the same proposal validator, deterministic reconciliation
calculation, task-specific routes, input limits and throttling. API responses
identify their source with `ai_mode=demo` or `ai_mode=live` plus non-sensitive
metadata.

The remaining controls can be tuned without code changes:

```dotenv
AI_REQUESTS_PER_MINUTE=5
AI_DAILY_REQUEST_LIMIT=100
AI_ALLOW_GENERIC_ENDPOINT=false
AI_MAX_PROMPT_CHARS=20000
AI_MAX_SYSTEM_CHARS=4000
AI_MAX_OUTPUT_TOKENS=1200
ANTHROPIC_MODEL=claude-sonnet-4-6
```

The stock workflow uses task-specific endpoints, so the generic `/api/ai`
proxy can remain disabled. Enable it only for isolated local development. Do
not expose a real key in client code or commit it to Git. For an internet
deployment, add authentication as well as the included cost controls.

## Tests and quality checks

```bash
composer test       # Laravel/PHPUnit tests
yarn test           # reconciliation JavaScript tests
yarn build          # production frontend build
./vendor/bin/pint --test
```

Key regression cases include string booleans (`"false"`), fractional animal
counts, unknown or reused source IDs, duplicate transaction quantities from
different dockets, fixture coverage, zero-network Demo behavior, dynamic Demo
reports, Live Anthropic requests and prompt-size limits.

## Repository and collaboration safety

`origin` is the writable personal fork:

```text
https://github.com/LishaYUJ/farm-finance-ai.git
```

If the team repository is added locally, name it `upstream` and disable pushes:

```bash
git remote add upstream <team-repository-url>
git remote set-url --push upstream DISABLED
```

Fetch from `upstream`; never push to it. This repository does not invent an
upstream URL because the authoritative team-repository URL is not encoded in
the current checkout.

## Current limitations

- Source identity for existing manual movements is represented by a normalized
  note rather than a dedicated relational link to `stock_records`.
- Authentication and persistent per-user budgets are required before exposing
  live AI to anonymous internet traffic.
- The seeded scenario is intentionally small and is not production farm data.

## Further work

- Store movement-to-source links in a join table and enforce uniqueness in the
  database.
- Add an approval action that persists selected proposals transactionally.
- Add browser-level accessibility and end-to-end tests.
