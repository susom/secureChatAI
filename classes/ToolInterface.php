<?php

namespace Stanford\SecureChatAI;

/**
 * Contract for typed tool definitions.
 *
 * EMs can implement this interface directly to participate in the
 * tool execution pipeline. Tools discovered from EM config.json
 * agent-tool-definitions are wrapped by JsonConfigToolAdapter.
 */
interface ToolInterface
{
    /** Unique tool name (e.g., "escalation.create") */
    public function name(): string;

    /** JSON Schema array describing expected input shape */
    public function inputSchema(): array;

    /**
     * Execute the tool.
     *
     * @return array Result data (will be wrapped in ToolResult by pipeline)
     */
    public function call(array $input, ToolContext $context): array;

    /**
     * Pre-execution validation beyond schema checks.
     *
     * @return string|null Error message, or null if valid
     */
    public function validateInput(array $input, ToolContext $context): ?string;

    /** Whether this tool only reads data (no side effects) */
    public function isReadOnly(): bool;

    /** Whether this tool performs irreversible operations */
    public function isDestructive(): bool;

    /** Human-readable description for tool catalog */
    public function description(): string;
}
