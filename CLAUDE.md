# Project Brief

## Model preference
- Use Haiku 4.5 unless explicitly told "use sonnet" or "use opus"

## What this is
- SecureChatAI: REDCap External Module providing unified AI gateway to Stanford AI Hub

## Tech stack
- Frontend: PHP/JS (REDCap EM pages)
- Backend: PHP 8.x, Guzzle HTTP, yethee/tiktoken
- Build/tooling: Composer
- Hosting/deploy: REDCap server (Stanford)
- Local dev (how to run): REDCap local instance + VPN to Stanford AI Hub

## Repo layout (key folders)
- /
  - Frontend: pages/
  - Backend: SecureChatAI.php, classes/Models/
  - Shared: emLoggerTrait.php
  - Infra: config.json, composer.json

## Current state
### Working / shipped
- All AI Hub models operational (Claude Bedrock, GPT Azure, Gemini Vertex, embeddings, whisper, TTS)
- Pattern-based model routing and response normalization
- Agent mode with tool orchestration
- Session logging and rehydration

### In progress
- Nothing active

### Cappy data-tool integration (2026-07, with REDCapAgentRecordTools + redcap-em-chatbot)
- `SecureChatAI.php:915-933` copies `reference/total/offset/limit/preview_markdown` from `records.search` tool results into `tools_used[].paging` — the only conduit from tool results to a frontend (e.g. Cappy's `PaginatedTable`). Keep in sync if the tools module changes response keys.
- **8000-char tool-result cap** (`SecureChatAI.php:1343`) drops **trailing** keys — tool EMs must order `preview_markdown`/`note`/`message` before any large `records` payload, and large raw payloads should be opt-in (`include_records`).
- Tool EMs must never echo `ref_xxx` session-cache handles to end users.

### Known issues
- grok-3: "Model service is unavailable" on AI Hub side (not code issue)
- Gemini access may require explicit subscription activation per product tier

## Roadmap / TODO
- Per-model timeout configuration
- Streaming response support

## Guardrails (important)
- Make small, reviewable diffs (avoid sweeping refactors unless asked)
- Do not rename files/folders unless explicitly requested
- Do not touch CI/CD, deployment, or infra files unless explicitly requested
- Do not introduce new libraries unless asked
- Prefer existing patterns and utilities already in the repo
- When changing behavior, update or add tests if tests exist
- **Sub-agents**: Handle single-file edits, quick fixes, and simple analysis in main agent. Use sub-agents **only** for: (1) cross-repo search/refactors (>5 files), (2) deep architecture review, (3) explicit requests like "use code-review agent". If considering one, state why briefly first.
- **Token efficiency**: Skip redundant file reads. Reference prior analysis. No sub-agents for trivial tasks.

## Claude Rules (Repo Safety)
- Never commit, merge, or push to `main` (or `master`).
- You may commit on the session branch.
- Do not run `gcloud`, `terraform`, `kubectl`, `aws` unless explicitly told.
- Avoid drive-by refactors. Only change code related to the asked task.
- If you add/modify an API contract, you must update:
  - the contract schema/types
  - at least one example payload
  - tests that validate the contract

## How to verify changes
When you say "tested", include:
- The exact command(s) run
- Pass/fail result (exit code)
- Any relevant output summary

## Communication style
- If a change is large, propose a plan first (no code) and wait for approval
- Call out any risky/destructive operations before doing them
- Keep changes scoped to the requested feature

