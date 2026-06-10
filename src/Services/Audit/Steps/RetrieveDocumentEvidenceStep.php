<?php

namespace Cla\GenerateAuditReport\Services\Audit\Steps;

use Cla\GenerateAuditReport\Enums\AuditStep as AuditStepEnum;
use Cla\GenerateAuditReport\Models\AuditSession;
use AyaAshraf\LaravelRag\Models\DocumentChunk;
use AyaAshraf\LaravelRag\Services\EmbeddingGenerator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class RetrieveDocumentEvidenceStep extends AuditStep
{
    public function step(): AuditStepEnum
    {
        return AuditStepEnum::RetrieveEvidence;
    }

    protected function execute(AuditSession $session, array $context): array
    {
        $queries           = $context['search_queries'];
        $documentIds       = $context['document_ids'];
        $fileNames         = $context['file_names'] ?? [];
        $language          = $context['survey_language'];
        $documentSummaries = $context['document_summaries'] ?? [];

        if (empty($documentIds)) {
            return ['chunk_count' => 0, 'chunks' => [], 'evidence_warning' => null];
        }

        $initialChunks = $this->retrieveChunks($queries, $documentIds, $fileNames, $language);
        $pruned        = $this->pruneForTokenBudget($initialChunks->sortByDesc('similarity')->values());

        if ($pruned->isEmpty() && ! empty($documentSummaries)) {
            $summaryKey      = $language === 'ar' ? 'ar' : 'en';
            $syntheticChunks = collect($documentSummaries)
                ->filter(fn ($s) => filled($s[$summaryKey] ?? null))
                ->map(fn ($s, $docId) => [
                    'id'          => 'summary_' . $docId,
                    'document_id' => $docId,
                    'file_name'   => $fileNames[$docId] ?? 'Unknown',
                    'position'    => 0,
                    'content'     => $s[$summaryKey],
                    'metadata'    => ['source' => 'pre_computed_summary'],
                    'similarity'  => 1.0,
                ])
                ->values();

            if ($syntheticChunks->isNotEmpty()) {
                return [
                    'chunk_count'      => $syntheticChunks->count(),
                    'chunks'           => $syntheticChunks->toArray(),
                    'evidence_warning' => null,
                ];
            }
        }

        $evidenceWarning = $pruned->isEmpty()
            ? ($language === 'ar'
                ? 'لم يتم استرداد أي أدلة وثائقية. سيُبنى التقرير على إجابات الاستطلاع فقط.'
                : 'No document evidence was retrieved. Report will be based on survey answers only.')
            : null;

        return [
            'chunk_count'      => $pruned->count(),
            'chunks'           => $pruned->values()->toArray(),
            'evidence_warning' => $evidenceWarning,
        ];
    }

    public function supplementalRetrieve(
        array  $queries,
        array  $documentIds,
        array  $fileNames,
        string $language
    ): array {
        $chunks = $this->retrieveChunks($queries, $documentIds, $fileNames, $language);
        return $this->pruneForTokenBudget($chunks->sortByDesc('similarity')->values())->toArray();
    }

    protected function retrieveChunks(
        array  $queries,
        array  $documentIds,
        array  $fileNames,
        string $language
    ): Collection {
        $hasArabicContent = collect($queries)->contains(fn ($q) => (bool) preg_match('/\p{Arabic}/u', $q));

        $minSimilarity = ($language === 'ar' || $hasArabicContent)
            ? (float) config('audit.arabic_min_similarity', 0.30)
            : (float) config('audit.english_min_similarity', 0.45);

        $limit = max(
            (int) config('audit.max_search_results', 10),
            (int) config('audit.candidate_limit', 20),
        );

        $generator  = app(EmbeddingGenerator::class);
        $embeddings = $generator->embed($queries);

        Log::debug('RAG retrieval', [
            'query_count'     => count($queries),
            'embedding_count' => count($embeddings),
            'embedding_dims'  => isset($embeddings[0]) ? count($embeddings[0]) : 0,
            'document_ids'    => $documentIds,
            'min_similarity'  => $minSimilarity,
        ]);

        $collected = collect();

        foreach ($queries as $i => $query) {
            $embedding = $embeddings[$i] ?? [];

            if (empty($embedding)) {
                Log::debug("RAG retrieval: empty embedding for query #{$i}, skipping");
                continue;
            }

            $chunks = DocumentChunk::query()
                ->whereIn('document_id', $documentIds)
                ->where('embedding_dimensions', count($embedding))
                ->select(['id', 'document_id', 'position', 'content', 'metadata'])
                ->selectVectorDistance('embedding', $embedding, 'embedding_distance')
                ->whereVectorSimilarTo('embedding', $embedding, minSimilarity: $minSimilarity)
                ->limit($limit)
                ->get();

            Log::debug("RAG retrieval query #{$i}: found {$chunks->count()} chunks (dims=" . count($embedding) . ", minSim={$minSimilarity})");

            $collected = $collected->concat($chunks);
        }

        return $collected
            ->map(function (DocumentChunk $chunk) use ($fileNames): array {
                $distance = $chunk->getAttribute('embedding_distance');
                return [
                    'id'          => $chunk->id,
                    'document_id' => $chunk->document_id,
                    'file_name'   => $fileNames[$chunk->document_id] ?? 'Unknown',
                    'position'    => $chunk->position,
                    'content'     => $chunk->content,
                    'metadata'    => $chunk->metadata ?? [],
                    'similarity'  => is_numeric($distance)
                        ? round(max(0, min(1, 1 - (float) $distance)), 4)
                        : null,
                ];
            })
            ->sortByDesc('similarity')
            ->unique('id')
            ->values();
    }

    protected function pruneForTokenBudget(Collection $chunks): Collection
    {
        $maxChars    = max(500, (int) config('audit.max_context_chars', 12000));
        $maxPerChunk = max(500, (int) config('audit.max_chunk_chars', 3000));
        $used        = 0;
        $result      = collect();

        foreach ($chunks as $chunk) {
            $content = mb_substr((string) ($chunk['content'] ?? ''), 0, $maxPerChunk);
            $len     = mb_strlen($content);

            if ($used > 0 && ($used + $len) > $maxChars) {
                break;
            }

            $chunk['content'] = $content;
            $result->push($chunk);
            $used += $len;
        }

        return $result;
    }
}
