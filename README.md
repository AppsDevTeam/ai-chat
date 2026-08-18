# ai-chat

An AI chat over your own database. The engine runs an Anthropic **Managed Agents**
conversation whose tools are safe, read-only SQL over the **anonymized views** of
[adt/doctrine-anonymization](https://github.com/AppsDevTeam/doctrine-anonymization) -
so the model can analyze your data without ever seeing personal details.

```bash
composer require adt/ai-chat
```

## What it is (and is not)

The package is the **conversation engine**: session handling, the polling loop,
tool execution, agent provisioning, prompt building and output redaction. It ships
**no HTTP client, no queue and no frontend** - those live in your project behind
two small interfaces, because every project already has its own.

```
ManagedAgentsClient (you implement)  ──►  AgentTurnRunner  ──►  TurnResult
                                            │
ToolHandler (SQL / charts / export) ◄──────┘
        │
ReadOnlyQueryExecutor (adt/doctrine-anonymization, read-only account, anon schema)
```

## Complete working example

The [`examples/`](examples) directory is a full reference integration you can copy
into a project and adjust:

| File | Shows |
|---|---|
| `GuzzleManagedAgentsClient.php` | the transport - raw HTTP with the beta header, over Guzzle |
| `Entity/Conversation.php`, `Entity/Agent.php` | entities built from the package traits, ownership on your side |
| `Entity/Message.php` | a minimal transcript entity (the package has no message contract on purpose) |
| `ChatService.php` | the coordinator - web request stores + dispatches, worker runs the turn, redacts and persists |
| `QueueMessageDispatcher.php` | the background hand-off (here adt/background-queue) incl. the consumer wiring |
| `config.neon` | complete DI registration of everything above |
| `ui/ChatControl.php` + `.latte` | the frontend as a Nette component: AJAX signals, polling, CSV download endpoint, tool-data whitelisting |
| `ui/ChatPresenter.php` + `.latte` | a page hosting the control incl. the CDN includes (Chart.js, marked, DOMPurify) |
| `ui/assets/aiChatControl.js`, `ui/assets/ai-chat.css` | the complete browser side: conversations, markdown rendering, charts, tables, context gauge, CSV buttons |

The examples are not autoloaded by the package - they are a starting point, not an
API.

## Wiring

### 1. Implement the transport

`ManagedAgentsClient` maps 1:1 to the Managed Agents REST endpoints (beta). Use
your existing HTTP client with its logging and error handling:

```php
class MyAiClient implements ADT\AiChat\Client\ManagedAgentsClient { ... }
```

### 2. Implement the dispatcher

A turn takes minutes, so it must run in a worker. `MessageDispatcher::dispatch()`
hands the message to your queue; the worker then runs the turn:

```php
class QueueMessageDispatcher implements ADT\AiChat\Dispatch\MessageDispatcher
{
	public function dispatch(int|string $conversationId, string $userMessage): void
	{
		$this->queue->publish('aiChatProcessMessage', compact('conversationId', 'userMessage'));
	}
}
```

### 3. Map the entities

Conversation and agent mapping are your Doctrine entities implementing the package
interfaces; the traits carry the default mapping, your side adds the id and the
owner relation:

```php
#[ORM\Entity]
class AiChatConversation implements ADT\AiChat\Entity\ConversationInterface
{
	use ADT\AiChat\Entity\ConversationTrait;

	#[ORM\ManyToOne(targetEntity: User::class)]
	public User $user;    // ownership stays on your side
	...
}

#[ORM\Entity]
class AiChatAgent implements ADT\AiChat\Entity\AgentInterface
{
	use ADT\AiChat\Entity\AgentTrait;
	...
}
```

### 4. Provision the agent

Run right after regenerating the anonymized views (same deploy step). Idempotent:
an existing agent is updated in place (same id, new version) with the current
system prompt and tool definitions:

```php
[$mapping, $created] = $provisioner->ensureProvisioned($schemaName);
```

### 5. Run a turn (in the worker)

```php
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
token accounting) plus the tool handler, prompt builder and redaction - no network
and no database.
