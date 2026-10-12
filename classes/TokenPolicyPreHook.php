<?php

namespace Stanford\SecureChatAI;

/**
 * Built-in pre-hook: keep another project's data inside its token policy.
 *
 * A call's policy is resolved from the project passed to callAI() — for Cappy
 * that is its own config project, not the project a tool reads. Without this
 * hook, a standard conversation could call records.get with the pid of a
 * project whose Minimum Token Policy is `high`, and that project's data would
 * land on a standard model.
 *
 * Fires before every tool call. If the tool payload has a 'pid' whose project
 * minimum is stricter than the conversation's policy, the call is denied.
 * Denying (not upgrading mid-loop) is deliberate: switching model sets halfway
 * through would send the earlier turns to the other set.
 *
 * Always active, like ProjectAccessPreHook — not configurable via settings.
 */
class TokenPolicyPreHook implements PreToolUseHook
{
    public function __construct(private SecureChatAI $module)
    {
    }

    public function handle(ToolUse $use, ToolContext $context): HookResult
    {
        $pid = isset($use->input['pid']) ? (int) $use->input['pid'] : null;
        if (empty($pid)) {
            return HookResult::allow();
        }

        $conversation = (string) $context->get('token_policy', TokenPolicy::STANDARD);
        $required = $this->module->getProjectMinimumTokenPolicy($pid);

        if (TokenPolicy::stricter($conversation, $required) === $conversation) {
            return HookResult::allow();
        }

        return HookResult::deny(
            "Project {$pid} requires the '{$required}' AI token policy, but this conversation runs under "
            . "'{$conversation}', so its data cannot be read here. Ask from a conversation that uses the "
            . "'{$required}' policy."
        );
    }
}
