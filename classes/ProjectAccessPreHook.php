<?php

namespace Stanford\SecureChatAI;

/**
 * Built-in pre-hook: enforce REDCap project access rights.
 *
 * Fires before every tool call. If the tool payload contains a 'pid',
 * verifies the calling user has rights to that project in REDCap.
 *
 * - No pid in payload → allow (tool doesn't touch a project)
 * - pid matches the current session project → allow (always in scope)
 * - No username in context → deny (can't verify, fail closed)
 * - User has REDCap rights to pid → allow
 * - Otherwise → deny
 *
 * This hook is always active — it is not configurable via settings.
 */
class ProjectAccessPreHook implements PreToolUseHook
{
    public function handle(ToolUse $use, ToolContext $context): HookResult
    {
        $pid = isset($use->input['pid']) ? (int) $use->input['pid'] : null;

        // Tool doesn't target a specific project — nothing to gate
        if (empty($pid)) {
            return HookResult::allow();
        }

        // Current session project is always in scope
        if (!empty($context->projectId) && $pid === (int) $context->projectId) {
            return HookResult::allow();
        }

        // No user identity — can't verify cross-project access, fail closed
        if (empty($context->username)) {
            return HookResult::deny(
                "Cannot verify access to project {$pid}: no user identity in context."
            );
        }

        // Check REDCap user rights table
        $result = db_query(
            "SELECT 1 FROM redcap_user_rights WHERE project_id = ? AND username = ? LIMIT 1",
            [$pid, $context->username]
        );

        if (db_num_rows($result) > 0) {
            return HookResult::allow();
        }

        return HookResult::deny(
            "Access denied: '{$context->username}' does not have rights to project {$pid}."
        );
    }
}
