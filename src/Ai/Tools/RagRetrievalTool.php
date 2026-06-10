<?php

namespace Cla\GenerateAuditReport\Ai\Tools;

use AyaAshraf\LaravelRag\Models\DocumentChunk;
use AyaAshraf\LaravelRag\Services\EmbeddingGenerator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class RagRetrievalTool implements Tool
{
    public function __construct(
        private readonly array  $documentIds,
        private readonly array  $fileNames,
        private readonly string $language = 'en',
    ) {}

    public function name(): string
    {
        return 'retrieve_document_context';
    }

    public function description(): string
    {
        return 'Retrieve relevant text chunks from uploaded documents by semantic search. '
            . 'Call this to access file content for evidence. '
            . 'Returns matching passages with their source file name and similarity score.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query'     => $schema->string()
                ->description('Semantic search query — a phrase or topic to look up in the documents')
                ->required(),

            'file_name' => $schema->string()
                ->description('Restrict search to a specific uploaded file name. Pass null to search all files.')
                ->nullable()
                ->required(),
        ];
    }

    public function handle(Request $request): string
    {
        $query    = (string) ($request['query'] ?? '');
        $fileName = (string) ($request['file_name'] ?? '');

        $documentIds = $this->documentIds;

        if ($fileName !== '') {
            $matched = array_keys(array_filter(
                $this->fileNames,
                fn ($name) => str_contains(strtolower((string) $name), strtolower($fileName))
            ));

            if (! empty($matched)) {
                $documentIds = $matched;
            }
        }

        $hasArabicContent = (bool) preg_match('/\p{Arabic}/u', $query);

        $minSimilarity = ($this->language === 'ar' || $hasArabicContent)
            ? (float) config('audit.arabic_min_similarity', 0.30)
            : (float) config('audit.english_min_similarity', 0.45);

        $embedding = app(EmbeddingGenerator::class)->embed([$query])[0] ?? [];

        if (empty($embedding)) {
            return 'No embedding generated for query.';
        }

        $chunks = DocumentChunk::query()
            ->whereIn('document_id', $documentIds)
            ->where('embedding_dimensions', count($embedding))
            ->select(['id', 'document_id', 'position', 'content'])
            ->selectVectorDistance('embedding', $embedding, 'embedding_distance')
            ->whereVectorSimilarTo('embedding', $embedding, minSimilarity: $minSimilarity)
            ->limit((int) config('audit.top_k', 4))
            ->get();

        if ($chunks->isEmpty()) {
            return 'No relevant content found for: ' . $query;
        }

        return $chunks->map(fn ($c, $i) =>
            '[Source ' . ($i + 1) .
            ' | File: ' . ($this->fileNames[$c->document_id] ?? 'Unknown') .
            " | Pos: {$c->position}]\n" .
            mb_substr((string) $c->content, 0, 1500)
        )->implode("\n\n---\n\n");
    }
}
