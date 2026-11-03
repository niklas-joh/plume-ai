# Tool Call Consistency — Design Spec

**Issue:** #567 — Tool calls not consistent  
**Date:** 2026-06-08  
**Status:** Approved for implementation

---

## Problem

`plan_post` and `plan_update` are registered in `ToolRegistry` (exposed to the AI when write tools are
enabled) but are absent from `ToolExecutor`'s dispatch table. Every time the AI calls either tool the
executor returns `'Unknown tool: plan_post'`, which is fed back to the model as a tool result. The model
then apologises and falls back to a text response. The failure is deterministic — not a race condition or
flaky network — but it looks "inconsistent" because it depends on whether `stilus_enable_write_tools` is
on or off at call time.

Secondary problem: without a structural guarantee that the model always uses a tool, future tools can
exhibit the same silent-fail pattern.

**Out of scope here:** expanding the WordPress tool inventory (edit page, install plugin, create product,
etc.). That work is tracked in a separate backlog issue.

---

## Design

### Invariant

Every LLM response is a tool call. There are no bare text responses from the model.

```
User message
    │
    ▼
ChatRestController
    │  tools: [chat_response, plan_post, plan_update, get_recent_posts, …]
    │  tool_choice: any  (all providers, when tools are loaded)
    ▼
Provider (Claude / OpenAI / Gemini)
    │
    ▼
Tool call (always)
    ├── chat_response({message})     → extract text, exit loop, return to client
    ├── plan_post({…})               → store plan transient → feed result back
    │       └── AI then calls chat_response({message: "Here's the plan…"})
    ├── plan_update({…})             → store plan transient → feed result back
    │       └── AI then calls chat_response({…})
    └── get_recent_posts / search / … → execute, feed result back
            └── AI then calls chat_response({…})
```

---

## Components

### 1. `chat_response` pass-through tool

Registered in `ToolRegistry` with `requires_write_tools: false` (always present when tools are loaded).

```json
{
  "name": "chat_response",
  "description": "Send a conversational reply to the user. Use this for all responses — acknowledgements, explanations, summaries, and follow-ups after completing an action.",
  "parameters": {
    "type": "object",
    "properties": {
      "message": { "type": "string", "description": "The reply to display to the user." }
    },
    "required": ["message"]
  }
}
```

No executor logic — the controller detects this tool and exits the loop, treating `message` as the
final text content.

### 2. `force_tool_use` in `CompletionRequest`

Add `public readonly bool $force_tool_use = true` as a new constructor parameter to
`includes/Providers/CompletionRequest.php`. Default `true`; callers that want the old
`auto` behaviour can pass `false`.

Each provider checks `$request->force_tool_use && !empty($request->tools)` and adds:

| Provider | File | API parameter |
|---|---|---|
| Claude | `ClaudeProvider.php` | `$body['tool_choice'] = ['type' => 'any']` |
| OpenAI | `OpenAIProvider.php` | `$body['tool_choice'] = 'required'` (replaces current `'auto'`) |
| Gemini | `GeminiProvider.php` | `$body['tool_config'] = ['function_calling_config' => ['mode' => 'ANY']]` |
| Ollama | No change | `supports_tools()` returns false; tools never loaded |

### 3. `plan_post` executor handler

Add to `ToolExecutor::execute()` dispatch table (alongside the existing eight entries in
`includes/Tools/ToolExecutor.php`). Handler:

1. Checks `user_can( $user_id, 'edit_posts' )` — returns `['error' => 'Insufficient permissions.']` if denied.
2. Validates args (`title` required; `post_type` in allowed list from `ToolRegistry::get_allowed_post_types()`).
3. Generates a plan ID: `substr( wp_generate_uuid4(), 0, 8 )`.
4. Stores plan as a WordPress transient — key format `stilus_plan_{user_id}_{plan_id}` so ownership is
   encoded in the key: `set_transient( "stilus_plan_{$user_id}_{$id}", $data, HOUR_IN_SECONDS )`.
5. Returns:
   ```php
   [
       'status'      => 'pending_approval',
       'id'          => $id,
       'plan_type'   => 'create',
       'title'       => $args['title'],
       'outline'     => $args['outline'] ?? '',
       'post_type'   => $args['post_type'] ?? 'post',
       'post_status' => $args['status'] ?? 'draft',
   ]
   ```

### 4. `plan_update` executor handler

Same pattern — capability check first, then same transient key format
(`stilus_plan_{$user_id}_{$id}`). Returns:
```php
[
    'status'         => 'pending_approval',
    'id'             => $id,
    'plan_type'      => 'update',
    'post_id'        => $source_post_id,
    'changes'        => $args['changes'],
    'post_status'    => $args['status'] ?? '',
]
```

### 5. Agentic loop change (`ChatRestController`)

The loop in `send_message()` (current exit: `! $response->is_tool_call()`) gains a second exit path.
Before executing any tool use blocks, the controller checks whether the call is `chat_response`:

```php
// inside the while loop, before $all_tool_uses collection
$tool_call = $response->tool_call;  // already extracted by provider normalisation
if ( 'chat_response' === ( $tool_call['name'] ?? '' ) ) {
    $final_text     = $tool_call['input']['message'] ?? '';
    $final_response = $response->with_text( $final_text );  // thin helper — see below
    break;
}
```

`CompletionResponse` gains a `with_text( string $text ): static` helper — a named constructor that
clones the current instance with `content` replaced. Immutable; creates a new object, does not mutate.
All other fields (`model`, `tokens`, `cost_usd`, `raw`) are copied unchanged.

> **Note (historical):** `cost_usd` was later removed from `CompletionResponse`; it no longer exists as a copied field.

**`pending_plan` propagation:**

The loop initialises `$pending_plan = null` before the `while`. When `plan_post` or `plan_update`
returns `status === 'pending_approval'`, the controller stores the result:

```php
foreach ( $tool_results as $result ) {
    if ( ( $result['status'] ?? '' ) === 'pending_approval' ) {
        $pending_plan = $result;
        break;
    }
}
```

`$pending_plan` is included in the final REST response body.

**`tools_called` collection:**

The loop initialises `$tools_called = []`. Each iteration appends every tool name that was executed.
These are passed to the REST response for the frontend passive indicator.

### 6. REST response extension

```json
{
  "message": "I've prepared a post plan for your review.",
  "model": "claude-sonnet-4-6",
  "tokens": 1234,
  "pending_plan": {
    "id": "abc123",
    "title": "Using AI in WordPress: A Complete Guide",
    "outline": "Introduction, AI tools overview, practical tips.",
    "post_type": "post",
    "post_status": "draft"
  },
  "tools_called": ["plan_post"]
}
```

`pending_plan` and `tools_called` are null/empty when not applicable.

### 7. Plan execution endpoint

`POST /stilus/v1/plans/{id}/execute`

- Gets `$current_user_id` from `get_current_user_id()`.
- Constructs transient key `stilus_plan_{$current_user_id}_{$id}` — ownership is inherent in the key.
  A different user cannot guess another user's key because the key is namespaced by user ID.
- `get_transient()` returning false → 404 (expired or never existed — client shows "Plan expired, please ask again.").
- Reads `$plan['plan_type']` from the stored transient to route to `'create_post'` or `'update_post'`.
- Calls `ToolExecutor::execute( $tool_name, $args, $current_user_id )` via the existing public dispatch method — executor re-validates capability internally.
- Deletes transient with `delete_transient()`.
- Returns `{ post_id, edit_url }`.

No explicit reject endpoint — transient expires after 1 hour. Dismiss is client-side only.

### 8. `PlanCard` React component

Rendered inline in the chat thread when `pending_plan` is present in the REST response.

- **Create / Update button** — POSTs to `/plans/{id}/execute`. On success shows the WP edit link.
- **Edit toggle** — shows inline fields (title, outline, status) pre-filled; re-submits with edited values.
- **Dismiss** — removes card from UI state; does not call the server.

### 9. Passive tool indicator

`tools_called` array in the REST response drives a subtle pill shown during the pending request:
"Searching posts…", "Fetching content…", etc. Fades out when the response arrives. No streaming
required.

---

## Error handling

| Scenario | Behaviour |
|---|---|
| `plan_post` with missing title | Executor returns `['error' => 'Title is required.']`; AI receives error, calls `chat_response` asking user to provide a title. |
| Plan transient expired | `/plans/{id}/execute` returns 404; frontend shows "This plan has expired — please ask again." |
| `create_post` capability denied | Executor returns permission error; AI calls `chat_response` with the error message. |
| Tool loop hits 5-iteration cap | Existing 500 behaviour unchanged. |

---

## Testing

1. Enable write tools in Stilus → Settings.
2. Open Chat, type *"Write me a post about using AI in WordPress."*
   - Expected: AI calls `plan_post`, then `chat_response`. PlanCard appears. No apology.
3. Click **Create** on the PlanCard.
   - Expected: Post created as draft. Edit link shown.
4. Open Chat with a post in context. Ask *"Make the intro punchier."*
   - Expected: AI calls `plan_update`, then `chat_response`. Update PlanCard appears.
5. Dismiss a PlanCard. Confirm the transient is NOT consumed (post not created).
6. Switch provider to OpenAI (if BYOK). Repeat steps 2–4.
   - Expected: identical behaviour.
7. Disable write tools. Confirm `plan_post` disappears from the tool list and AI can still converse.
8. Ask a read-only question: *"What are my most recent posts?"*
   - Expected: `get_recent_posts` called, then `chat_response`. No PlanCard. Passive "Fetching posts…" pill shown while request is in-flight.

---

## Future work (separate issue)

Expand the WordPress tool inventory:
- `edit_page`, `create_page`
- `install_plugin`, `activate_plugin`, `search_plugins`
- `update_post_meta`, `set_featured_image`
- WooCommerce product tools

The `chat_response` + `tool_choice: any` foundation established here means each new tool only needs
an entry in `ToolRegistry` and `ToolExecutor` — no further consistency work required.
