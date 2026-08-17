<?php

declare(strict_types=1);

namespace ADT\AiChat;

use ADT\AiChat\Exception\AiChatException;
use ADT\DoctrineAnonymization\ReadOnlyQueryExecutor;

/**
 * Builds the system prompt of the agent from a template.
 *
 * The prompt is kept slim on purpose: instructions plus only the LIST of table
 * names ({{tableList}} placeholder). Columns and their descriptions are fetched
 * on demand through the get_database_schema tool - cheap (a small context on
 * every inference) and safe against the hard length limit of the agent `system`
 * field.
 *
 * A default template ships in resources/system-prompt.md; projects usually keep
 * their own copy tuned to their domain and pass its path.
 */
class SystemPromptBuilder
{
	public const TABLE_LIST_PLACEHOLDER = '{{tableList}}';

	public function __construct(
		private readonly ReadOnlyQueryExecutor $queryExecutor,
		private readonly string $templatePath,
		private readonly AiChatOptions $options = new AiChatOptions(),
	) {
	}

	public static function defaultTemplatePath(): string
	{
		return __DIR__ . '/resources/system-prompt.md';
	}

	/**
	 * @throws AiChatException
	 */
	public function build(): string
	{
		$template = @file_get_contents($this->templatePath);
		if ($template === false) {
			throw new AiChatException('System prompt template could not be read: ' . $this->templatePath);
		}

		// The agent `system` field has a hard length limit; the table list is small
		// and $budget is only a safety margin (whatever is left after the template).
		$reserved = mb_strlen(str_replace(self::TABLE_LIST_PLACEHOLDER, '', $template)) + 1000;
		$budget = max(0, $this->options->systemPromptMaxLength - $reserved);

		return strtr($template, [self::TABLE_LIST_PLACEHOLDER => $this->buildTableList($budget)]);
	}

	/**
	 * @throws AiChatException
	 */
	private function buildTableList(int $maxLength): string
	{
		try {
			$lines = [];
			$length = 0;
			$truncated = false;

			foreach ($this->queryExecutor->getTables() as $table) {
				$name = (string) $table;
				if ($name === '') {
					continue;
				}

				$line = '- ' . $name;
				if ($length + mb_strlen($line) + 1 > $maxLength) {
					$truncated = true;
					break;
				}

				$lines[] = $line;
				$length += mb_strlen($line) + 1;
			}

			if (!$lines) {
				return '(Schema is empty. Use get_database_schema tool to explore.)';
			}

			if ($truncated) {
				$lines[] = '- ... (further tables omitted - use get_database_schema to list them)';
			}

			return implode("\n", $lines);
		} catch (AiChatException $e) {
			throw $e;
		} catch (\Throwable $e) {
			// Used at provisioning time only. When the anonymized schema cannot be
			// read, the agent must NOT be provisioned with a placeholder prompt -
			// fail loudly so the problem gets fixed.
			throw new AiChatException('Anonymized schema could not be loaded for the agent system prompt: ' . $e->getMessage(), 0, $e);
		}
	}
}
