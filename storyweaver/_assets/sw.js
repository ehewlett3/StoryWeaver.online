/**
 * StoryWeaver — Shared front-end JavaScript.
 *
 * Loaded on every page. Handles: flash messages, in-place editor,
 * pending choice clicks, new-story modal, and CSRF token injection.
 */

document.addEventListener('DOMContentLoaded', function () {

    console.log('[SW] DOMContentLoaded — sw.js loaded OK');

    /* ==================================================================
     * Flash Messages
     * ================================================================*/

    var flashes = document.querySelectorAll('.sw-flash');
    flashes.forEach(function (el) {
        var btn = el.querySelector('.sw-flash-dismiss');
        if (btn) {
            btn.addEventListener('click', function () {
                el.style.transition = 'opacity 0.3s';
                el.style.opacity = '0';
                setTimeout(function () { el.remove(); }, 300);
            });
        }
        if (!el.classList.contains('sw-flash-error')) {
            setTimeout(function () {
                el.style.transition = 'opacity 0.3s';
                el.style.opacity = '0';
                setTimeout(function () { el.remove(); }, 300);
            }, 5000);
        }
    });

    /* ==================================================================
     * Utility — show a flash message dynamically
     * ================================================================*/

    function showFlash(message, type) {
        type = type || 'info';
        var container = document.querySelector('.sw-container');
        if (!container) return;
        var div = document.createElement('div');
        div.className = 'sw-flash sw-flash-' + type;
        var span = document.createElement('span');
        span.textContent = message;
        div.appendChild(span);
        var dismiss = document.createElement('button');
        dismiss.type = 'button';
        dismiss.className = 'sw-flash-dismiss';
        dismiss.setAttribute('aria-label', 'Dismiss');
        dismiss.textContent = '\u00D7';
        div.appendChild(dismiss);
        container.insertBefore(div, container.firstChild);
        var btn = div.querySelector('.sw-flash-dismiss');
        if (btn) {
            btn.addEventListener('click', function () {
                div.style.transition = 'opacity 0.3s';
                div.style.opacity = '0';
                setTimeout(function () { div.remove(); }, 300);
            });
        }
        if (type !== 'error') {
            setTimeout(function () {
                div.style.transition = 'opacity 0.3s';
                div.style.opacity = '0';
                setTimeout(function () { div.remove(); }, 300);
            }, 5000);
        }
    }

    /* ==================================================================
     * CSRF Token Injection — fill hidden CSRF fields in node HTML forms
     * ================================================================*/

    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    if (csrfMeta) {
        var csrfValue = csrfMeta.getAttribute('content');
        document.querySelectorAll('input[name="_csrf_token"][value=""]').forEach(function (el) {
            el.value = csrfValue;
        });
    }

    /* ==================================================================
     * Streaming AI Generation — Overlay + SSE
     * ================================================================*/

    var apiBase = (function () {
        // Derive base URL from the current page location
        var path = window.location.pathname;
        var idx = path.lastIndexOf('/');
        return idx > 0 ? path.substring(0, idx) : '';
    })();

    var csrfValue = (function () {
        var meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) return meta.getAttribute('content');
        // Fallback: read from any hidden CSRF input on the page
        var input = document.querySelector('input[name="_csrf_token"]');
        return input ? input.value : '';
    })();

    /**
     * Create and show the generation overlay with streaming text.
     * Returns the overlay element.
     */
    function showGenerationOverlay(titleText) {
        var overlay = document.createElement('div');
        overlay.className = 'sw-gen-overlay';
        overlay.innerHTML =
            '<div class="sw-gen-modal">' +
            '  <div class="sw-gen-header">' +
            '    <span class="sw-gen-spinner">✨</span>' +
            '    <span class="sw-gen-title">' + (titleText || 'Generating story…') + '</span>' +
            '    <span class="sw-gen-key-label"></span>' +
            '  </div>' +
            '  <div class="sw-gen-text"></div>' +
            '  <div class="sw-gen-status">This can take a while. You can abort if it stalls.</div>' +
            '  <div class="sw-gen-actions">' +
            '    <button type="button" class="sw-btn sw-btn-secondary sw-gen-abort">Abort</button>' +
            '  </div>' +
            '</div>';
        document.body.appendChild(overlay);
        // Force reflow then add open class for animation
        overlay.offsetHeight;
        overlay.classList.add('sw-gen-open');
        return overlay;
    }

    /**
     * Get the selected text-generation mode value.
     */
    function getSelectedTextModeValue() {
        var picker = document.getElementById('sw-text-key-picker')
                  || document.getElementById('sw-key-picker-modal');
        if (!picker) return '';
        return picker.value;
    }

    /**
     * Get the selected text-generation key ID.
     */
    function getSelectedTextKeyId() {
        var value = getSelectedTextModeValue();
        return value === 'human' ? '' : value;
    }

    /**
     * Determine whether human text mode is selected.
     */
    function isHumanTextMode() {
        return getSelectedTextModeValue() === 'human';
    }

    /**
     * Get the selected image-generation key ID.
     */
    function getSelectedImageKeyId() {
        var picker = document.getElementById('sw-image-key-picker');
        if (!picker) return '';
        return picker.value;
    }

    // Backward-compatible alias
    function getSelectedKeyId() {
        return getSelectedTextKeyId();
    }

    function promptForOptionalGuidance(actionLabel) {
        var text = window.prompt(
            'Optional: add extra guidance for ' + actionLabel + '. Leave it blank if you want.\n\n'
            + 'Press OK to continue, or Cancel to cancel the regeneration.',
            ''
        );
        if (text === null) {
            return null;
        }
        return text.trim();
    }

    function createStreamingRequestId() {
        var bytes = [];
        if (window.crypto && typeof window.crypto.getRandomValues === 'function') {
            var random = new Uint8Array(16);
            window.crypto.getRandomValues(random);
            for (var i = 0; i < random.length; i++) {
                bytes.push(random[i].toString(16).padStart(2, '0'));
            }
            return 'gen_' + bytes.join('');
        }

        var fallback = '';
        while (fallback.length < 32) {
            fallback += Math.floor(Math.random() * 0x100000000).toString(16).padStart(8, '0');
        }
        return 'gen_' + fallback.slice(0, 32);
    }

    function notifyGenerationAbort(requestId) {
        if (!requestId) {
            return;
        }

        var body = JSON.stringify({
            request_id: requestId,
            _csrf_token: csrfValue
        });
        var url = apiBase + '/api?action=abort_generation';

        if (navigator.sendBeacon) {
            try {
                var blob = new Blob([body], { type: 'application/json' });
                if (navigator.sendBeacon(url, blob)) {
                    return;
                }
            } catch (err) {
                // Fall through to fetch keepalive.
            }
        }

        fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: body,
            keepalive: true
        }).catch(function () {});
    }

    function ensureChoiceFormField(form, fieldName) {
        var field = form.querySelector('[name="' + fieldName + '"]');
        if (field) {
            return field;
        }

        field = document.createElement('input');
        field.type = 'hidden';
        field.name = fieldName;
        form.appendChild(field);
        return field;
    }

    function syncChoiceFormMode(form) {
        if (!form) return;

        ensureChoiceFormField(form, 'use_ai').value = isHumanTextMode() ? '0' : '1';
        ensureChoiceFormField(form, 'key_id').value = getSelectedTextKeyId();
    }

    function updateTextAIControlVisibility() {
        var showAiTextControls = !isHumanTextMode();
        document.querySelectorAll('[data-ai-text-control]').forEach(function (el) {
            el.hidden = !showAiTextControls;
        });
        if (!showAiTextControls) {
            var pendingChoicesModalEl = document.getElementById('sw-pending-choice-modal');
            if (pendingChoicesModalEl) {
                pendingChoicesModalEl.classList.remove('sw-modal-open');
                pendingChoicesModalEl.setAttribute('aria-hidden', 'true');
            }
        }
    }

    function submitChoiceForm(form, choiceText) {
        if (!form) return;

        syncChoiceFormMode(form);

        var choiceField = ensureChoiceFormField(form, 'choice');
        choiceField.value = choiceText || '';

        var custom = form.querySelector('[name="custom_choice"]');
        if (choiceText && custom) {
            custom.value = '';
        }

        form.submit();
    }

    // Restore last chosen keys from localStorage and listen for changes
    (function () {
        var textPickers = [
            document.getElementById('sw-text-key-picker'),
            document.getElementById('sw-key-picker-modal')
        ];
        var imagePickers = [
            document.getElementById('sw-image-key-picker')
        ];

        function restoreAndListen(pickers, storageKey) {
            var saved = localStorage.getItem(storageKey);
            pickers.forEach(function (picker) {
                if (!picker) return;
                if (saved) {
                    for (var i = 0; i < picker.options.length; i++) {
                        if (picker.options[i].value === saved) {
                            picker.value = saved;
                            break;
                        }
                    }
                }
                picker.addEventListener('change', function () {
                    localStorage.setItem(storageKey, picker.value);
                    updateTextAIControlVisibility();
                    document.querySelectorAll('.sw-custom-choice').forEach(syncChoiceFormMode);
                });
            });
        }

        restoreAndListen(textPickers, 'sw-last-text-key-id');
        restoreAndListen(imagePickers, 'sw-last-image-key-id');
        updateTextAIControlVisibility();
        document.querySelectorAll('.sw-custom-choice').forEach(syncChoiceFormMode);
    })();

    /**
     * Start a streaming generation request.
     *
     * @param {Object} payload - The request payload.
     * @param {Object} options - Optional callbacks/config: onFallback, onDone, action, startTitle, doneTitle, failTitle
     */
    function startStreamingGeneration(payload, options) {
        options = options || {};
        var overlay = showGenerationOverlay(options.startTitle || 'Generating story…');
        var textEl = overlay.querySelector('.sw-gen-text');
        var statusEl = overlay.querySelector('.sw-gen-status');
        var keyLabelEl = overlay.querySelector('.sw-gen-key-label');
        var headerEl = overlay.querySelector('.sw-gen-header');
        var abortBtn = overlay.querySelector('.sw-gen-abort');
        var streamBuffer = ''; // accumulates all raw tokens
        var reader = null;
        var controller = typeof AbortController === 'function' ? new AbortController() : null;
        var finished = false;
        var abortedByUser = false;
        var abortHandled = false;
        var requestId = createStreamingRequestId();

        function completeAbortUi() {
            if (abortHandled) {
                return;
            }
            abortHandled = true;
            if (overlay && overlay.parentNode) {
                overlay.remove();
            }
            showFlash('Generation cancelled.', 'info');
        }

        function removeAbortButton() {
            if (abortBtn && abortBtn.parentNode) {
                abortBtn.parentNode.remove();
                abortBtn = null;
            }
        }

        function handleUserAbort() {
            if (finished) {
                return;
            }

            finished = true;
            abortedByUser = true;
            statusEl.textContent = 'Aborting…';
            if (abortBtn) {
                abortBtn.disabled = true;
                abortBtn.textContent = 'Aborting…';
            }
            notifyGenerationAbort(requestId);
            if (reader) {
                reader.cancel().catch(function () {});
            }
            if (controller) {
                controller.abort();
            }
            completeAbortUi();
        }

        if (abortBtn) {
            abortBtn.addEventListener('click', handleUserAbort);
        }

        // Extract readable paragraph text from the streaming JSON buffer.
        // The AI response format is: {"paragraphs":["text","text"],"choices":[...]}
        // We parse incrementally to show only paragraph content.
        function extractDisplayText(buf) {
            var result = '';
            // Find the start of paragraphs array
            var pStart = buf.indexOf('"paragraphs"');
            if (pStart === -1) return '';
            var bracketStart = buf.indexOf('[', pStart);
            if (bracketStart === -1) return '';

            // Walk through the buffer extracting string contents within the array
            var i = bracketStart + 1;
            var inString = false;
            var escaped = false;
            var depth = 1;
            var paraCount = 0;

            while (i < buf.length && depth > 0) {
                var ch = buf[i];
                if (inString) {
                    if (escaped) {
                        // Handle escape sequences
                        if (ch === 'n') result += '\n';
                        else if (ch === '"') result += '"';
                        else if (ch === '\\') result += '\\';
                        else if (ch === '/') result += '/';
                        else result += ch;
                        escaped = false;
                    } else if (ch === '\\') {
                        escaped = true;
                    } else if (ch === '"') {
                        inString = false;
                    } else {
                        result += ch;
                    }
                } else {
                    if (ch === '"') {
                        inString = true;
                        if (paraCount > 0) result += '\n\n';
                        paraCount++;
                    } else if (ch === ']') {
                        depth--;
                    } else if (ch === '[') {
                        depth++;
                    }
                }
                i++;
            }
            return result;
        }

        payload._csrf_token = csrfValue;
        payload.key_id = payload.key_id || getSelectedTextKeyId();
        payload.request_id = requestId;

        // Persist text key choice for next time
        if (payload.key_id) {
            localStorage.setItem('sw-last-text-key-id', payload.key_id);
        }

        // Use fetch + ReadableStream for SSE (more control than EventSource for POST)
        var requestOptions = {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        };
        if (controller) {
            requestOptions.signal = controller.signal;
        }

        fetch(apiBase + '/api?action=' + (options.action || 'stream_generate_node'), requestOptions).then(function (response) {
            if (!response.ok || !response.body) {
                throw new Error('Stream unavailable');
            }

            reader = response.body.getReader();
            var decoder = new TextDecoder();
            var buffer = '';

            function read() {
                reader.read().then(function (result) {
                    if (result.done) return;

                    buffer += decoder.decode(result.value, { stream: true });

                    // Process complete SSE messages (double newline separated)
                    var parts = buffer.split('\n\n');
                    buffer = parts.pop(); // keep incomplete part

                    parts.forEach(function (part) {
                        if (abortedByUser) {
                            return;
                        }
                        var eventMatch = part.match(/^event:\s*(\w+)/m);
                        if (!eventMatch) return;
                        var eventType = eventMatch[1];

                        // Extract all data: lines and join them
                        var dataLines = [];
                        part.split('\n').forEach(function (line) {
                            var m = line.match(/^data:\s?(.*)/);
                            if (m) dataLines.push(m[1]);
                        });
                        var rawData = dataLines.join('\n');

                        if (eventType === 'token') {
                            // Accumulate raw tokens, extract paragraph text for display
                            streamBuffer += rawData;
                            textEl.textContent = extractDisplayText(streamBuffer);
                            textEl.scrollTop = textEl.scrollHeight;
                        } else if (eventType === 'info') {
                            var data;
                            try { data = JSON.parse(rawData); } catch (e) { return; }
                            if (data.key_label) {
                                keyLabelEl.textContent = 'Using: ' + data.key_label;
                            }
                            if (data.message) {
                                statusEl.textContent = data.message;
                            }
                        } else if (eventType === 'done') {
                            if (abortedByUser) {
                                return;
                            }
                            finished = true;
                            removeAbortButton();
                            var data;
                            try { data = JSON.parse(rawData); } catch (e) { return; }
                            if (options.onDone) {
                                options.onDone(overlay, data, options);
                            } else {
                                // Replace raw stream text with rendered paragraphs + choices
                                showDropInResult(overlay, data, options);
                            }
                        } else if (eventType === 'error') {
                            if (abortedByUser) {
                                return;
                            }
                            finished = true;
                            removeAbortButton();
                            var data;
                            try { data = JSON.parse(rawData); } catch (e) { return; }
                            headerEl.querySelector('.sw-gen-spinner').textContent = '\u274C';
                            headerEl.querySelector('.sw-gen-title').textContent = options.failTitle || 'Generation failed';
                            statusEl.textContent = data.error || 'Unknown error';
                            var closeBtn = document.createElement('button');
                            closeBtn.className = 'sw-btn sw-btn-secondary';
                            closeBtn.textContent = 'Close';
                            closeBtn.style.marginTop = '1rem';
                            closeBtn.addEventListener('click', function () {
                                overlay.remove();
                            });
                            overlay.querySelector('.sw-gen-modal').appendChild(closeBtn);
                        }
                    });

                    if (!finished) {
                        read();
                    }
                }).catch(function () {
                    if (abortedByUser) {
                        completeAbortUi();
                        return;
                    }
                    // Stream error — fall back
                    if (options.onFallback) {
                        overlay.remove();
                        options.onFallback();
                    }
                });
            }

            read();
        }).catch(function (err) {
            if (abortedByUser || (err && err.name === 'AbortError')) {
                completeAbortUi();
                return;
            }
            // Fetch failed — fall back to form POST
            overlay.remove();
            if (options.onFallback) {
                options.onFallback();
            }
        });
    }

    /**
     * Replace the raw streaming text with rendered paragraphs + choices,
     * each animated with a staggered drop-in effect before redirecting.
     */
    function showDropInResult(overlay, data, options) {
        options = options || {};
        var modal = overlay.querySelector('.sw-gen-modal');
        var headerEl = modal.querySelector('.sw-gen-header');
        var textEl = modal.querySelector('.sw-gen-text');
        var statusEl = modal.querySelector('.sw-gen-status');
        var actionEl = modal.querySelector('.sw-gen-actions');

        if (actionEl) {
            actionEl.remove();
        }

        headerEl.querySelector('.sw-gen-spinner').textContent = '\u2705';
        headerEl.querySelector('.sw-gen-title').textContent = options.doneTitle || 'Story generated!';
        statusEl.textContent = '';

        // Build rendered content with paragraphs and choices
        var rendered = document.createElement('div');
        rendered.className = 'sw-gen-rendered';

        var paras = data.paragraphs || [];
        var choices = data.choices || [];

        paras.forEach(function (p, i) {
            var el = document.createElement('p');
            el.className = 'sw-para sw-drop-in';
            el.innerHTML = p;
            el.style.animationDelay = (i * 140) + 'ms';
            rendered.appendChild(el);
        });

        if (choices.length > 0) {
            var choiceList = document.createElement('ul');
            choiceList.className = 'sw-gen-choices';
            choices.forEach(function (c, i) {
                var li = document.createElement('li');
                li.className = 'sw-drop-in';
                li.textContent = c.text || c;
                li.style.animationDelay = ((paras.length + i) * 140 + 200) + 'ms';
                choiceList.appendChild(li);
            });
            rendered.appendChild(choiceList);
        }

        // Swap out the streaming text for the rendered result
        textEl.style.opacity = '0';
        textEl.style.transition = 'opacity 0.3s ease';
        setTimeout(function () {
            textEl.replaceWith(rendered);
            // Show redirect message after animations complete
            var totalDelay = (paras.length + choices.length) * 140 + 600;
            setTimeout(function () {
                statusEl.textContent = 'Redirecting\u2026';
                setTimeout(function () {
                    window.location.href = data.url;
                }, 500);
            }, totalDelay);
        }, 300);
    }

    var nodeContent = document.querySelector('.sw-node-content');
    if (nodeContent) {
        var showNodeEndActions = function () {
            nodeContent.classList.add('sw-node-content-tapped');
        };
        var hideNodeEndActions = function (event) {
            if (event && nodeContent.contains(event.target)) {
                return;
            }
            nodeContent.classList.remove('sw-node-content-tapped');
        };

        nodeContent.addEventListener('click', showNodeEndActions);
        nodeContent.addEventListener('focusin', showNodeEndActions);
        document.addEventListener('click', hideNodeEndActions);
    }

    function escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function renderStoryComparePreview(paragraphs, choices) {
        var html = '';
        (paragraphs || []).forEach(function (p) {
            html += '<p class="sw-para">' + p + '</p>';
        });
        if (!paragraphs || paragraphs.length === 0) {
            html += '<p class="sw-text-muted"><em>No story text.</em></p>';
        }

        choices = choices || [];
        if (choices.length > 0) {
            html += '<h3 class="sw-story-compare-heading">Choices</h3><ul class="sw-story-compare-choices">';
            choices.forEach(function (choice) {
                html += '<li>' + escapeHtml(choice.text || '') + '</li>';
            });
            html += '</ul>';
        }

        return html;
    }

    function showStoryCompareModal(data) {
        var overlay = document.createElement('div');
        overlay.className = 'sw-modal-backdrop sw-modal-open';
        overlay.innerHTML =
            '<div class="sw-modal sw-story-compare-modal">' +
                '<h2>Choose a story version</h2>' +
                '<p class="sw-text-muted">Click the version you want to keep. Only the selected version will remain.</p>' +
                '<div class="sw-story-compare">' +
                    '<div class="sw-story-option" data-choice="current">' +
                        '<span class="sw-story-label">Current</span>' +
                        '<div class="sw-story-preview">' + renderStoryComparePreview(data.current_paragraphs || [], data.current_choices || []) + '</div>' +
                    '</div>' +
                    '<div class="sw-story-option" data-choice="new">' +
                        '<span class="sw-story-label">Regenerated</span>' +
                        '<div class="sw-story-preview">' + renderStoryComparePreview(data.paragraphs || [], data.choices || []) + '</div>' +
                    '</div>' +
                '</div>' +
                '<div class="sw-modal-actions">' +
                    '<button type="button" class="sw-btn sw-btn-secondary sw-story-compare-cancel">Cancel (keep current)</button>' +
                '</div>' +
            '</div>';

        document.body.appendChild(overlay);

        overlay.querySelectorAll('.sw-story-option').forEach(function (opt) {
            opt.addEventListener('click', function () {
                if (opt.getAttribute('data-choice') === 'current') {
                    overlay.remove();
                    return;
                }

                overlay.querySelectorAll('.sw-story-option').forEach(function (optionEl) {
                    optionEl.style.pointerEvents = 'none';
                });

                fetch(apiBase + '/api?action=apply_regenerated_node', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        story_id: data.story_id,
                        node_id: data.node_id,
                        paragraphs: data.paragraphs || [],
                        choices: data.choices || [],
                        ai_model: data.ai_model || '',
                        ai_provider: data.ai_provider || '',
                        ai_key_label: data.ai_key_label || '',
                        scenario_essentials: data.scenario_essentials || '',
                        ending: !!data.ending,
                        _csrf_token: csrfValue
                    })
                })
                .then(function (res) { return res.json(); })
                .then(function (applyData) {
                    if (applyData.ok && applyData.url) {
                        window.location.href = applyData.url;
                        return;
                    }
                    overlay.remove();
                    showFlash(applyData.error || 'Failed to apply regenerated story.', 'error');
                })
                .catch(function (err) {
                    overlay.remove();
                    showFlash('Error: ' + err.message, 'error');
                });
            });
        });

        overlay.querySelector('.sw-story-compare-cancel').addEventListener('click', function () {
            overlay.remove();
        });
    }

    /* ==================================================================
     * Pending Choice Clicks — wire up with streaming
     * ================================================================*/

    document.querySelectorAll('.sw-choice-pending').forEach(function (link) {
        link.addEventListener('click', function (e) {
            e.preventDefault();
            var choiceText = this.dataset.choiceText || this.textContent.trim();
            var section = this.closest('.sw-choices');
            if (!section) return;

            var form = section.querySelector('.sw-custom-choice');
            if (!form) return;

            var storyId = form.querySelector('[name="story_id"]');
            var parentNodeId = form.querySelector('[name="parent_node_id"]');
            if (!storyId || !parentNodeId) return;

            syncChoiceFormMode(form);
            if (isHumanTextMode()) {
                submitChoiceForm(form, choiceText);
                return;
            }

            startStreamingGeneration({
                story_id: storyId.value,
                parent_node_id: parentNodeId.value,
                choice_text: choiceText
            }, {
                onFallback: function () {
                    submitChoiceForm(form, choiceText);
                }
            });
        });
    });

    /* ==================================================================
     * Custom Choice Form — intercept for streaming
     * ================================================================*/

    document.querySelectorAll('.sw-custom-choice').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            var customInput = form.querySelector('[name="custom_choice"]');
            var choiceText = customInput ? customInput.value.trim() : '';
            if (choiceText === '') return; // let normal validation handle it

            var storyId = form.querySelector('[name="story_id"]');
            var parentNodeId = form.querySelector('[name="parent_node_id"]');
            if (!storyId || !parentNodeId) return;

            syncChoiceFormMode(form);
            if (isHumanTextMode()) {
                return;
            }

            e.preventDefault();

            startStreamingGeneration({
                story_id: storyId.value,
                parent_node_id: parentNodeId.value,
                choice_text: choiceText
            }, {
                onFallback: function () {
                    form.submit();
                }
            });
        });
    });

    /* ==================================================================
     * "Generate with AI" Button on Node Pages
     * ================================================================*/

    var aiContinueBtn = document.getElementById('sw-ai-continue-btn');
    if (aiContinueBtn) {
        aiContinueBtn.addEventListener('click', function () {
            var storyId = aiContinueBtn.getAttribute('data-story-id');
            var nodeId = aiContinueBtn.getAttribute('data-node-id');

            startStreamingGeneration({
                story_id: storyId,
                parent_node_id: nodeId,
                choice_text: 'Continue the story'
            }, {
                onFallback: function () {
                    var form = document.createElement('form');
                    form.method = 'POST';
                    form.action = 'play';
                    form.innerHTML =
                        '<input type="hidden" name="story_id" value="' + storyId + '">' +
                        '<input type="hidden" name="parent_node_id" value="' + nodeId + '">' +
                        '<input type="hidden" name="custom_choice" value="Continue the story">' +
                        '<input type="hidden" name="_csrf_token" value="' + csrfValue + '">';
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        });
    }

    var regenerateStoryBtn = document.getElementById('sw-regenerate-story-btn');
    if (regenerateStoryBtn) {
        regenerateStoryBtn.addEventListener('click', function () {
            var steerPrompt = promptForOptionalGuidance('this story regeneration');
            if (steerPrompt === null) {
                return;
            }

            var storyId = regenerateStoryBtn.getAttribute('data-story-id');
            var nodeId = regenerateStoryBtn.getAttribute('data-node-id');
            var payload = {
                story_id: storyId,
                node_id: nodeId,
                key_id: getSelectedTextKeyId() || '',
                steer_prompt: steerPrompt
            };

            startStreamingGeneration(payload, {
                action: 'stream_regenerate_node',
                startTitle: 'Regenerating story…',
                doneTitle: 'Story regenerated!',
                failTitle: 'Regeneration failed',
                onDone: function (overlay, data) {
                    overlay.remove();
                    showStoryCompareModal(data);
                },
                onFallback: function () {
                    fetch(apiBase + '/api?action=regenerate_node', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            story_id: storyId,
                            node_id: nodeId,
                            key_id: payload.key_id,
                            steer_prompt: steerPrompt,
                            _csrf_token: csrfValue
                        })
                    })
                    .then(function (res) { return res.json(); })
                    .then(function (data) {
                        if (data.ok) {
                            showStoryCompareModal(data);
                            return;
                        }
                        showFlash(data.error || 'Failed to regenerate the page.', 'error');
                    })
                    .catch(function (err) {
                        showFlash('Error: ' + err.message, 'error');
                    });
                }
            });
        });
    }

    var deleteFinalPageBtn = document.getElementById('sw-delete-final-page-btn');
    if (deleteFinalPageBtn) {
        deleteFinalPageBtn.addEventListener('click', function () {
            if (!confirm('Delete this final page? This cannot be undone.')) {
                return;
            }

            var storyId = deleteFinalPageBtn.getAttribute('data-story-id');
            var nodeId = deleteFinalPageBtn.getAttribute('data-node-id');
            var originalLabel = deleteFinalPageBtn.textContent;

            deleteFinalPageBtn.disabled = true;
            deleteFinalPageBtn.textContent = '…';

            fetch(apiBase + '/api?action=delete_final_page', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    story_id: storyId,
                    node_id: nodeId,
                    _csrf_token: csrfValue
                })
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.ok && data.redirect) {
                    window.location.href = data.redirect;
                    return;
                }

                showFlash(data.error || 'Failed to delete the page.', 'error');
                deleteFinalPageBtn.disabled = false;
                deleteFinalPageBtn.textContent = originalLabel;
            })
            .catch(function (err) {
                showFlash('Error: ' + err.message, 'error');
                deleteFinalPageBtn.disabled = false;
                deleteFinalPageBtn.textContent = originalLabel;
            });
        });
    }

    var pendingChoicesModal = document.getElementById('sw-pending-choice-modal');
    var openPendingChoicesBtn = document.getElementById('sw-open-pending-choices-btn');
    var closePendingChoicesBtn = document.getElementById('sw-close-pending-choices-btn');

    function openPendingChoicesModal() {
        if (!pendingChoicesModal) return;
        pendingChoicesModal.classList.add('sw-modal-open');
        pendingChoicesModal.setAttribute('aria-hidden', 'false');
    }

    function closePendingChoicesModal() {
        if (!pendingChoicesModal) return;
        pendingChoicesModal.classList.remove('sw-modal-open');
        pendingChoicesModal.setAttribute('aria-hidden', 'true');
        if (window.location.hash === '#sw-pending-choices') {
            history.replaceState(null, '', window.location.pathname + window.location.search);
        }
    }

    if (openPendingChoicesBtn && pendingChoicesModal) {
        openPendingChoicesBtn.addEventListener('click', function () {
            openPendingChoicesModal();
        });
    }

    if (closePendingChoicesBtn && pendingChoicesModal) {
        closePendingChoicesBtn.addEventListener('click', function () {
            closePendingChoicesModal();
        });
    }

    if (pendingChoicesModal) {
        pendingChoicesModal.addEventListener('click', function (e) {
            if (e.target === pendingChoicesModal) {
                closePendingChoicesModal();
            }
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && pendingChoicesModal.classList.contains('sw-modal-open')) {
                closePendingChoicesModal();
            }
        });

        if (window.location.hash === '#sw-pending-choices') {
            openPendingChoicesModal();
        }
    }

    var regeneratePendingChoicesBtn = document.getElementById('sw-regenerate-pending-choices-btn');
    if (regeneratePendingChoicesBtn) {
        var regeneratePendingChoicesController = null;

        regeneratePendingChoicesBtn.addEventListener('click', function () {
            if (regeneratePendingChoicesController) {
                regeneratePendingChoicesController.abort();
                return;
            }

            var steerPrompt = promptForOptionalGuidance('these pending choices');
            if (steerPrompt === null) {
                return;
            }

            var storyId = regeneratePendingChoicesBtn.getAttribute('data-story-id');
            var nodeId = regeneratePendingChoicesBtn.getAttribute('data-node-id');
            var keyId = getSelectedTextKeyId() || '';

            regeneratePendingChoicesController = typeof AbortController === 'function' ? new AbortController() : null;
            regeneratePendingChoicesBtn.disabled = !regeneratePendingChoicesController;
            regeneratePendingChoicesBtn.textContent = regeneratePendingChoicesController
                ? '✖ Abort Pending-Choice Regeneration'
                : '✨ Regenerating…';
            regeneratePendingChoicesBtn.classList.toggle('sw-btn-danger', !!regeneratePendingChoicesController);

            var regeneratePendingChoicesRequest = {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    story_id: storyId,
                    node_id: nodeId,
                    key_id: keyId,
                    steer_prompt: steerPrompt,
                    _csrf_token: csrfValue
                })
            };
            if (regeneratePendingChoicesController) {
                regeneratePendingChoicesRequest.signal = regeneratePendingChoicesController.signal;
            }

            fetch(apiBase + '/api?action=regenerate_pending_choices', {
                method: regeneratePendingChoicesRequest.method,
                headers: regeneratePendingChoicesRequest.headers,
                body: regeneratePendingChoicesRequest.body,
                signal: regeneratePendingChoicesRequest.signal
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.ok) {
                    closePendingChoicesModal();
                    window.location.reload();
                    return;
                }
                showFlash(data.error || 'Failed to regenerate pending choices.', 'error');
            })
            .catch(function (err) {
                if (err && err.name === 'AbortError') {
                    showFlash('Pending-choice regeneration cancelled.', 'info');
                    return;
                }
                showFlash('Error: ' + err.message, 'error');
            })
            .finally(function () {
                regeneratePendingChoicesController = null;
                regeneratePendingChoicesBtn.disabled = false;
                regeneratePendingChoicesBtn.textContent = regeneratePendingChoicesBtn.dataset.idleText || '✨ Regenerate Pending Choices';
                regeneratePendingChoicesBtn.classList.remove('sw-btn-danger');
            });
        });
    }

    document.querySelectorAll('.sw-pending-choice-save-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var item = btn.closest('.sw-pending-choice-item');
            if (!item) return;

            var input = item.querySelector('.sw-pending-choice-input');
            var choiceText = input ? input.value.trim() : '';
            if (!choiceText) {
                showFlash('Choice text cannot be empty.', 'error');
                return;
            }

            btn.disabled = true;
            fetch(apiBase + '/api?action=update_pending_choice', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    story_id: item.dataset.storyId,
                    node_id: item.dataset.nodeId,
                    choice_id: Number(item.dataset.choiceId || 0),
                    choice_text: choiceText,
                    _csrf_token: csrfValue
                })
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.ok) {
                    window.location.hash = 'sw-pending-choices';
                    window.location.reload();
                    return;
                }
                showFlash(data.error || 'Failed to update pending choice.', 'error');
                btn.disabled = false;
            })
            .catch(function (err) {
                showFlash('Error: ' + err.message, 'error');
                btn.disabled = false;
            });
        });
    });

    document.querySelectorAll('.sw-pending-choice-delete-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var item = btn.closest('.sw-pending-choice-item');
            if (!item) return;

            btn.disabled = true;
            fetch(apiBase + '/api?action=delete_pending_choice', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    story_id: item.dataset.storyId,
                    node_id: item.dataset.nodeId,
                    choice_id: Number(item.dataset.choiceId || 0),
                    _csrf_token: csrfValue
                })
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.ok) {
                    window.location.hash = 'sw-pending-choices';
                    window.location.reload();
                    return;
                }
                showFlash(data.error || 'Failed to delete pending choice.', 'error');
                btn.disabled = false;
            })
            .catch(function (err) {
                showFlash('Error: ' + err.message, 'error');
                btn.disabled = false;
            });
        });
    });

    /* ==================================================================
     * Image Generation Button
     * ================================================================*/

    var genImageBtn = document.getElementById('sw-gen-image-btn');
    var regenImageBtn = document.getElementById('sw-regen-image-btn');
    var uploadInput = document.getElementById('sw-image-upload');
    var imageUploadLabel = document.querySelector('label[for="sw-image-upload"]');
    var imageControlsHost = document.getElementById('sw-node-image-actions');
    var imageActionInsertBefore = document.getElementById('sw-image-action-menu');
    var imageUploadMenu = document.getElementById('sw-image-action-menu');
    var imageActionStoryId = '';
    var imageActionNodeId = '';
    var canGenerateImages = !!genImageBtn || !!regenImageBtn;
    var canManageImages = !!uploadInput;
    var genImageController = null;
    var regenImageController = null;
    var imageEstimateStorageKey = 'sw-image-generation-history-ms';
    var activeImageProgress = null;

    [genImageBtn, regenImageBtn, uploadInput].forEach(function (el) {
        if (!el) return;
        if (!imageActionStoryId) {
            imageActionStoryId = el.getAttribute('data-story-id') || '';
        }
        if (!imageActionNodeId) {
            imageActionNodeId = el.getAttribute('data-node-id') || '';
        }
    });

    function getCurrentNodeImageUrl() {
        var currentImage = document.querySelector('#sw-images .sw-node-image');
        return currentImage ? (currentImage.getAttribute('src') || '') : '';
    }

    function readImageGenerationEstimate() {
        try {
            var raw = window.localStorage.getItem(imageEstimateStorageKey);
            if (!raw) {
                return 0;
            }

            var history = JSON.parse(raw);
            if (!Array.isArray(history) || history.length === 0) {
                return 0;
            }

            var total = 0;
            var count = 0;
            history.forEach(function (entry) {
                var value = Number(entry);
                if (!isFinite(value) || value < 1000) {
                    return;
                }
                total += Math.min(value, 180000);
                count++;
            });

            if (count === 0) {
                return 0;
            }

            return Math.round(total / count);
        } catch (err) {
            return 0;
        }
    }

    function storeImageGenerationEstimate(elapsedMs) {
        if (!isFinite(elapsedMs) || elapsedMs < 250) {
            return;
        }

        var normalized = Math.max(1000, Math.min(Math.round(elapsedMs), 180000));

        try {
            var raw = window.localStorage.getItem(imageEstimateStorageKey);
            var history = raw ? JSON.parse(raw) : [];
            if (!Array.isArray(history)) {
                history = [];
            }
            history.push(normalized);
            if (history.length > 12) {
                history = history.slice(history.length - 12);
            }
            window.localStorage.setItem(imageEstimateStorageKey, JSON.stringify(history));
        } catch (err) {
            // Ignore localStorage failures.
        }
    }

    function formatRemainingEstimate(ms) {
        var seconds = Math.max(1, Math.round(ms / 1000));
        return seconds + 's';
    }

    function clearImageProgress(btn, idleText) {
        if (activeImageProgress && activeImageProgress.button === btn) {
            if (activeImageProgress.timerId) {
                window.clearInterval(activeImageProgress.timerId);
            }
            activeImageProgress = null;
        }

        if (!btn) return;

        btn.classList.remove('sw-btn-progress', 'sw-btn-progress-estimating', 'sw-btn-danger');
        btn.style.removeProperty('--sw-progress-percent');
        btn.removeAttribute('title');

        if (document.body.contains(btn)) {
            btn.disabled = false;
            btn.textContent = idleText;
        }
    }

    function updateImageProgressVisual(state) {
        if (!state || !state.button) return;

        var btn = state.button;
        if (!document.body.contains(btn)) {
            clearImageProgress(btn, '');
            return;
        }

        var elapsed = Date.now() - state.startedAt;
        var progressPercent = 16;
        if (state.estimateMs > 0) {
            progressPercent = Math.min(100, Math.max(6, (elapsed / state.estimateMs) * 100));
            var remaining = Math.max(0, state.estimateMs - elapsed);
            btn.title = remaining > 0
                ? 'Estimated time remaining: about ' + formatRemainingEstimate(remaining)
                : 'Working longer than the current local average estimate.';
        } else {
            progressPercent = Math.min(32, 14 + (elapsed / 1800));
            btn.title = 'Timing this image generation to build a local average estimate.';
        }

        btn.style.setProperty('--sw-progress-percent', progressPercent.toFixed(1) + '%');
    }

    function startImageProgress(btn, activeText, canAbort) {
        clearImageProgress(activeImageProgress ? activeImageProgress.button : null, '');

        var estimateMs = readImageGenerationEstimate();
        var startedAt = Date.now();

        btn.disabled = !canAbort;
        btn.textContent = activeText;
        btn.classList.add('sw-btn-danger', 'sw-btn-progress');
        btn.classList.toggle('sw-btn-progress-estimating', estimateMs <= 0);

        activeImageProgress = {
            button: btn,
            startedAt: startedAt,
            estimateMs: estimateMs,
            timerId: window.setInterval(function () {
                updateImageProgressVisual(activeImageProgress);
            }, 150)
        };

        updateImageProgressVisual(activeImageProgress);
        return startedAt;
    }

    function bindDeleteImageButton(btn) {
        if (!btn || btn.dataset.bound === '1') return;
        btn.dataset.bound = '1';

        btn.addEventListener('click', function () {
            if (!confirm('Delete this image?')) return;
            var imageUrl = btn.getAttribute('data-image-url');
            btn.disabled = true;
            fetch('api?action=delete_image', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    image_url: imageUrl,
                    _csrf_token: csrfValue
                })
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.ok) {
                    var wrap = btn.closest('.sw-image-wrap');
                    if (wrap) {
                        wrap.remove();
                    }
                    updateImageActionButtons();
                } else {
                    alert(data.error || 'Failed to delete image.');
                    btn.disabled = false;
                }
            })
            .catch(function () {
                alert('Delete request failed.');
                btn.disabled = false;
            });
        });
    }

    function createImageWrap(imageUrl) {
        var wrap = document.createElement('div');
        wrap.className = 'sw-image-wrap';

        var img = document.createElement('img');
        img.src = imageUrl;
        img.alt = 'Story illustration';
        img.className = 'sw-node-image';
        wrap.appendChild(img);

        if (canManageImages) {
            var delBtn = document.createElement('button');
            delBtn.type = 'button';
            delBtn.className = 'sw-image-delete-btn';
            delBtn.setAttribute('data-image-url', imageUrl);
            delBtn.title = 'Delete image';
            delBtn.textContent = '×';
            bindDeleteImageButton(delBtn);
            wrap.appendChild(delBtn);
        }

        return wrap;
    }

    function insertImageActionButton(btn) {
        if (!imageControlsHost) return;
        if (imageActionInsertBefore && imageActionInsertBefore.parentNode === imageControlsHost) {
            imageControlsHost.insertBefore(btn, imageActionInsertBefore);
            return;
        }
        imageControlsHost.appendChild(btn);
    }

    function bindGenerateImageButton(btn) {
        if (!btn || btn.dataset.bound === '1') return;
        btn.dataset.bound = '1';

        btn.addEventListener('click', function () {
            if (genImageController) {
                genImageController.abort();
                return;
            }

            var storyId = btn.getAttribute('data-story-id');
            var nodeId = btn.getAttribute('data-node-id');
            var keyId = getSelectedImageKeyId() || getSelectedTextKeyId();

            genImageController = typeof AbortController === 'function' ? new AbortController() : null;
            var requestStartedAt = startImageProgress(
                btn,
                genImageController ? '✖ Abort Image Generation' : '🖼️ Generating…',
                !!genImageController
            );

            var generateImageRequest = {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    story_id: storyId,
                    node_id: nodeId,
                    key_id: keyId,
                    _csrf_token: csrfValue
                })
            };
            if (genImageController) {
                generateImageRequest.signal = genImageController.signal;
            }

            fetch('api?action=generate_image', generateImageRequest)
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.ok && data.image_url) {
                    storeImageGenerationEstimate(Date.now() - requestStartedAt);
                    var imgContainer = document.getElementById('sw-images');
                    if (imgContainer) {
                        imgContainer.appendChild(createImageWrap(data.image_url));
                    }
                    showFlash('Image generated!', 'success');
                    updateImageActionButtons();
                } else {
                    alert(data.error || 'Image generation failed.');
                }
            })
            .catch(function (err) {
                if (err && err.name === 'AbortError') {
                    showFlash('Image generation cancelled.', 'info');
                    return;
                }
                alert('Image generation request failed.');
            })
            .finally(function () {
                genImageController = null;
                clearImageProgress(btn, '🖼️ Generate Image');
            });
        });
    }

    /* ==================================================================
     * Image Upload (logged-in users with edit access)
     * ================================================================*/

    if (uploadInput) {
        if (imageUploadLabel) {
            imageUploadLabel.addEventListener('click', function () {
                if (imageUploadMenu) {
                    imageUploadMenu.removeAttribute('open');
                }
            });
        }

        uploadInput.addEventListener('change', function () {
            var file = uploadInput.files[0];
            if (!file) return;

            // Client-side validation
            var maxSize = 5 * 1024 * 1024; // 5 MB
            if (file.size > maxSize) {
                showFlash('Image must be under 5 MB.', 'error');
                uploadInput.value = '';
                return;
            }
            var allowed = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];
            if (allowed.indexOf(file.type) === -1) {
                showFlash('Only PNG, JPEG, GIF, and WebP images are allowed.', 'error');
                uploadInput.value = '';
                return;
            }

            var storyId = uploadInput.getAttribute('data-story-id');
            var nodeId = uploadInput.getAttribute('data-node-id');

            var formData = new FormData();
            formData.append('file', file);
            formData.append('story_id', storyId);
            formData.append('node_id', nodeId);
            formData.append('_csrf_token', csrfValue);

            // Disable while uploading
            var label = imageUploadLabel;
            if (label) {
                label.textContent = 'Uploading…';
                label.style.pointerEvents = 'none';
            }

            fetch('api?action=upload_image', {
                method: 'POST',
                headers: { 'Accept': 'application/json' },
                body: formData
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.ok && data.image_url) {
                    var imgContainer = document.getElementById('sw-images');
                    if (imgContainer) {
                        imgContainer.appendChild(createImageWrap(data.image_url));
                    }
                    updateImageActionButtons();
                    showFlash('Image uploaded!', 'success');
                } else {
                    showFlash(data.error || 'Upload failed.', 'error');
                }
            })
            .catch(function () {
                showFlash('Upload request failed.', 'error');
            })
            .finally(function () {
                uploadInput.value = '';
                if (imageUploadMenu) {
                    imageUploadMenu.removeAttribute('open');
                }
                if (label) {
                    label.textContent = 'Upload image';
                    label.style.pointerEvents = '';
                }
            });
        });
    }

    /* ==================================================================
     * Image Regeneration with side-by-side comparison
     * ================================================================*/

    function bindRegenerateImageButton(btn) {
        if (!btn || btn.dataset.bound === '1') return;
        btn.dataset.bound = '1';

        btn.addEventListener('click', function () {
            if (regenImageController) {
                regenImageController.abort();
                return;
            }

            var steerPrompt = promptForOptionalGuidance('this image regeneration');
            if (steerPrompt === null) {
                return;
            }

            var storyId = btn.getAttribute('data-story-id');
            var nodeId = btn.getAttribute('data-node-id');
            var existingUrl = btn.getAttribute('data-existing-image');
            var keyId = getSelectedImageKeyId() || getSelectedTextKeyId();

            regenImageController = typeof AbortController === 'function' ? new AbortController() : null;
            var requestStartedAt = startImageProgress(
                btn,
                regenImageController ? '✖ Abort Image Regeneration' : '🖼️ Generating new image…',
                !!regenImageController
            );

            var regenerateImageRequest = {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    story_id: storyId,
                    node_id: nodeId,
                    key_id: keyId,
                    steer_prompt: steerPrompt,
                    _csrf_token: csrfValue
                })
            };
            if (regenImageController) {
                regenerateImageRequest.signal = regenImageController.signal;
            }

            fetch('api?action=generate_image', regenerateImageRequest)
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.ok && data.image_url) {
                    storeImageGenerationEstimate(Date.now() - requestStartedAt);
                    showImageCompareModal(existingUrl, data.image_url);
                } else {
                    alert(data.error || 'Image generation failed.');
                }
            })
            .catch(function (err) {
                if (err && err.name === 'AbortError') {
                    showFlash('Image regeneration cancelled.', 'info');
                    return;
                }
                alert('Image generation request failed.');
            })
            .finally(function () {
                regenImageController = null;
                clearImageProgress(btn, '🖼️ Regenerate Image');
            });
        });
    }

    function updateImageActionButtons() {
        if (!canGenerateImages || !imageControlsHost) {
            return;
        }

        var currentImageUrl = getCurrentNodeImageUrl();
        var currentGenBtn = document.getElementById('sw-gen-image-btn');
        var currentRegenBtn = document.getElementById('sw-regen-image-btn');

        if (currentImageUrl !== '') {
            if (currentGenBtn) {
                currentGenBtn.remove();
                currentGenBtn = null;
            }

            if (!currentRegenBtn) {
                currentRegenBtn = document.createElement('button');
                currentRegenBtn.type = 'button';
                currentRegenBtn.id = 'sw-regen-image-btn';
                currentRegenBtn.className = 'sw-btn sw-btn-sm sw-btn-secondary';
                currentRegenBtn.textContent = '🖼️ Regenerate Image';
                insertImageActionButton(currentRegenBtn);
            }

            currentRegenBtn.setAttribute('data-story-id', imageActionStoryId);
            currentRegenBtn.setAttribute('data-node-id', imageActionNodeId);
            currentRegenBtn.setAttribute('data-existing-image', currentImageUrl);
            bindRegenerateImageButton(currentRegenBtn);
            return;
        }

        if (currentRegenBtn) {
            currentRegenBtn.remove();
            currentRegenBtn = null;
        }

        if (!currentGenBtn) {
            currentGenBtn = document.createElement('button');
            currentGenBtn.type = 'button';
            currentGenBtn.id = 'sw-gen-image-btn';
            currentGenBtn.className = 'sw-btn sw-btn-sm sw-btn-secondary';
            currentGenBtn.textContent = '🖼️ Generate Image';
            insertImageActionButton(currentGenBtn);
        }

        currentGenBtn.setAttribute('data-story-id', imageActionStoryId);
        currentGenBtn.setAttribute('data-node-id', imageActionNodeId);
        bindGenerateImageButton(currentGenBtn);
    }

    document.querySelectorAll('.sw-image-delete-btn').forEach(bindDeleteImageButton);
    updateImageActionButtons();

    function showImageCompareModal(oldUrl, newUrl) {
        // Create modal overlay
        var overlay = document.createElement('div');
        overlay.className = 'sw-modal-backdrop sw-modal-open';
        overlay.innerHTML =
            '<div class="sw-modal sw-image-compare-modal">' +
                '<h2>Choose an image</h2>' +
                '<p class="sw-text-muted">Click the image you want to keep. The other will be deleted.</p>' +
                '<div class="sw-image-compare">' +
                    '<div class="sw-image-option" data-url="' + oldUrl + '" data-delete="' + newUrl + '">' +
                        '<img src="' + oldUrl + '" alt="Current image">' +
                        '<span class="sw-image-label">Current</span>' +
                    '</div>' +
                    '<div class="sw-image-option" data-url="' + newUrl + '" data-delete="' + oldUrl + '">' +
                        '<img src="' + newUrl + '" alt="New image">' +
                        '<span class="sw-image-label">New</span>' +
                    '</div>' +
                '</div>' +
                '<div class="sw-modal-actions">' +
                    '<button type="button" class="sw-btn sw-btn-secondary sw-image-compare-cancel">Cancel (keep both)</button>' +
                '</div>' +
            '</div>';

        document.body.appendChild(overlay);

        // Click handler for image options
        overlay.querySelectorAll('.sw-image-option').forEach(function (opt) {
            opt.addEventListener('click', function () {
                var deleteUrl = opt.getAttribute('data-delete');
                // Delete the rejected image
                fetch('api?action=delete_image', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        image_url: deleteUrl,
                        _csrf_token: csrfValue
                    })
                }).then(function () {
                    overlay.remove();
                    location.reload();
                }).catch(function () {
                    overlay.remove();
                    location.reload();
                });
            });
        });

        // Cancel button
        overlay.querySelector('.sw-image-compare-cancel').addEventListener('click', function () {
            overlay.remove();
            location.reload();
        });
    }

    /* ==================================================================
     * New Story Modal
     * ================================================================*/

    var newStoryBtn = document.getElementById('sw-new-story-btn');
    var newStoryModal = document.getElementById('sw-new-story-modal');

    if (newStoryBtn && newStoryModal) {
        newStoryBtn.addEventListener('click', function () {
            newStoryModal.classList.add('sw-modal-open');
            var titleInput = newStoryModal.querySelector('[name="title"]');
            if (titleInput) titleInput.focus();
        });

        // Close modal on backdrop click
        newStoryModal.addEventListener('click', function (e) {
            if (e.target === newStoryModal) {
                newStoryModal.classList.remove('sw-modal-open');
            }
        });

        // Close on Escape
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && newStoryModal.classList.contains('sw-modal-open')) {
                newStoryModal.classList.remove('sw-modal-open');
            }
        });

        var cancelBtn = newStoryModal.querySelector('.sw-modal-cancel');
        if (cancelBtn) {
            cancelBtn.addEventListener('click', function () {
                newStoryModal.classList.remove('sw-modal-open');
            });
        }

        // "Start Manually" button — submit form with use_ai=0
        var startManualBtn = document.getElementById('sw-start-manual');
        if (startManualBtn) {
            startManualBtn.addEventListener('click', function () {
                var useAiInput = document.getElementById('sw-use-ai');
                if (useAiInput) useAiInput.value = '0';
                var form = newStoryModal.querySelector('form');
                if (form) form.submit();
            });
        }

        // Intercept "Generate with AI" form submit for streaming
        var newStoryForm = newStoryModal.querySelector('form');
        if (newStoryForm) {
            newStoryForm.addEventListener('submit', function (e) {
                var useAiInput = document.getElementById('sw-use-ai');
                if (useAiInput && useAiInput.value === '0') return; // manual mode — let form submit

                var titleInput = newStoryForm.querySelector('[name="title"]');
                var scenarioInput = newStoryForm.querySelector('[name="scenario_essentials"]');
                var title = titleInput ? titleInput.value.trim() : '';
                if (title === '') return; // let browser validation handle

                e.preventDefault();
                newStoryModal.classList.remove('sw-modal-open');

                startStreamingGeneration({
                    title: title,
                    parent_node_id: '',
                    choice_text: '',
                    scenario_essentials: scenarioInput ? scenarioInput.value.trim() : ''
                }, {
                    onFallback: function () {
                        newStoryForm.submit();
                    }
                });
            });
        }

    }

    function setupApiProviderDefaults(providerSelect, baseUrlInput) {
        if (!providerSelect || !baseUrlInput) return;

        var defaultUrls = {
            openai: 'https://api.openai.com/v1',
            anthropic: 'https://api.anthropic.com',
            gemini: 'https://generativelanguage.googleapis.com/v1beta',
            ollama: 'http://localhost:11434/v1',
            custom: ''
        };

        providerSelect.addEventListener('change', function () {
            var url = defaultUrls[this.value] || '';
            if (baseUrlInput.value === '' || Object.values(defaultUrls).indexOf(baseUrlInput.value) !== -1) {
                baseUrlInput.value = url;
            }
        });

        baseUrlInput.addEventListener('blur', function () {
            if (providerSelect.value === 'ollama') {
                var v = this.value.replace(/\/+$/, '');
                if (v !== '' && !v.endsWith('/v1')) {
                    this.value = v + '/v1';
                }
            }
        });
    }

    setupApiProviderDefaults(document.getElementById('key-provider'), document.getElementById('key-base-url'));

    /* ==================================================================
     * Settings — API Key Management
     * ================================================================*/

    var addKeyForm = document.getElementById('sw-add-key-form');
    if (addKeyForm) {
        var keyFormStatus = document.getElementById('sw-key-form-status');
        var headingEl = document.getElementById('sw-key-form-heading');
        var keyIdInput = document.getElementById('key-id');
        var keyLabelInput = document.getElementById('key-label');
        var providerInput = document.getElementById('key-provider');
        var baseUrlInput = document.getElementById('key-base-url');
        var apiKeyInput = document.getElementById('key-api-key');
        var apiKeyNote = document.getElementById('sw-key-api-key-note');
        var textModelInput = document.getElementById('key-model-text');
        var imageModelInput = document.getElementById('key-model-image');
        var textModelSelect = document.getElementById('key-model-text-select');
        var imageModelSelect = document.getElementById('key-model-image-select');
        var scopeInput = document.getElementById('key-scope');
        var fallbackInput = document.getElementById('key-fallback');
        var submitBtn = document.getElementById('sw-key-submit-btn');
        var cancelEditBtn = document.getElementById('sw-key-cancel-edit');
        var fetchModelsBtn = document.getElementById('sw-fetch-models-btn');

        function setKeyFormStatus(message, isError) {
            if (!keyFormStatus) return;
            keyFormStatus.textContent = message || '';
            keyFormStatus.className = 'sw-editor-status' + (isError ? ' sw-editor-status-error' : '');
        }

        function resetModelSelect(selectEl, placeholder) {
            if (!selectEl) return;
            selectEl.innerHTML = '';
            var option = document.createElement('option');
            option.value = '';
            option.textContent = placeholder;
            selectEl.appendChild(option);
            selectEl.disabled = true;
        }

        function clearModelSelects() {
            resetModelSelect(textModelSelect, 'Choose from fetched models…');
            resetModelSelect(imageModelSelect, 'Choose from fetched models…');
        }

        function populateModelSelect(selectEl, models, currentValue) {
            if (!selectEl) return;
            resetModelSelect(selectEl, 'Choose from fetched models…');
            models.forEach(function (model) {
                var option = document.createElement('option');
                option.value = model;
                option.textContent = model;
                if (model === currentValue) {
                    option.selected = true;
                }
                selectEl.appendChild(option);
            });
            selectEl.disabled = models.length === 0;
        }

        function applyFetchedModels(models) {
            populateModelSelect(textModelSelect, models, textModelInput.value.trim());
            populateModelSelect(imageModelSelect, models, imageModelInput.value.trim());
            setKeyFormStatus(models.length > 0 ? 'Models loaded.' : 'No models were returned.', false);
        }

        function enterCreateMode() {
            addKeyForm.dataset.mode = 'create';
            addKeyForm.reset();
            keyIdInput.value = '';
            apiKeyInput.disabled = false;
            apiKeyInput.value = '';
            apiKeyInput.placeholder = 'sk-...';
            if (apiKeyNote) {
                apiKeyNote.textContent = 'Not needed for local Ollama.';
            }
            if (headingEl) headingEl.textContent = 'Add New Key';
            if (submitBtn) submitBtn.textContent = 'Add Key';
            if (cancelEditBtn) cancelEditBtn.hidden = true;
            clearModelSelects();
            setKeyFormStatus('', false);
            providerInput.dispatchEvent(new Event('change'));
        }

        function enterEditMode(keyData) {
            addKeyForm.dataset.mode = 'edit';
            keyIdInput.value = keyData.id || '';
            keyLabelInput.value = keyData.label || '';
            providerInput.value = keyData.provider || 'openai';
            baseUrlInput.value = keyData.base_url || '';
            apiKeyInput.value = '';
            apiKeyInput.disabled = true;
            apiKeyInput.placeholder = 'Stored securely';
            textModelInput.value = keyData.model_text || '';
            imageModelInput.value = keyData.model_image || '';
            scopeInput.value = keyData.scope || 'self';
            if (fallbackInput) {
                fallbackInput.value = keyData.fallback_key_id || '';
            }
            if (apiKeyNote) {
                apiKeyNote.textContent = 'Stored securely. The existing secret is kept as-is.';
            }
            if (headingEl) headingEl.textContent = 'Edit API Key';
            if (submitBtn) submitBtn.textContent = 'Save Changes';
            if (cancelEditBtn) cancelEditBtn.hidden = false;
            clearModelSelects();
            setKeyFormStatus('Editing "' + (keyData.label || 'API key') + '".', false);

            var scrollTarget = headingEl || addKeyForm;
            if (scrollTarget && typeof scrollTarget.scrollIntoView === 'function') {
                scrollTarget.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
            if (keyLabelInput && typeof keyLabelInput.focus === 'function') {
                try {
                    keyLabelInput.focus({ preventScroll: true });
                } catch (err) {
                    keyLabelInput.focus();
                }
            }
        }

        function buildKeyPayload() {
            var payload = {
                key_id: keyIdInput.value,
                label: keyLabelInput.value.trim(),
                provider: providerInput.value,
                base_url: baseUrlInput.value.trim(),
                model_text: textModelInput.value.trim(),
                model_image: imageModelInput.value.trim(),
                scope: scopeInput.value,
                fallback_key_id: fallbackInput ? (fallbackInput.value || '') : '',
                _csrf_token: csrfValue
            };

            if (addKeyForm.dataset.mode !== 'edit') {
                payload.api_key = apiKeyInput.value;
            }

            return payload;
        }

        clearModelSelects();
        enterCreateMode();

        [textModelSelect, imageModelSelect].forEach(function (selectEl) {
            if (!selectEl) return;
            selectEl.addEventListener('change', function () {
                var target = selectEl === textModelSelect ? textModelInput : imageModelInput;
                if (selectEl.value) {
                    target.value = selectEl.value;
                }
            });
        });

        [providerInput, baseUrlInput, apiKeyInput].forEach(function (el) {
            if (!el) return;
            el.addEventListener('change', clearModelSelects);
            el.addEventListener('input', clearModelSelects);
        });

        if (providerInput && apiKeyNote) {
            providerInput.addEventListener('change', function () {
                if (addKeyForm.dataset.mode === 'edit') {
                    apiKeyNote.textContent = 'Stored securely. The existing secret is kept as-is.';
                    return;
                }
                apiKeyInput.placeholder = providerInput.value === 'gemini'
                    ? 'AIza...'
                    : 'sk-...';
                apiKeyNote.textContent = providerInput.value === 'ollama'
                    ? 'Not needed for local Ollama.'
                    : (providerInput.value === 'gemini'
                        ? 'Stored securely after you save it. Free AI Studio keys can be quota-, region-, or model-limited, so use Fetch Models to pick an available Gemini text model.'
                        : 'Stored securely after you save it.');
            });
        }

        if (cancelEditBtn) {
            cancelEditBtn.addEventListener('click', function () {
                enterCreateMode();
            });
        }

        if (fetchModelsBtn) {
            fetchModelsBtn.addEventListener('click', function () {
                fetchModelsBtn.disabled = true;
                setKeyFormStatus('Loading models…', false);

                var payload;
                if (addKeyForm.dataset.mode === 'edit' && keyIdInput.value) {
                    payload = { key_id: keyIdInput.value, _csrf_token: csrfValue };
                } else {
                    payload = {
                        provider: providerInput.value,
                        base_url: baseUrlInput.value.trim(),
                        api_key: apiKeyInput.value,
                        _csrf_token: csrfValue
                    };
                }

                fetch(apiBase + '/api?action=list_api_models', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (!data.ok) {
                        setKeyFormStatus('Error: ' + (data.error || 'Model listing failed'), true);
                        return;
                    }
                    applyFetchedModels(data.models || []);
                })
                .catch(function (err) {
                    setKeyFormStatus('Error: ' + err.message, true);
                })
                .finally(function () {
                    fetchModelsBtn.disabled = false;
                });
            });
        }

        addKeyForm.addEventListener('submit', function (e) {
            e.preventDefault();
            setKeyFormStatus(addKeyForm.dataset.mode === 'edit' ? 'Saving changes…' : 'Saving…', false);

            var action = addKeyForm.dataset.mode === 'edit' ? 'update_api_key' : 'save_api_key';
            fetch(apiBase + '/api?action=' + action, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(buildKeyPayload())
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.ok) {
                    setKeyFormStatus(addKeyForm.dataset.mode === 'edit' ? 'Key updated ✓' : 'Key saved ✓', false);
                    setTimeout(function () { window.location.reload(); }, 400);
                } else {
                    setKeyFormStatus('Error: ' + (data.error || 'Save failed'), true);
                }
            })
            .catch(function (err) {
                setKeyFormStatus('Error: ' + err.message, true);
            });
        });

        var defaultPublicKeySelect = document.getElementById('sw-default-public-key');
        var defaultPublicKeyBtn = document.getElementById('sw-save-default-public-key-btn');
        var defaultPublicKeyStatus = document.getElementById('sw-default-public-key-status');

        function setDefaultPublicKeyStatus(message, isError) {
            if (!defaultPublicKeyStatus) return;
            defaultPublicKeyStatus.textContent = message || '';
            defaultPublicKeyStatus.className = 'sw-editor-status' + (isError ? ' sw-editor-status-error' : '');
        }

        if (defaultPublicKeyBtn && defaultPublicKeySelect) {
            defaultPublicKeyBtn.addEventListener('click', function () {
                defaultPublicKeyBtn.disabled = true;
                setDefaultPublicKeyStatus('Saving…', false);

                fetch(apiBase + '/api?action=set_default_public_api_key', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        key_id: defaultPublicKeySelect.value || '',
                        _csrf_token: csrfValue
                    })
                })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (data.ok) {
                        setDefaultPublicKeyStatus('Saved ✓', false);
                        setTimeout(function () { window.location.reload(); }, 400);
                    } else {
                        setDefaultPublicKeyStatus('Error: ' + (data.error || 'Save failed'), true);
                    }
                })
                .catch(function (err) {
                    setDefaultPublicKeyStatus('Error: ' + err.message, true);
                })
                .finally(function () {
                    defaultPublicKeyBtn.disabled = false;
                });
            });
        }

        // Edit, Test, Deactivate, Reactivate, Delete buttons (delegated)
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-key-id]');
            if (!btn) return;

            var keyId = btn.dataset.keyId;
            var action = null;

            if (btn.classList.contains('sw-key-edit')) {
                var keyItem = btn.closest('.sw-key-item');
                if (!keyItem || !keyItem.dataset.key) return;
                try {
                    enterEditMode(JSON.parse(keyItem.dataset.key));
                } catch (err) {
                    setKeyFormStatus('Error: Could not load key details for editing.', true);
                }
                return;
            }
            if (btn.classList.contains('sw-key-test')) action = 'test_api_key';
            else if (btn.classList.contains('sw-key-deactivate')) action = 'deactivate_api_key';
            else if (btn.classList.contains('sw-key-reactivate')) action = 'reactivate_api_key';
            else if (btn.classList.contains('sw-key-delete')) action = 'delete_api_key';
            else return;

            if (action === 'delete_api_key' && !confirm('Delete this API key?')) return;

            btn.disabled = true;
            btn.textContent = '…';

            fetch(apiBase + '/api?action=' + action, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ key_id: keyId, _csrf_token: csrfValue })
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (action === 'test_api_key') {
                    alert(data.ok
                        ? 'Connection successful!\n\n' + (data.preview || '')
                        : 'Test failed: ' + (data.message || data.error || 'Unknown error'));
                    btn.disabled = false;
                    btn.textContent = '🧪 Test';
                } else {
                    window.location.reload();
                }
            })
            .catch(function (err) {
                alert('Error: ' + err.message);
                btn.disabled = false;
            });
        });
    }

    /* ==================================================================
     * Flag for Concern (any user)
     * ================================================================*/

    var flagConcernBtn = document.getElementById('sw-flag-concern-btn');
    if (flagConcernBtn) {
        flagConcernBtn.addEventListener('click', function (e) {
            e.preventDefault();
            var reason = prompt('Why are you flagging this page? (optional)');
            if (reason === null) return; // cancelled

            var storyId = flagConcernBtn.getAttribute('data-story-id');
            var nodeId = flagConcernBtn.getAttribute('data-node-id');

            fetch('api?action=flag_concern', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    story_id: storyId,
                    node_id: nodeId,
                    reason: reason || '',
                    _csrf_token: csrfValue
                })
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.ok) {
                    flagConcernBtn.textContent = '✓ Flagged';
                    flagConcernBtn.style.pointerEvents = 'none';
                } else {
                    alert(data.error || 'Failed to flag page.');
                }
            })
            .catch(function () {
                alert('Failed to submit flag.');
            });
        });
    }

    /* ==================================================================
     * Flag for Review / Quarantine (editor+)
     * ================================================================*/

    var flagReviewBtn = document.getElementById('sw-flag-review-btn');
    if (flagReviewBtn) {
        flagReviewBtn.addEventListener('click', function (e) {
            e.preventDefault();
            if (!confirm('Move this page and all its child pages to quarantine?')) return;

            var storyId = flagReviewBtn.getAttribute('data-story-id');
            var nodeId = flagReviewBtn.getAttribute('data-node-id');

            fetch('api?action=flag_review', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    story_id: storyId,
                    node_id: nodeId,
                    _csrf_token: csrfValue
                })
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.ok) {
                    alert('Page moved to quarantine.');
                    window.location.reload();
                } else {
                    alert(data.error || 'Failed to quarantine page.');
                }
            })
            .catch(function () {
                alert('Failed to quarantine page.');
            });
        });
    }

    /* ==================================================================
     * Admin Dashboard Actions
     * ================================================================*/

    document.querySelectorAll('.sw-admin-action').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var action = btn.getAttribute('data-action');
            var payload = { _csrf_token: csrfValue };

            // Build payload based on action
            if (action === 'dismiss_concern') {
                payload.id = btn.getAttribute('data-id');
            } else if (action === 'flag_review') {
                if (!confirm('Move this page to quarantine?')) return;
                payload.story_id = btn.getAttribute('data-story-id');
                payload.node_id = btn.getAttribute('data-node-id');
                payload.concern_id = btn.getAttribute('data-concern-id') || '';
            } else if (action === 'approve_node') {
                if (!confirm('Restore this page from quarantine?')) return;
                payload.story_id = btn.getAttribute('data-story-id');
                payload.node_id = btn.getAttribute('data-node-id');
            } else if (action === 'delete_node') {
                if (!confirm('Permanently delete this page? This cannot be undone.')) return;
                payload.story_id = btn.getAttribute('data-story-id');
                payload.node_id = btn.getAttribute('data-node-id');
            } else if (action === 'deactivate_api_key' || action === 'reactivate_api_key' || action === 'delete_api_key') {
                if (action === 'delete_api_key' && !confirm('Delete this API key?')) return;
                payload.key_id = btn.getAttribute('data-id');
            } else if (action === 'delete_user') {
                if (!confirm('Delete this user? This cannot be undone.')) return;
                payload.id = btn.getAttribute('data-id');
            } else if (action === 'apply_theme') {
                if (!confirm('Apply this theme site-wide?')) return;
                payload.theme = btn.getAttribute('data-theme');
            }

            btn.disabled = true;

            fetch('api?action=' + action, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.ok) {
                    if (action === 'delete_node' && data.redirect) {
                        window.location.href = data.redirect;
                    } else {
                        window.location.reload();
                    }
                } else {
                    alert(data.error || 'Action failed.');
                    btn.disabled = false;
                }
            })
            .catch(function () {
                alert('Request failed.');
                btn.disabled = false;
            });
        });
    });

    // Admin role change dropdowns
    document.querySelectorAll('.sw-admin-role-select').forEach(function (select) {
        select.addEventListener('change', function () {
            var userId = select.getAttribute('data-user-id');
            var newRole = select.value;

            fetch('api?action=change_role', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    user_id: userId,
                    role: newRole,
                    _csrf_token: csrfValue
                })
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.ok) {
                    select.setAttribute('data-current-role', newRole);
                } else {
                    alert(data.error || 'Failed to change role.');
                    select.value = select.getAttribute('data-current-role');
                }
            })
            .catch(function () {
                alert('Failed to change role.');
                select.value = select.getAttribute('data-current-role');
            });
        });
    });

    /* ==================================================================
     * In-Place Editor (only active on edit.php)
     * ================================================================*/

    function paragraphEditorIsBlockTag(tag) {
        return ['blockquote', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'hr', 'li', 'ol', 'pre', 'style', 'ul']
            .indexOf(String(tag || '').toLowerCase()) !== -1;
    }

    function paragraphEditorCloneForSource(node) {
        if (!node || node.nodeType !== 1) {
            return node;
        }

        var clone = node.cloneNode(true);
        if (clone.removeAttribute) {
            clone.removeAttribute('contenteditable');
        }
        return clone;
    }

    function paragraphEditorEscapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = String(text || '');
        return div.innerHTML;
    }

    function paragraphEditorTextToParagraphHtml(text) {
        return paragraphEditorEscapeHtml(String(text || '').replace(/\r\n?/g, '\n')).replace(/\n/g, '<br>');
    }

    function paragraphEditorNormalizePastedText(text) {
        text = String(text || '').replace(/\r\n?/g, '\n');
        text = text.replace(/<style\b[^>]*>[\s\S]*?<\/style>/gi, '');
        text = text.replace(/<br\s*\/?>/gi, '\n');
        text = text.replace(/<\/div\s*>/gi, '\n\n');
        text = text.replace(/<div\b[^>]*>/gi, '');
        text = text.replace(/<\/span\s*>/gi, '');
        text = text.replace(/<span\b[^>]*>/gi, '');
        return text;
    }

    function paragraphEditorSplitSourceBlocks(source) {
        source = String(source || '').replace(/\r\n?/g, '\n').trim();
        if (source === '') {
            return [];
        }

        return source.split(/\n\s*\n+/).map(function (block) {
            return block.trim();
        }).filter(function (block) {
            return block !== '';
        });
    }

    function paragraphEditorCollectBlocks(container) {
        var blocks = [];
        if (!container) return blocks;

        Array.from(container.childNodes).forEach(function (node) {
            if (node.nodeType === 3) {
                var text = String(node.textContent || '').trim();
                if (text !== '') {
                    var textWrap = document.createElement('div');
                    textWrap.textContent = text;
                    blocks.push(textWrap.innerHTML);
                }
                return;
            }

            if (node.nodeType !== 1) return;

            var tag = node.nodeName.toLowerCase();
            if (tag === 'p' && node.classList.contains('sw-para')) {
                var inner = String(node.innerHTML || '').trim();
                blocks.push(inner === '<br>' ? '' : inner);
                return;
            }

            if (paragraphEditorIsBlockTag(tag)) {
                var clone = paragraphEditorCloneForSource(node);
                blocks.push((clone.outerHTML || '').trim());
                return;
            }

            var inlineClone = paragraphEditorCloneForSource(node);
            blocks.push((inlineClone.outerHTML || '').trim());
        });

        return blocks;
    }

    function paragraphEditorParagraphsToSource(container) {
        return paragraphEditorCollectBlocks(container).join('\n\n').trim();
    }

    function paragraphEditorParseSource(source) {
        var blocks = [];
        paragraphEditorSplitSourceBlocks(source).forEach(function (segment) {
            var wrapper = document.createElement('div');
            var normalizedSegment = String(segment || '').replace(/\r\n?/g, '\n').trim();
            if (normalizedSegment === '') {
                return;
            }

            wrapper.innerHTML = normalizedSegment;
            var elementChildren = Array.from(wrapper.childNodes).filter(function (node) {
                return node.nodeType === 1;
            });

            if (elementChildren.length === 1 && wrapper.childNodes.length === 1) {
                var element = elementChildren[0];
                var tag = element.nodeName.toLowerCase();
                if (tag === 'p') {
                    blocks.push(String(element.innerHTML || '').trim());
                    return;
                }

                if (paragraphEditorIsBlockTag(tag)) {
                    blocks.push((paragraphEditorCloneForSource(element).outerHTML || '').trim());
                    return;
                }
            }

            if (elementChildren.length === 0) {
                blocks.push(paragraphEditorTextToParagraphHtml(wrapper.textContent || normalizedSegment));
                return;
            }

            blocks.push(normalizedSegment.replace(/\n/g, '<br>'));
        });

        return blocks;
    }

    function paragraphEditorCreateParagraph(html) {
        var paragraph = document.createElement('p');
        paragraph.className = 'sw-para';
        paragraph.setAttribute('contenteditable', 'true');
        paragraph.innerHTML = String(html || '');
        return paragraph;
    }

    function paragraphEditorRenderBlocks(container, blocks) {
        if (!container) return;

        container.innerHTML = '';
        if (!Array.isArray(blocks) || blocks.length === 0) {
            var emptyPara = document.createElement('p');
            emptyPara.className = 'sw-para';
            emptyPara.setAttribute('contenteditable', 'true');
            container.appendChild(emptyPara);
            return;
        }

        blocks.forEach(function (block) {
            block = String(block || '').trim();
            if (block === '') {
                return;
            }

            var blockWrapper = document.createElement('div');
            blockWrapper.innerHTML = block;

            var elementChildren = Array.from(blockWrapper.childNodes).filter(function (node) {
                return node.nodeType === 1;
            });

            if (elementChildren.length === 1 && blockWrapper.childNodes.length === 1) {
                var element = elementChildren[0];
                var tag = element.nodeName.toLowerCase();
                if (tag === 'p') {
                    var para = document.createElement('p');
                    para.className = 'sw-para';
                    para.setAttribute('contenteditable', 'true');
                    para.innerHTML = String(element.innerHTML || '');
                    container.appendChild(para);
                    return;
                }

                if (paragraphEditorIsBlockTag(tag)) {
                    if (tag !== 'style' && tag !== 'hr') {
                        element.setAttribute('contenteditable', 'true');
                    }
                    container.appendChild(element);
                    return;
                }
            }

            var paragraph = document.createElement('p');
            paragraph.className = 'sw-para';
            paragraph.setAttribute('contenteditable', 'true');
            paragraph.innerHTML = block;
            container.appendChild(paragraph);
        });

        if (!container.children.length) {
            container.appendChild(paragraphEditorCreateParagraph(''));
        }
    }

    function paragraphEditorIsEmpty(paragraph) {
        if (!paragraph) return true;

        var html = String(paragraph.innerHTML || '')
            .replace(/<br\s*\/?>/gi, '')
            .replace(/&nbsp;/gi, '')
            .replace(/\u00a0/g, '')
            .trim();
        var text = String(paragraph.textContent || '')
            .replace(/\u00a0/g, '')
            .trim();

        return html === '' && text === '';
    }

    function paragraphEditorSelectionAtStart(paragraph) {
        if (!paragraph || !window.getSelection || !document.createRange) {
            return false;
        }

        var selection = window.getSelection();
        if (!selection || selection.rangeCount === 0) {
            return false;
        }

        var range = selection.getRangeAt(0);
        if (!range.collapsed || !paragraph.contains(range.startContainer)) {
            return false;
        }

        var prefix = range.cloneRange();
        prefix.selectNodeContents(paragraph);
        prefix.setEnd(range.startContainer, range.startOffset);
        return prefix.toString() === '';
    }

    function paragraphEditorFocusEnd(paragraph) {
        if (!paragraph) return;
        paragraph.focus();
        if (!window.getSelection || !document.createRange) {
            return;
        }

        var selection = window.getSelection();
        var range = document.createRange();
        range.selectNodeContents(paragraph);
        range.collapse(false);
        selection.removeAllRanges();
        selection.addRange(range);
    }

    function paragraphEditorHandlePaste(event, container, isSourceMode, onChange) {
        if (!container || isSourceMode || event.defaultPrevented) {
            return;
        }

        var editable = event.target && event.target.closest ? event.target.closest('[contenteditable="true"]') : null;
        if (!editable || !container.contains(editable)) {
            return;
        }

        var clipboard = event.clipboardData || window.clipboardData;
        if (!clipboard) {
            return;
        }

        var text = clipboard.getData('text/plain');
        if (text === '') {
            var html = clipboard.getData('text/html');
            if (html !== '') {
                var textWrapper = document.createElement('div');
                textWrapper.innerHTML = html;
                text = textWrapper.textContent || textWrapper.innerText || '';
            }
        }

        text = paragraphEditorNormalizePastedText(text);

        if (text === '') {
            return;
        }

        event.preventDefault();

        if (!editable.classList.contains('sw-para')) {
            document.execCommand('insertText', false, text);
            if (typeof onChange === 'function') {
                onChange();
            }
            return;
        }

        var blocks = paragraphEditorSplitSourceBlocks(text).map(function (block) {
            return paragraphEditorTextToParagraphHtml(block);
        });
        if (blocks.length === 0) {
            blocks = [paragraphEditorTextToParagraphHtml(text)];
        }

        var selection = window.getSelection ? window.getSelection() : null;
        if (!selection || selection.rangeCount === 0) {
            editable.innerHTML += blocks.join('<br><br>');
            paragraphEditorFocusEnd(editable);
            if (typeof onChange === 'function') {
                onChange();
            }
            return;
        }

        var range = selection.getRangeAt(0);
        if (!editable.contains(range.startContainer) || !editable.contains(range.endContainer)) {
            document.execCommand('insertText', false, text);
            if (typeof onChange === 'function') {
                onChange();
            }
            return;
        }

        var beforeRange = document.createRange();
        beforeRange.selectNodeContents(editable);
        beforeRange.setEnd(range.startContainer, range.startOffset);

        var afterRange = document.createRange();
        afterRange.selectNodeContents(editable);
        afterRange.setStart(range.endContainer, range.endOffset);

        var beforeWrapper = document.createElement('div');
        beforeWrapper.appendChild(beforeRange.cloneContents());
        var afterWrapper = document.createElement('div');
        afterWrapper.appendChild(afterRange.cloneContents());

        editable.innerHTML = beforeWrapper.innerHTML + blocks[0];
        var lastParagraph = editable;
        for (var i = 1; i < blocks.length; i += 1) {
            var paragraph = paragraphEditorCreateParagraph(blocks[i]);
            lastParagraph.insertAdjacentElement('afterend', paragraph);
            lastParagraph = paragraph;
        }
        lastParagraph.innerHTML += afterWrapper.innerHTML;
        paragraphEditorFocusEnd(lastParagraph);
        if (typeof onChange === 'function') {
            onChange();
        }
    }

    function paragraphEditorHandleBackspace(event, container, isSourceMode, onChange) {
        if (!container || isSourceMode || event.key !== 'Backspace' || event.defaultPrevented) {
            return;
        }

        var paragraph = event.target && event.target.closest ? event.target.closest('.sw-para') : null;
        if (!paragraph || !container.contains(paragraph)) {
            return;
        }

        var paragraphs = Array.from(container.querySelectorAll('.sw-para'));
        if (paragraphs.length <= 1) {
            return;
        }

        if (paragraphEditorIsEmpty(paragraph)) {
            event.preventDefault();
            var currentIndex = paragraphs.indexOf(paragraph);
            var focusTarget = paragraphs[currentIndex - 1] || paragraphs[currentIndex + 1] || null;
            paragraph.remove();
            paragraphEditorFocusEnd(focusTarget);
            if (typeof onChange === 'function') {
                onChange();
            }
            return;
        }

        if (!paragraphEditorSelectionAtStart(paragraph)) {
            return;
        }

        var previous = paragraph.previousElementSibling;
        if (!previous || !previous.classList || !previous.classList.contains('sw-para') || !paragraphEditorIsEmpty(previous)) {
            return;
        }

        event.preventDefault();
        previous.remove();
        if (typeof onChange === 'function') {
            onChange();
        }
    }

    var editorContainer = document.getElementById('sw-editor');

    if (editorContainer) {

    var toolbar = document.getElementById('sw-editor-toolbar');
    var saveBtn = document.getElementById('sw-editor-save');
    var cancelBtn2 = document.getElementById('sw-editor-cancel');
    var addParaBtn = document.getElementById('sw-editor-add-para');
    var sourceToggle = document.getElementById('sw-editor-source-toggle');
    var editorContent = document.getElementById('sw-editor-content');
    var sourceArea = document.getElementById('sw-editor-source');
    var statusEl = document.getElementById('sw-editor-status');

    var isSourceMode = false;
    var hasUnsaved = false;

    // Track changes
    if (editorContent) {
        editorContent.addEventListener('input', function () {
            hasUnsaved = true;
            updateStatus('Unsaved changes');
        });
        editorContent.addEventListener('paste', function (e) {
            paragraphEditorHandlePaste(e, editorContent, isSourceMode, function () {
                hasUnsaved = true;
                updateStatus('Unsaved changes');
            });
        });
        editorContent.addEventListener('keydown', function (e) {
            paragraphEditorHandleBackspace(e, editorContent, isSourceMode, function () {
                hasUnsaved = true;
                updateStatus('Unsaved changes');
            });
        });
    }

    // Warn on navigate away with unsaved changes
    window.addEventListener('beforeunload', function (e) {
        if (hasUnsaved) {
            e.preventDefault();
            e.returnValue = '';
        }
    });

    // Toolbar: Bold
    var boldBtn = document.getElementById('sw-editor-bold');
    if (boldBtn) {
        boldBtn.addEventListener('click', function () {
            document.execCommand('bold', false, null);
            editorContent.focus();
            hasUnsaved = true;
        });
    }

    // Toolbar: Italic
    var italicBtn = document.getElementById('sw-editor-italic');
    if (italicBtn) {
        italicBtn.addEventListener('click', function () {
            document.execCommand('italic', false, null);
            editorContent.focus();
            hasUnsaved = true;
        });
    }

    // Toolbar: Add Paragraph
    if (addParaBtn) {
        addParaBtn.addEventListener('click', function () {
            if (isSourceMode) return;
            var newPara = document.createElement('p');
            newPara.className = 'sw-para';
            newPara.setAttribute('contenteditable', 'true');
            newPara.textContent = '';
            editorContent.appendChild(newPara);
            newPara.focus();
            hasUnsaved = true;
            updateStatus('Unsaved changes');
        });
    }

    // Toolbar: Source Mode Toggle
    if (sourceToggle && sourceArea && editorContent) {
        sourceToggle.addEventListener('click', function () {
            isSourceMode = !isSourceMode;

            if (isSourceMode) {
                // Switch to source mode — collect paragraph HTML
                sourceArea.value = paragraphEditorParagraphsToSource(editorContent);
                editorContent.style.display = 'none';
                sourceArea.style.display = 'block';
                sourceToggle.textContent = '🔤 Visual';
                sourceArea.focus();
            } else {
                // Switch back to visual — parse source into paragraphs
                paragraphEditorRenderBlocks(editorContent, paragraphEditorParseSource(sourceArea.value));
                editorContent.style.display = 'block';
                sourceArea.style.display = 'none';
                sourceToggle.textContent = '\u003C/\u003E Source';
                editorContent.focus();
            }
            hasUnsaved = true;
        });
    }

    // Save
    if (saveBtn) {
        saveBtn.addEventListener('click', function () {
            doSave();
        });
    }

    // Cancel
    if (cancelBtn2) {
        cancelBtn2.addEventListener('click', function () {
            if (hasUnsaved && !confirm('You have unsaved changes. Discard them?')) {
                return;
            }
            hasUnsaved = false;
            var storyId = editorContainer.dataset.storyId;
            var nodeId = editorContainer.dataset.nodeId;
            window.location.href = editorContainer.dataset.cancelUrl;
        });
    }

    /**
     * Collect paragraphs and POST to api.php.
     */
    function doSave() {
        var paragraphs = [];

        if (isSourceMode) {
            // Parse from source textarea
            paragraphs = paragraphEditorParseSource(sourceArea.value);
        } else {
            // Collect from contenteditable elements and top-level blocks
            paragraphs = paragraphEditorCollectBlocks(editorContent).filter(function (line) {
                return String(line || '').trim() !== '';
            });
        }

        if (paragraphs.length === 0) {
            updateStatus('Error: at least one paragraph required', true);
            return;
        }

        updateStatus('Saving…');
        saveBtn.disabled = true;

        var payload = {
            story_id: editorContainer.dataset.storyId,
            node_id: editorContainer.dataset.nodeId,
            paragraphs: paragraphs,
            _csrf_token: editorContainer.dataset.csrfToken
        };

        fetch(editorContainer.dataset.apiUrl + '?action=save_node_text', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            saveBtn.disabled = false;
            if (data.ok) {
                hasUnsaved = false;
                updateStatus('Saved ✓');
                // Redirect to the node page after saving
                var base = editorContainer.dataset.cancelUrl;
                if (data.review_pending_choices) {
                    base += '#sw-pending-choices';
                }
                window.location.href = base;
            } else {
                updateStatus('Error: ' + (data.error || 'Save failed'), true);
            }
        })
        .catch(function (err) {
            saveBtn.disabled = false;
            updateStatus('Error: ' + err.message, true);
        });
    }

    /**
     * Update the editor status indicator.
     */
    function updateStatus(text, isError) {
        if (!statusEl) return;
        statusEl.textContent = text;
        statusEl.className = 'sw-editor-status' + (isError ? ' sw-editor-status-error' : '');
    }

    // Keyboard shortcut: Ctrl/Cmd+S to save
    document.addEventListener('keydown', function (e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 's') {
            if (editorContainer) {
                e.preventDefault();
                doSave();
            }
        }
    });

    }  // end if (editorContainer)

    /* ==================================================================
     * Homepage Announcement Editor
     * ================================================================*/

    var announcementPanel = document.getElementById('sw-announcement-panel');
    if (announcementPanel) {
        try {
            if (window.sessionStorage.getItem('sw-announcement-seen') === '1') {
                announcementPanel.open = false;
            } else {
                window.sessionStorage.setItem('sw-announcement-seen', '1');
            }
        } catch (err) {
            console.warn('[SW] Unable to persist announcement collapse state for this session.', err);
        }
    }

    var announcementEditor = document.getElementById('sw-announcement-editor');

    if (announcementEditor) {

    var announcementForm = announcementEditor.closest('form');
    var announcementSaveBtn = document.getElementById('sw-announcement-editor-save');
    var announcementCancelBtn = document.getElementById('sw-announcement-editor-cancel');
    var announcementAddParaBtn = document.getElementById('sw-announcement-add-para');
    var announcementSourceToggle = document.getElementById('sw-announcement-source-toggle');
    var announcementContent = document.getElementById('sw-announcement-editor-content');
    var announcementSource = document.getElementById('sw-announcement-editor-source');
    var announcementStatus = document.getElementById('sw-announcement-editor-status');
    var announcementHiddenInput = document.getElementById('sw-announcement-paragraphs-input');
    var announcementDetails = document.getElementById('sw-announcement-details');
    var announcementOriginal = [];
    try {
        announcementOriginal = JSON.parse(announcementEditor.dataset.originalParagraphs || '[]');
    } catch (e) {
        announcementOriginal = [];
    }

    var announcementSourceMode = false;
    var announcementUnsaved = false;

    function setAnnouncementStatus(text, isError) {
        if (!announcementStatus) return;
        announcementStatus.textContent = text;
        announcementStatus.className = 'sw-editor-status' + (isError ? ' sw-editor-status-error' : '');
    }

    function markAnnouncementDirty() {
        announcementUnsaved = true;
        setAnnouncementStatus('Unsaved changes');
    }

    function announcementPopulateParagraphs(paragraphs) {
        paragraphEditorRenderBlocks(announcementContent, paragraphs);
    }

    function announcementCollectParagraphs() {
        var paragraphs = [];

        if (announcementSourceMode) {
            paragraphs = paragraphEditorParseSource(announcementSource.value);
            return paragraphs;
        }

        paragraphEditorCollectBlocks(announcementContent).forEach(function (line) {
            if (String(line || '').trim() !== '') {
                paragraphs.push(line);
            }
        });

        return paragraphs;
    }

    function announcementExitSourceMode() {
        if (!announcementSourceMode) return;

        var paragraphs = announcementCollectParagraphs();
        announcementPopulateParagraphs(paragraphs);
        announcementContent.style.display = 'block';
        announcementSource.style.display = 'none';
        announcementSourceToggle.textContent = '\u003C/\u003E Source';
        announcementSourceMode = false;
    }

    if (announcementContent) {
        announcementContent.addEventListener('input', markAnnouncementDirty);
        announcementContent.addEventListener('paste', function (e) {
            paragraphEditorHandlePaste(e, announcementContent, announcementSourceMode, markAnnouncementDirty);
        });
        announcementContent.addEventListener('keydown', function (e) {
            paragraphEditorHandleBackspace(e, announcementContent, announcementSourceMode, markAnnouncementDirty);
        });
    }

    window.addEventListener('beforeunload', function (e) {
        if (announcementUnsaved) {
            e.preventDefault();
            e.returnValue = '';
        }
    });

    var announcementBoldBtn = document.getElementById('sw-announcement-bold');
    if (announcementBoldBtn) {
        announcementBoldBtn.addEventListener('click', function () {
            document.execCommand('bold', false, null);
            announcementContent.focus();
            markAnnouncementDirty();
        });
    }

    var announcementItalicBtn = document.getElementById('sw-announcement-italic');
    if (announcementItalicBtn) {
        announcementItalicBtn.addEventListener('click', function () {
            document.execCommand('italic', false, null);
            announcementContent.focus();
            markAnnouncementDirty();
        });
    }

    if (announcementAddParaBtn) {
        announcementAddParaBtn.addEventListener('click', function () {
            if (announcementSourceMode) return;
            var newPara = document.createElement('p');
            newPara.className = 'sw-para';
            newPara.setAttribute('contenteditable', 'true');
            announcementContent.appendChild(newPara);
            newPara.focus();
            markAnnouncementDirty();
        });
    }

    if (announcementSourceToggle && announcementSource && announcementContent) {
        announcementSourceToggle.addEventListener('click', function () {
            if (!announcementSourceMode) {
                announcementSource.value = paragraphEditorParagraphsToSource(announcementContent);
                announcementContent.style.display = 'none';
                announcementSource.style.display = 'block';
                announcementSourceToggle.textContent = '🔤 Visual';
                announcementSourceMode = true;
                announcementSource.focus();
            } else {
                announcementExitSourceMode();
                announcementContent.focus();
            }

            markAnnouncementDirty();
        });
    }

    if (announcementCancelBtn) {
        announcementCancelBtn.addEventListener('click', function () {
            if (announcementUnsaved && !confirm('You have unsaved announcement changes. Discard them?')) {
                return;
            }

            announcementSourceMode = false;
            announcementPopulateParagraphs(announcementOriginal);
            announcementContent.style.display = 'block';
            announcementSource.style.display = 'none';
            announcementSource.value = '';
            if (announcementSourceToggle) {
                announcementSourceToggle.textContent = '\u003C/\u003E Source';
            }
            if (announcementHiddenInput) {
                announcementHiddenInput.value = JSON.stringify(announcementOriginal);
            }
            announcementUnsaved = false;
            setAnnouncementStatus('');
            if (announcementDetails && announcementOriginal.length > 0) {
                announcementDetails.open = false;
            }
        });
    }

    if (announcementForm) {
        announcementForm.addEventListener('submit', function () {
            if (announcementHiddenInput) {
                announcementHiddenInput.value = JSON.stringify(announcementCollectParagraphs());
            }
            announcementUnsaved = false;
            if (announcementSaveBtn) {
                announcementSaveBtn.disabled = true;
            }
            setAnnouncementStatus('Saving…');
        });
    }

    }  // end if (announcementEditor)

    /* ================================================================
     * Story theme picker
     * ================================================================ */
    const storyScenarioBtn = document.getElementById('sw-save-story-scenario-btn');
    if (storyScenarioBtn) {
        storyScenarioBtn.addEventListener('click', async function () {
            const scenarioInput = document.getElementById('sw-story-scenario-input');
            const scenarioDetails = document.getElementById('sw-story-scenario-details');
            const status = document.getElementById('sw-story-scenario-status');
            const storyId = scenarioInput?.dataset.storyId;

            if (!scenarioInput || !storyId) {
                return;
            }

            storyScenarioBtn.disabled = true;
            if (status) {
                status.textContent = 'Saving…';
                status.className = 'sw-editor-status';
            }

            try {
                const resp = await fetch(apiBase + '/api?action=update_story_scenario', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        _csrf_token: csrfValue,
                        story_id: storyId,
                        scenario_essentials: scenarioInput.value
                    })
                });
                const data = await resp.json();
                if (data.ok) {
                    scenarioInput.dataset.originalValue = scenarioInput.value;
                    if (status) {
                        status.textContent = '';
                        status.className = 'sw-editor-status';
                    }
                    if (scenarioDetails) {
                        scenarioDetails.open = false;
                    }
                    showFlash(data.message || 'Scenario Essentials saved.', 'success');
                } else {
                    if (status) {
                        status.textContent = data.error || 'Failed to save Scenario Essentials.';
                        status.className = 'sw-editor-status sw-editor-status-error';
                    }
                }
            } catch (err) {
                if (status) {
                    status.textContent = 'Error: ' + err.message;
                    status.className = 'sw-editor-status sw-editor-status-error';
                }
            } finally {
                storyScenarioBtn.disabled = false;
            }
        });
    }

    const storyScenarioCancelBtn = document.getElementById('sw-cancel-story-scenario-btn');
    if (storyScenarioCancelBtn) {
        storyScenarioCancelBtn.addEventListener('click', function () {
            const scenarioInput = document.getElementById('sw-story-scenario-input');
            const scenarioDetails = document.getElementById('sw-story-scenario-details');
            const status = document.getElementById('sw-story-scenario-status');

            if (scenarioInput) {
                scenarioInput.value = scenarioInput.dataset.originalValue || '';
            }
            if (status) {
                status.textContent = '';
                status.className = 'sw-editor-status';
            }
            if (scenarioDetails) {
                scenarioDetails.open = false;
            }
        });
    }

    const storyTitleBtn = document.getElementById('sw-save-story-title-btn');
    if (storyTitleBtn) {
        storyTitleBtn.addEventListener('click', async function () {
            const titleInput = document.getElementById('sw-story-title-input');
            const titleDetails = document.getElementById('sw-story-title-details');
            const status = document.getElementById('sw-story-title-status');
            const storyId = titleInput?.dataset.storyId;

            if (!titleInput || !storyId) {
                return;
            }

            storyTitleBtn.disabled = true;
            if (status) {
                status.textContent = 'Saving…';
                status.className = 'sw-editor-status';
            }

            try {
                const resp = await fetch(apiBase + '/api?action=rename_story', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        _csrf_token: csrfValue,
                        story_id: storyId,
                        title: titleInput.value
                    })
                });
                const data = await resp.json();
                if (data.ok) {
                    titleInput.value = data.title || titleInput.value;
                    titleInput.dataset.originalValue = titleInput.value;
                    if (status) {
                        status.textContent = '';
                        status.className = 'sw-editor-status';
                    }
                    if (titleDetails) {
                        titleDetails.open = false;
                    }
                    location.reload();
                } else if (status) {
                    status.textContent = data.error || 'Failed to save story title.';
                    status.className = 'sw-editor-status sw-editor-status-error';
                }
            } catch (err) {
                if (status) {
                    status.textContent = 'Error: ' + err.message;
                    status.className = 'sw-editor-status sw-editor-status-error';
                }
            } finally {
                storyTitleBtn.disabled = false;
            }
        });
    }

    const storyTitleCancelBtn = document.getElementById('sw-cancel-story-title-btn');
    if (storyTitleCancelBtn) {
        storyTitleCancelBtn.addEventListener('click', function () {
            const titleInput = document.getElementById('sw-story-title-input');
            const titleDetails = document.getElementById('sw-story-title-details');
            const status = document.getElementById('sw-story-title-status');

            if (titleInput) {
                titleInput.value = titleInput.dataset.originalValue || '';
            }
            if (status) {
                status.textContent = '';
                status.className = 'sw-editor-status';
            }
            if (titleDetails) {
                titleDetails.open = false;
            }
        });
    }

    const storyThemeBtn = document.getElementById('sw-apply-story-theme-btn');
    if (storyThemeBtn) {
        storyThemeBtn.addEventListener('click', async function () {
            const select = document.getElementById('sw-story-theme-select');
            const storyId = select?.dataset.storyId;
            const theme = select?.value || '';
            storyThemeBtn.disabled = true;
            storyThemeBtn.textContent = 'Saving…';
            try {
                const resp = await fetch(apiBase + '/api?action=set_story_theme', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        _csrf_token: csrfValue,
                        story_id: storyId,
                        theme: theme
                    })
                });
                const data = await resp.json();
                if (data.ok) {
                    location.reload();
                } else {
                    showFlash(data.error || 'Failed to set theme.', 'error');
                }
            } catch (err) {
                showFlash('Error setting theme: ' + err.message, 'error');
            } finally {
                storyThemeBtn.disabled = false;
                storyThemeBtn.textContent = 'Apply';
            }
        });
    }

    /* ==================================================================
     * Admin — Prompt Preview
     * ================================================================*/

    console.log('[SW] Registering preview-prompt click handler');

    function handlePreviewPromptClick(btn) {
        console.log('[SW] preview-prompt click fired, storyId=', btn.dataset.storyId, 'nodeId=', btn.dataset.nodeId);

        var storyId = btn.dataset.storyId;
        var nodeId  = btn.dataset.nodeId;

        var customChoiceInput = document.querySelector('.sw-custom-choice [name="custom_choice"]');
        var pendingChoice = document.querySelector('.sw-choice-pending');
        var continueBtn = document.getElementById('sw-ai-continue-btn');
        var choiceText = customChoiceInput && customChoiceInput.value.trim() !== ''
            ? customChoiceInput.value.trim()
            : pendingChoice
                ? (pendingChoice.dataset.choiceText || pendingChoice.textContent.trim())
                : continueBtn
                    ? 'Continue the story'
                    : '(example choice)';

        btn.disabled = true;
        btn.textContent = '🔍 Loading\u2026';

        fetch('api?action=preview_prompt', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                story_id:        storyId,
                parent_node_id:  nodeId,
                choice_text:     choiceText,
                key_id:          getSelectedTextKeyId() || '',
                _csrf_token:     csrfValue
            })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            btn.disabled = false;
            btn.textContent = '\uD83D\uDD0D Preview Prompts';
            if (!data.ok) {
                showFlash(data.error || 'Preview failed.', 'error');
                return;
            }
            showPromptPreviewModal(data);
        })
        .catch(function (err) {
            btn.disabled = false;
            btn.textContent = '\uD83D\uDD0D Preview Prompts';
            showFlash('Error: ' + err.message, 'error');
        });
    }

    // Direct binding (primary — works when button is present at load time)
    var previewBtn = document.getElementById('sw-preview-prompt-btn');
    if (previewBtn) {
        previewBtn.addEventListener('click', function () {
            handlePreviewPromptClick(this);
        });
        console.log('[SW] preview-prompt button found and bound directly');
    }

    // Capture-phase delegation (fallback — fires before any stopPropagation)
    document.addEventListener('click', function (e) {
        var btn = e.target;
        while (btn && btn.nodeType === 1) {
            if (btn.id === 'sw-preview-prompt-btn') break;
            btn = btn.parentElement;
        }
        if (!btn || btn.id !== 'sw-preview-prompt-btn') return;
        handlePreviewPromptClick(btn);
    }, true); // capture phase

    function showPromptPreviewModal(data) {
        var existing = document.getElementById('sw-prompt-preview-modal');
        if (existing) existing.remove();

        function esc(str) {
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        }

        var sections = '';
        if (data.system_prompt) {
            sections += '<h3 class="sw-prompt-preview-heading">System Prompt</h3>' +
                        '<pre class="sw-prompt-pre">' + esc(data.system_prompt) + '</pre>';
        }
        if (data.story_context) {
            sections += '<h3 class="sw-prompt-preview-heading">Story Context</h3>' +
                        '<pre class="sw-prompt-pre">' + esc(data.story_context) + '</pre>';
        }
        if (data.image_prompt) {
            sections += '<h3 class="sw-prompt-preview-heading">Image Prompt</h3>' +
                        '<pre class="sw-prompt-pre">' + esc(data.image_prompt) + '</pre>';
        }
        if (!sections) {
            sections = '<p class="sw-text-muted">No prompts available for this node.</p>';
        }

        var modal = document.createElement('div');
        modal.id = 'sw-prompt-preview-modal';
        modal.className = 'sw-modal-backdrop sw-modal-open';
        modal.innerHTML =
            '<div class="sw-modal sw-prompt-preview-dialog">' +
                '<div class="sw-modal-header">' +
                    '<h2>Prompt Preview</h2>' +
                    '<button type="button" class="sw-modal-close" aria-label="Close">&times;</button>' +
                '</div>' +
                '<div class="sw-prompt-preview-body">' + sections + '</div>' +
            '</div>';

        document.body.appendChild(modal);

        modal.querySelector('.sw-modal-close').addEventListener('click', function () {
            modal.remove();
        });
        modal.addEventListener('click', function (e) {
            if (e.target === modal) modal.remove();
        });
        document.addEventListener('keydown', function onEsc(e) {
            if (e.key === 'Escape') { modal.remove(); document.removeEventListener('keydown', onEsc); }
        });
    }

});
