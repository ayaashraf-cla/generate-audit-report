<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AI Chat Provider & Model
    |--------------------------------------------------------------------------
    */
    'chat' => [
        'provider' => env('RAG_CHAT_PROVIDERS', 'gemini'),
        'model'    => env('RAG_CHAT_MODEL', 'gemini-2.5-flash'),
        'timeout'  => (int) env('RAG_CHAT_TIMEOUT', 40),
    ],

    /*
    |--------------------------------------------------------------------------
    | RAG Retrieval Thresholds
    |--------------------------------------------------------------------------
    */
    'arabic_min_similarity'  => (float) env('RAG_ARABIC_MIN_SIMILARITY', 0.30),
    'english_min_similarity' => (float) env('RAG_ENGLISH_MIN_SIMILARITY', 0.45),
    'max_search_results'     => (int) env('RAG_MAX_SEARCH_RESULTS', 10),
    'candidate_limit'        => (int) env('RAG_CANDIDATE_LIMIT', 8),
    'top_k'                  => (int) env('RAG_TOP_K', 4),
    'max_context_chars'      => (int) env('RAG_MAX_CONTEXT_CHARS', 12000),
    'max_chunk_chars'        => (int) env('RAG_MAX_CHUNK_CHARS', 3000),

    /*
    |--------------------------------------------------------------------------
    | Documentable Type
    |--------------------------------------------------------------------------
    | The polymorphic morph class used when querying RAG documents for an audit.
    | Set this to the model that owns the uploaded documents (e.g. Survey).
    */
    'documentable_type'      => env('AUDIT_DOCUMENTABLE_TYPE', 'App\\Models\\Survey'),
    'documentable_id_column' => env('AUDIT_DOCUMENTABLE_ID_COLUMN', 'survey_id'),

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    */
    'queue' => env('AUDIT_QUEUE', 'audit-workflow'),

];
