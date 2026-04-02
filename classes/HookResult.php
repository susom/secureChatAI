<?php

namespace Stanford\SecureChatAI;

/**
 * Result returned by a PreToolUseHook.
 */
class HookResult
{
    /** "allow" or "deny" */
    public readonly string $decision;

    /** Optional message (shown when denied, or attached to context when allowed) */
    public readonly ?string $message;

    public function __construct(string $decision, ?string $message = null)
    {
        $this->decision = $decision;
        $this->message = $message;
    }

    public static function allow(): self
    {
        return new self('allow');
    }

    public static function deny(string $message): self
    {
        return new self('deny', $message);
    }
}
