@php
    $stepMeta = [
        1 => ['color' => '#6366f1', 'light' => '#eef2ff', 'label' => 'Analyze Answers'],
        2 => ['color' => '#8b5cf6', 'light' => '#f5f3ff', 'label' => 'Retrieve Evidence'],
        3 => ['color' => '#0891b2', 'light' => '#ecfeff', 'label' => 'Compare Evidence'],
        4 => ['color' => '#d97706', 'light' => '#fffbeb', 'label' => 'Generate Findings'],
        5 => ['color' => '#059669', 'light' => '#ecfdf5', 'label' => 'Generate Report'],
    ];
@endphp

<div
    @if(!$complete && !$failed) wire:poll.2000ms="refresh" @endif
    class="audit-progress-widget d-flex flex-column gap-4"
>

    {{-- ── Status Banner ──────────────────────────────────── --}}
    @if($failed)
        <div class="d-flex align-items-start gap-3 p-3 rounded-3 border border-danger bg-danger bg-opacity-10">
            <div class="flex-shrink-0 mt-1">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="#dc3545" viewBox="0 0 16 16">
                    <path d="M8.982 1.566a1.13 1.13 0 0 0-1.96 0L.165 13.233c-.457.778.091 1.767.98 1.767h13.713c.889 0 1.438-.99.98-1.767L8.982 1.566zM8 5c.535 0 .954.462.9.995l-.35 3.507a.552.552 0 0 1-1.1 0L7.1 5.995A.905.905 0 0 1 8 5zm.002 6a1 1 0 1 1 0 2 1 1 0 0 1 0-2z"/>
                </svg>
            </div>
            <div class="flex-grow-1">
                <div class="fw-semibold text-danger">Audit failed</div>
                @if($error)
                    <div class="small text-muted mt-1">{{ $error }}</div>
                @endif
            </div>
            <form method="POST" action="{{ route('admin.audits.retry', $sessionId) }}">
                @csrf
                <button type="submit" class="btn btn-sm btn-danger">Retry</button>
            </form>
        </div>

    @elseif($complete)
        <div class="d-flex align-items-center gap-3 p-3 rounded-3 border border-success bg-success bg-opacity-10">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="#198754" viewBox="0 0 16 16">
                <path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0zm-3.97-3.03a.75.75 0 0 0-1.08.022L7.477 9.417 5.384 7.323a.75.75 0 0 0-1.06 1.06L6.97 11.03a.75.75 0 0 0 1.079-.02l3.992-4.99a.75.75 0 0 0-.01-1.05z"/>
            </svg>
            <span class="fw-semibold  flex-grow-1">All steps completed</span>
            <a href="{{ route('admin.audits.show', $sessionId) }}" class="btn btn-sm btn-success">View Report</a>
        </div>

    @else
        {{-- Running: progress bar + named step dots --}}
        <div class="d-flex flex-column gap-3">
            <div class="d-flex justify-content-between align-items-center">
                <span class="small text-muted d-flex align-items-center gap-2">
                    <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                    <span>{{ $label }}</span>
                </span>
                <span class="small fw-semibold text-muted">{{ $percent }}%</span>
            </div>

            <div class="progress rounded-pill" style="height:6px;">
                <div class="progress-bar progress-bar-striped progress-bar-animated"
                     style="width:{{ $percent }}%; background: linear-gradient(90deg, #6366f1, #8b5cf6);"></div>
            </div>

            <div class="d-flex justify-content-between mt-1">
                @foreach($stepMeta as $n => $meta)
                    @php $done = $n < $currentStep; $active = $n === $currentStep; @endphp
                    <div class="d-flex flex-column align-items-center gap-1" style="flex:1;">
                        <div class="rounded-circle d-flex align-items-center justify-content-center fw-semibold"
                             style="width:30px;height:30px;font-size:11px;
                                    background:{{ $done ? $meta['color'] : ($active ? $meta['color'] : '#e5e7eb') }};
                                    color:{{ ($done || $active) ? '#fff' : '#9ca3af' }};
                                    box-shadow:{{ $active ? '0 0 0 3px '.$meta['light'].', 0 0 0 5px '.$meta['color'].'40' : 'none' }};
                                    transition:all .3s;">
                            @if($done)
                                <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" fill="currentColor" viewBox="0 0 16 16">
                                    <path d="M13.854 3.646a.5.5 0 0 1 0 .708l-7 7a.5.5 0 0 1-.708 0l-3.5-3.5a.5.5 0 1 1 .708-.708L6.5 10.293l6.646-6.647a.5.5 0 0 1 .708 0z"/>
                                </svg>
                            @else
                                {{ $n }}
                            @endif
                        </div>
                        <span class="text-center" style="font-size:10px; color:{{ ($done || $active) ? $meta['color'] : '#9ca3af' }}; line-height:1.2; max-width:60px;">
                            {{ $meta['label'] }}
                        </span>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- ── Completed Step Timeline ─────────────────────────── --}}
    @if(!empty($stepLogs))
        <div class="d-flex flex-column" style="gap:0;">

            @foreach($stepLogs as $index => $log)
                @php
                    $meta   = $stepMeta[$log['step']] ?? ['color' => '#6b7280', 'light' => '#f9fafb', 'label' => $log['step_name']];
                    $out    = $log['output'] ?? [];
                    $isLast = $index === count($stepLogs) - 1;
                @endphp

                <div class="d-flex gap-3" x-data="{ open: false }" wire:ignore.self>

                    {{-- Timeline spine --}}
                    <div class="d-flex flex-column align-items-center flex-shrink-0" style="width:32px;">
                        <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0"
                             style="width:32px;height:32px;background:{{ $meta['color'] }};color:#fff;font-size:12px;font-weight:600;box-shadow:0 0 0 4px {{ $meta['light'] }};">
                            @if($log['status'] === 'failed')
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" viewBox="0 0 16 16"><path d="M4.646 4.646a.5.5 0 0 1 .708 0L8 7.293l2.646-2.647a.5.5 0 0 1 .708.708L8.707 8l2.647 2.646a.5.5 0 0 1-.708.708L8 8.707l-2.646 2.647a.5.5 0 0 1-.708-.708L7.293 8 4.646 5.354a.5.5 0 0 1 0-.708z"/></svg>
                            @else
                                <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" fill="currentColor" viewBox="0 0 16 16"><path d="M13.854 3.646a.5.5 0 0 1 0 .708l-7 7a.5.5 0 0 1-.708 0l-3.5-3.5a.5.5 0 1 1 .708-.708L6.5 10.293l6.646-6.647a.5.5 0 0 1 .708 0z"/></svg>
                            @endif
                        </div>
                        @if(!$isLast)
                            <div class="flex-grow-1 mt-1" style="width:2px;background:{{ $meta['color'] }}30;min-height:16px;"></div>
                        @endif
                    </div>

                    {{-- Step card --}}
                    <div class="flex-grow-1 mb-3">
                        {{-- Clickable header --}}
                        <div role="button"
                             @click="open = !open"
                             class="d-flex align-items-center gap-2 p-3 rounded-3 border user-select-none"
                             style="background:{{ $meta['light'] }};border-color:{{ $meta['color'] }}30 !important;cursor:pointer;">

                            <div class="flex-grow-1 d-flex align-items-center gap-2 flex-wrap">
                                <span class="fw-semibold small" style="color:{{ $meta['color'] }};">{{ $log['step_name'] }}</span>

                                @if($log['duration_ms'])
                                    <span class="d-flex align-items-center gap-1 small text-muted">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" fill="currentColor" viewBox="0 0 16 16"><path d="M8 3.5a.5.5 0 0 0-1 0V9a.5.5 0 0 0 .252.434l3.5 2a.5.5 0 0 0 .496-.868L8 8.71V3.5z"/><path d="M8 16A8 8 0 1 0 8 0a8 8 0 0 0 0 16zm7-8A7 7 0 1 1 1 8a7 7 0 0 1 14 0z"/></svg>
                                        {{ number_format($log['duration_ms'] / 1000, 2) }}s
                                    </span>
                                @endif

                                @if($log['chunks_retrieved'] !== null)
                                    <span class="badge" style="background:{{ $meta['color'] }}20;color:{{ $meta['color'] }};border-color:{{ $meta['color'] }}40;">
                                        {{ $log['chunks_retrieved'] }} chunks
                                    </span>
                                @endif

                                {{-- Step-specific quick stats --}}
                                @if($log['step'] === 1 && isset($out['overall_completeness']))
                                    <span class="badge" style="background:transparent;color:{{ $meta['color'] }};border-color:{{ $meta['color'] }};">
                                        {{ $out['overall_completeness'] }}% complete
                                    </span>
                                @endif
                                @if($log['step'] === 1 && !empty($out['risk_signals']))
                                    <span class="badge badge-error badge-soft">
                                        {{ count($out['risk_signals']) }} risks
                                    </span>
                                @endif
                                @if($log['step'] === 2 && isset($out['readiness_check']['ready']))
                                    <span class="badge {{ ($out['readiness_check']['ready'] ?? true) ? 'badge-success badge-soft' : 'badge-warning badge-soft' }}">
                                        {{ ($out['readiness_check']['ready'] ?? true) ? 'Ready' : 'Extended search' }}
                                    </span>
                                @endif
                                @if($log['step'] === 3)
                                    @if(!empty($out['documentation_gaps']))
                                        <span class="badge badge-warning badge-soft">{{ count($out['documentation_gaps']) }} gaps</span>
                                    @endif
                                    @if(!empty($out['contradicted_by_docs']))
                                        <span class="badge badge-error badge-soft">{{ count($out['contradicted_by_docs']) }} contradictions</span>
                                    @endif
                                @endif
                                @if($log['step'] === 4 && isset($out['compliance_score']))
                                    <span class="badge badge-outline" style="--badge-color:{{ $meta['color'] }};">
                                        Score {{ $out['compliance_score'] }}/100
                                    </span>
                                    @if(isset($out['risk_level']))
                                        <span class="badge
                                            @if($out['risk_level'] === 'critical') badge-error
                                            @elseif($out['risk_level'] === 'high') badge-warning
                                            @elseif($out['risk_level'] === 'medium') badge-info
                                            @else badge-success @endif">
                                            {{ strtoupper($out['risk_level']) }}
                                        </span>
                                    @endif
                                @endif
                            </div>

                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="{{ $meta['color'] }}" class="flex-shrink-0" viewBox="0 0 16 16"
                                 :style="open ? 'transform:rotate(180deg);transition:transform .25s' : 'transition:transform .25s'">
                                <path fill-rule="evenodd" d="M1.646 4.646a.5.5 0 0 1 .708 0L8 10.293l5.646-5.647a.5.5 0 0 1 .708.708l-6 6a.5.5 0 0 1-.708 0l-6-6a.5.5 0 0 1 0-.708z"/>
                            </svg>
                        </div>

                        {{-- Collapsible body --}}
                        <div x-show="open" x-transition:enter="transition-opacity duration-200" x-cloak>
                            <div class="border rounded-bottom-3 p-3 d-flex flex-column gap-4 small"
                                 style="border-color:{{ $meta['color'] }}30 !important;border-top:none;">

                                {{-- Error --}}
                                @if($log['error'])
                                    <div class="d-flex gap-2 p-2 rounded-2 bg-danger bg-opacity-10 border border-danger border-opacity-25">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="#dc3545" class="flex-shrink-0 mt-1" viewBox="0 0 16 16"><path d="M8.982 1.566a1.13 1.13 0 0 0-1.96 0L.165 13.233c-.457.778.091 1.767.98 1.767h13.713c.889 0 1.438-.99.98-1.767L8.982 1.566zM8 5c.535 0 .954.462.9.995l-.35 3.507a.552.552 0 0 1-1.1 0L7.1 5.995A.905.905 0 0 1 8 5zm.002 6a1 1 0 1 1 0 2 1 1 0 0 1 0-2z"/></svg>
                                        <span class="text-danger">{{ $log['error'] }}</span>
                                    </div>
                                @endif

                                {{-- Prompt --}}
                                @if(!empty($log['prompt']))
                                    <div>
                                        <div class="d-flex align-items-center gap-2 mb-2">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" fill="{{ $meta['color'] }}" viewBox="0 0 16 16"><path d="M14 1a1 1 0 0 1 1 1v8a1 1 0 0 1-1 1H4.414A2 2 0 0 0 3 11.586l-2 2V2a1 1 0 0 1 1-1h12zM2 0a2 2 0 0 0-2 2v12.793a.5.5 0 0 0 .854.353l2.853-2.853A1 1 0 0 1 4.414 12H14a2 2 0 0 0 2-2V2a2 2 0 0 0-2-2H2z"/></svg>
                                            <span class="fw-semibold text-uppercase" style="font-size:0.68rem;letter-spacing:.05em;color:{{ $meta['color'] }};">Prompt</span>
                                        </div>
                                        <pre class="rounded-2 p-3 mb-0"
                                             style="font-size:0.7rem;max-height:220px;overflow-y:auto;white-space:pre-wrap;word-break:break-word;background:#1e1e2e;color:#cdd6f4;border:none;line-height:1.6;">{{ $log['prompt'] }}</pre>
                                    </div>
                                @endif

                                {{-- ── Step 1 ──────────────────────── --}}
                                @if($log['step'] === 1 && !empty($out))

                                    @if(isset($out['overall_completeness']))
                                        <div>
                                            <div class="d-flex align-items-center gap-2 mb-2">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" fill="{{ $meta['color'] }}" viewBox="0 0 16 16"><path d="M10.97 4.97a.75.75 0 0 1 1.07 1.05l-3.99 4.99a.75.75 0 0 1-1.08.02L4.324 8.384a.75.75 0 1 1 1.06-1.06l2.094 2.093 3.473-4.425a.267.267 0 0 1 .02-.022z"/><path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14zm0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16z"/></svg>
                                                <span class="fw-semibold text-uppercase" style="font-size:0.68rem;letter-spacing:.05em;color:{{ $meta['color'] }};">Completeness Score</span>
                                            </div>
                                            <div class="d-flex align-items-center gap-3 mt-2">
                                                <div class="progress flex-grow-1 rounded-pill" style="height:10px;">
                                                    <div class="progress-bar rounded-pill"
                                                         style="width:{{ $out['overall_completeness'] }}%;
                                                                background:{{ $out['overall_completeness'] >= 70 ? '#059669' : ($out['overall_completeness'] >= 40 ? '#d97706' : '#dc3545') }};">
                                                    </div>
                                                </div>
                                                <span class="fw-bold" style="font-size:0.85rem;color:{{ $out['overall_completeness'] >= 70 ? '#059669' : ($out['overall_completeness'] >= 40 ? '#d97706' : '#dc3545') }};">
                                                    {{ $out['overall_completeness'] }}%
                                                </span>
                                            </div>
                                        </div>
                                    @endif

                                    @if(!empty($out['key_topics']))
                                        <div>
                                            <div class="d-flex align-items-center gap-2 mb-2">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" fill="{{ $meta['color'] }}" viewBox="0 0 16 16"><path d="M2 2a1 1 0 0 1 1-1h4.586a1 1 0 0 1 .707.293l7 7a1 1 0 0 1 0 1.414l-4.586 4.586a1 1 0 0 1-1.414 0l-7-7A1 1 0 0 1 2 6.586V2zm3.5 4a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3z"/></svg>
                                                <span class="fw-semibold text-uppercase" style="font-size:0.68rem;letter-spacing:.05em;color:{{ $meta['color'] }};">Key Topics</span>
                                            </div>
                                            <div class="d-flex flex-wrap gap-1">
                                                @foreach($out['key_topics'] as $topic)
                                                    <span class="badge rounded-pill px-2 py-1"
                                                          style="background:{{ $meta['light'] }};color:{{ $meta['color'] }};border:1px solid {{ $meta['color'] }}30;font-weight:500;">
                                                        {{ $topic }}
                                                    </span>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endif

                                    @if(!empty($out['risk_signals']))
                                        <div>
                                            <div class="d-flex align-items-center gap-2 mb-2">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" fill="#dc3545" viewBox="0 0 16 16"><path d="M8.982 1.566a1.13 1.13 0 0 0-1.96 0L.165 13.233c-.457.778.091 1.767.98 1.767h13.713c.889 0 1.438-.99.98-1.767L8.982 1.566zM8 5c.535 0 .954.462.9.995l-.35 3.507a.552.552 0 0 1-1.1 0L7.1 5.995A.905.905 0 0 1 8 5zm.002 6a1 1 0 1 1 0 2 1 1 0 0 1 0-2z"/></svg>
                                                <span class="fw-semibold text-uppercase" style="font-size:0.68rem;letter-spacing:.05em;color:#dc3545;">Risk Signals ({{ count($out['risk_signals']) }})</span>
                                            </div>
                                            <div class="d-flex flex-column gap-1">
                                                @foreach($out['risk_signals'] as $signal)
                                                    @php
                                                        $sev = $signal['severity'] ?? 'low';
                                                        $sevColor = $sev === 'high' ? '#dc3545' : ($sev === 'medium' ? '#d97706' : '#6b7280');
                                                    @endphp
                                                    <div class="d-flex align-items-start gap-2 px-3 py-2 rounded-2"
                                                         style="background:{{ $sevColor }}0d;border-left:3px solid {{ $sevColor }};">
                                                        <span class="badge shrink-0"
                                                              style="background:{{ $sevColor }}20;color:{{ $sevColor }};border-color:{{ $sevColor }}40;font-size:0.65rem;">
                                                            {{ strtoupper($sev) }}
                                                        </span>
                                                        <span class="flex-grow-1">{{ $signal['signal'] ?? '' }}</span>
                                                        @if(isset($signal['question_id']))
                                                            <span class="text-muted flex-shrink-0" style="font-size:0.7rem;">Q#{{ $signal['question_id'] }}</span>
                                                        @endif
                                                    </div>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endif

                                    @if(!empty($out['completeness_issues']))
                                        <div>
                                            <div class="d-flex align-items-center gap-2 mb-2">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" fill="#d97706" viewBox="0 0 16 16"><path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14zm0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16z"/><path d="M7.002 11a1 1 0 1 1 2 0 1 1 0 0 1-2 0zM7.1 4.995a.905.905 0 1 1 1.8 0l-.35 3.507a.552.552 0 0 1-1.1 0L7.1 4.995z"/></svg>
                                                <span class="fw-semibold text-uppercase" style="font-size:0.68rem;letter-spacing:.05em;color:#d97706;">Completeness Issues ({{ count($out['completeness_issues']) }})</span>
                                            </div>
                                            <div class="d-flex flex-column gap-1">
                                                @foreach($out['completeness_issues'] as $issue)
                                                    <div class="px-3 py-2 rounded-2" style="background:#fffbeb;border-left:3px solid #d97706;">
                                                        @if(isset($issue['question_id']))<span class="fw-semibold text-muted" style="font-size:0.7rem;">Q#{{ $issue['question_id'] }}</span> @endif
                                                        {{ $issue['issue'] ?? '' }}
                                                    </div>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endif

                                    @if(!empty($out['inconsistencies']))
                                        <div>
                                            <div class="d-flex align-items-center gap-2 mb-2">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" fill="#dc3545" viewBox="0 0 16 16"><path d="M9.293 0H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V4.707A1 1 0 0 0 13.707 4L10 .293A1 1 0 0 0 9.293 0zM9.5 3.5v-2l3 3h-2a1 1 0 0 1-1-1zM6.854 7.146 8 8.293l1.146-1.147a.5.5 0 1 1 .708.708L8.707 9l1.147 1.146a.5.5 0 0 1-.708.708L8 9.707l-1.146 1.147a.5.5 0 0 1-.708-.708L7.293 9 6.146 7.854a.5.5 0 1 1 .708-.708z"/></svg>
                                                <span class="fw-semibold text-uppercase" style="font-size:0.68rem;letter-spacing:.05em;color:#dc3545;">Inconsistencies ({{ count($out['inconsistencies']) }})</span>
                                            </div>
                                            <div class="d-flex flex-column gap-1">
                                                @foreach($out['inconsistencies'] as $inc)
                                                    <div class="px-3 py-2 rounded-2" style="background:#fff5f5;border-left:3px solid #dc3545;">
                                                        {{ $inc['description'] ?? '' }}
                                                        @if(!empty($inc['questions']))
                                                            <span class="text-muted ms-1" style="font-size:0.7rem;">(Q#{{ implode(', Q#', $inc['questions']) }})</span>
                                                        @endif
                                                    </div>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endif

                                @endif

                                {{-- ── Step 2 ──────────────────────── --}}
                                @if($log['step'] === 2 && !empty($out))

                                    @if(!empty($out['evidence_warning']))
                                        <div class="d-flex align-items-start gap-2 p-3 rounded-2"
                                             style="background:#fffbeb;border-left:3px solid #d97706;">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="#d97706" class="flex-shrink-0 mt-1" viewBox="0 0 16 16">
                                                <path d="M8.982 1.566a1.13 1.13 0 0 0-1.96 0L.165 13.233c-.457.778.091 1.767.98 1.767h13.713c.889 0 1.438-.99.98-1.767L8.982 1.566zM8 5c.535 0 .954.462.9.995l-.35 3.507a.552.552 0 0 1-1.1 0L7.1 5.995A.905.905 0 0 1 8 5zm.002 6a1 1 0 1 1 0 2 1 1 0 0 1 0-2z"/>
                                            </svg>
                                            <span class="fw-semibold" style="color:#d97706;">{{ $out['evidence_warning'] }}</span>
                                        </div>
                                    @endif

                                    @if(isset($out['readiness_check']))
                                        @php $rc = $out['readiness_check']; $ready = $rc['ready'] ?? true; @endphp
                                        <div class="p-3 rounded-2"
                                             style="background:{{ $ready ? '#ecfdf5' : '#fffbeb' }};border-left:3px solid {{ $ready ? '#059669' : '#d97706' }};">
                                            <div class="d-flex align-items-center gap-2 mb-1">
                                                <span class="fw-semibold" style="color:{{ $ready ? '#059669' : '#d97706' }};">
                                                    {{ $ready ? 'Evidence sufficient — proceeding' : 'Requested additional context' }}
                                                </span>
                                            </div>
                                            @if(!empty($rc['reasoning']))
                                                <p class="mb-0 text-muted" style="font-size:0.8rem;">{{ $rc['reasoning'] }}</p>
                                            @endif
                                            @if(!empty($rc['additional_queries']))
                                                <div class="mt-2">
                                                    <div class="fw-semibold mb-1" style="font-size:0.7rem;color:#d97706;text-transform:uppercase;letter-spacing:.05em;">Follow-up queries</div>
                                                    <div class="d-flex flex-column gap-1">
                                                        @foreach($rc['additional_queries'] as $q)
                                                            <div class="d-flex align-items-start gap-2">
                                                                <span style="color:#d97706;margin-top:2px;">›</span>
                                                                <span>{{ $q }}</span>
                                                            </div>
                                                        @endforeach
                                                    </div>
                                                </div>
                                            @endif
                                        </div>
                                    @endif

                                    @if(!empty($out['chunks']))
                                        <div>
                                            <div class="d-flex align-items-center gap-2 mb-2">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" fill="{{ $meta['color'] }}" viewBox="0 0 16 16"><path d="M1 2.5A1.5 1.5 0 0 1 2.5 1h3A1.5 1.5 0 0 1 7 2.5v3A1.5 1.5 0 0 1 5.5 7h-3A1.5 1.5 0 0 1 1 5.5v-3zm8 0A1.5 1.5 0 0 1 10.5 1h3A1.5 1.5 0 0 1 15 2.5v3A1.5 1.5 0 0 1 13.5 7h-3A1.5 1.5 0 0 1 9 5.5v-3zm-8 8A1.5 1.5 0 0 1 2.5 9h3A1.5 1.5 0 0 1 7 10.5v3A1.5 1.5 0 0 1 5.5 15h-3A1.5 1.5 0 0 1 1 13.5v-3zm8 0A1.5 1.5 0 0 1 10.5 9h3a1.5 1.5 0 0 1 1.5 1.5v3a1.5 1.5 0 0 1-1.5 1.5h-3A1.5 1.5 0 0 1 9 13.5v-3z"/></svg>
                                                <span class="fw-semibold text-uppercase" style="font-size:0.68rem;letter-spacing:.05em;color:{{ $meta['color'] }};">Retrieved Chunks ({{ count($out['chunks']) }})</span>
                                            </div>
                                            <div class="d-flex flex-column gap-2">
                                                @foreach($out['chunks'] as $i => $chunk)
                                                    @php $sim = isset($chunk['similarity']) ? (float)$chunk['similarity'] : null; @endphp
                                                    <div class="rounded-2 overflow-hidden border" style="border-color:{{ $meta['color'] }}20 !important;">
                                                        <div class="d-flex align-items-center gap-2 px-3 py-2 flex-wrap"
                                                             style="background:{{ $meta['light'] }};border-bottom:1px solid {{ $meta['color'] }}15;">
                                                            <span class="rounded-circle d-inline-flex align-items-center justify-content-center text-white flex-shrink-0"
                                                                  style="width:20px;height:20px;font-size:10px;font-weight:700;background:{{ $meta['color'] }};">
                                                                {{ $i + 1 }}
                                                            </span>
                                                            <span class="fw-semibold flex-grow-1" style="color:{{ $meta['color'] }};">{{ $chunk['file_name'] ?? 'Unknown' }}</span>
                                                            @if(isset($chunk['position']))
                                                                <span class="text-muted" style="font-size:0.7rem;">pos {{ $chunk['position'] }}</span>
                                                            @endif
                                                            @if($sim !== null)
                                                                @php
                                                                    $simColor = $sim >= 0.8 ? '#059669' : ($sim >= 0.6 ? '#0891b2' : '#d97706');
                                                                @endphp
                                                                <span class="badge rounded-pill" style="background:{{ $simColor }}15;color:{{ $simColor }};border:1px solid {{ $simColor }}30;">
                                                                    {{ number_format($sim * 100, 1) }}% match
                                                                </span>
                                                            @endif
                                                        </div>
                                                        <div class="px-3 py-2 font-monospace text-muted"
                                                             style="font-size:0.7rem;max-height:100px;overflow-y:auto;white-space:pre-wrap;word-break:break-word;background:#fafafa;line-height:1.5;">{{ $chunk['content'] ?? '' }}</div>
                                                    </div>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endif

                                @endif

                                {{-- ── Step 3 ──────────────────────── --}}
                                @if($log['step'] === 3 && !empty($out))

                                    @foreach([
                                        ['key' => 'supported_by_docs',    'label' => 'Supported by Documents',    'color' => '#059669', 'bg' => '#ecfdf5'],
                                        ['key' => 'contradicted_by_docs', 'label' => 'Contradicted by Documents', 'color' => '#dc3545', 'bg' => '#fff5f5'],
                                        ['key' => 'documentation_gaps',   'label' => 'Documentation Gaps',        'color' => '#d97706', 'bg' => '#fffbeb'],
                                    ] as $group)
                                        @if(!empty($out[$group['key']]))
                                            <div>
                                                <div class="d-flex align-items-center gap-2 mb-2">
                                                    <span class="fw-semibold text-uppercase" style="font-size:0.68rem;letter-spacing:.05em;color:{{ $group['color'] }};">
                                                        {{ $group['label'] }} ({{ count($out[$group['key']]) }})
                                                    </span>
                                                </div>
                                                <div class="d-flex flex-column gap-1">
                                                    @foreach($out[$group['key']] as $item)
                                                        <div class="px-3 py-2 rounded-2"
                                                             style="background:{{ $group['bg'] }};border-left:3px solid {{ $group['color'] }};">
                                                            <span class="fw-semibold">{{ $item['topic'] ?? '' }}</span>
                                                            @if(!empty($item['contradiction']))
                                                                <div class="mt-1 text-muted">{{ $item['contradiction'] }}</div>
                                                            @endif
                                                            @if(!empty($item['gap_description']))
                                                                <div class="mt-1 text-muted">{{ $item['gap_description'] }}</div>
                                                            @endif
                                                            @if(!empty($item['evidence_quote']))
                                                                <div class="mt-1 fst-italic text-muted" style="font-size:0.8rem;">"{{ $item['evidence_quote'] }}"</div>
                                                            @endif
                                                        </div>
                                                    @endforeach
                                                </div>
                                            </div>
                                        @endif
                                    @endforeach

                                @endif

                                {{-- ── Step 4 ──────────────────────── --}}
                                @if($log['step'] === 4 && !empty($out))

                                    @if(isset($out['compliance_score']) || isset($out['risk_level']))
                                        <div class="d-flex align-items-center gap-3 p-3 rounded-2" style="background:{{ $meta['light'] }};">
                                            @if(isset($out['compliance_score']))
                                                <div class="d-flex flex-column align-items-center gap-1" style="min-width:70px;">
                                                    <span style="font-size:1.6rem;font-weight:700;color:{{ $out['compliance_score'] >= 70 ? '#059669' : ($out['compliance_score'] >= 40 ? '#d97706' : '#dc3545') }};">
                                                        {{ $out['compliance_score'] }}
                                                    </span>
                                                    <span class="text-muted text-center" style="font-size:0.65rem;text-transform:uppercase;letter-spacing:.05em;">Compliance<br>Score</span>
                                                </div>
                                                <div class="vr" style="opacity:.15;"></div>
                                            @endif
                                            @if(isset($out['risk_level']))
                                                @php
                                                    $rl = $out['risk_level'];
                                                    $rlColor = $rl === 'critical' ? '#dc3545' : ($rl === 'high' ? '#d97706' : ($rl === 'medium' ? '#0891b2' : '#059669'));
                                                @endphp
                                                <div class="d-flex flex-column align-items-center gap-1" style="min-width:70px;">
                                                    <span class="fw-bold" style="font-size:1rem;color:{{ $rlColor }};">{{ strtoupper($rl) }}</span>
                                                    <span class="text-muted text-center" style="font-size:0.65rem;text-transform:uppercase;letter-spacing:.05em;">Risk<br>Level</span>
                                                </div>
                                            @endif
                                        </div>
                                    @endif

                                    @if(!empty($out['findings']))
                                        <div>
                                            <div class="d-flex align-items-center gap-2 mb-2">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" fill="{{ $meta['color'] }}" viewBox="0 0 16 16"><path d="M5 10.5a.5.5 0 0 1 .5-.5h2a.5.5 0 0 1 0 1h-2a.5.5 0 0 1-.5-.5zm0-2a.5.5 0 0 1 .5-.5h5a.5.5 0 0 1 0 1h-5a.5.5 0 0 1-.5-.5zm0-2a.5.5 0 0 1 .5-.5h5a.5.5 0 0 1 0 1h-5a.5.5 0 0 1-.5-.5zm0-2a.5.5 0 0 1 .5-.5h5a.5.5 0 0 1 0 1h-5a.5.5 0 0 1-.5-.5z"/><path d="M3 0h10a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V2a2 2 0 0 1 2-2zm0 1a1 1 0 0 0-1 1v12a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1V2a1 1 0 0 0-1-1H3z"/></svg>
                                                <span class="fw-semibold text-uppercase" style="font-size:0.68rem;letter-spacing:.05em;color:{{ $meta['color'] }};">Findings ({{ count($out['findings']) }})</span>
                                            </div>
                                            <div class="d-flex flex-column gap-2">
                                                @foreach($out['findings'] as $finding)
                                                    @php
                                                        $sev = $finding['severity'] ?? 'info';
                                                        $sevColor = $sev === 'critical' ? '#dc3545' : ($sev === 'high' ? '#d97706' : ($sev === 'medium' ? '#0891b2' : '#6b7280'));
                                                    @endphp
                                                    <div class="rounded-2 overflow-hidden border" style="border-color:{{ $sevColor }}30 !important;">
                                                        <div class="d-flex align-items-center gap-2 px-3 py-2 flex-wrap" style="background:{{ $sevColor }}08;border-bottom:1px solid {{ $sevColor }}15;">
                                                            @if(isset($finding['id']))
                                                                <span class="text-muted" style="font-size:0.7rem;">#{{ $finding['id'] }}</span>
                                                            @endif
                                                            @if(isset($finding['category']))
                                                                <span class="badge rounded-pill" style="background:{{ $sevColor }}15;color:{{ $sevColor }};border:1px solid {{ $sevColor }}30;font-size:0.65rem;">
                                                                    {{ str_replace('_', ' ', $finding['category']) }}
                                                                </span>
                                                            @endif
                                                            <span class="ms-auto fw-semibold" style="font-size:0.72rem;color:{{ $sevColor }};">{{ strtoupper($sev) }}</span>
                                                        </div>
                                                        <div class="px-3 py-2">
                                                            <p class="mb-0">{{ $finding['description'] ?? '' }}</p>
                                                            @if(!empty($finding['evidence_quote']))
                                                                <div class="mt-2 fst-italic text-muted" style="font-size:0.8rem;border-left:2px solid {{ $sevColor }}40;padding-left:8px;">"{{ $finding['evidence_quote'] }}"</div>
                                                            @endif
                                                            @if(!empty($finding['recommendation']))
                                                                <div class="mt-2 text-muted" style="font-size:0.8rem;">
                                                                    <span class="fw-semibold" style="color:{{ $sevColor }};">Rec:</span> {{ $finding['recommendation'] }}
                                                                </div>
                                                            @endif
                                                        </div>
                                                    </div>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endif

                                @endif

                                {{-- ── Step 5 ──────────────────────── --}}
                                @if($log['step'] === 5 && !empty($out))

                                    @foreach([
                                        'executive_summary'  => 'Executive Summary',
                                        'scope_methodology'  => 'Scope & Methodology',
                                        'key_findings_prose' => 'Key Findings',
                                        'conclusion'         => 'Conclusion',
                                    ] as $key => $sectionLabel)
                                        @if(!empty($out[$key]))
                                            <div>
                                                <div class="d-flex align-items-center gap-2 mb-2">
                                                    <span class="fw-semibold text-uppercase" style="font-size:0.68rem;letter-spacing:.05em;color:{{ $meta['color'] }};">{{ $sectionLabel }}</span>
                                                </div>
                                                <p class="mb-0 text-muted" style="line-height:1.7;">{{ $out[$key] }}</p>
                                            </div>
                                        @endif
                                    @endforeach

                                    @if(!empty($out['recommendations']))
                                        <div>
                                            <div class="d-flex align-items-center gap-2 mb-2">
                                                <span class="fw-semibold text-uppercase" style="font-size:0.68rem;letter-spacing:.05em;color:{{ $meta['color'] }};">Recommendations</span>
                                            </div>
                                            <div class="d-flex flex-column gap-2">
                                                @foreach(collect($out['recommendations'])->sortBy('priority') as $idx => $rec)
                                                    <div class="d-flex gap-3 px-3 py-2 rounded-2" style="background:{{ $meta['light'] }};border-left:3px solid {{ $meta['color'] }};">
                                                        <span class="fw-bold flex-shrink-0" style="color:{{ $meta['color'] }};">{{ $idx + 1 }}</span>
                                                        <div>
                                                            <div class="fw-semibold">{{ $rec['action'] ?? '' }}</div>
                                                            @if(!empty($rec['rationale']))
                                                                <div class="text-muted mt-1" style="font-size:0.8rem;">{{ $rec['rationale'] }}</div>
                                                            @endif
                                                        </div>
                                                    </div>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endif

                                @endif

                            </div>
                        </div>
                    </div>

                </div>
            @endforeach
        </div>
    @endif

</div>

<style>
    [x-cloak] { display: none !important; }
</style>
