# cla/generate-audit-report

A Laravel package that runs a multi-step AI audit workflow against any Eloquent model and its uploaded documents, then produces a scored compliance report.

## Requirements

- PHP 8.2+
- Laravel 11 or 12
- [`ayaashraf/laravel-rag`](https://github.com/ayaashraf-cla/laravelRag) — document storage, text extraction, and vector search
- PostgreSQL + pgvector extension (for vector embeddings)
- A Google Gemini API key (or any provider supported by `laravel/ai`)

---

## Quick Start

```php
use Cla\GenerateAuditReport\Facades\GenerateAuditReport;

$session = GenerateAuditReport::for($contract)
    ->documents($contract->documents()->where('status', 'processed')->get())
    ->prompt("Evaluate this contract for regulatory compliance.\n\nFocus areas:\n- Data handling clauses\n- Liability terms\n- Termination conditions")
    ->language('en')
    ->initiatedBy(auth()->id())
    ->generate();

// $session->id is available immediately
// the report is generated asynchronously on the queue
```

---

## Installation Overview

1. [Install and configure `ayaashraf/laravel-rag`](#step-1--install-ayaashraflaravel-rag)
2. [Add required `.env` variables](#step-2--environment-variables)
3. [Install `cla/generate-audit-report`](#step-3--install-clagenerateauditreport)
4. [Run migrations and publish config](#step-4--run-migrations--publish-config)
5. [Start the queue worker](#step-5--start-the-queue-worker)
6. [Extend `AuditSession` with your app's relationships](#step-6--extend-auditsession)

---

## Step 1 — Install `ayaashraf/laravel-rag`

### Add the VCS repository

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/ayaashraf-cla/laravelRag"
        }
    ],
    "require": {
        "ayaashraf/laravel-rag": "dev-main"
    },
    "minimum-stability": "dev",
    "prefer-stable": true
}
```

```bash
composer update ayaashraf/laravel-rag
```

### Dual-database architecture

The RAG package uses two database connections:

| Connection | Purpose | Engine |
|------------|---------|--------|
| Default (`mysql`) | `documents` table — file metadata | MySQL / MariaDB |
| `pgsql_vector` | `document_chunks` table — vector embeddings | PostgreSQL + pgvector |

Add the second connection in `config/database.php`:

```php
'pgsql_vector' => [
    'driver'   => 'pgsql',
    'host'     => env('VECTOR_DB_HOST', '127.0.0.1'),
    'port'     => env('VECTOR_DB_PORT', '5432'),
    'database' => env('VECTOR_DB_DATABASE', 'vector_db'),
    'username' => env('VECTOR_DB_USERNAME', 'postgres'),
    'password' => env('VECTOR_DB_PASSWORD', ''),
    'charset'  => 'utf8',
    'schema'   => 'public',
],
```

Enable the pgvector extension on your PostgreSQL instance:

```sql
CREATE EXTENSION IF NOT EXISTS vector;
```

### Publish RAG config and run migrations

```bash
php artisan vendor:publish --tag=rag-config
php artisan vendor:publish --tag=rag-views
php artisan vendor:publish --tag=rag-migrations
php artisan migrate
```

### Attach documents to a model

Add the `HasDocuments` trait to any model that owns uploaded files:

```php
use AyaAshraf\LaravelRag\Traits\HasDocuments;

class Contract extends Model
{
    use HasDocuments;
}
```

This adds `documents()`, `visibleDocuments()`, and `hiddenDocuments()` relationships.

### Upload documents via the Livewire uploader

```blade
<livewire:rag-uploader
    :documentable-type="App\Models\Contract::class"
    :documentable-id="$contract->id"
    :uploaded-by="auth()->id()"
/>
```

Accepted formats: **PDF, DOCX, TXT, XLS, XLSX, CSV** (max 100 MB, up to 10 files at once).

Document statuses:

| Status | Meaning |
|--------|---------|
| `queued` | Waiting for the queue worker |
| `processing` | Job running |
| `processed` | Ready for audit evidence retrieval |
| `empty` | File had no extractable text |
| `failed` | Extraction or embedding error |

Only `processed` documents are used during an audit.

---

## Step 2 — Environment variables

```env
# Primary database (MySQL)
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=your_app_db

# Vector database (PostgreSQL + pgvector)
VECTOR_DB_HOST=127.0.0.1
VECTOR_DB_PORT=5432
VECTOR_DB_DATABASE=your_vector_db
VECTOR_DB_USERNAME=postgres
VECTOR_DB_PASSWORD=secret

# AI / embeddings
GEMINI_API_KEY=your_key_here
EMBEDDING_PROVIDER=gemini
RAG_VECTOR_CONNECTION=pgsql_vector
RAG_CHAT_MODEL=gemini-2.5-flash
#or
OPENAI_API_KEY=your_key_here
EMBEDDING_PROVIDER=openai
EMBEDDING_MODEL=text-embedding-3-small
RAG_CHAT_PROVIDERS=openai
RAG_CHAT_MODEL=gpt-4.1-mini
# Audit workflow
AUDIT_QUEUE=audit-workflow
QUEUE_CONNECTION=database
```

---

## Step 3 — Install `cla/generate-audit-report`


```bash
composer require cla/generate-audit-report
```

Until then (local development via path repository):

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "packages/cla/generate-audit-report",
            "options": { "symlink": true }
        }
    ],
    "require": {
        "cla/generate-audit-report": "@dev"
    }
}
```

```bash
composer update cla/generate-audit-report
```

The service provider is auto-discovered. The `GenerateAuditReport` facade alias is registered automatically at boot — no entry needed in `config/app.php`.

---

## Step 4 — Run migrations & publish config

```bash
# Package migrations are loaded automatically — just run migrate
php artisan migrate

# Publish audit config for customisation (optional)
php artisan vendor:publish --tag=audit-config

# Publish Livewire progress view for customisation (optional)
php artisan vendor:publish --tag=audit-views
```

Migrations create three tables: `audit_sessions`, `audit_reports`, `audit_step_logs`.

### Full config reference

```php
// config/audit.php
return [
    'chat' => [
        'provider' => env('RAG_CHAT_PROVIDERS', 'gemini'),
        'model'    => env('RAG_CHAT_MODEL', 'gemini-2.5-flash'),
        'timeout'  => env('RAG_CHAT_TIMEOUT', 40),
    ],

    'arabic_min_similarity'  => env('RAG_ARABIC_MIN_SIMILARITY', 0.30),
    'english_min_similarity' => env('RAG_ENGLISH_MIN_SIMILARITY', 0.45),
    'max_search_results'     => env('RAG_MAX_SEARCH_RESULTS', 10),
    'candidate_limit'        => env('RAG_CANDIDATE_LIMIT', 8),
    'top_k'                  => env('RAG_TOP_K', 4),
    'max_context_chars'      => env('RAG_MAX_CONTEXT_CHARS', 12000),
    'max_chunk_chars'        => env('RAG_MAX_CHUNK_CHARS', 3000),

    'queue' => env('AUDIT_QUEUE', 'audit-workflow'),
];
```

---

## Step 5 — Start the queue worker

Both the RAG document processing and the audit workflow run on the queue:

```bash
# Single worker covering all queues
php artisan queue:listen --tries=1 --timeout=0

# Or separate workers per queue
php artisan queue:listen --queue=default --tries=1 --timeout=0        # ProcessDocumentForRag
php artisan queue:listen --queue=audit-workflow --tries=1 --timeout=0 # RunAuditWorkflow
```

`RunAuditWorkflow` has a built-in 600-second timeout, 2 retries, and a 30-second backoff. On exhaustion, `AuditSession::markFailed()` is called automatically.

---

## Step 6 — Extend `AuditSession`

The base `AuditSession` model has no knowledge of your app's relationships. Extend it to add whatever associations your application needs:

```php
namespace App\Models;

class AuditSession extends \Cla\GenerateAuditReport\Models\AuditSession
{
    public function subject()
    {
        return $this->auditable(); // polymorphic — resolves to the audited model
    }

    public function initiator()
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }
}
```

> This step is optional if you only access `$session` through the base model.

---

## Builder API Reference

### `GenerateAuditReport::for(Model $model)`

Sets the subject model. Any Eloquent model is accepted — the polymorphic `auditable_type` / `auditable_id` columns are populated automatically.

```php
GenerateAuditReport::for($contract)
GenerateAuditReport::for($vendor)
GenerateAuditReport::for($employee)
```

### `->documents(Collection $documents)`

The `Document` collection to audit against. Pass only `status = 'processed'` documents, or pass all and let the retrieval step filter:

```php
->documents($contract->documents()->where('status', 'processed')->get())
```

If omitted, no document evidence is retrieved and the report is based on the prompt alone.

### `->prompt(string $prompt)`

The context text the AI uses to understand what is being audited. Use line breaks to separate distinct topics — the orchestrator splits the prompt into search queries for RAG retrieval, so multi-paragraph prompts retrieve more diverse evidence:

```php
->prompt(
    "Compliance audit for: {$contract->title}\n\n" .
    "Counterparty: {$contract->vendor->name}\n\n" .
    "Areas to evaluate:\n" .
    "- Data processing and GDPR obligations\n" .
    "- SLA commitments and penalty terms\n" .
    "- Termination and exit conditions"
)
```

### `->language(string $language)`

Audit language. Accepted values: `'en'` (default), `'ar'`.

Controls RAG similarity thresholds (Arabic: 0.30, English: 0.45) and the language of AI-generated prose.

### `->initiatedBy(int $userId)`

Records which user triggered the audit. Defaults to `auth()->id()` if omitted.

### `->generate()`

Creates the `AuditSession`, persists all context, and dispatches `RunAuditWorkflow` to the queue. Returns the session immediately — the report is generated asynchronously.

---

## Complete Controller Example

```php
use Cla\GenerateAuditReport\Facades\GenerateAuditReport;

class ContractAuditController extends Controller
{
    public function store(Contract $contract): RedirectResponse
    {
        $session = GenerateAuditReport::for($contract)
            ->documents($contract->documents()->where('status', 'processed')->get())
            ->prompt($this->buildPrompt($contract))
            ->language($contract->language ?? 'en')
            ->initiatedBy(auth()->id())
            ->generate();

        return redirect()->route('admin.audits.show', $session->id);
    }

    private function buildPrompt(Contract $contract): string
    {
        return implode("\n\n", [
            "Contract compliance review: {$contract->reference_number}",
            "Vendor: {$contract->vendor->name}",
            "Effective: {$contract->effective_date->toDateString()}",
            "Compliance areas:\n- " . implode("\n- ", $contract->compliance_areas),
        ]);
    }
}
```

---

## Audit Workflow

The job runs five sequential steps. Each step writes an `AuditStepLog` checkpoint — the workflow is **idempotent on retry**, resuming from the last completed step.

| Step | What it does |
|------|-------------|
| 1 — Analyze Context | Structures the provided context for AI reasoning; extracts risk signals and key topics |
| 2 — Retrieve Evidence | Semantic search (RAG) over uploaded documents to find relevant chunks |
| 2b — Readiness check | Validates evidence sufficiency; triggers supplemental retrieval if needed |
| 3 — Compare Evidence | Cross-references context against retrieved evidence; identifies gaps and contradictions |
| 4 — Generate Findings | Produces compliance score, risk level, and detailed findings |
| 5 — Generate Report | Produces the final prose report with executive summary and recommendations |

---

## Report Output

`AuditReport` is available after the session status becomes `completed`:

```php
$session = AuditSession::with('report')->find($sessionId);

$report = $session->report;

$report->compliance_score;   // int, e.g. 78
$report->risk_level;         // 'low' | 'medium' | 'high' | 'critical'
$report->executive_summary;  // prose string
$report->findings;           // array of findings
$report->recommendations;    // array of remediation steps
$report->evidence_references; // retrieved chunks used as evidence
$report->document_stats;     // structured stats if a spreadsheet was uploaded
$report->document_summary;   // prose summary of uploaded documents
```

---

## Real-Time Progress

```blade
<livewire:generate-audit-report::audit-progress :session-id="$session->id" />
```

| Property | Type | Description |
|----------|------|-------------|
| `$status` | string | `pending` / `running` / `completed` / `failed` |
| `$currentStep` | int | 0–5 |
| `$percent` | int | 0–100 |
| `$label` | string | Current step label |
| `$complete` | bool | `true` when finished |
| `$failed` | bool | `true` on failure |
| `$error` | string\|null | Error message if failed |
| `$stepLogs` | array | Completed step details (duration, token usage) |

---

## Document Profiling

When a document is uploaded, the package automatically dispatches a `ProfileDocument` job that:

1. Detects the file type (CSV, XLSX, or unstructured)
2. Analyses spreadsheet structure (sheets, columns, row count) for CSV/XLSX
3. Generates a bilingual (AR/EN) prose summary via AI
4. Stores the result as `document_profile` (JSON) and `summary_en` / `summary_ar` on the `Document` model

These are injected into the audit context before evidence retrieval so the AI understands each document's content and structure.

---

## Retrying a Failed Audit

```bash
php artisan queue:retry all      # retry all failed jobs
php artisan queue:retry <id>     # retry one specific job
```

The orchestrator skips any step that already completed successfully on the previous attempt.

---

## Troubleshooting

**`GenerateAuditReport` class not found**
Run `php artisan config:clear` and restart your queue worker. The facade alias is registered at boot via the service provider.

**`__PHP_Incomplete_Class` on the queue worker**
The worker started before `composer update` ran. Restart it.

**`Class "Cla\GenerateAuditReport\..." not found`**
Run `composer update cla/generate-audit-report` then `php artisan package:discover`.

**No evidence retrieved (chunks = 0)**
Confirm the documents you passed have `status = 'processed'` and that their `document_chunks` exist in the pgvector DB. Verify the `pgsql_vector` connection is reachable and the `vector` extension is installed.

**Report generated but findings are empty**
The AI received the context but found nothing to flag. Check that the prompt describes specific compliance areas and that at least some chunks were retrieved — inspect the `AuditStepLog` for step 2's `chunk_count`.

**Audit fails immediately**
Check the `error_message` on the `AuditSession` and the Laravel log. Most common causes: missing `GEMINI_API_KEY`, unreachable pgvector DB, or no processed documents.
