# SecureChatAI Agent Framework — Platform Guide

> **Status:** Work-in-progress. Core architecture is functional. Project-level scoping, tool governance, and documentation are actively evolving.

---

## What Is This?

SecureChatAI is a **unified AI runtime layer** for REDCap. It lets any External Module (EM) call Stanford-approved AI models through a single, auditable interface — with optional **agent mode** that gives the AI the ability to read and write REDCap data autonomously.

Think of it as two things:

1. **A simple AI API** — any EM calls `$secureChatAI->callAI($model, $params, $pid)` and gets back a response. No API keys, no model-specific code, no compliance headaches.

2. **An agent platform** — when `agent_mode: true` is passed, the AI enters a reasoning loop where it can discover and use tools (read metadata, search records, save data, create escalations, etc.) to accomplish multi-step tasks autonomously.

---

## Two Patterns for AI in REDCap

### Pattern 1: Conversational Chatbot

**Use case:** Ongoing dialogue — the user asks questions, gets answers, follows up.

**Example:** The REDCap Chatbot EM. User opens a chat window, types questions about their project, gets AI-assisted answers with optional RAG context.

**How it works:**
- Chatbot EM manages the conversation UI and message history
- Each message round-trip calls `callAI()` on SecureChatAI
- With agent mode enabled, the chatbot can also take actions (create escalations, look up records)

**When to use this:** Help desks, research assistant bots, project navigation, FAQ systems.

### Pattern 2: Standalone Task (the "MSPA Pattern")

**Use case:** One-shot task — the user clicks a button, AI does a specific job, done.

**Example:** The MSPA Test EM. User pastes an essay into a text field on a data entry form, clicks "Parse & Save", and the AI scores the essay and fills in 6 fields on the current record.

**How it works:**
- A thin EM injects a button on the data entry form (via `redcap_data_entry_form` hook)
- On click, it sends the text + record ID + project ID to SecureChatAI with `agent_mode: true`
- The agent discovers available tools, reads the project's data dictionary, parses the text, and saves structured data back to the record
- The user sees the fields populated — never leaves the page

**When to use this:** Data extraction from free text, automated scoring/classification, form pre-population, batch data cleanup, any "parse this and fill that" workflow.

**What makes this pattern powerful:**
- **Zero custom tools needed** — it reused the generic Record Tools EM that already existed
- **Zero project-specific code** — the agent discovers field names from the data dictionary at runtime
- **Minimal EM code** — the caller EM is ~100 lines of PHP + ~100 lines of JS
- **Works on any project** — point it at a different project with different instruments, same code works

---

## Architecture Overview

```
┌─────────────────────────────────────────┐
│         YOUR EM (the trigger)           │
│  Chatbot, MSPA, Dashboard, anything     │
│  Calls: callAI(model, params, pid)      │
└──────────────────┬──────────────────────┘
                   │
                   ▼
┌─────────────────────────────────────────┐
│       SECURECHAT AI (the brain)         │
│                                         │
│  agent_mode: false → single LLM call   │
│  agent_mode: true  → agent loop:       │
│    1. Discover tools for this project   │
│    2. Prompt LLM with tool catalog      │
│    3. Execute tool calls as requested   │
│    4. Loop until done or safety limit   │
└──────────────────┬──────────────────────┘
                   │
                   ▼
┌─────────────────────────────────────────┐
│       TOOL EMs (the hands)              │
│                                         │
│  Record Tools    → CRUD on any project  │
│  Escalation Tools → ticket management  │
│  Your Tools      → anything you build   │
│                                         │
│  Auto-discovered via config.json        │
│  Scoped per-project via EM settings     │
└─────────────────────────────────────────┘
```

---

## How to Add AI to a REDCap Research Project

### Step 1: Decide Your Pattern

| Question | → Pattern |
|----------|-----------|
| Users need back-and-forth conversation? | Chatbot |
| Users click a button and AI does a job? | Standalone Task (MSPA) |
| Both? | Both — they use the same backend |

### Step 2: Enable SecureChatAI on the Project

1. Go to **Control Center → External Modules**
2. Enable **SecureChatAI** on the target project
3. In the project's SecureChatAI settings, set **Agent Tool EM Prefixes** to the tool EMs you want available (e.g., `redcap_agent_record_tools`)

### Step 3: Enable Tool EMs on the Project

1. Enable the tool EMs you need (e.g., **REDCapAgentRecordTools**) on the same project
2. This allows SecureChatAI to resolve the tool EM's directory and discover its tools

### Step 4: For Chatbot Pattern

1. Enable **REDCapChatBot** on the project
2. In project settings, enable **Agent Mode**
3. Configure system context, model selection, and optional RAG
4. Users access via the Standalone Chatbot link in the project menu

### Step 4: For Standalone Task Pattern (MSPA)

1. Create a new EM (or clone mspa_test as a template)
2. In your EM:
   - Use `redcap_data_entry_form` hook to inject UI on the right instrument
   - On user action, call SecureChatAI:
     ```php
     $secureChatAI = \ExternalModules\ExternalModules::getModuleInstance('secure_chat_ai');
     $response = $secureChatAI->callAI('gpt-4.1', [
         'messages' => [
             ['role' => 'system', 'content' => $yourPrompt],
             ['role' => 'user', 'content' => $userData],
         ],
         'agent_mode' => true,
     ], $project_id);
     ```
   - Display the response to the user
3. The agent will auto-discover tools and the project's data dictionary — no hardcoding needed

### Step 5: For Custom Tools (Optional)

Only needed if the generic Record Tools don't cover your use case.

1. Create a new EM (e.g., `redcap_agent_myproject_tools`)
2. Add `agent-tool-definitions` to its `config.json`:
   ```json
   "agent-tool-definitions": [
     {
       "name": "myproject.doThing",
       "description": "Does the thing for this project",
       "api-action": "do_thing",
       "parameters": {
         "type": "object",
         "properties": {
           "input": {"type": "string", "description": "The input data"}
         },
         "required": ["input"]
       },
       "readOnly": false,
       "destructive": false
     }
   ]
   ```
3. Implement `redcap_module_api($action, $payload)` to handle the action
4. Add the EM prefix to the project's `project_agent_tool_em_prefixes` setting
5. SecureChatAI auto-discovers it — no changes to SecureChatAI or any other module

---

## Available Generic Tools (Record Tools EM)

These work on **any** REDCap project — no custom code needed:

| Tool | What It Does | Read/Write |
|------|-------------|------------|
| `projects.getMetadata` | Get data dictionary (field names, types, choices) | Read |
| `projects.getInstruments` | List all forms/instruments | Read |
| `projects.search` | Find projects by name or ID | Read |
| `records.get` | Fetch a specific record's data | Read |
| `records.search` | Search records with REDCap logic filters | Read |
| `records.save` | Create or update record data | **Write** |
| `records.evaluateLogic` | Test a logic expression against a record | Read |
| `survey.getLink` | Generate a survey URL for a record | Read |

The agent uses these dynamically. For example, it might:
1. Call `getMetadata` to learn what fields exist
2. Call `records.search` to find matching records
3. Call `records.save` to update them

All without any project-specific code.

---

## Safety & Governance

| Control | Description |
|---------|------------|
| **Max steps** | Agent loop terminates after N reasoning iterations (default: 8) |
| **Max tool calls** | Total tool executions capped per run (default: 15) |
| **Timeout** | Hard wall-clock limit (default: 120 seconds) |
| **Loop detection** | Detects and breaks tool ping-pong (same tool + same args 3+ times) |
| **Per-project scoping** | Each project explicitly lists which tool EMs it can access |
| **Read/Write flags** | Each tool declares readOnly and destructive status |
| **Audit logging** | Every agent step, tool call, and result is logged with session ID |
| **Model selection** | Agent mode forces schema-capable models (GPT-4.1, o3-mini, etc.) |

---

## What's Still WIP

- [ ] **System vs. project-level tool scoping** — Currently project-level overrides system-level. Considering merge behavior (project adds to system baseline).
- [ ] **Tool governance UI** — Admin panel to view/manage which tools are available per project without editing EM settings directly.
- [ ] **Monolithic vs. modular tool EMs** — Current design favors small, focused tool EMs. May consolidate common tools (records, projects, surveys) into a single "REDCap Core Tools" EM for simpler setup.
- [ ] **Cost/token budgets** — Per-project token limits and cost tracking (Phase 3 settings exist but not yet enforced).
- [ ] **Human-in-the-loop for writes** — Option to require user confirmation before `records.save` executes (currently the agent writes directly).
- [ ] **Tool result preview** — Show the user what the agent is about to save before committing.

---

## Quick Reference: The MSPA Pattern in 30 Seconds

1. User pastes text into a REDCap field
2. User clicks a button
3. Your EM sends text + PID + record ID to SecureChatAI with `agent_mode: true`
4. Agent discovers the project's fields automatically
5. Agent parses the text, scores/classifies it, saves to the record
6. User sees fields populated

**Lines of custom code: ~200.  Projects it works on: all of them.**
