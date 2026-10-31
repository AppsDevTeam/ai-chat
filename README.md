# ai-chat

An AI chat over your own database. The engine runs an Anthropic **Managed Agents**
conversation whose tools are safe, read-only SQL over the **anonymized views** of
[adt/doctrine-anonymization](https://github.com/AppsDevTeam/doctrine-anonymization) -
so the model can analyze your data without ever seeing personal details.

```bash
composer require adt/ai-chat
```

## What it is

Three layers, use as much as fits your project:

| Layer | Classes | You provide |
|---|---|---|
| **Engine** | `AgentTurnRunner`, `ToolHandler`, `AgentProvisioner`, `SystemPromptBuilder`, `PiiResponseFilter` | a `ManagedAgentsClient` (or the bundled Guzzle one) |
| **Chat** | `ChatService`, `ChatOptions`, `AttachmentPolicy`, `Dispatch\BackgroundQueueMessageDispatcher` | two Doctrine entities (traits included) and a queue |
| **HTTP + widget** | `Http\ChatEndpoint`, `Http\CsvExport`, `assets/ai-chat.js` + `assets/ai-chat.css` | a few routes/signals calling the endpoint |
| **MCP server** | `Mcp\McpServer`, `Mcp\McpResponse` | one authenticated POST route (see [MCP server](#mcp-server)) |

```
browser (ai-chat.js) ──► your routes ──► ChatEndpoint ──► ChatService ──► MessageDispatcher ──► queue
                                                              ▲                                     │
                                                              └── processQueuedMessage() (worker) ◄─┘
                                                                        │
                                                                  AgentTurnRunner ──► ManagedAgentsClient
                                                                        │
                                                                  ToolHandler ──► ReadOnlyQueryExecutor (anon schema)
```

A turn takes minutes (polling, tool round-trips), so the web request only stores
the message and dispatches it; a worker runs the turn and stores the (redacted)
reply, which the widget picks up by polling.

## Complete working example

The [`examples/`](examples) directory is a reference integration for Nette:

| File | Shows |
|---|---|
| `Entity/Conversation.php`, `Entity/Message.php`, `Entity/Agent.php` | the entities built from the package traits |
| `config.neon` | complete DI registration incl. the background-queue callback |
| `ui/ChatPresenter.php` + `.latte` | signals translating HTTP requests to `ChatEndpoint` calls, the CSV download, the widget root |
| `ui/ChatTranslator.php` | localizing the user-facing errors |

The examples are not autoloaded by the package - they are a starting point, not an
API. [adt/fancyadmin](https://github.com/AppsDevTeam/fancyadmin) ships a complete
integration (grid of conversations, chat in the side panel).

## Wiring

### 1. The transport

`Client\GuzzleManagedAgentsClient` (needs `guzzlehttp/guzzle`) sends the beta
headers and turns failures into `ApiUnavailableException` with a user-safe detail.
Extend it (`request()`, `send()`, `createHttpClient()`) for logging or retries, or
implement `Client\ManagedAgentsClient` over your own HTTP client - it maps 1:1 to
the REST endpoints.

The API key is either a string, or a `Client\ApiKeyProvider` asked on every request
- for a key per tenant (entered in the tenant's settings) that a long-running queue
consumer picks up without a restart. Agents, environments, sessions and files
belong to the workspace of the key they were created with, so after a key change
drop the agent mapping (the next session provisions a new agent, step 5) or call
`ensureProvisioned()` right away to validate the key - and sessions started under
the old key cannot continue.

### 2. The entities

Conversation, message and agent mapping are your Doctrine entities; the traits
carry the columns incl. their anonymization attributes, your side adds the ids and
the relations:

```php
#[ORM\Entity]
class AiChatConversation implements ADT\AiChat\Entity\ConversationInterface
{
	use ADT\AiChat\Entity\ConversationTrait;

	#[ORM\ManyToOne(targetEntity: User::class)]
	public User $user;    // the owner, named by ChatOptions::$ownerField
	...
}

#[ORM\Entity]
class AiChatMessage implements ADT\AiChat\Entity\MessageInterface
{
	use ADT\AiChat\Entity\MessageTrait;

	#[ORM\ManyToOne(targetEntity: AiChatConversation::class)]
	protected ConversationInterface $conversation;    // ChatOptions::$conversationField
	...
}

#[ORM\Entity]
class AiChatAgent implements ADT\AiChat\Entity\AgentInterface
{
	use ADT\AiChat\Entity\AgentTrait;
	...
}
```

`ChatService` creates conversations and messages with `new $class()`, so the
constructors must not require arguments.

`MessageTrait` carries the `model` and `effort` the message was sent or answered
with, `ConversationTrait` the user's
`rating` (1 / -1), `rating_comment` (anonymized as free text) and `rated`.

### 3. The chat service and the queue

```php
$chat = new ChatService($em, $client, $toolHandler, $queryExecutor, $provisioner,
	new BackgroundQueueMessageDispatcher($backgroundQueue),    // or your MessageDispatcher
	$piiFilter, $piiValueCollector,
	new ChatOptions(AiChatConversation::class, AiChatMessage::class, ownerField: 'user'),
);
```

- web request: `enqueueMessage($conversation, $text, $uploads)` - empty-message and
  rate-limit checks (`rateLimitPerMinute`/`rateLimitPerHour` across all
  conversations of the owner), attachments to the Files API, the user message
  stored, the title set, the message dispatched
- worker: `processQueuedMessage($conversationId, $text, $attachments)` - runs the
  turn, redacts the reply and stores it; failures are stored as an error reply
  (no exception escapes back to the queue, a retry would duplicate the message)

With adt/background-queue register the callback
`aiChatProcessMessage: [@ADT\AiChat\ChatService, processQueuedMessage]`.

Everything taking an owner scopes the data to it - a foreign conversation reads as
"not found". Who may see whose conversations is your decision
(`findConversation()` skips the check for privileged views).

### 4. The HTTP side and the widget

`ChatEndpoint` returns the JSON payloads of the widget actions (`conversations`,
`load`, `send`, `poll`, `cancel`, `rate`, `delete`) and the streamed CSV of the export tool
(`export`). Failures come back as `{success: false, error}`. Only displayable tool
results reach the browser - SQL, schema and table names stay on the server.

The widget is a vanilla ES module (npm package `adt-ai-chat` in this repository):

```js
import { mountAll } from 'adt-ai-chat';
import 'adt-ai-chat/ai-chat.css';
import { marked } from 'marked';
import DOMPurify from 'dompurify';
import Chart from 'chart.js/auto';

mountAll(document, { marked, DOMPurify, Chart });
```

```html
<div data-ai-chat='{"urls": {"conversations": "...", "load": "...", "send": "...",
     "poll": "...", "cancel": "...", "delete": "...", "export": "..."}, "mode": "full"}'></div>
```

`mode: "single"` renders one conversation without the list (a side panel); without
`conversationId` the first message starts a new conversation. Other options:
`readOnly`, `canManage`, `urlParam` (see Shared conversations), `locale`, `accept`/`maxAttachments`/`maxAttachmentBytes` (take them
from `AttachmentPolicy`), `modelSelection` (`ModelSelection::toClient()`), `labels`
(all texts), `icons` (Font Awesome classes by default), `pollIntervalMs`,
`fitViewport`, `params` (extra parameters sent with every request and export
link, e.g. `{"userId": 5}` for an administrator viewing someone's conversations;
`AiChat.get(root).setParams({...})` changes them and reloads the list). The root
dispatches `ai-chat:conversation` and `ai-chat:deleted` events. No inline styles or scripts -
works under a strict CSP; colours are CSS variables (`--ai-chat-*`).

### 5. Provisioning the agent

Nothing to do in the deploy: `AgentProvisioner::resolver()` runs before every new
session, creates the agent and environment when the schema has none, and updates
the same agent (new version, same id) when the system prompt or the tool
definitions changed. Changes are detected by a fingerprint stored on the mapping
(`AgentTrait::$fingerprint`), so an unchanged agent costs no API call. The deploy
thus needs no API key, and a broken key of one tenant fails only that tenant's
chat.

Provisioning on demand - e.g. from a console command, or to validate a newly
entered API key - updates the agent even when it looks current:

```php
[$mapping, $created] = $provisioner->ensureProvisioned($schemaName);
```

Upgrading from 0.6: the trait adds a nullable `fingerprint` column (migration in
your project); existing agents have none and are updated once by the next session.

### Running a turn without ChatService

```php
// What ChatService::processQueuedMessage() does for you:
$runner = new AgentTurnRunner($client, $toolHandler, $provisioner->resolver($schemaName));

$result = $runner->run($conversation, $userMessage, function (?string $sessionId) use ($em): void {
	$em->flush();    // persist the session id as soon as it changes
});

$text = $piiFilter->filter($result->text, $piiValueCollector->collect($result->toolData));
// store $text + $result->toolData + $result->tokensInput/Output as a message
```

The runner handles: ignoring pre-existing session events, executing custom tools
and sending their results back, token accounting (window usage = last request incl.
cache counters; output summed), a retry with a fresh session when a stale one
refuses the message, and hard stops that surface as `TurnTimeoutException` - which
is never retried, so a message cannot be duplicated:

- `AiChatOptions::$idleTimeoutSeconds` - no new event for that long: the runner asks
  for the session status and goes on while it is still running (a long query, a
  thinking model), otherwise it interrupts the session and gives up;
- `$maxTurnSeconds` - a hard cap of the whole turn;
- `$maxToolCalls` - per thread (the main agent and every subagent).

Pass a `TurnMonitor` as the last argument of `run()` to stop a turn
(`isCancelled()`: the runner sends `user.interrupt` and returns what the agent wrote
as `TurnResult::$interrupted`) and to receive its progress (`onProgress()`: tool
calls and subagents). `ChatService` uses `ConversationTurnMonitor`, which reads
`turnCancelled` and writes `turnActivity`/`turnProgress` of the conversation.

### Stopping a reply and subagents

`ChatEndpoint::cancel()` (the widget's `urls.cancel`) marks the running turn as
cancelled; the worker interrupts the agent and stores what it has as a message with
`interrupted = true` - an ordinary reply, the conversation goes on in the same
session. The poll response carries the turn state (`turn`: activity, subagents with
their status and number of queries); the widget restarts its time limit whenever the
activity changes, so it only gives up on a worker that went silent.

`AgentProvisioner(..., subagents: true)` makes the agent a coordinator that may
delegate independent parts of a question to copies of itself
(`multiagent: {type: "coordinator", agents: [{type: "self"}]}`); each copy has the
same tools and its own context window. Subagent calls are executed by the same tool
handler. The reply then holds a `subagent` entry per delegated thread (task and
report, PII-filtered like the reply); the widget shows it as a collapsed card with
the charts and tables of that thread. Subagents cost more tokens - enable them per
tenant or deployment where the questions are broad enough.

### Model selection

`ChatOptions::$modelSelection` lists the models (and thinking effort levels) the
user may pick for a conversation:

```php
new ChatOptions(AiChatConversation::class, AiChatMessage::class,
	modelSelection: new ModelSelection(
		models: [
			'claude-sonnet-5-5' => ['label' => 'Claude Sonnet 5.5', 'contextWindowTokens' => 1000000],
			'claude-opus-5-5' => ['label' => 'Claude Opus 5.5', 'contextWindowTokens' => 1000000],
		],
		efforts: ['medium', 'high', 'max'],    // [] = send none, the model default
		defaultModel: 'claude-sonnet-5-5',     // null = the first model
		defaultEffort: 'high',
	),
);
```

The widget shows a model and an effort select (pass `modelSelection` from
`ModelSelection::toClient()` in its config) and sends them with every message;
`ChatEndpoint::send($owner, $id, $text, $uploads, $model, $effort)` validates them
and stores them on the message. The conversation runs on the choice of its latest
message (`ChatService::getModelChoice()`), it keeps none of its own. The session starts with
`agent: {type: "agent_with_overrides", id, model: {id, effort}}`, so one agent
serves every model. A session cannot switch models, so switching within a
conversation drops its session: the next turn starts a new one and sends the earlier
transcript ahead of the message (`ChatService::buildHistory()` - the texts, the SQL
and the rendered tables and charts, the newest `ChatOptions::$historyMaxChars`).
The same happens when an expired session is replaced. AI replies show the model
that wrote them. The model select shows the context window next to the label
(`Claude Opus 5.5 (1M)`) and the context gauge follows the selected model: its window,
and once the model or effort differs from the latest message's, the estimated size of
the new session (`contextUsage.historyTokens` - the first reply's input plus the
transcript) with the `contextSwitch` label.

Without models (the default) the selection is off, messages keep a null model
and run on the model of the agent. Only offer models that support the listed effort
levels (e.g. not Haiku 4.5, which takes none).

### Rating

With `urls.rate` in the widget config the owner can rate a conversation that has a
reply - thumbs up / down plus an optional comment; a second click on the active
thumb takes the rating back. A read-only view shows the rating and the comment, the
conversation list marks rated conversations. On the server:

```php
// POST conversationId, rating ('1', '-1' or '' to clear), comment
$rating = $request->getPost('rating');
$response = $endpoint->rate($owner, $conversationId, $rating !== '' && $rating !== null ? (int) $rating : null, $request->getPost('comment'));
```

Only the owner rates - pass the signed-in user, never the owner of a read-only view.
`ChatOptions::$ratingCommentMaxLength` (2000) cuts longer comments.

### Shared conversations and links

With `ChatOptions::$senderField` other users may write into a conversation - a
colleague continuing your analysis. Map the field on the message entity (nullable,
it stays empty for AI replies):

```php
#[ORM\ManyToOne(targetEntity: User::class)]
#[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
protected ?User $sender = null;
```

```php
$endpoint->setSenderFormatter(fn(User $user): string => $user->getName());

// the owner's conversation, written by the signed-in colleague
$endpoint->send($owner, $id, $text, $uploads, $model, $effort, sender: $me);
// readOnly false = may write, canManage false = may not rate or delete
$endpoint->conversations($owner, false, $owner === $me);
$endpoint->load($owner, $id, false, $owner === $me, viewer: $me);
$endpoint->poll($owner, $id, $afterId, viewer: $me);
```

User messages then carry `sender: {name, self, owner}` and the widget shows the
name above every message the owner of the conversation did not write, and above
the owner's messages for the other readers. The rate limit counts the messages per sender. Who may write into
whose conversation is your decision, as with reading.

A new message is refused (`aiChat.error.turnPending`) while the previous one of
the conversation waits for its reply - two turns must not run in one session at
once. `ChatOptions::$pendingTurnSeconds` (900) releases it after a lost turn.

Widget config `urlParam: "conversation"` keeps the open conversation in that query
parameter of the page URL, so the address can be sent to someone; a page opened
with it loads the conversation. Whose conversations to list for such a link (the
owner's, when the viewer may see them) is up to the page - e.g. pass the owner in
`params`.

### Attachments

Users can attach files (images, PDFs, text/CSV) to a message. `ChatService` checks
them against `AttachmentPolicy` (count, size, media types), uploads them to the
Files API in the web request and passes only the file ids through the queue; the
runner sends them as image/document content blocks of the same `user.message`.
Without ChatService:

```php
$file = $client->uploadFile('export.csv', $contents, 'text/plain');

$result = $runner->run($conversation, $userMessage, $persist, [
    new Attachment($file['id'], 'text/plain', 'export.csv'),
]);
```

Media types follow the content-block rules: `image/jpeg|png|gif|webp` become image
blocks, `application/pdf` and `text/plain` document blocks. Other text formats
(CSV, Markdown, logs) go as `text/plain` - the filename keeps the extension.

## MCP server

`Mcp\McpServer` publishes the data tools (`get_database_schema`, `execute_sql`) over
the [Model Context Protocol](https://modelcontextprotocol.io), so other agents -
Claude Code, Claude Desktop, a claude.ai connector - can query the same anonymized
data the chat uses. Transport is **Streamable HTTP, stateless**: every JSON-RPC
message is a POST answered by one `application/json` response (no SSE, no session).
Supported protocol versions: `2025-11-25`, `2025-06-18`, `2025-03-26`, `2024-11-05`.

```php
$server = new McpServer(
    $toolHandler,                       // the same ToolExecutor the chat uses
    new PiiResponseFilter(),            // applied to every string of every tool result
    serverName: 'my-app',
    serverVersion: '1.0.0',
    instructions: McpServer::DEFAULT_INSTRUCTIONS, // shown to the client model
    logger: $auditLogger,               // one info record per tool call
);

// in your controller, AFTER authenticating the caller:
$response = $server->handle($request->getMethod(), $request->getBody(), ['user' => $userId]);
// -> $response->status, $response->headers, $response->body (null = no body)
```

Authentication and authorization stay in your application: check the bearer token
and the user's permission first and answer `McpResponse::unauthorized()` /
`McpResponse::forbidden()` otherwise. The tools are read-only over the read-only
account, results are row-capped like in the chat, and chat-only tools (charts,
tables, CSV export) are never exposed.

Connect a client:

```bash
claude mcp add --transport http my-app https://example.com/mcp --header "Authorization: Bearer <token>"
```

[adt/fancyadmin](https://github.com/AppsDevTeam/fancyadmin) ships the integration
(per-user tokens, endpoint presenter, ACL).

## Translations

User-facing errors are `AiChatException`s with a translation key; pass a
translator to `ChatService::setTranslator(fn(string $key, array $params, string
$default): string)`. Without it the English defaults are used. Keys:

| Key | Params |
|---|---|
| `aiChat.error.emptyMessage` | |
| `aiChat.error.rateLimitMinute`, `aiChat.error.rateLimitHour` | `limit` |
| `aiChat.error.tooManyAttachments` | `max` |
| `aiChat.error.attachmentTooLarge` | `name`, `max` (MB) |
| `aiChat.error.attachmentType`, `aiChat.error.attachmentUploadFailed` | `name` |
| `aiChat.error.serviceUnavailable` | `detail` |
| `aiChat.error.turnTimeout`, `aiChat.error.tooManyToolCalls` | |
| `aiChat.error.conversationNotFound`, `aiChat.error.invalidExport` | |
| `aiChat.error.unknownModel` | `model` |
| `aiChat.error.unknownEffort` | `effort` |
| `aiChat.error.invalidRating` | |
| `aiChat.error.turnPending` | |
| `aiChat.error.unexpected` | |
| `aiChat.export.empty`, `aiChat.export.failed` | |

The widget texts are passed in its `labels` config.

## Upgrading to 0.11

- `ManagedAgentsClient`: `createAgent()`/`updateAgent()` take `?array $multiagent`
  (`updateAgent()` always sends it - null clears it), new `retrieveSession()`,
  `listSessionEvents($sessionId, $limit, $page, $order)`. Own implementations must
  follow; `GuzzleManagedAgentsClient` does.
- `AiChatOptions`: `maxPolls` is gone - the turn ends on inactivity
  (`idleTimeoutSeconds`, default 300) or the cap (`maxTurnSeconds`, default 3600);
  `interruptGraceSeconds` is how long a stopped turn waits for the session to settle.
  The constructor is `(pollIntervalMicroseconds, idleTimeoutSeconds, maxToolCalls,
  systemPromptMaxLength, maxTurnSeconds, interruptGraceSeconds)` - update positional
  arguments.
- New columns (migration in your project): conversation `turn_activity`,
  `turn_progress`, `turn_cancelled`, message `interrupted` (bool, default false).
  `turn_progress` holds subagent tasks - it is marked for anonymization.
- `ChatOptions::$pendingTurnSeconds` counts from the last activity of the worker,
  not from the message - keep it above `idleTimeoutSeconds`.
- New endpoint action `cancel` (`urls.cancel` of the widget, labels `stop`,
  `stopping`, `interrupted`, `subagent`, `subagentStatus`, `toolCalls`).
- Long sessions: events are read newest first with paging, so turns beyond the
  first 1000 events of a session are no longer missed.

## Upgrading to 0.10

- The conversation no longer stores `model` and `effort`: the model of a conversation
  is the one of its latest message (`ChatService::getModelChoice()`). Drop both
  columns from the conversation table. Messages with a null model ran on the agent
  model - fill them, or the first message with a model selection starts a new
  session:

```sql
UPDATE ai_message SET model = 'claude-sonnet-4-6', effort = 'high' WHERE model IS NULL;
```

  (your agent model and table name). `ConversationInterface` lost
  `get/setModel()` and `get/setEffort()`.

## Upgrading to 0.9

- A message cannot be sent while the previous one of the conversation still waits
  for its reply (`aiChat.error.turnPending`, `ChatOptions::$pendingTurnSeconds`).
- Optional `ChatOptions::$senderField` (a new nullable column on your message entity)
  for conversations several users write into; `ChatService::enqueueMessage()` and
  `ChatEndpoint::send()` take a `$sender`, `load()`/`poll()` a `$viewer`,
  `conversations()`/`load()` a `$canManage` flag. Messages carry `sender` (null
  without the field).
- The model and effort can be switched within a conversation (see Model
  selection). `MessageInterface` has new `get/setModel()` and `get/setEffort()`
  (the trait implements them) - generate a migration for the new nullable columns
  `model` and `effort` of the message (fill existing rows from their conversation).
  `ChatService::isModelLocked()` is gone; `AgentTurnRunner::run()` takes an
  optional `$history` callback. Messages carry `model` and `effort`.
- `render_table` / `render_chart` arguments the model sends as JSON-encoded strings
  are decoded (`ToolHandler::normalizeRenderResult()`), stored ones too.
- Widget: `canManage` (rating and deleting, default not read-only), `urlParam`,
  the `.ai-chat__sender` and `.ai-chat__model-tag` labels; polling stops at the reply, not at the first new
  message. Install it from the same tag (`yarn add adt-ai-chat@github:AppsDevTeam/ai-chat#v0.9.0`).

## Upgrading to 0.8

- `ConversationInterface` has new `get/setModel()`, `get/setEffort()`,
  `get/setRating()`, `get/setRatingComment()` and `get/setRated()` (the trait
  implements them) - generate a migration for the new nullable columns `model`,
  `effort`, `rating`, `rating_comment` and `rated`.
- `ManagedAgentsClient::createSession()` takes a fourth `?array $model` (the session
  override); custom implementations must accept it.
- New widget config `modelSelection` and URL `rate`, new labels and icons
- Widget config `params` and `AiChat::setParams()` for extra request parameters; install the widget from the same tag (`yarn add adt-ai-chat@github:AppsDevTeam/ai-chat#v0.8.0`)
  (`rateUp`, `rateDown`) - both features stay off without them.

## Upgrading to 0.4

- `ConversationInterface` has a new `setCreated()` (the trait implements it).
- `AiChatException` carries an optional translation key - `TurnTimeoutException`s
  now have one, so translate them via `describeError()`.
- The example `GuzzleManagedAgentsClient`, `ChatService`, dispatcher and UI moved
  into the package (`Client\`, `ChatService`, `Dispatch\`, `Http\`, `assets/`);
  the example `Message` entity is replaced by `MessageInterface` + `MessageTrait`
  (adds an `attachments` column).

## Tools

`ToolHandler` is the default `ToolExecutor`:

| Tool | Purpose |
|---|---|
| `get_database_schema` | tables of the anon schema; columns incl. `#[Description]` texts |
| `execute_sql` | read-only SELECT, row-capped, over the read-only account |
| `render_chart` / `render_table` | displayable results for your frontend |
| `export_csv` | validates the SQL and returns a download token; your endpoint streams the rows later via `ReadOnlyQueryExecutor::streamQuery()` (never through the model) |

Different tool set? Implement `ToolExecutor` yourself.

## Output redaction

`PiiResponseFilter` redacts e-mails, phone numbers, bank accounts and secrets from
the final reply by pattern, and - via `PiiValueCollector` - the literal values that
appeared in personal-data columns of SQL results (names, birth dates), which no
regex can recognise. Replacement labels and the phone prefix are configurable for
localized chats.

## System prompt

`SystemPromptBuilder` renders a template with a `{{tableList}}` placeholder; only
table names go into the prompt (columns are fetched on demand by the schema tool),
which keeps every inference cheap and clear of the hard `system` length limit.
A generic default template ships in `src/resources/system-prompt.md` - copy it and
tune the wording to your domain.

## Tests

```bash
composer install
vendor/bin/codecept run unit
```

The suite drives the full turn loop against a scriptable fake of the Managed
Agents API (tool round-trips, history isolation, timeouts, stale-session retry,
token accounting), the chat service and endpoint over an in-memory SQLite database
(ownership, rate limits, attachments, error replies, the tool-data whitelist, CSV
export), the Guzzle transport over a mock handler, plus the tool handler, prompt
builder and redaction - no network.
