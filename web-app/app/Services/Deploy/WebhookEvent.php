<?php

namespace App\Services\Deploy;

/**
 * A verified webhook request of pull-mode deploy (E20 spec §6.2).
 */
final class WebhookEvent
{
    public const KIND_PING = 'ping';

    public const KIND_PUSH = 'push';

    public const KIND_OTHER = 'other';

    public function __construct(
        /** github, gitlab or gitea */
        public readonly string $provider,
        /** ping, push or other (a branch deletion is other) */
        public readonly string $kind,
        /** the pushed ref, for example refs/heads/main; null for a ping */
        public readonly ?string $ref,
        /** the pushed commit as 40 lowercase hex digits, or null */
        public readonly ?string $commit,
    ) {}

    /** The branch of the pushed ref, null for a tag or no ref. */
    public function branch(): ?string
    {
        return WebhookSignature::branchOf($this->ref);
    }
}
