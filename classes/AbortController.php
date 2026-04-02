<?php

namespace Stanford\SecureChatAI;

/**
 * Cancellation token with cascading abort support.
 *
 * Parent controllers propagate abort to children (future: sub-agents).
 */
class AbortController
{
    private bool $aborted = false;

    /** @var AbortController[] */
    private array $children = [];

    public function abort(): void
    {
        $this->aborted = true;
        foreach ($this->children as $child) {
            $child->abort();
        }
    }

    public function isAborted(): bool
    {
        return $this->aborted;
    }

    public function createChild(): self
    {
        $child = new self();
        $this->children[] = $child;
        return $child;
    }
}
