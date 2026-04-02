<?php

namespace Stanford\SecureChatAI;

/**
 * Context bag passed through the tool execution pipeline.
 *
 * Carries session/project state and a metadata array that hooks
 * can use to pass data between pipeline phases.
 */
class ToolContext
{
    public readonly ?int $projectId;
    public readonly AbortController $abortController;
    private array $metadata = [];

    public function __construct(
        ?int $projectId = null,
        ?AbortController $abortController = null
    ) {
        $this->projectId = $projectId;
        $this->abortController = $abortController ?? new AbortController();
    }

    /** Get a metadata value */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->metadata[$key] ?? $default;
    }

    /** Set a metadata value (hooks use this to pass data between phases) */
    public function set(string $key, mixed $value): void
    {
        $this->metadata[$key] = $value;
    }
}
