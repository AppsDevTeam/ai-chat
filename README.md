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
provision the agent again (step 5) - and sessions started under the old key cannot
continue.

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
`load`, `send`, `poll`, `delete`) and the streamed CSV of the export tool
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
     "poll": "...", "delete": "...", "export": "..."}, "mode": "full"}'></div>
```

`mode: "single"` renders one conversation without the list (a side panel); without
`conversationId` the first message starts a new conversation. Other options:
`readOnly`, `locale`, `accept`/`maxAttachments`/`maxAttachmentBytes` (take them
from `AttachmentPolicy`), `labels` (all texts), `icons` (Font Awesome classes by
default), `pollIntervalMs`, `fitViewport`. The root dispatches
`ai-chat:conversation` and `ai-chat:deleted` events. No inline styles or scripts -
works under a strict CSP; colours are CSS variables (`--ai-chat-*`).

### 5. Provision the agent

Run right after regenerating the anonymized views (same deploy step). Idempotent:
an existing agent is updated in place (same id, new version) with the current
system prompt and tool definitions:

```php
[$mapping, $created] = $provisioner->ensureProvisioned($schemaName);
```

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
refuses the message, and hard stops (`maxPolls`, `maxToolCalls`) that surface as
`TurnTimeoutException` - which is never retried, so a message cannot be duplicated.

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
| `aiChat.error.unexpected` | |
| `aiChat.export.empty`, `aiChat.export.failed` | |

The widget texts are passed in its `labels` config.

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
