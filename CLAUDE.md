# Project Brief

## Model preference
- Use Haiku 4.5 unless explicitly told "use sonnet" or "use opus"

## What this is
-

## Tech stack
- Frontend:
- Backend:
- Build/tooling:
- Hosting/deploy:
- Local dev (how to run):

## Repo layout (key folders)
- /
  - Frontend:
  - Backend:
  - Shared:
  - Infra:

## Current state
### Working / shipped
-

### In progress
-

### Known issues
-

## Roadmap / TODO
-

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

