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
        // Tools name the target project 'pid'; check 'project_id' too so a tool
        // that names it differently can't skip this check. Every key present is
        // checked, and a present value that isn't a project id is denied, so
        // {"pid": 0, "project_id": 282} can't slip past on the empty one.
        $pids = [];
        foreach (['pid', 'project_id'] as $key) {
            if (!array_key_exists($key, $use->input) || $use->input[$key] === null || $use->input[$key] === '') {
                continue;
            }
            $value = $use->input[$key];
            if (!is_numeric($value) || (int) $value <= 0 || (string)(int) $value !== trim((string) $value)) {
                return HookResult::deny("Invalid project id in '{$key}'.");
            }
            $pids[] = (int) $value;
        }
        if (!$pids) {
            return HookResult::allow();
        }

        $conversation = (string) $context->get('token_policy', TokenPolicy::STANDARD);
        $required = TokenPolicy::STANDARD;
        foreach (array_unique($pids) as $p) {
            $required = TokenPolicy::stricter($required, $this->module->getProjectMinimumTokenPolicy($p));
        }
        $pid = implode(', ', array_unique($pids));

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
