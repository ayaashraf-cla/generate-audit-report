<?php

namespace Cla\GenerateAuditReport\Services\Document\Profile;

readonly class ProfileResult
{
    /**
     * @param  array{en: string|null, ar: string|null}  $summary
     */
    public function __construct(
        public DocumentProfile $profile,
        public array           $summary,
    ) {}
}
