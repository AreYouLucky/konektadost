# Ask me! planning assistant

Click **Ask me!** in either sidebar or the always-visible lower-right floating button to open the messenger. It uses the municipality selected in the page URL, or lets you search municipalities by name/province. Province and barangay screens require a municipality selection before planning.

The action plan's focus is **matching DOST catalog technologies/programs to a selected location**, explaining each match, then planning deployment of those technologies. It is not a generic municipal development plan. Each proposed assignment identifies the actual technology, catalog reference, target municipality and supporting local need. Historical project titles are background evidence, not deployable technology assignments. Exact barangay/host sites require technology-specific validation; general priority rankings alone do not establish a fit.

Ask questions, request a technology-assignment action plan, then refine it with a budget, timeframe or sector priority. Complete plans have the five executive outputs and a descriptive technology/location plan title. Complete drafts and revisions are saved automatically as separate versions; any earlier assistant reply can also be saved with **Save as plan**.

Both sidebars have a **Generated action plans** list showing each saved plan's name, municipality and version. Clicking an entry opens that exact saved version in the floating assistant. **Show more plans** provides access to all older records, across the account's municipalities.

The assistant's **History** tab resumes previous conversations, and **Saved plans** opens earlier plan versions. **New conversation** archives the current chat without deleting its messages or plans. Chat history and saved plans remain available after logout and login. **Load earlier messages** retrieves older messages in batches of 40.

## Configuration

Uses the existing server-side `services.openai` configuration:

- `OPENAI_API_KEY`
- `OPENAI_MODEL` (default `gpt-4.1-mini`; must support Responses API function calling)
- `OPENAI_TIMEOUT` (per-provider-call timeout, default 60 seconds)
- `OPENAI_CA_BUNDLE` (optional local CA bundle; TLS verification stays enabled)

Never use `VITE_` variables for credentials. If Laravel configuration is cached, clear/rebuild it after changing environment values. No new dependency or domain model is required.

## Non-destructive database migration

Apply only the new history migration:

```bash
php artisan migrate --path=database/migrations/2026_11_03_120000_create_planning_chat_history_tables.php
```

It adds `planning_conversations`, `planning_messages` and `planning_action_plans`. The subsequent additive migration stores visual snapshots:

```bash
php artisan migrate --path=database/migrations/2026_11_03_130000_add_visuals_to_planning_history_tables.php
```

This adds nullable JSON `visuals` columns to chat messages and action plans. Do **not** run `migrate:fresh`, reset, or refresh; existing domain data, chats and plans are preserved. Conversation state and versioned plan snapshots use these tables through `PlanningChatHistory`, without adding more domain models.

Surviving chats from the earlier file-cache version are imported once when that account opens its municipality chat in the original login session. Already-expired cache entries cannot be recovered. No filesystem-wide or cross-user cache scan is performed.

## Graphs and diagrams

- Technology-match bar charts show actual deterministic relevance scores, not AI-generated values.
- Location-screening bar charts show recorded barangay priority scores, clearly distinguished from technology-specific site suitability.
- Assignment diagrams show location → matched need → named DOST technology → proposed deployment/validation.
- `PlanningConversationVisuals` builds these from function-tool outputs. `planning-visuals.tsx` renders safe native SVG charts and structured diagram nodes/edges, without model-authored HTML, SVG, Mermaid, JavaScript or a new chart dependency.
- Chart series, labels, provenance, diagram nodes and edges are saved as JSON with each assistant reply and saved plan version. Reopening a chat/plan restores that version's visuals. Older records with no visual snapshot are left unchanged rather than given invented charts.
- Revised plans can retain matching prior evidence with an explicit “not recomputed” caption. A retained assignment diagram is omitted if its technology is not mentioned in the revised draft. Changing technology selections should refresh the matching tools.

## Architecture and safeguards

- `PlanningChatController` authenticates/validates requests, resolves municipalities, and coordinates conversation history and locks.
- `PlanningChatService` calls the OpenAI Responses API and dispatches approved functions through the existing `FunctionToolRegistry` (Layers A–F).
- Tool schemas omit location overrides, model-supplied gaps and custom scoring weights. Laravel injects the selected municipality, validates argument types, and limits tool calls, history and evidence sizes.
- `generate_action_plan` runs with `include_narrative=false` in chat, avoiding the legacy Gemini narrative call. No-match plans do not fabricate a deployment package or feasibility status.
- Chat replies distinguish local evidence from screening estimates and proposed actions. Plans remain drafts pending LGU/DOST validation. Model-generated prose still requires human review.
- Full message history is stored in the database and scoped to the signed-in account and municipality. Archived conversations are retained, not deleted. Only four recent complete tool-call/output exchanges and the latest saved draft are sent as AI context, with older evidence dropped if the size limit is reached; this does not delete archived messages or plans.
- Saved plan versions contain the reply, model, draft status and compact tool-evidence snapshots where available. Revisions link back to the previous saved plan. A manual save of an older message does not attach potentially unrelated evidence from a newer turn.
- Responses use `Cache-Control: private, no-store`. Provider-side response storage is disabled (`store=false`); the user's messages and compact tool evidence are still sent to OpenAI for processing. Credentials and raw errors are not returned to the browser.
- Per-account/municipality locks prevent concurrent sends, archives, resumes and saves. Request UUIDs and database uniqueness checks prevent duplicate successful turns on network retries. Every history, plan, save and resume query enforces ownership. Routes are rate-limited.

Long planning turns can involve multiple API calls and database tools. The provider loop has a 150-second budget (database execution can add time), and the browser waits up to 210 seconds. Configure PHP/web-server/proxy timeouts accordingly. The UI shows a pending indicator; responses are not token-streamed.

## Checks

```bash
php artisan test --compact tests/Feature/PlanningChatTest.php
npx tsc --noEmit
npx eslint resources/js/components/planning-chat.tsx resources/js/components/planning-saved-plans.tsx resources/js/components/planning-visuals.tsx
npm run build
```

Backend chat tests apply only the new migration to in-memory SQLite, with a fake HTTP provider and in-memory locks. They do not require or modify the live domain database. They cover persistence across sessions, account/location isolation, non-destructive archives, resuming, plan names and versions, sidebar pagination, legacy imports and duplicate submissions.
