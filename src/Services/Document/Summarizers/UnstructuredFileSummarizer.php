<?php

namespace Cla\GenerateAuditReport\Services\Document\Summarizers;

use Cla\GenerateAuditReport\Services\Document\Profile\DocumentProfile;
use Cla\GenerateAuditReport\Services\Document\Summarizers\Contracts\FileSummarizerInterface;
use Laravel\Ai\AnonymousAgent;

class UnstructuredFileSummarizer implements FileSummarizerInterface
{
    private string $provider;
    private string $model;

    public function __construct()
    {
        $this->provider = config('audit.chat.provider', 'gemini');
        $this->model    = config('audit.chat.model', 'gemini-2.5-flash');
    }

    public function supports(DocumentProfile $profile): bool
    {
        return ! $profile->isStructured();
    }

    public function summarize(DocumentProfile $profile): array
    {
        if (blank($profile->extractedText)) {
            return ['en' => null, 'ar' => null];
        }

        $content = mb_substr($profile->extractedText, 0, 4000);

        $schema = json_encode([
            'en' => 'string — 3-5 paragraph English description covering content, date coverage, missing/incomplete data, numerical distributions, and value variation if present',
            'ar' => 'string — 3-5 paragraph Arabic description of the same document covering the same topics',
        ]);

        $prompt = <<<PROMPT
        You are a document analyst. Read the content below and write a factual description.

        Rules:
        - Use ONLY what is explicitly present in the document. Never invent or estimate.
        - Describe what the document contains in objective terms.
        - Describe missing data observations: note any sections, fields, or expected information that appear incomplete, blank, or absent in the document.
        - Describe date coverage: identify any dates mentioned in the document, note the earliest and latest, and describe the time span they cover.
        - Describe distribution patterns: if the document contains numerical values or amounts, note their general range and how they are distributed.
        - Note variation: if the document contains repeated numerical data, flag any figures that stand out as unusually high, low, or consistent compared to others.
        - If a category does not apply (e.g. no dates, no numbers), omit it rather than fabricating observations.
        - 3–5 paragraphs per language.
        - No audit opinions, compliance conclusions, or recommendations.

        Respond ONLY with a valid JSON object matching this schema exactly:
        {$schema}

        FILE NAME: {$profile->fileName}

        DOCUMENT CONTENT:
        {$content}
        PROMPT;

        try {
            $response = AnonymousAgent::make(
                instructions: 'You are a precise document analyst. Always respond with a valid JSON object only.',
                messages: [],
                tools: [],
            )->prompt($prompt, provider: $this->provider, model: $this->model, timeout: 60);

            $text    = $this->stripFences($response->text);
            $decoded = json_decode($text, true);

            if (json_last_error() !== JSON_ERROR_NONE || ! isset($decoded['en'], $decoded['ar'])) {
                return ['en' => null, 'ar' => null];
            }

            return [
                'en' => filled($decoded['en']) ? (string) $decoded['en'] : null,
                'ar' => filled($decoded['ar']) ? (string) $decoded['ar'] : null,
            ];
        } catch (\Throwable) {
            return ['en' => null, 'ar' => null];
        }
    }

    private function stripFences(string $text): string
    {
        $text = preg_replace('/^```(?:json)?\s*/m', '', $text);
        $text = preg_replace('/\s*```$/m', '', $text);

        return trim($text);
    }
}
