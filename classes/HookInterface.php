<?php

namespace Stanford\SecureChatAI;

/**
 * Hook that runs BEFORE tool execution (Phase 4).
 *
 * Can inspect/modify input, or deny the tool call entirely.
 */
interface PreToolUseHook
{
    /**
     * @return HookResult allow() to proceed, deny($message) to block
     */
    public function handle(ToolUse $use, ToolContext $context): HookResult;
}

/**
 * Hook that runs AFTER tool execution (Phase 7).
 *
 * Can log results, trigger side-effects, or update metrics.
 */
interface PostToolUseHook
{
    public function handle(ToolUse $use, ToolResult $result, ToolContext $context): void;
}
