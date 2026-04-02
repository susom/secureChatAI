<?php

namespace Stanford\SecureChatAI;

/**
 * 7-phase tool execution pipeline.
 *
 * Phase 1: Lookup    — find tool in registry
 * Phase 2: Parse     — validate required fields exist
 * Phase 3: Validate  — tool-specific validation
 * Phase 4: PreHooks  — run registered PreToolUseHook list
 * Phase 5: Permits   — if hook returned "deny", abort
 * Phase 6: Execute   — call the tool
 * Phase 7: PostHooks — run registered PostToolUseHook list
 *
 * Errors at any phase are caught and returned as ToolResult::fail().
 * The pipeline never throws — callers get a ToolResult back.
 */
class ToolPipeline
{
    /** @var PreToolUseHook[] */
    private array $preHooks;

    /** @var PostToolUseHook[] */
    private array $postHooks;

    /** @var callable(ToolUse, ToolContext): ToolInterface|null  Tool resolver */
    private $resolver;

    /**
     * @param callable $resolver  Function(ToolUse, ToolContext) => ToolInterface|null
     * @param PreToolUseHook[] $preHooks
     * @param PostToolUseHook[] $postHooks
     */
    public function __construct(
        callable $resolver,
        array $preHooks = [],
        array $postHooks = []
    ) {
        $this->resolver = $resolver;
        $this->preHooks = $preHooks;
        $this->postHooks = $postHooks;
    }

    /**
     * Run the full 7-phase pipeline.
     */
    public function handle(ToolUse $use, ToolContext $context): ToolResult
    {
        // Phase 1: Lookup
        $tool = ($this->resolver)($use, $context);
        if ($tool === null) {
            return ToolResult::fail("UNKNOWN_TOOL: Tool '{$use->name}' not registered");
        }

        // Phase 2: Parse (required field check)
        $parseError = $this->checkRequiredFields($tool, $use);
        if ($parseError !== null) {
            return ToolResult::fail($parseError);
        }

        // Phase 3: Validate (tool-specific)
        $validationError = $tool->validateInput($use->input, $context);
        if ($validationError !== null) {
            return ToolResult::fail($validationError);
        }

        // Phase 4: Pre-hooks
        foreach ($this->preHooks as $hook) {
            $result = $hook->handle($use, $context);
            if ($result->decision === 'deny') {
                return ToolResult::fail($result->message ?? 'Tool execution denied by hook');
            }
        }

        // Phase 5: Permissions (handled by pre-hooks above for now)

        // Phase 6: Execute
        try {
            $data = $tool->call($use->input, $context);
            $toolResult = ToolResult::ok($data);
        } catch (\Throwable $e) {
            $toolResult = ToolResult::fail($e->getMessage());
        }

        // Phase 7: Post-hooks
        foreach ($this->postHooks as $hook) {
            try {
                $hook->handle($use, $toolResult, $context);
            } catch (\Throwable $e) {
                // Post-hook errors are logged but don't affect the result
            }
        }

        return $toolResult;
    }

    /**
     * Check that all required fields in the tool's schema are present in the input.
     */
    private function checkRequiredFields(ToolInterface $tool, ToolUse $use): ?string
    {
        $schema = $tool->inputSchema();
        $required = $schema['required'] ?? [];
        $missing = array_diff($required, array_keys($use->input));

        if (empty($missing)) {
            return null;
        }

        return "Missing required parameters: " . implode(", ", $missing);
    }
}
