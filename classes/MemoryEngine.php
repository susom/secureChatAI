<?php

namespace Stanford\SecureChatAI;

/**
 * Generic memory merge engine for persistent entity memory.
 *
 * Provides utilities for maintaining a "living memory document"
 * with a rolling summary + changelog format:
 *
 *   # Current Summary
 *   [3-5 sentences]
 *
 *   # Changelog
 *   - [YYYY-MM-DD HH:MM] [description]
 *
 * Extracted from RexiDashboard for reuse across EMs.
 */
class MemoryEngine
{
    private const MAX_CHANGELOG = 50;

    // =========================================================================
    // Pure utilities (no LLM needed)
    // =========================================================================

    /**
     * Significance gate: skip trivial deltas that don't warrant a memory update.
     *
     * @param array $delta Array of ['role' => string, 'content' => string] messages
     */
    public static function isDeltaSignificant(array $delta): bool
    {
        $text = implode(' ', array_column($delta, 'content'));
        $wordCount = str_word_count($text);

        // Too short to be meaningful
        if ($wordCount < 10) {
            return false;
        }

        // Check if user messages are all trivial
        $trivialPatterns = [
            '/^(hi|hello|hey|thanks|thank you|ok|okay|bye|goodbye|yes|no|sure)\s*[.!?]*$/i'
        ];
        $userMessages = array_filter($delta, fn($m) => ($m['role'] ?? '') === 'user');
        $assistantMessages = array_filter($delta, fn($m) => ($m['role'] ?? '') === 'assistant');

        $allUserTrivial = true;
        foreach ($userMessages as $msg) {
            $content = trim($msg['content'] ?? '');
            $isTrivial = false;
            foreach ($trivialPatterns as $pattern) {
                if (preg_match($pattern, $content)) {
                    $isTrivial = true;
                    break;
                }
            }
            if (!$isTrivial) {
                $allUserTrivial = false;
                break;
            }
        }

        // Even if user messages are trivial, check if assistant said something substantial
        if ($allUserTrivial) {
            $assistantText = implode(' ', array_column($assistantMessages, 'content'));
            if (str_word_count($assistantText) < 20) {
                return false;
            }
        }

        // Skip canned/template content
        $templatePatterns = [
            '/has returned to the conversation/i',
            '/welcomed.*back/i',
            '/resume the intake/i',
            '/assistant welcomed/i',
            '/offered.*to continue/i',
            '/offered support for.*previous work/i'
        ];
        foreach ($templatePatterns as $pattern) {
            if (preg_match($pattern, $text)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Trim changelog to max entries (oldest pruned).
     *
     * @param string $memory      Memory document with # Changelog section
     * @param int    $maxEntries  Maximum changelog entries (default 50)
     */
    public static function trimChangelog(string $memory, int $maxEntries = self::MAX_CHANGELOG): string
    {
        if (!preg_match('/# Changelog\s*\n(.*)/s', $memory, $matches)) {
            return $memory;
        }

        $changelogBlock = trim($matches[1]);
        $lines = preg_split('/\n(?=- \[)/', $changelogBlock);

        if (count($lines) > $maxEntries) {
            $lines = array_slice($lines, 0, $maxEntries);
            $trimmedChangelog = implode("\n", $lines);
            $memory = preg_replace('/# Changelog\s*\n.*/s', "# Changelog\n$trimmedChangelog", $memory);
        }

        return $memory;
    }

    /**
     * Build an updated memory document from a new summary + changelog entry.
     *
     * @param string $existingMemory  Current memory document
     * @param string $newSummary      New summary text (3-5 sentences)
     * @param string $changelogEntry  Single changelog line (e.g., "- [2026-02-11 14:30] Description")
     * @param int    $maxEntries      Max changelog entries (default 50)
     */
    public static function buildUpdatedMemory(
        string $existingMemory,
        string $newSummary,
        string $changelogEntry,
        int $maxEntries = self::MAX_CHANGELOG
    ): string {
        // Extract existing changelog entries
        $existingChangelog = '';
        if (preg_match('/# Changelog\s*\n(.*)/s', $existingMemory, $matches)) {
            $existingChangelog = trim($matches[1]);
        }

        $fullChangelog = $changelogEntry;
        if (!empty($existingChangelog)) {
            $fullChangelog .= "\n" . $existingChangelog;
        }

        $memory = "# Current Summary\n$newSummary\n\n# Changelog\n$fullChangelog";
        return self::trimChangelog($memory, $maxEntries);
    }

    // =========================================================================
    // LLM-dependent (caller injects callable)
    // =========================================================================

    /**
     * Merge a conversation delta into an existing memory document via LLM.
     *
     * @param string   $entityId       Entity identifier (used in prompt context)
     * @param string   $existingMemory Current memory document
     * @param string   $deltaText      Formatted conversation excerpt
     * @param callable $llmCall        fn(string $model, array $params): array{content: ?string}
     * @param string   $model          Model to use for summarization
     * @return string|null Updated memory or null on failure
     */
    public static function mergeMemory(
        string $entityId,
        string $existingMemory,
        string $deltaText,
        callable $llmCall,
        string $model = 'gpt-4.1-mini'
    ): ?string {
        $timestamp = date('Y-m-d H:i');

        $prompt = <<<PROMPT
You maintain a living memory document for research project (intake $entityId).

Current memory:
$existingMemory

New conversation excerpt:
$deltaText

Update the memory following this exact format:

# Current Summary
[Rewrite the summary incorporating any new information. Keep it concise — 3-5 sentences max. If the current memory is empty, create a new summary from the conversation.]

# Changelog
- [$timestamp] [1-2 sentence description of what happened in this conversation]
[Keep existing changelog entries below, newest first. Maximum 50 entries — trim oldest if needed.]

Return ONLY the updated memory document, nothing else.
PROMPT;

        try {
            $messages = [
                ["role" => "system", "content" => "You are a concise memory manager for a research project tracking system. Output only the updated memory document."],
                ["role" => "user", "content" => $prompt]
            ];

            $response = $llmCall($model, [
                "messages" => $messages,
                "max_tokens" => 1500,
                "temperature" => 0.3
            ]);

            $content = $response['content'] ?? null;
            if (empty($content)) {
                return null;
            }

            return self::trimChangelog($content);

        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Route a conversation delta to relevant entity/entities via LLM.
     *
     * @param array    $memories   Associative array [id => memory_string]
     * @param string   $deltaText  Formatted conversation excerpt
     * @param array    $entityMap  Associative array [id => metadata array]
     * @param callable $llmCall    fn(string $model, array $params): array{content: ?string}
     * @param string   $model      Model to use for routing
     * @return array|null Routing result: {"updates": [...], "skipped": bool} or null on failure
     */
    public static function routeDelta(
        array $memories,
        string $deltaText,
        array $entityMap,
        callable $llmCall,
        string $model = 'gpt-4.1-mini'
    ): ?array {
        $entitySummaries = [];
        foreach ($memories as $id => $memory) {
            $summary = '';
            if (!empty($memory)) {
                if (preg_match('/# Current Summary\s*\n(.*?)(?=\n#|\z)/s', $memory, $matches)) {
                    $summary = trim($matches[1]);
                } else {
                    $summary = substr($memory, 0, 200);
                }
            }
            $entityInfo = $entityMap[$id] ?? [];
            $entitySummaries[] = [
                "intake_id" => $id,
                "project_name" => $entityInfo['project_name'] ?? 'Unnamed Project',
                "study_title" => $entityInfo['study_title'] ?? '',
                "research_title" => $entityInfo['research_title'] ?? '',
                "research_nickname" => $entityInfo['research_nickname'] ?? '',
                "pi_name" => $entityInfo['pi_name'] ?? '',
                "current_memory_summary" => $summary ?: "(no memory yet)"
            ];
        }

        $entitiesJson = json_encode($entitySummaries, JSON_PRETTY_PRINT);
        $timestamp = date('Y-m-d H:i');

        $prompt = <<<PROMPT
You are a project router. Given a conversation excerpt and a list of projects with their details, determine which project(s) the conversation explicitly refers to.

CRITICAL: Users refer to projects by their research_title or research_nickname (e.g., "Project 2"), NOT by intake_id.

Matching rules (in priority order):
- "Project 2" or "Project X" → Match to research_title or research_nickname containing "Project 2" or "Project X"
- Project names mentioned → Match to project_name, study_title, research_title, or research_nickname
- PI names mentioned → Match to pi_name
- intake_id is the internal database ID - users don't know or reference this directly

Projects (with identifying info):
$entitiesJson

Conversation excerpt:
$deltaText

Routing rules:
1. EXPLICIT REFERENCE: If user says "Project 2", find the project where research_title="Project 2" OR research_nickname="Project 2" (NOT intake_id="2")
2. NAME MATCH: If conversation mentions a project name, study title, research title, or nickname, match to that project
3. CONTENT MATCH: If topics align with a project's current summary, update that project
4. UNCLEAR: If multiple projects could match or it's too vague, set skipped to true
5. MULTIPLE: Only update multiple projects if conversation clearly applies to all

Return ONLY valid JSON (no markdown, no explanation):
{
  "updates": [
    {
      "intake_id": "<id from projects list>",
      "new_summary": "<rewritten summary, 3-5 sentences incorporating new info>",
      "changelog_entry": "- [$timestamp] <1-2 sentence description>"
    }
  ],
  "skipped": false
}

If unclear which project, return:
{ "updates": [], "skipped": true }
PROMPT;

        try {
            $messages = [
                ["role" => "system", "content" => "You are a JSON-only responder. Output valid JSON with no markdown fencing or explanation."],
                ["role" => "user", "content" => $prompt]
            ];

            $response = $llmCall($model, [
                "messages" => $messages,
                "max_tokens" => 2000,
                "temperature" => 0.2
            ]);

            $content = $response['content'] ?? '';
            if (empty($content)) {
                return null;
            }

            // Strip markdown fencing if present
            $content = preg_replace('/^```(?:json)?\s*/', '', $content);
            $content = preg_replace('/\s*```$/', '', $content);

            $decoded = json_decode(trim($content), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return null;
            }

            return $decoded;

        } catch (\Throwable $e) {
            return null;
        }
    }
}
