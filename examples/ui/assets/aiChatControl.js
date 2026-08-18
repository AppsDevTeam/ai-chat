(function($) {
	'use strict';

	var AiChat = {
		container: null,
		activeConversationId: null,
		isLoading: false,
		chartInstances: {},
		chartCounter: 0,
		// Polling for the reply processed in the background (queue worker).
		pollTimer: null,
		pollAttempts: 0,
		pollStartedAt: 0,
		POLL_INTERVAL_MS: 2000,
		POLL_MAX_MS: 300000, // ~5 min wall-clock (accounting for the staged backoff)
		// The "view another user's conversations" switcher (privileged roles only, optional).
		selfUserId: null,
		selectedUserId: null,
		readOnly: false,

		init: function() {
			this.container = $('#ai-chat');
			if (!this.container.length) return;

			this.initUserSelect();
			this.bindEvents();
			this.initTextarea();
			this.adjustHeight();
			this.setInputEnabled(true);
		},

		// Searchable user select (select2). Defaults to the signed-in user. Optional -
		// everything works without the element.
		initUserSelect: function() {
			var self = this;
			var $select = $('#ai-chat-user-select');
			if (!$select.length) return;

			this.selfUserId = String($select.data('self-id'));
			this.selectedUserId = String($select.val());

			if ($.fn.select2) {
				$select.select2({ width: '100%' });
			}

			$select.on('change', function() {
				self.onUserChange(String($(this).val()));
			});
		},

		// Switching to another user = a read-only view of their conversations.
		onUserChange: function(userId) {
			this.selectedUserId = userId;
			this.activeConversationId = null;
			this.applyReadOnly(userId !== this.selfUserId);
			this.clearMessages();
			this.showWelcome(true);
			$('#ai-chat-title').text('');
			this.refreshConversationList();
		},

		// Read-only mode hides creating/sending/deleting - viewing only.
		applyReadOnly: function(readOnly) {
			this.readOnly = readOnly;
			$('#ai-chat-new-btn').toggle(!readOnly);
			$('#ai-chat-input-area').toggle(!readOnly);
			this.container.toggleClass('ai-chat--readonly', readOnly);
		},

		// Adds userId to AJAX requests (only when the switcher exists).
		withUser: function(data) {
			if (this.selectedUserId !== null) {
				data.userId = this.selectedUserId;
			}
			return data;
		},

		// The height is computed from the real position of the component (from its top
		// edge to the bottom of the window), independent of any fixed header height.
		adjustHeight: function() {
			if (!this.container || !this.container.length) return;
			var top = this.container[0].getBoundingClientRect().top;
			this.container.css('height', Math.max(window.innerHeight - top - 16, 400) + 'px');
		},

		bindEvents: function() {
			var self = this;

			$(window).on('resize', function() {
				self.adjustHeight();
			});

			$('#ai-chat-new-btn').on('click', function() {
				self.newConversation();
			});

			$('#ai-chat-context-new').on('click', function() {
				self.newConversation();
			});

			$('#ai-chat-send-btn').on('click', function() {
				self.sendMessage();
			});

			$('#ai-chat-textarea').on('keydown', function(e) {
				if (e.key !== 'Enter') return;

				// Shift+Enter (default behaviour) and Alt+Enter = new line, do not send.
				if (e.altKey) {
					e.preventDefault();
					self.insertNewline(this);
					return;
				}
				if (e.shiftKey) return;

				// Plain Enter = send.
				e.preventDefault();
				self.sendMessage();
			});

			this.container.on('click', '.ai-chat__conversation-item', function(e) {
				if ($(e.target).closest('.ai-chat__conversation-delete').length) return;
				var id = $(this).data('id');
				self.loadConversation(id);
			});

			this.container.on('click', '.ai-chat__conversation-delete', function(e) {
				e.stopPropagation();
				var id = $(this).data('id');
				if (confirm($(this).attr('title') + '?')) {
					self.deleteConversation(id);
				}
			});
		},

		initTextarea: function() {
			var textarea = $('#ai-chat-textarea');
			textarea.on('input', function() {
				this.style.height = 'auto';
				this.style.height = Math.min(this.scrollHeight, 150) + 'px';
			});
		},

		// Inserts a newline at the caret and recomputes the textarea height.
		insertNewline: function(textarea) {
			var start = textarea.selectionStart;
			var end = textarea.selectionEnd;
			textarea.value = textarea.value.slice(0, start) + '\n' + textarea.value.slice(end);
			textarea.selectionStart = textarea.selectionEnd = start + 1;
			$(textarea).trigger('input');
		},

		setLoading: function(loading) {
			this.isLoading = loading;
			$('#ai-chat-loading').toggle(loading);
			$('#ai-chat-send-btn').prop('disabled', loading);
			$('#ai-chat-textarea').prop('disabled', loading);
		},

		newConversation: function() {
			if (this.readOnly) return;
			var self = this;
			var url = this.container.data('new-url');

			$.ajax({
				url: url,
				type: 'GET',
				dataType: 'json',
				success: function(data) {
					if (data.success) {
						self.activeConversationId = data.conversation.id;
						self.clearMessages();
						self.showWelcome(false);
						self.refreshConversationList();
						self.setInputEnabled(true);
						$('#ai-chat-title').text('');
					}
				}
			});
		},

		loadConversation: function(conversationId) {
			var self = this;
			var url = this.container.data('load-url');

			this.stopPolling();
			this.setLoading(true);
			this.activeConversationId = conversationId;

			$('.ai-chat__conversation-item').removeClass('ai-chat__conversation-item--active');
			$('.ai-chat__conversation-item[data-id="' + conversationId + '"]').addClass('ai-chat__conversation-item--active');

			$.ajax({
				url: url,
				type: 'GET',
				data: this.withUser({ conversationId: conversationId }),
				dataType: 'json',
				success: function(data) {
					self.setLoading(false);
					if (data.success) {
						self.clearMessages();
						self.setInputEnabled(!self.readOnly);

						$('#ai-chat-title').text(data.conversation.title || '');
						self.updateContextUsage(data.contextUsage);

						self.showWelcome(false);
						for (var i = 0; i < data.messages.length; i++) {
							var msg = data.messages[i];
							if (msg.error) {
								self.appendError(msg.content);
							} else {
								self.appendMessage(msg.author, msg.content, msg.tool_data);
							}
						}
						if (data.messages.length) {
							self.scrollToBottom();
						}

						// The last message is the user's with no reply yet = the worker is
						// still processing it - resume polling (e.g. after a page reload).
						var last = data.messages[data.messages.length - 1];
						if (last && last.author === 'user' && !self.readOnly) {
							self.setLoading(true);
							self.startPolling(last.id);
						}
					}
				},
				error: function() {
					self.setLoading(false);
				}
			});
		},

		sendMessage: function() {
			if (this.isLoading || this.readOnly) return;

			var textarea = $('#ai-chat-textarea');
			var message = $.trim(textarea.val());
			if (!message) return;

			var self = this;

			if (!this.activeConversationId) {
				this.setLoading(true);
				$.ajax({
					url: this.container.data('new-url'),
					type: 'GET',
					dataType: 'json',
					success: function(data) {
						if (data.success) {
							self.activeConversationId = data.conversation.id;
							self.refreshConversationList();
							self.setLoading(false);
							self.doSendMessage(message);
						} else {
							self.setLoading(false);
						}
					},
					error: function() {
						self.setLoading(false);
					}
				});
				return;
			}

			this.doSendMessage(message);
		},

		doSendMessage: function(message) {
			var textarea = $('#ai-chat-textarea');
			textarea.val('');
			textarea.css('height', 'auto');

			this.showWelcome(false);
			this.appendMessage('user', message, null);
			this.scrollToBottom();
			this.setLoading(true);

			var self = this;
			var url = this.container.data('send-url');

			$.ajax({
				url: url,
				type: 'POST',
				data: {
					conversationId: this.activeConversationId,
					message: message
				},
				dataType: 'json',
				success: function(data) {
					if (data.success) {
						// The message is queued - the worker processes the reply in the
						// background and polling picks it up.
						if (data.conversation && data.conversation.title) {
							$('#ai-chat-title').text(data.conversation.title);
							self.refreshConversationList();
						}
						self.startPolling(data.lastMessageId);
					} else {
						self.setLoading(false);
						self.appendError(data.error || 'Unknown error occurred.');
						self.scrollToBottom();
					}
				},
				error: function(xhr) {
					self.setLoading(false);
					self.appendError('Connection error. Please try again.');
					self.scrollToBottom();
				}
			});
		},

		// Reply polling: ask for messages newer than afterId until the AI reply (or an
		// error message from the worker) arrives, then stop.
		startPolling: function(afterId) {
			var self = this;
			var conversationId = this.activeConversationId;

			this.stopPolling();
			this.pollAttempts = 0;
			this.pollStartedAt = Date.now();

			var poll = function() {
				if (self.activeConversationId !== conversationId) return;

				if (Date.now() - self.pollStartedAt > self.POLL_MAX_MS) {
					self.setLoading(false);
					self.appendError('The response is taking too long. Reload the conversation later.');
					self.scrollToBottom();
					return;
				}
				self.pollAttempts++;

				$.ajax({
					url: self.container.data('poll-url'),
					type: 'GET',
					data: { conversationId: conversationId, afterId: afterId },
					dataType: 'json',
					success: function(data) {
						if (self.activeConversationId !== conversationId) return;

						// The conversation is gone (deleted) or the request was refused - no
						// point polling on, the input would stay locked until the timeout.
						if (data.success === false) {
							self.setLoading(false);
							self.appendError(data.error || 'The conversation is no longer available.');
							self.scrollToBottom();
							return;
						}

						if (data.success && data.messages.length) {
							self.setLoading(false);
							for (var i = 0; i < data.messages.length; i++) {
								var msg = data.messages[i];
								if (msg.error) {
									self.appendError(msg.content);
								} else {
									self.appendMessage(msg.author, msg.content, msg.tool_data);
								}
							}
							self.scrollToBottom();

							if (data.conversation && data.conversation.title) {
								$('#ai-chat-title').text(data.conversation.title);
							}
							self.updateContextUsage(data.contextUsage);
							return;
						}

						self.pollTimer = setTimeout(poll, self.pollDelay());
					},
					error: function() {
						if (self.activeConversationId !== conversationId) return;
						// Transient error (deploy, outage) - keep polling.
						self.pollTimer = setTimeout(poll, self.pollDelay());
					}
				});
			};

			this.pollTimer = setTimeout(poll, this.pollDelay());
		},

		// Staged backoff: a reply typically takes minutes, no reason to ask every 2 s
		// the whole time. 2 s (warm-up), then 5 s, then 10 s by the number of polls so far.
		pollDelay: function() {
			if (this.pollAttempts < 5) return this.POLL_INTERVAL_MS;
			if (this.pollAttempts < 15) return 5000;
			return 10000;
		},

		stopPolling: function() {
			if (this.pollTimer) {
				clearTimeout(this.pollTimer);
				this.pollTimer = null;
			}
		},

		deleteConversation: function(conversationId) {
			if (this.readOnly) return;
			var self = this;
			var url = this.container.data('delete-url');

			$.ajax({
				url: url,
				type: 'GET',
				data: { conversationId: conversationId },
				dataType: 'json',
				success: function(data) {
					if (data.success) {
						if (self.activeConversationId === conversationId) {
							self.activeConversationId = null;
							self.clearMessages();
							self.showWelcome(true);
							self.setInputEnabled(false);
							$('#ai-chat-title').text('');
						}
						self.refreshConversationList();
					}
				}
			});
		},

		refreshConversationList: function() {
			var self = this;
			var url = this.container.data('conversations-url');

			$.ajax({
				url: url,
				type: 'GET',
				data: this.withUser({}),
				dataType: 'json',
				success: function(data) {
					if (data.success) {
						if (typeof data.readOnly !== 'undefined') {
							self.applyReadOnly(data.readOnly);
						}
						self.renderConversationList(data.conversations);
					}
				}
			});
		},

		renderConversationList: function(conversations) {
			var list = $('#ai-chat-conversation-list');
			list.empty();

			if (conversations.length === 0) {
				list.append('<div class="ai-chat__no-conversations" id="ai-chat-no-conversations">No conversations yet.</div>');
				return;
			}

			for (var i = 0; i < conversations.length; i++) {
				var conv = conversations[i];
				var activeClass = conv.id === this.activeConversationId ? ' ai-chat__conversation-item--active' : '';
				var deleteBtn = this.readOnly
					? ''
					: '<button type="button" class="ai-chat__conversation-delete" data-id="' + conv.id + '" title="Delete">'
						+ '<i class="fa fa-trash"></i></button>';
				var html = '<div class="ai-chat__conversation-item' + activeClass + '" data-id="' + conv.id + '">'
					+ '<div class="ai-chat__conversation-title">' + this.escapeHtml(conv.title) + '</div>'
					+ '<div class="ai-chat__conversation-date">' + this.escapeHtml(conv.updated) + '</div>'
					+ deleteBtn
					+ '</div>';
				list.append(html);
			}
		},

		appendMessage: function(role, content, toolData) {
			var messagesEl = $('#ai-chat-messages');
			var msgDiv = $('<div>').addClass('ai-chat__message ai-chat__message--' + role);
			var bubbleDiv = $('<div>').addClass('ai-chat__bubble ai-chat__bubble--' + role);

			if (role === 'ai') {
				var rendered = this.renderMarkdown(content);
				bubbleDiv.html(rendered);
			} else {
				bubbleDiv.text(content);
			}

			msgDiv.append(bubbleDiv);

			if (toolData && toolData.length) {
				for (var i = 0; i < toolData.length; i++) {
					var td = toolData[i];
					if (td.tool_result && td.tool_result.type === 'chart') {
						var chartEl = this.renderChart(td.tool_result);
						msgDiv.append(chartEl);
					}
					if (td.tool_result && td.tool_result.type === 'table') {
						var tableEl = this.renderTable(td.tool_result);
						msgDiv.append(tableEl);
					}
					if (td.tool_result && td.tool_result.type === 'export') {
						var exportEl = this.renderExport(td.tool_result, td.messageId);
						if (exportEl) {
							msgDiv.append(exportEl);
						}
					}
				}
			}

			messagesEl.append(msgDiv);
		},

		appendError: function(errorText) {
			var messagesEl = $('#ai-chat-messages');
			var msgDiv = $('<div>').addClass('ai-chat__message ai-chat__message--error');
			var bubbleDiv = $('<div>').addClass('ai-chat__bubble ai-chat__bubble--error');
			bubbleDiv.html('<i class="fa fa-exclamation-triangle"></i> ' + this.escapeHtml(errorText));
			msgDiv.append(bubbleDiv);
			messagesEl.append(msgDiv);
		},

		renderMarkdown: function(text) {
			if (!text) return '';
			try {
				var html = marked.parse(text);
				return DOMPurify.sanitize(html, {
					ALLOWED_TAGS: ['p', 'br', 'strong', 'em', 'code', 'pre', 'ul', 'ol', 'li',
						'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'a', 'table',
						'thead', 'tbody', 'tr', 'th', 'td', 'hr', 'span', 'del'],
					ALLOWED_ATTR: ['href', 'target', 'class']
				});
			} catch (e) {
				return this.escapeHtml(text);
			}
		},

		renderChart: function(chartData) {
			var self = this;
			this.chartCounter++;
			var canvasId = 'ai-chart-' + this.chartCounter;
			var wrapper = $('<div>').addClass('ai-chat__chart-wrapper');

			if (chartData.title) {
				wrapper.append($('<div>').addClass('ai-chat__chart-title').text(chartData.title));
			}

			var canvas = $('<canvas>').attr('id', canvasId);
			wrapper.append($('<div>').addClass('ai-chat__chart-canvas').append(canvas));

			setTimeout(function() {
				var ctx = document.getElementById(canvasId);
				if (!ctx) return;

				var colors = [
					'rgba(54, 162, 235, 0.7)', 'rgba(255, 99, 132, 0.7)',
					'rgba(75, 192, 192, 0.7)', 'rgba(255, 206, 86, 0.7)',
					'rgba(153, 102, 255, 0.7)', 'rgba(255, 159, 64, 0.7)',
					'rgba(199, 199, 199, 0.7)', 'rgba(83, 102, 255, 0.7)'
				];

				var borderColors = [
					'rgba(54, 162, 235, 1)', 'rgba(255, 99, 132, 1)',
					'rgba(75, 192, 192, 1)', 'rgba(255, 206, 86, 1)',
					'rgba(153, 102, 255, 1)', 'rgba(255, 159, 64, 1)',
					'rgba(199, 199, 199, 1)', 'rgba(83, 102, 255, 1)'
				];

				var datasets = [];
				for (var i = 0; i < chartData.datasets.length; i++) {
					var ds = chartData.datasets[i];
					var isPie = (chartData.chart_type === 'pie' || chartData.chart_type === 'doughnut');
					datasets.push({
						label: ds.label,
						data: ds.data,
						backgroundColor: isPie ? colors.slice(0, ds.data.length) : colors[i % colors.length],
						borderColor: isPie ? borderColors.slice(0, ds.data.length) : borderColors[i % borderColors.length],
						borderWidth: 1
					});
				}

				// The instance is kept so destroyCharts() really disposes it when the
				// conversation is switched/cleared (otherwise even the global resize
				// listeners of Chart.js leak).
				self.chartInstances[canvasId] = new Chart(ctx, {
					type: chartData.chart_type,
					data: {
						labels: chartData.labels,
						datasets: datasets
					},
					options: {
						responsive: true,
						maintainAspectRatio: false,
						plugins: {
							title: {
								display: false
							}
						}
					}
				});
			}, 100);

			return wrapper;
		},

		renderTable: function(tableData) {
			var wrapper = $('<div>').addClass('ai-chat__table-wrapper');

			if (tableData.title) {
				wrapper.append($('<div>').addClass('ai-chat__table-title').text(tableData.title));
			}

			var table = $('<table>').addClass('ai-chat__table table table-striped table-sm');
			var thead = $('<thead>');
			var headerRow = $('<tr>');
			for (var i = 0; i < tableData.headers.length; i++) {
				headerRow.append($('<th>').text(tableData.headers[i]));
			}
			thead.append(headerRow);
			table.append(thead);

			var tbody = $('<tbody>');
			for (var j = 0; j < tableData.rows.length; j++) {
				var tr = $('<tr>');
				for (var k = 0; k < tableData.rows[j].length; k++) {
					tr.append($('<td>').text(tableData.rows[j][k]));
				}
				tbody.append(tr);
			}
			table.append(tbody);
			wrapper.append(table);

			// Client-side CSV export of a rendered table (from the data already loaded).
			var self = this;
			var label = $('#ai-chat').attr('data-export-csv-label') || 'CSV';
			var exportBtn = $('<button>').attr('type', 'button').addClass('btn btn-sm ai-chat__table-export');
			exportBtn.append($('<i>').addClass('fa fa-download')).append(document.createTextNode(' ' + label));
			exportBtn.on('click', function() {
				var base = (tableData.title || 'export').replace(/[^0-9A-Za-zÀ-ž_-]+/g, '_').replace(/^_+|_+$/g, '') || 'export';
				self.downloadCsv(base + '.csv', self.buildCsv(tableData.headers || [], tableData.rows || []));
			});
			wrapper.append($('<div>').addClass('ai-chat__table-actions').append(exportBtn));

			return wrapper;
		},

		// Server-side CSV export (large result sets): a download link streaming the full
		// result straight from the anonymized views. No SQL is sent to the browser -
		// only conversationId/messageId/token.
		renderExport: function(exportData, messageId) {
			var url = this.container.data('export-url');
			if (!url || !messageId || !exportData.token || !this.activeConversationId) {
				return null;
			}

			var href = url + (url.indexOf('?') >= 0 ? '&' : '?')
				+ 'conversationId=' + encodeURIComponent(this.activeConversationId)
				+ '&messageId=' + encodeURIComponent(messageId)
				+ '&token=' + encodeURIComponent(exportData.token);

			var label = $('#ai-chat').attr('data-export-csv-label') || 'CSV';
			var text = (exportData.title ? exportData.title + ' – ' : '') + label;

			var link = $('<a>').attr('href', href).addClass('btn btn-sm ai-chat__table-export');
			link.append($('<i>').addClass('fa fa-download')).append(document.createTextNode(' ' + text));

			return $('<div>').addClass('ai-chat__table-actions').append(link);
		},

		// Builds a CSV from headers + rows. Fields are quoted with inner quotes doubled;
		// a UTF-8 BOM is prepended so Excel renders diacritics correctly.
		buildCsv: function(headers, rows) {
			var esc = function(value) {
				return '"' + String(value == null ? '' : value).replace(/"/g, '""') + '"';
			};
			var lines = [headers.map(esc).join(',')];
			for (var i = 0; i < rows.length; i++) {
				lines.push((rows[i] || []).map(esc).join(','));
			}
			return '\ufeff' + lines.join('\r\n');
		},

		// Triggers a CSV file download in the browser (no server involved).
		downloadCsv: function(filename, content) {
			var blob = new Blob([content], { type: 'text/csv;charset=utf-8;' });
			var url = URL.createObjectURL(blob);
			var link = document.createElement('a');
			link.href = url;
			link.download = filename;
			document.body.appendChild(link);
			link.click();
			document.body.removeChild(link);
			setTimeout(function() { URL.revokeObjectURL(url); }, 0);
		},

		clearMessages: function() {
			$('#ai-chat-messages').empty();
			this.destroyCharts();
			this.hideContext();
		},

		// Hides the context gauge (empty/new conversation, user switch).
		hideContext: function() {
			$('#ai-chat-context').hide();
			$('#ai-chat-context-new').hide();
		},

		// Context-window usage: bar + verbal state + colour thresholds
		// (<70 % ok, 70-90 % warning, >90 % nearly full + a "new conversation" CTA).
		updateContextUsage: function(usage) {
			var box = $('#ai-chat-context');
			if (!box.length) return;

			if (!usage || !usage.window || !usage.used) {
				this.hideContext();
				return;
			}

			var percent = Math.max(0, Math.min(100, usage.percent || 0));
			var level = percent > 90 ? 'full' : (percent >= 70 ? 'warn' : 'ok');
			var tooltip = (box.attr('data-label') || '') + ': ' + usage.used + ' / ' + usage.window + ' (' + percent + ' %)';

			box.show()
				.removeClass('ai-chat__context--ok ai-chat__context--warn ai-chat__context--full')
				.addClass('ai-chat__context--' + level)
				.attr('title', tooltip)
				.attr('data-original-title', tooltip);

			$('#ai-chat-context-fill').css('width', percent + '%');
			$('#ai-chat-context-text').text(box.attr('data-state-' + level) || '');
			$('#ai-chat-context-percent').text(percent + ' %');
			$('#ai-chat-context-new').toggle(level === 'full' && !this.readOnly);
		},

		showWelcome: function(show) {
			var welcome = $('#ai-chat-welcome');
			if (show) {
				if (!welcome.length) {
					$('#ai-chat-messages').append(
						'<div class="ai-chat__welcome" id="ai-chat-welcome">'
						+ '<div class="ai-chat__welcome-icon"><i class="fa fa-comments fa-3x"></i></div>'
						+ '<p>Select a conversation or create a new one to get started.</p>'
						+ '</div>'
					);
				}
			} else {
				welcome.remove();
			}
		},

		setInputEnabled: function(enabled) {
			$('#ai-chat-textarea').prop('disabled', !enabled);
			$('#ai-chat-send-btn').prop('disabled', !enabled);
			if (enabled) {
				$('#ai-chat-textarea').focus();
			}
		},

		scrollToBottom: function() {
			var el = document.getElementById('ai-chat-messages');
			if (el) {
				el.scrollTop = el.scrollHeight;
			}
		},

		destroyCharts: function() {
			for (var key in this.chartInstances) {
				if (this.chartInstances.hasOwnProperty(key)) {
					this.chartInstances[key].destroy();
				}
			}
			this.chartInstances = {};
		},

		escapeHtml: function(text) {
			var div = document.createElement('div');
			div.appendChild(document.createTextNode(text));
			return div.innerHTML;
		}
	};

	$(function() {
		AiChat.init();
	});

})(jQuery);
