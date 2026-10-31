<?php

declare(strict_types=1);

namespace ADT\AiChat;

use ADT\AiChat\Exception\AiChatException;

/**
 * Which Claude models and thinking effort levels a user may pick for a conversation.
 *
 * The choice is stored on the conversation and sent as a per-session override of the
 * agent (`agent_with_overrides`), so one provisioned agent serves every model. A
 * session cannot switch models - switching within a conversation starts a new
 * session, seeded with the transcript ({@see ChatService::buildHistory()}).
 *
 * With no models configured the selection is off: conversations keep a null model
 * and run on the model of the agent.
 */
class ModelSelection
{
	public const DEFAULT_EFFORTS = ['medium', 'high', 'max'];

	/**
	 * @param array<string, array{label?: string, contextWindowTokens?: int}> $models model id => its
	 *        label in the widget and context window (for the gauge); the first one is the default
	 * @param list<string> $efforts effort levels to offer; empty sends no effort (the model default)
	 * @param string|null $defaultModel null = the first of $models
	 * @param string|null $defaultEffort null or a level not in $efforts = the first of $efforts
	 */
	public function __construct(
		public readonly array $models = [],
		public readonly array $efforts = self::DEFAULT_EFFORTS,
		public readonly ?string $defaultModel = null,
		public readonly ?string $defaultEffort = 'high',
	) {
		if ($defaultModel !== null && !isset($models[$defaultModel])) {
			throw new \InvalidArgumentException(sprintf('The default model "%s" is not among the models.', $defaultModel));
		}
	}

	/**
	 * @param array<string, mixed> $options the constructor arguments by name
	 */
	public static function fromArray(array $options): self
	{
		if (isset($options['efforts']) && is_array($options['efforts'])) {
			$options['efforts'] = array_values($options['efforts']);
		}

		return new self(...$options);
	}

	public function isEnabled(): bool
	{
		return $this->models !== [];
	}

	public function getDefaultModel(): ?string
	{
		return $this->defaultModel ?? array_key_first($this->models);
	}

	public function getDefaultEffort(): ?string
	{
		if ($this->defaultEffort !== null && in_array($this->defaultEffort, $this->efforts, true)) {
			return $this->defaultEffort;
		}

		return $this->efforts[0] ?? null;
	}

	/**
	 * Validates the choice of the user; a missing model or effort falls back to the
	 * default.
	 *
	 * @return array{model: string|null, effort: string|null} both null when the selection is off
	 * @throws AiChatException
	 */
	public function resolve(?string $model, ?string $effort): array
	{
		if (!$this->isEnabled()) {
			return ['model' => null, 'effort' => null];
		}

		$model = $model !== null && $model !== '' ? $model : $this->getDefaultModel();
		if (!isset($this->models[$model])) {
			throw AiChatException::translatable(sprintf('Model "%s" is not available.', $model), 'aiChat.error.unknownModel', ['model' => $model]);
		}

		if ($this->efforts === []) {
			return ['model' => $model, 'effort' => null];
		}

		$effort = $effort !== null && $effort !== '' ? $effort : $this->getDefaultEffort();
		if (!in_array($effort, $this->efforts, true)) {
			throw AiChatException::translatable(sprintf('Effort level "%s" is not available.', $effort), 'aiChat.error.unknownEffort', ['effort' => $effort]);
		}

		return ['model' => $model, 'effort' => $effort];
	}

	/** Context window of the model, or the fallback for an unknown or null model. */
	public function getContextWindow(?string $model, int $fallback): int
	{
		return $model !== null ? (int) ($this->models[$model]['contextWindowTokens'] ?? $fallback) : $fallback;
	}

	/**
	 * The widget config: `modelSelection` of the data-ai-chat JSON, or null when off.
	 *
	 * @return array{models: list<array{id: string, label: string}>, efforts: list<string>, defaultModel: string|null, defaultEffort: string|null}|null
	 */
	public function toClient(): ?array
	{
		if (!$this->isEnabled()) {
			return null;
		}

		$models = [];
		foreach ($this->models as $id => $model) {
			$models[] = ['id' => (string) $id, 'label' => (string) ($model['label'] ?? $id)];
		}

		return [
			'models' => $models,
			'efforts' => $this->efforts,
			'defaultModel' => $this->getDefaultModel(),
			'defaultEffort' => $this->getDefaultEffort(),
		];
	}
}
