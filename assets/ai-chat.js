/**
 * Browser side of adt/ai-chat: conversations, markdown replies, charts, tables,
 * CSV exports, attachments, the context gauge and polling for the reply that the
 * worker processes in the background.
 *
 * Vanilla ES module without jQuery. Everything is scoped to the root element, so
 * several chats can live on one page (a full page and a side panel). The server
 * renders just the root with a JSON config; the markup is built here:
 *
 *   <div data-ai-chat='{"urls": {...}, "labels": {...}}'></div>
 *
 *   import { AiChat } from 'adt-ai-chat';
 *   import { marked } from 'marked';
 *   import DOMPurify from 'dompurify';
 *   import Chart from 'chart.js/auto';
 *
 *   AiChat.mount(element, { marked, DOMPurify, Chart });
 *
 * The dependencies are optional and fall back to window globals: without marked
 * the replies are plain text, without Chart.js charts are skipped.
 *
 * Modes:
 *   full   - conversation list + chat (a page of its own)
 *   single - one conversation without the list (side panel); without
 *            conversationId the first message starts a new conversation
 *
 * The server side is ADT\AiChat\Http\ChatEndpoint. Requests: GET for reading
 * (conversations, load, poll), POST FormData for changes (send, create, delete).
 * The URLs are used as they are, so tokens the framework puts into them (e.g. a
 * CSRF token of signals) are kept.
 *
 * Model selection: with `modelSelection` in the config ({models: [{id, label}],
 * efforts, defaultModel, defaultEffort} - ModelSelection::toClient()) the input
 * gets a model and an effort select. The choice travels with the first message of
 * a conversation and is locked afterwards (a session cannot switch models).
 *
 * Rating: with `urls.rate` the owner can rate a conversation that has a reply
 * (thumbs up / down + an optional comment); a read-only view shows the rating.
 *
 * Extra parameters: `params` ({name: value}) go with every request and export
 * link, e.g. whose conversations an administrator views. setParams() changes them
 * at runtime: the chat empties and reloads the conversation list, which also
 * tells whether the view is read-only.
 *
 * Events dispatched on the root element (bubbling):
 *   ai-chat:conversation  {conversation}  created, renamed, rated or a reply arrived
 *   ai-chat:deleted       {id}
 */

const DEFAULT_LABELS = {
	title: 'AI assistant',
	newConversation: 'New conversation',
	noConversations: 'No conversations yet.',
	noTitle: 'New conversation',
	placeholder: 'Ask a question about your data...',
	send: 'Send',
	attach: 'Attach a file (image, PDF, CSV, text)',
	removeFile: 'Remove',
	delete: 'Delete',
	deleteConfirm: 'Do you really want to delete this conversation?',
	loading: 'The AI is thinking...',
	welcome: 'Pick an existing conversation or start a new one.',
	welcomeSingle: 'Ask anything about your data.',
	exportCsv: 'Download CSV',
	contextLabel: 'Conversation context',
	contextOk: 'I remember the whole conversation.',
	contextWarn: 'The conversation is getting long - I will start forgetting the beginning soon.',
	contextFull: 'Memory is almost full, consider starting a new conversation.',
	errorConnection: 'Connection error. Please try again.',
	errorTimeout: 'The response is taking too long. Reload the conversation later.',
	errorUnavailable: 'The conversation is no longer available.',
	errorUnknown: 'An unexpected error occurred. Please try again.',
	errorTooManyFiles: 'You can attach at most {max} files per message.',
	errorFileTooLarge: 'File "{name}" is too large. The limit is {max} MB.',
	model: 'Model',
	effort: 'Thinking',
	modelHint: 'Model and thinking depth for this conversation. They cannot be changed after the first message.',
	effortLevels: { low: 'Low', medium: 'Medium', high: 'High', xhigh: 'Extra high', max: 'Max' },
	rateQuestion: 'Was this conversation helpful?',
	rateUp: 'Helpful',
	rateDown: 'Not helpful',
	rateComment: 'What could be better? (optional)',
	rateCommentUp: 'What did you like? (optional)',
	rateSave: 'Send',
	rateThanks: 'Thank you for your feedback.',
	rated: 'Rated',
};

const DEFAULT_ICONS = {
	new: 'fa-solid fa-plus',
	delete: 'fa-solid fa-trash',
	send: 'fa-solid fa-arrow-up',
	attach: 'fa-solid fa-paperclip',
	file: 'fa-solid fa-file',
	remove: 'fa-solid fa-xmark',
	download: 'fa-solid fa-download',
	error: 'fa-solid fa-triangle-exclamation',
	spinner: 'fa-solid fa-spinner fa-spin',
	welcome: 'fa-solid fa-comments',
	rateUp: 'fa-solid fa-thumbs-up',
	rateDown: 'fa-solid fa-thumbs-down',
};

const DEFAULTS = {
	mode: 'full',
	conversationId: null,
	readOnly: false,
	locale: undefined,
	accept: '',
	maxAttachments: 4,
	maxAttachmentBytes: 10 * 1024 * 1024,
	pollIntervalMs: 2000,
	// A turn may run for minutes in the worker; the limit only stops an endless spinner.
	pollMaxMs: 660000,
	// Full mode stretches to the bottom of the window; off when a parent sizes the chat.
	fitViewport: null,
	// Sent with every request (query or FormData) and export link.
	params: {},
};

const MARKDOWN_TAGS = ['p', 'br', 'strong', 'em', 'code', 'pre', 'ul', 'ol', 'li',
	'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'a', 'table',
	'thead', 'tbody', 'tr', 'th', 'td', 'hr', 'span', 'del'];

const CHART_COLORS = [
	[54, 162, 235], [255, 99, 132], [75, 192, 192], [255, 206, 86],
	[153, 102, 255], [255, 159, 64], [199, 199, 199], [83, 102, 255],
];

const INSTANCES = new WeakMap();

export class AiChat {
	/**
	 * Creates the chat in the element from its data-ai-chat JSON config; a second
	 * call on the same element returns the existing instance.
	 */
	static mount(root, deps = {}, config = null) {
		if (INSTANCES.has(root)) {
			return INSTANCES.get(root);
		}

		const instance = new AiChat(root, config ?? JSON.parse(root.dataset.aiChat || '{}'), deps);
		INSTANCES.set(root, instance);

		return instance;
	}

	static get(root) {
		return INSTANCES.get(root) ?? null;
	}

	constructor(root, config, deps = {}) {
		this.root = root;
		this.config = { ...DEFAULTS, ...config };
		this.urls = config.urls || {};
		this.labels = { ...DEFAULT_LABELS, ...(config.labels || {}) };
		this.labels.effortLevels = { ...DEFAULT_LABELS.effortLevels, ...(config.labels?.effortLevels || {}) };
		this.icons = { ...DEFAULT_ICONS, ...(config.icons || {}) };
		this.deps = {
			marked: deps.marked ?? window.marked,
			DOMPurify: deps.DOMPurify ?? window.DOMPurify,
			Chart: deps.Chart ?? window.Chart,
			confirm: deps.confirm ?? ((text) => window.confirm(text)),
		};

		this.single = this.config.mode === 'single';
		this.readOnly = !!this.config.readOnly;
		this.conversationId = this.config.conversationId ?? null;
		this.params = { ...(this.config.params || {}) };
		this.loading = false;
		this.files = [];
		this.charts = [];
		this.pollTimer = null;
		this.pollAttempts = 0;
		this.pollStartedAt = 0;
		this.modelSelection = this.config.modelSelection?.models?.length ? this.config.modelSelection : null;
		this.preferredModel = this.modelSelection?.defaultModel ?? null;
		this.preferredEffort = this.modelSelection?.defaultEffort ?? null;
		this.modelLocked = false;
		this.conversation = null;
		this.hasReply = false;
		this.onResize = () => this.fitHeight();

		this.build();

		if (this.config.fitViewport ?? !this.single) {
			window.addEventListener('resize', this.onResize);
			this.fitHeight();
		}

		if (this.conversationId) {
			this.loadConversation(this.conversationId);
		} else {
			this.showWelcome(true);
		}

		if (!this.single) {
			this.refreshConversationList();
		}

		this.applyReadOnly(this.readOnly);
	}

	destroy() {
		this.stopPolling();
		this.destroyCharts();
		window.removeEventListener('resize', this.onResize);
		this.root.replaceChildren();
		INSTANCES.delete(this.root);
	}

	// -- Markup ----------------------------------------------------------------

	build() {
		const root = this.root;
		root.classList.add('ai-chat', this.single ? 'ai-chat--single' : 'ai-chat--full');
		root.replaceChildren();

		if (!this.single) {
			this.newButton = el('button', { type: 'button', class: 'btn btn-primary btn-sm ai-chat__new-btn' }, [
				icon(this.icons.new), ' ' + this.labels.newConversation,
			]);
			this.newButton.addEventListener('click', () => this.newConversation());

			this.list = el('div', { class: 'ai-chat__conversation-list' });
			this.list.addEventListener('click', (e) => this.onListClick(e));

			root.append(el('div', { class: 'ai-chat__sidebar' }, [
				el('div', { class: 'ai-chat__sidebar-header' }, [this.newButton]),
				this.list,
			]));
		}

		this.title = el('h3', { class: 'ai-chat__title' });

		this.contextFill = el('div', { class: 'ai-chat__context-fill' });
		this.contextText = el('span', { class: 'ai-chat__context-text' });
		this.contextPercent = el('span', { class: 'ai-chat__context-percent' });
		this.contextNew = el('button', { type: 'button', class: 'ai-chat__context-new', hidden: true }, [
			icon(this.icons.new), ' ' + this.labels.newConversation,
		]);
		this.contextNew.addEventListener('click', () => this.newConversation());
		this.context = el('div', { class: 'ai-chat__context', hidden: true }, [
			el('div', { class: 'ai-chat__context-info' }, [this.contextText, this.contextPercent]),
			el('div', { class: 'ai-chat__context-bar' }, [this.contextFill]),
			this.contextNew,
		]);

		this.messages = el('div', { class: 'ai-chat__messages' });

		this.fileInput = el('input', { type: 'file', class: 'ai-chat__file-input', multiple: true, hidden: true });
		if (this.config.accept) {
			this.fileInput.accept = this.config.accept;
		}
		this.fileInput.addEventListener('change', () => {
			this.addFiles(Array.from(this.fileInput.files || []));
			this.fileInput.value = '';
		});

		this.attachButton = el('button', { type: 'button', class: 'ai-chat__attach-btn', title: this.labels.attach, 'aria-label': this.labels.attach }, [
			icon(this.icons.attach),
		]);
		this.attachButton.addEventListener('click', () => this.fileInput.click());
		this.attachButton.hidden = !(this.config.maxAttachments > 0);

		this.textarea = el('textarea', { class: 'ai-chat__textarea', rows: 1, placeholder: this.labels.placeholder });
		this.textarea.addEventListener('input', () => this.resizeTextarea());
		this.textarea.addEventListener('keydown', (e) => this.onKeydown(e));

		this.sendButton = el('button', { type: 'submit', class: 'btn btn-primary ai-chat__send-btn', title: this.labels.send, 'aria-label': this.labels.send }, [
			icon(this.icons.send),
		]);

		this.fileList = el('div', { class: 'ai-chat__files', hidden: true });
		this.buildModelOptions();
		this.buildRating();
		this.loadingBox = el('div', { class: 'ai-chat__loading', hidden: true }, [icon(this.icons.spinner), ' ' + this.labels.loading]);

		// A real form: Enter in assistive technologies submits it, and hosts like the
		// fancyadmin side panel recognise their content by it.
		this.form = el('form', { class: 'ai-chat__input-area', novalidate: true }, [
			this.fileList,
			this.modelOptions,
			el('div', { class: 'ai-chat__input-wrapper' }, [this.attachButton, this.textarea, this.sendButton]),
			this.fileInput,
			this.loadingBox,
		]);
		this.form.addEventListener('submit', (e) => {
			e.preventDefault();
			e.stopPropagation();
			this.sendMessage();
		});

		root.append(el('div', { class: 'ai-chat__main' }, [
			el('div', { class: 'ai-chat__header' }, [this.title, this.context]),
			this.messages,
			this.ratingBox,
			this.form,
		]));
	}

	/** The model and effort selects; null without modelSelection in the config. */
	buildModelOptions() {
		this.modelOptions = null;
		if (!this.modelSelection) {
			return;
		}

		const select = (label, options, value) => {
			const element = el('select', { class: 'ai-chat__option-select', 'aria-label': label },
				options.map(([id, text]) => el('option', { value: id }, [text])));
			element.value = value ?? '';
			return element;
		};

		this.modelSelect = select(this.labels.model, this.modelSelection.models.map((model) => [model.id, model.label || model.id]), this.preferredModel);
		this.modelSelect.addEventListener('change', () => {
			this.preferredModel = this.modelSelect.value;
		});

		const options = [el('label', { class: 'ai-chat__option' }, [
			el('span', { class: 'ai-chat__option-label' }, [this.labels.model]), this.modelSelect,
		])];

		const efforts = this.modelSelection.efforts || [];
		if (efforts.length) {
			this.effortSelect = select(this.labels.effort, efforts.map((effort) => [effort, this.labels.effortLevels[effort] || effort]), this.preferredEffort);
			this.effortSelect.addEventListener('change', () => {
				this.preferredEffort = this.effortSelect.value;
			});
			options.push(el('label', { class: 'ai-chat__option' }, [
				el('span', { class: 'ai-chat__option-label' }, [this.labels.effort]), this.effortSelect,
			]));
		}

		this.modelOptions = el('div', { class: 'ai-chat__options', title: this.labels.modelHint }, options);
	}

	/**
	 * Locked: shows the choice of the conversation (its model, or the default for one
	 * that ran on the agent model). Unlocked: the user's last choice.
	 */
	setModelChoice(conversation, locked) {
		this.modelLocked = locked;
		if (!this.modelOptions) {
			return;
		}

		this.modelSelect.value = (locked ? conversation?.model : null) ?? this.preferredModel ?? '';
		this.modelSelect.disabled = locked;
		if (this.effortSelect) {
			this.effortSelect.value = (locked ? conversation?.effort : null) ?? this.preferredEffort ?? '';
			this.effortSelect.disabled = locked;
		}
		this.modelOptions.classList.toggle('ai-chat__options--locked', locked);
	}

	buildRating() {
		this.ratingBox = el('div', { class: 'ai-chat__rating', hidden: true });
		if (!this.urls.rate) {
			return;
		}

		const button = (rating, label, iconClass) => {
			const element = el('button', { type: 'button', class: 'ai-chat__rating-btn', title: label, 'aria-label': label, 'aria-pressed': 'false', 'data-rating': rating }, [icon(iconClass)]);
			element.addEventListener('click', () => this.onRate(rating));
			return element;
		};

		this.rateUpButton = button(1, this.labels.rateUp, this.icons.rateUp);
		this.rateDownButton = button(-1, this.labels.rateDown, this.icons.rateDown);
		this.ratingStatus = el('span', { class: 'ai-chat__rating-status' });

		this.ratingComment = el('textarea', { class: 'ai-chat__rating-comment', rows: 2, maxlength: 2000 });
		this.ratingSave = el('button', { type: 'button', class: 'btn btn-primary btn-sm ai-chat__rating-save' }, [this.labels.rateSave]);
		this.ratingSave.addEventListener('click', () => this.saveRating(this.conversation?.rating ?? null, this.ratingComment.value));
		this.ratingCommentRow = el('div', { class: 'ai-chat__rating-comment-row', hidden: true }, [this.ratingComment, this.ratingSave]);
		this.ratingCommentText = el('div', { class: 'ai-chat__rating-comment-text', hidden: true });

		this.ratingBox.append(
			el('div', { class: 'ai-chat__rating-bar' }, [
				el('span', { class: 'ai-chat__rating-question' }, [this.labels.rateQuestion]),
				this.rateUpButton,
				this.rateDownButton,
				this.ratingStatus,
			]),
			this.ratingCommentRow,
			this.ratingCommentText,
		);
	}

	/** The rating bar under the messages, once the conversation has a reply. */
	renderRating() {
		if (!this.urls.rate) {
			return;
		}

		const rating = this.conversation?.rating ?? null;
		// A read-only view shows the owner's feedback, if there is any.
		this.ratingBox.hidden = !this.conversation?.id || !this.hasReply || (this.readOnly && rating === null);
		if (this.ratingBox.hidden) {
			return;
		}

		this.ratingBox.classList.toggle('ai-chat__rating--readonly', this.readOnly);
		for (const button of [this.rateUpButton, this.rateDownButton]) {
			const active = Number(button.dataset.rating) === rating;
			button.classList.toggle('ai-chat__rating-btn--active', active);
			button.setAttribute('aria-pressed', active ? 'true' : 'false');
			button.disabled = this.readOnly;
		}

		const comment = this.conversation?.ratingComment || '';
		this.ratingCommentText.hidden = !this.readOnly || comment === '';
		this.ratingCommentText.textContent = comment;
		if (this.readOnly) {
			this.ratingCommentRow.hidden = true;
		}
	}

	onRate(rating) {
		if (this.readOnly || !this.conversation?.id) {
			return;
		}

		// A second click on the active thumb takes the rating back.
		if (this.conversation.rating === rating) {
			this.ratingCommentRow.hidden = true;
			this.saveRating(null, '');
			return;
		}

		this.ratingComment.value = this.conversation.ratingComment || '';
		this.ratingComment.placeholder = rating > 0 ? this.labels.rateCommentUp : this.labels.rateComment;
		this.ratingCommentRow.hidden = false;
		this.saveRating(rating, this.ratingComment.value).then(() => this.ratingComment.focus());
	}

	async saveRating(rating, comment) {
		const conversationId = this.conversation?.id;
		if (!conversationId) {
			return;
		}

		const data = await this.request('POST', this.urls.rate, { conversationId, rating: rating ?? '', comment: comment ?? '' })
			.catch(() => ({ success: false, error: this.labels.errorConnection }));

		if (String(this.conversationId) !== String(conversationId)) {
			return;
		}

		if (!data.success) {
			this.appendError(data.error || this.labels.errorUnknown);
			this.scrollToBottom();
			return;
		}

		this.conversation = data.conversation;
		this.ratingStatus.textContent = rating !== null && comment.trim() !== '' ? this.labels.rateThanks : '';
		if (rating !== null && comment.trim() !== '') {
			this.ratingCommentRow.hidden = true;
		}
		this.renderRating();
		this.emit('ai-chat:conversation', { conversation: data.conversation, created: false });
		this.refreshConversationList();
	}

	applyReadOnly(readOnly) {
		this.readOnly = readOnly;
		this.root.classList.toggle('ai-chat--readonly', readOnly);
		this.form.hidden = readOnly;
		if (this.newButton) {
			this.newButton.hidden = readOnly;
		}
		this.renderRating();
	}

	fitHeight() {
		const top = this.root.getBoundingClientRect().top;
		this.root.style.height = Math.max(window.innerHeight - top - 16, 400) + 'px';
	}

	setTitle(title) {
		this.title.textContent = title || (this.single ? this.labels.title : '');
	}

	showWelcome(show) {
		this.welcome?.remove();
		this.welcome = null;

		if (show) {
			this.welcome = el('div', { class: 'ai-chat__welcome' }, [
				el('div', { class: 'ai-chat__welcome-icon' }, [icon(this.icons.welcome + ' fa-3x')]),
				el('p', {}, [this.single ? this.labels.welcomeSingle : this.labels.welcome]),
			]);
			this.messages.append(this.welcome);
		}
	}

	setLoading(loading) {
		this.loading = loading;
		this.loadingBox.hidden = !loading;
		this.textarea.disabled = loading;
		this.sendButton.disabled = loading;
		this.attachButton.disabled = loading;
	}

	focusInput() {
		if (!this.readOnly && !this.loading) {
			this.textarea.focus();
		}
	}

	resizeTextarea() {
		this.textarea.style.height = 'auto';
		this.textarea.style.height = Math.min(this.textarea.scrollHeight, 150) + 'px';
	}

	onKeydown(e) {
		if (e.key !== 'Enter' || e.isComposing) {
			return;
		}

		// Shift+Enter (the default) and Alt+Enter insert a newline, plain Enter sends.
		if (e.altKey) {
			e.preventDefault();
			const { selectionStart: start, selectionEnd: end, value } = this.textarea;
			this.textarea.value = value.slice(0, start) + '\n' + value.slice(end);
			this.textarea.selectionStart = this.textarea.selectionEnd = start + 1;
			this.resizeTextarea();
			return;
		}

		if (!e.shiftKey) {
			e.preventDefault();
			this.sendMessage();
		}
	}

	// -- Conversations -----------------------------------------------------------

	async refreshConversationList() {
		if (this.single || !this.urls.conversations) {
			return;
		}

		const data = await this.request('GET', this.urls.conversations).catch(() => null);
		if (!data?.success) {
			return;
		}

		if (typeof data.readOnly === 'boolean') {
			this.applyReadOnly(data.readOnly);
		}

		this.renderConversationList(data.conversations || []);
	}

	renderConversationList(conversations) {
		this.list.replaceChildren();

		if (!conversations.length) {
			this.list.append(el('div', { class: 'ai-chat__no-conversations' }, [this.labels.noConversations]));
			return;
		}

		for (const conversation of conversations) {
			const item = el('div', {
				class: 'ai-chat__conversation-item' + (String(conversation.id) === String(this.conversationId) ? ' ai-chat__conversation-item--active' : ''),
				'data-id': conversation.id,
				role: 'button',
				tabindex: 0,
			}, [
				el('div', { class: 'ai-chat__conversation-title' }, [conversation.title || this.labels.noTitle]),
				el('div', { class: 'ai-chat__conversation-date' }, [
					this.formatDate(conversation.updated),
					conversation.rating ? el('span', { class: 'ai-chat__conversation-rating ai-chat__conversation-rating--' + (conversation.rating > 0 ? 'up' : 'down'), title: this.labels.rated }, [
						icon(conversation.rating > 0 ? this.icons.rateUp : this.icons.rateDown),
					]) : '',
				]),
			]);

			if (!this.readOnly) {
				item.append(el('button', { type: 'button', class: 'ai-chat__conversation-delete', 'data-id': conversation.id, title: this.labels.delete, 'aria-label': this.labels.delete }, [
					icon(this.icons.delete),
				]));
			}

			item.addEventListener('keydown', (e) => {
				if (e.key === 'Enter' && e.target === item) {
					this.loadConversation(conversation.id);
				}
			});

			this.list.append(item);
		}
	}

	onListClick(e) {
		const deleteButton = e.target.closest('.ai-chat__conversation-delete');
		if (deleteButton) {
			e.stopPropagation();
			if (this.deps.confirm(this.labels.deleteConfirm)) {
				this.deleteConversation(deleteButton.dataset.id);
			}
			return;
		}

		const item = e.target.closest('.ai-chat__conversation-item');
		if (item) {
			this.loadConversation(item.dataset.id);
		}
	}

	markActive() {
		this.list?.querySelectorAll('.ai-chat__conversation-item').forEach((item) => {
			item.classList.toggle('ai-chat__conversation-item--active', String(item.dataset.id) === String(this.conversationId));
		});
	}

	/** An empty chat; the conversation itself is created by the first message. */
	newConversation() {
		if (this.readOnly) {
			return;
		}

		this.reset();
		this.focusInput();
	}

	/**
	 * Replaces the extra request parameters, empties the chat and reloads the
	 * conversation list (its response sets the read-only state).
	 */
	setParams(params) {
		this.params = { ...(params || {}) };
		this.reset();

		return this.refreshConversationList();
	}

	/** No conversation open, nothing pending. */
	reset() {
		this.stopPolling();
		this.setLoading(false);
		this.conversationId = null;
		this.conversation = null;
		this.clearMessages();
		this.showWelcome(true);
		this.setTitle('');
		this.setModelChoice(null, false);
		this.renderRating();
		this.markActive();
	}

	async loadConversation(conversationId) {
		this.stopPolling();
		this.conversationId = conversationId;
		this.markActive();
		this.setLoading(true);

		let data;
		try {
			data = await this.request('GET', this.urls.load, { conversationId });
		} catch {
			data = { success: false, error: this.labels.errorConnection };
		}

		// The user switched to another conversation meanwhile.
		if (String(this.conversationId) !== String(conversationId)) {
			return;
		}

		this.setLoading(false);
		this.clearMessages();
		this.conversation = null;

		if (!data.success) {
			this.setModelChoice(null, false);
			this.renderRating();
			this.appendError(data.error || this.labels.errorUnavailable);
			return;
		}

		this.conversation = data.conversation ?? null;
		const messages = data.messages || [];
		this.setModelChoice(this.conversation, messages.length > 0);

		if (typeof data.readOnly === 'boolean') {
			this.applyReadOnly(data.readOnly);
		}

		this.setTitle(data.conversation?.title);
		this.updateContextUsage(data.contextUsage);

		messages.forEach((message) => this.appendMessage(message));
		this.renderRating();
		if (!messages.length) {
			this.showWelcome(true);
		}
		this.scrollToBottom();

		// The last message is the user's without a reply yet = the worker is still
		// processing it (e.g. after a page reload) - resume polling.
		const last = messages[messages.length - 1];
		if (last && last.author === 'user' && !this.readOnly) {
			this.setLoading(true);
			this.startPolling(last.id);
		} else {
			this.focusInput();
		}
	}

	async deleteConversation(conversationId) {
		if (this.readOnly) {
			return;
		}

		const data = await this.request('POST', this.urls.delete, { conversationId }).catch(() => null);
		if (!data?.success) {
			return;
		}

		if (String(this.conversationId) === String(conversationId)) {
			this.newConversation();
		}

		this.emit('ai-chat:deleted', { id: conversationId });
		this.refreshConversationList();
	}

	// -- Sending and polling -----------------------------------------------------

	async sendMessage() {
		if (this.loading || this.readOnly) {
			return;
		}

		const text = this.textarea.value.trim();
		const files = this.files.slice();
		if (!text && !files.length) {
			return;
		}

		this.textarea.value = '';
		this.resizeTextarea();
		this.files = [];
		this.renderFiles();

		this.showWelcome(false);
		const pending = this.appendMessage({
			author: 'user',
			content: text,
			attachments: files.map((file) => ({ filename: file.name, mediaType: file.type, size: file.size })),
		});
		this.scrollToBottom();
		this.setLoading(true);

		const body = new FormData();
		body.append('conversationId', this.conversationId ?? '');
		body.append('message', text);
		files.forEach((file) => body.append('attachments[]', file, file.name));

		// The choice counts for the first message only; lock it right away.
		const wasLocked = this.modelLocked;
		if (this.modelOptions && !wasLocked) {
			body.append('model', this.modelSelect.value);
			if (this.effortSelect) {
				body.append('effort', this.effortSelect.value);
			}
		}
		this.setModelChoice(this.conversation, true);

		let data;
		try {
			data = await this.request('POST', this.urls.send, body);
		} catch {
			data = { success: false, error: this.labels.errorConnection };
		}

		if (!data.success) {
			this.setLoading(false);
			pending.remove();
			// Nothing was stored - give the text back so it is not lost.
			this.textarea.value = text;
			this.resizeTextarea();
			this.files = files;
			this.renderFiles();
			if (!wasLocked) {
				this.setModelChoice(this.conversation, false);
			}
			this.appendError(data.error || this.labels.errorUnknown);
			this.scrollToBottom();
			return;
		}

		const created = String(this.conversationId) !== String(data.conversation.id);
		this.conversationId = data.conversation.id;
		this.conversation = data.conversation;
		this.setModelChoice(this.conversation, true);
		this.setTitle(data.conversation.title);
		this.emit('ai-chat:conversation', { conversation: data.conversation, created });

		if (created || !this.single) {
			this.refreshConversationList();
		}

		this.startPolling(data.lastMessageId);
	}

	startPolling(afterId) {
		const conversationId = this.conversationId;

		this.stopPolling();
		this.pollAttempts = 0;
		this.pollStartedAt = Date.now();

		const poll = async () => {
			if (String(this.conversationId) !== String(conversationId)) {
				return;
			}

			// The page removed the chat (closed side panel, replaced snippet) - nobody
			// would see the reply, so stop and let the instance go.
			if (!this.root.isConnected) {
				this.destroy();
				return;
			}

			if (Date.now() - this.pollStartedAt > this.config.pollMaxMs) {
				this.setLoading(false);
				this.appendError(this.labels.errorTimeout);
				this.scrollToBottom();
				return;
			}
			this.pollAttempts++;

			let data;
			try {
				data = await this.request('GET', this.urls.poll, { conversationId, afterId });
			} catch {
				// A transient failure (deploy, outage) - keep polling.
				data = null;
			}

			if (String(this.conversationId) !== String(conversationId)) {
				return;
			}

			// The conversation is gone or the request was refused - polling on would
			// only keep the input locked until the time limit.
			if (data && data.success === false) {
				this.setLoading(false);
				this.appendError(data.error || this.labels.errorUnavailable);
				this.scrollToBottom();
				return;
			}

			if (data?.messages?.length) {
				this.setLoading(false);
				data.messages.forEach((message) => this.appendMessage(message));
				this.scrollToBottom();
				this.setTitle(data.conversation?.title);
				this.updateContextUsage(data.contextUsage);
				this.conversation = data.conversation ?? this.conversation;
				this.renderRating();
				this.emit('ai-chat:conversation', { conversation: data.conversation, created: false });
				this.refreshConversationList();
				this.focusInput();
				return;
			}

			this.pollTimer = setTimeout(poll, this.pollDelay());
		};

		this.pollTimer = setTimeout(poll, this.pollDelay());
	}

	// A reply typically takes minutes - 2 s while warming up, then 5 s, then 10 s.
	pollDelay() {
		if (this.pollAttempts < 5) {
			return this.config.pollIntervalMs;
		}

		return this.pollAttempts < 15 ? 5000 : 10000;
	}

	stopPolling() {
		clearTimeout(this.pollTimer);
		this.pollTimer = null;
	}

	/**
	 * GET with query parameters, POST with a FormData body. The URL keeps whatever
	 * the server put in it.
	 */
	async request(method, url, data = {}) {
		if (!url) {
			throw new Error('ai-chat: missing URL');
		}

		const target = new URL(url, window.location.href);
		const options = {
			method,
			credentials: 'same-origin',
			headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
		};

		if (method === 'GET') {
			Object.entries({ ...this.params, ...data }).forEach(([key, value]) => target.searchParams.set(key, value ?? ''));
		} else if (data instanceof FormData) {
			Object.entries(this.params).forEach(([key, value]) => data.has(key) || data.append(key, value ?? ''));
			options.body = data;
		} else {
			options.body = new FormData();
			Object.entries({ ...this.params, ...data }).forEach(([key, value]) => options.body.append(key, value ?? ''));
		}

		const response = await fetch(target, options);
		if (!response.ok) {
			throw new Error('ai-chat: HTTP ' + response.status);
		}

		return response.json();
	}

	// -- Attachments ---------------------------------------------------------------

	addFiles(files) {
		for (const file of files) {
			if (this.files.length >= this.config.maxAttachments) {
				this.appendError(format(this.labels.errorTooManyFiles, { max: this.config.maxAttachments }));
				break;
			}

			if (file.size > this.config.maxAttachmentBytes) {
				this.appendError(format(this.labels.errorFileTooLarge, {
					name: file.name,
					max: Math.floor(this.config.maxAttachmentBytes / 1024 / 1024),
				}));
				continue;
			}

			this.files.push(file);
		}

		this.renderFiles();
		this.scrollToBottom();
		this.focusInput();
	}

	renderFiles() {
		this.fileList.replaceChildren();
		this.fileList.hidden = !this.files.length;

		this.files.forEach((file, index) => {
			const remove = el('button', { type: 'button', class: 'ai-chat__file-remove', title: this.labels.removeFile, 'aria-label': this.labels.removeFile }, [
				icon(this.icons.remove),
			]);
			remove.addEventListener('click', () => {
				this.files.splice(index, 1);
				this.renderFiles();
			});

			this.fileList.append(el('span', { class: 'ai-chat__file' }, [
				icon(this.icons.file), ' ', el('span', { class: 'ai-chat__file-name' }, [file.name]), remove,
			]));
		});
	}

	// -- Messages ------------------------------------------------------------------

	clearMessages() {
		this.destroyCharts();
		this.messages.replaceChildren();
		this.welcome = null;
		this.hasReply = false;
		this.updateContextUsage(null);
		if (this.ratingStatus) {
			this.ratingStatus.textContent = '';
			this.ratingCommentRow.hidden = true;
		}
	}

	appendMessage(message) {
		if (message.error) {
			return this.appendError(message.content);
		}

		this.showWelcome(false);

		const author = message.author === 'user' ? 'user' : 'ai';
		if (author === 'ai') {
			this.hasReply = true;
		}
		const item = el('div', { class: 'ai-chat__message ai-chat__message--' + author });
		const bubble = el('div', { class: 'ai-chat__bubble ai-chat__bubble--' + author });

		if (author === 'ai') {
			this.renderMarkdown(bubble, message.content);
		} else {
			bubble.textContent = message.content || '';
		}

		if (message.attachments?.length) {
			bubble.append(el('div', { class: 'ai-chat__message-files' }, message.attachments.map((file) => el('span', { class: 'ai-chat__file' }, [
				icon(this.icons.file), ' ', el('span', { class: 'ai-chat__file-name' }, [file.filename]),
			]))));
		}

		if (author === 'user' || (message.content || '').trim() !== '') {
			item.append(bubble);
		}

		for (const entry of message.tool_data || []) {
			const result = entry.tool_result || {};
			if (result.type === 'chart') {
				item.append(this.renderChart(result));
			} else if (result.type === 'table') {
				item.append(this.renderTable(result));
			} else if (result.type === 'export') {
				const link = this.renderExport(result, entry.messageId);
				if (link) {
					item.append(link);
				}
			}
		}

		this.messages.append(item);

		return item;
	}

	appendError(text) {
		this.showWelcome(false);

		const item = el('div', { class: 'ai-chat__message ai-chat__message--error' }, [
			el('div', { class: 'ai-chat__bubble ai-chat__bubble--error', role: 'alert' }, [icon(this.icons.error), ' ' + (text || this.labels.errorUnknown)]),
		]);
		this.messages.append(item);

		return item;
	}

	renderMarkdown(target, text) {
		const { marked, DOMPurify } = this.deps;

		if (!text || !marked || !DOMPurify) {
			target.classList.add('ai-chat__bubble--plain');
			target.textContent = text || '';
			return;
		}

		try {
			const html = typeof marked.parse === 'function' ? marked.parse(text) : marked(text);
			target.innerHTML = DOMPurify.sanitize(html, { ALLOWED_TAGS: MARKDOWN_TAGS, ALLOWED_ATTR: ['href', 'target', 'class'] });
			target.querySelectorAll('a[href]').forEach((link) => {
				link.target = '_blank';
				link.rel = 'noopener noreferrer';
			});
		} catch {
			target.classList.add('ai-chat__bubble--plain');
			target.textContent = text;
		}
	}

	renderChart(chart) {
		const canvas = el('canvas');
		const wrapper = el('div', { class: 'ai-chat__chart-wrapper' }, [
			chart.title ? el('div', { class: 'ai-chat__chart-title' }, [chart.title]) : '',
			el('div', { class: 'ai-chat__chart-canvas' }, [canvas]),
		]);

		const Chart = this.deps.Chart;
		if (!Chart) {
			return wrapper;
		}

		const pie = chart.chart_type === 'pie' || chart.chart_type === 'doughnut';
		const color = (index, alpha) => {
			const [r, g, b] = CHART_COLORS[index % CHART_COLORS.length];
			return `rgba(${r}, ${g}, ${b}, ${alpha})`;
		};

		const datasets = (chart.datasets || []).map((dataset, index) => ({
			label: dataset.label,
			data: dataset.data,
			backgroundColor: pie ? dataset.data.map((_, i) => color(i, 0.7)) : color(index, 0.7),
			borderColor: pie ? dataset.data.map((_, i) => color(i, 1)) : color(index, 1),
			borderWidth: 1,
		}));

		// Created once the canvas is in the document, so Chart.js can measure it.
		requestAnimationFrame(() => {
			if (!canvas.isConnected) {
				return;
			}

			this.charts.push(new Chart(canvas, {
				type: chart.chart_type,
				data: { labels: chart.labels, datasets },
				options: { responsive: true, maintainAspectRatio: false, plugins: { title: { display: false } } },
			}));
		});

		return wrapper;
	}

	renderTable(table) {
		const headers = table.headers || [];
		const rows = table.rows || [];

		const download = el('button', { type: 'button', class: 'btn btn-sm ai-chat__table-export' }, [
			icon(this.icons.download), ' ' + this.labels.exportCsv,
		]);
		download.addEventListener('click', () => {
			const base = (table.title || 'export').replace(/[^0-9A-Za-zÀ-ž_-]+/g, '_').replace(/^_+|_+$/g, '') || 'export';
			downloadCsv(base + '.csv', buildCsv(headers, rows));
		});

		return el('div', { class: 'ai-chat__table-wrapper' }, [
			table.title ? el('div', { class: 'ai-chat__table-title' }, [table.title]) : '',
			el('table', { class: 'ai-chat__table table table-striped table-sm' }, [
				el('thead', {}, [el('tr', {}, headers.map((header) => el('th', {}, [String(header ?? '')])))]),
				el('tbody', {}, rows.map((row) => el('tr', {}, (row || []).map((cell) => el('td', {}, [cell == null ? '' : String(cell)]))))),
			]),
			el('div', { class: 'ai-chat__table-actions' }, [download]),
		]);
	}

	/**
	 * A large result exported server-side: only conversationId/messageId/token go to
	 * the server, the SQL never leaves it.
	 */
	renderExport(result, messageId) {
		if (!this.urls.export || !messageId || !result.token || !this.conversationId) {
			return null;
		}

		const href = new URL(this.urls.export, window.location.href);
		Object.entries(this.params).forEach(([key, value]) => href.searchParams.set(key, value ?? ''));
		href.searchParams.set('conversationId', this.conversationId);
		href.searchParams.set('messageId', messageId);
		href.searchParams.set('token', result.token);

		return el('div', { class: 'ai-chat__table-actions' }, [
			el('a', { href: href.toString(), class: 'btn btn-sm ai-chat__table-export', download: result.filename || '' }, [
				icon(this.icons.download), ' ' + (result.title ? result.title + ' – ' : '') + this.labels.exportCsv,
			]),
		]);
	}

	destroyCharts() {
		this.charts.forEach((chart) => chart.destroy());
		this.charts = [];
	}

	// Context-window usage: <70 % ok, 70-90 % warning, >90 % nearly full + a CTA.
	updateContextUsage(usage) {
		if (!usage || !usage.window || !usage.used) {
			this.context.hidden = true;
			this.contextNew.hidden = true;
			return;
		}

		const percent = Math.max(0, Math.min(100, usage.percent || 0));
		const level = percent > 90 ? 'full' : (percent >= 70 ? 'warn' : 'ok');

		this.context.hidden = false;
		this.context.classList.remove('ai-chat__context--ok', 'ai-chat__context--warn', 'ai-chat__context--full');
		this.context.classList.add('ai-chat__context--' + level);
		this.context.title = `${this.labels.contextLabel}: ${usage.used} / ${usage.window} (${percent} %)`;
		this.contextFill.style.width = percent + '%';
		this.contextText.textContent = this.labels['context' + level.charAt(0).toUpperCase() + level.slice(1)];
		this.contextPercent.textContent = percent + ' %';
		this.contextNew.hidden = level !== 'full' || this.readOnly;
	}

	scrollToBottom() {
		this.messages.scrollTop = this.messages.scrollHeight;
	}

	formatDate(value) {
		const date = new Date(value);
		if (Number.isNaN(date.getTime())) {
			return value || '';
		}

		return new Intl.DateTimeFormat(this.config.locale, { dateStyle: 'short', timeStyle: 'short' }).format(date);
	}

	emit(name, detail) {
		this.root.dispatchEvent(new CustomEvent(name, { bubbles: true, detail }));
	}
}

/** Mounts every [data-ai-chat] element under the root. */
export function mountAll(root = document, deps = {}) {
	return Array.from(root.querySelectorAll('[data-ai-chat]')).map((element) => AiChat.mount(element, deps));
}

/**
 * CSV from headers + rows: every field quoted, inner quotes doubled, a UTF-8 BOM
 * so Excel renders diacritics.
 */
export function buildCsv(headers, rows) {
	const escape = (value) => '"' + String(value == null ? '' : value).replace(/"/g, '""') + '"';
	const lines = [headers.map(escape).join(',')];
	rows.forEach((row) => lines.push((row || []).map(escape).join(',')));

	return '﻿' + lines.join('\r\n');
}

function downloadCsv(filename, content) {
	const url = URL.createObjectURL(new Blob([content], { type: 'text/csv;charset=utf-8;' }));
	const link = el('a', { href: url, download: filename, hidden: true });
	document.body.append(link);
	link.click();
	link.remove();
	setTimeout(() => URL.revokeObjectURL(url), 0);
}

/** `{name}` placeholders. */
export function format(text, params) {
	return String(text).replace(/\{(\w+)\}/g, (match, key) => (key in params ? String(params[key]) : match));
}

function el(tag, attributes = {}, children = []) {
	const element = document.createElement(tag);

	for (const [name, value] of Object.entries(attributes)) {
		if (value === false || value === null || value === undefined) {
			continue;
		}
		element.setAttribute(name, value === true ? '' : String(value));
	}

	for (const child of children) {
		if (child === '' || child === null || child === undefined) {
			continue;
		}
		element.append(child instanceof Node ? child : document.createTextNode(String(child)));
	}

	return element;
}

function icon(className) {
	return el('i', { class: className, 'aria-hidden': 'true' });
}
