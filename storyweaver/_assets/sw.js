/**
 * StoryWeaver — Shared front-end JavaScript.
 *
 * Loaded on every page. Handles: flash messages, in-place editor,
 * pending choice clicks, new-story modal, and CSRF token injection.
 */

document.addEventListener('DOMContentLoaded', function () {

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
    function showGenerationOverlay() {
        var overlay = document.createElement('div');
        overlay.className = 'sw-gen-overlay';
        overlay.innerHTML =
            '<div class="sw-gen-modal">' +
            '  <div class="sw-gen-header">' +
            '    <span class="sw-gen-spinner">✨</span>' +
            '    <span class="sw-gen-title">Generating story…</span>' +
            '    <span class="sw-gen-key-label"></span>' +
            '  </div>' +
            '  <div class="sw-gen-text"></div>' +
            '  <div class="sw-gen-status"></div>' +
            '</div>';
        document.body.appendChild(overlay);
        // Force reflow then add open class for animation
        overlay.offsetHeight;
        overlay.classList.add('sw-gen-open');
        return overlay;
    }

    /**
     * Get the selected key ID from whichever key picker is present.
     * Checks the node page picker first, then the modal picker.
     */
    function getSelectedKeyId() {
        var picker = document.getElementById('sw-key-picker')
                  || document.getElementById('sw-key-picker-modal');
        if (!picker) return '';
        return picker.value;
    }

    // Restore last chosen key from localStorage and listen for changes
    (function () {
        var pickers = [
            document.getElementById('sw-key-picker'),
            document.getElementById('sw-key-picker-modal')
        ];
        var saved = localStorage.getItem('sw-last-key-id');
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
                localStorage.setItem('sw-last-key-id', picker.value);
            });
        });
    })();

    /**
     * Start a streaming generation request.
     *
     * @param {Object} payload - The request payload.
     * @param {Object} options - Optional callbacks: onFallback (called if SSE fails)
     */
    function startStreamingGeneration(payload, options) {
        options = options || {};
        var overlay = showGenerationOverlay();
        var textEl = overlay.querySelector('.sw-gen-text');
        var statusEl = overlay.querySelector('.sw-gen-status');
        var keyLabelEl = overlay.querySelector('.sw-gen-key-label');
        var headerEl = overlay.querySelector('.sw-gen-header');
        var streamBuffer = ''; // accumulates all raw tokens

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
        payload.key_id = payload.key_id || getSelectedKeyId();

        // Persist key choice for next time
        if (payload.key_id) {
            localStorage.setItem('sw-last-key-id', payload.key_id);
        }

        // Use fetch + ReadableStream for SSE (more control than EventSource for POST)
        fetch(apiBase + '/api.php?action=stream_generate_node', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        }).then(function (response) {
            if (!response.ok || !response.body) {
                throw new Error('Stream unavailable');
            }

            var reader = response.body.getReader();
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
                            var data;
                            try { data = JSON.parse(rawData); } catch (e) { return; }
                            // Replace raw stream text with rendered paragraphs + choices
                            showDropInResult(overlay, data);
                        } else if (eventType === 'error') {
                            var data;
                            try { data = JSON.parse(rawData); } catch (e) { return; }
                            headerEl.querySelector('.sw-gen-spinner').textContent = '\u274C';
                            headerEl.querySelector('.sw-gen-title').textContent = 'Generation failed';
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

                    read();
                }).catch(function () {
                    // Stream error — fall back
                    if (options.onFallback) {
                        overlay.remove();
                        options.onFallback();
                    }
                });
            }

            read();
        }).catch(function () {
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
    function showDropInResult(overlay, data) {
        var modal = overlay.querySelector('.sw-gen-modal');
        var headerEl = modal.querySelector('.sw-gen-header');
        var textEl = modal.querySelector('.sw-gen-text');
        var statusEl = modal.querySelector('.sw-gen-status');

        headerEl.querySelector('.sw-gen-spinner').textContent = '\u2705';
        headerEl.querySelector('.sw-gen-title').textContent = 'Story generated!';
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

            startStreamingGeneration({
                story_id: storyId.value,
                parent_node_id: parentNodeId.value,
                choice_text: choiceText
            }, {
                onFallback: function () {
                    // Fall back to regular form POST
                    var input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'choice';
                    input.value = choiceText;
                    form.appendChild(input);
                    var custom = form.querySelector('[name="custom_choice"]');
                    if (custom) custom.value = '';
                    form.submit();
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

            e.preventDefault();

            var storyId = form.querySelector('[name="story_id"]');
            var parentNodeId = form.querySelector('[name="parent_node_id"]');
            if (!storyId || !parentNodeId) return;

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
                    form.action = 'play.php';
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

    /* ==================================================================
     * Image Generation Button
     * ================================================================*/

    var genImageBtn = document.getElementById('sw-gen-image-btn');
    if (genImageBtn) {
        genImageBtn.addEventListener('click', function () {
            var storyId = genImageBtn.getAttribute('data-story-id');
            var nodeId = genImageBtn.getAttribute('data-node-id');
            var keyId = getSelectedKeyId();

            genImageBtn.disabled = true;
            genImageBtn.textContent = '🖼️ Generating…';

            fetch('api.php?action=generate_image', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    story_id: storyId,
                    node_id: nodeId,
                    key_id: keyId,
                    _csrf_token: csrfValue
                })
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.ok && data.image_url) {
                    var imgContainer = document.getElementById('sw-images');
                    if (imgContainer) {
                        var img = document.createElement('img');
                        img.src = data.image_url;
                        img.alt = 'Story illustration';
                        img.className = 'sw-node-image';
                        imgContainer.appendChild(img);
                    }
                    genImageBtn.remove();
                } else {
                    alert(data.error || 'Image generation failed.');
                    genImageBtn.disabled = false;
                    genImageBtn.textContent = '🖼️ Generate Image';
                }
            })
            .catch(function () {
                alert('Image generation request failed.');
                genImageBtn.disabled = false;
                genImageBtn.textContent = '🖼️ Generate Image';
            });
        });
    }

    /* ==================================================================
     * Individual image delete buttons (admin/editors)
     * ================================================================*/

    document.querySelectorAll('.sw-image-delete-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!confirm('Delete this image?')) return;
            var imageUrl = btn.getAttribute('data-image-url');
            btn.disabled = true;
            fetch('api.php?action=delete_image', {
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
                    btn.closest('.sw-image-wrap').remove();
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
    });

    /* ==================================================================
     * Image Regeneration with side-by-side comparison
     * ================================================================*/

    var regenImageBtn = document.getElementById('sw-regen-image-btn');
    if (regenImageBtn) {
        regenImageBtn.addEventListener('click', function () {
            var storyId = regenImageBtn.getAttribute('data-story-id');
            var nodeId = regenImageBtn.getAttribute('data-node-id');
            var existingUrl = regenImageBtn.getAttribute('data-existing-image');
            var keyId = getSelectedKeyId();

            regenImageBtn.disabled = true;
            regenImageBtn.textContent = '🖼️ Generating new image…';

            fetch('api.php?action=generate_image', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    story_id: storyId,
                    node_id: nodeId,
                    key_id: keyId,
                    _csrf_token: csrfValue
                })
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.ok && data.image_url) {
                    showImageCompareModal(existingUrl, data.image_url);
                    regenImageBtn.disabled = false;
                    regenImageBtn.textContent = '🖼️ Regenerate Image';
                } else {
                    alert(data.error || 'Image generation failed.');
                    regenImageBtn.disabled = false;
                    regenImageBtn.textContent = '🖼️ Regenerate Image';
                }
            })
            .catch(function () {
                alert('Image generation request failed.');
                regenImageBtn.disabled = false;
                regenImageBtn.textContent = '🖼️ Regenerate Image';
            });
        });
    }

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
                fetch('api.php?action=delete_image', {
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

        // Provider dropdown auto-fills base URL
        var providerSelect = document.getElementById('key-provider');
        var baseUrlInput = document.getElementById('key-base-url');
        if (providerSelect && baseUrlInput) {
            var defaultUrls = {
                openai: 'https://api.openai.com/v1',
                anthropic: 'https://api.anthropic.com',
                ollama: 'http://localhost:11434/v1',
                custom: ''
            };
            providerSelect.addEventListener('change', function () {
                var url = defaultUrls[this.value] || '';
                if (baseUrlInput.value === '' || Object.values(defaultUrls).indexOf(baseUrlInput.value) !== -1) {
                    baseUrlInput.value = url;
                }
            });
        }
    }

    /* ==================================================================
     * Settings — API Key Management
     * ================================================================*/

    var addKeyForm = document.getElementById('sw-add-key-form');
    if (addKeyForm) {
        var keyFormStatus = document.getElementById('sw-key-form-status');
        var csrfToken = document.querySelector('meta[name="csrf-token"]');
        var csrf = csrfToken ? csrfToken.getAttribute('content') : '';
        var baseUrl = window.location.pathname.replace(/\/settings\.php.*/, '');

        addKeyForm.addEventListener('submit', function (e) {
            e.preventDefault();
            if (keyFormStatus) keyFormStatus.textContent = 'Saving…';

            var payload = {
                label: document.getElementById('key-label').value,
                provider: document.getElementById('key-provider').value,
                base_url: document.getElementById('key-base-url').value,
                api_key: document.getElementById('key-api-key').value,
                model_text: document.getElementById('key-model-text').value,
                model_image: document.getElementById('key-model-image').value,
                scope: document.getElementById('key-scope').value,
                _csrf_token: csrf
            };

            fetch(baseUrl + '/api.php?action=save_api_key', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.ok) {
                    if (keyFormStatus) keyFormStatus.textContent = 'Key saved ✓';
                    setTimeout(function () { window.location.reload(); }, 500);
                } else {
                    if (keyFormStatus) keyFormStatus.textContent = 'Error: ' + (data.error || 'Save failed');
                }
            })
            .catch(function (err) {
                if (keyFormStatus) keyFormStatus.textContent = 'Error: ' + err.message;
            });
        });

        // Test, Deactivate, Reactivate, Delete buttons (delegated)
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-key-id]');
            if (!btn) return;

            var keyId = btn.dataset.keyId;
            var action = null;

            if (btn.classList.contains('sw-key-test')) action = 'test_api_key';
            else if (btn.classList.contains('sw-key-deactivate')) action = 'deactivate_api_key';
            else if (btn.classList.contains('sw-key-reactivate')) action = 'reactivate_api_key';
            else if (btn.classList.contains('sw-key-delete')) action = 'delete_api_key';
            else return;

            if (action === 'delete_api_key' && !confirm('Delete this API key?')) return;

            btn.disabled = true;
            btn.textContent = '…';

            fetch(baseUrl + '/api.php?action=' + action, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ key_id: keyId, _csrf_token: csrf })
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

            fetch('api.php?action=flag_concern', {
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

            fetch('api.php?action=flag_review', {
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

            fetch('api.php?action=' + action, {
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

            fetch('api.php?action=change_role', {
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

    var editorContainer = document.getElementById('sw-editor');
    if (!editorContainer) return; // Not on edit page

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
                var paragraphs = editorContent.querySelectorAll('.sw-para');
                var html = '';
                paragraphs.forEach(function (p) {
                    html += p.innerHTML + '\n\n';
                });
                sourceArea.value = html.trim();
                editorContent.style.display = 'none';
                sourceArea.style.display = 'block';
                sourceToggle.textContent = '🔤 Visual';
                sourceArea.focus();
            } else {
                // Switch back to visual — parse source into paragraphs
                var lines = sourceArea.value.split(/\n\n+/);
                editorContent.innerHTML = '';
                lines.forEach(function (line) {
                    line = line.trim();
                    if (line === '') return;
                    var p = document.createElement('p');
                    p.className = 'sw-para';
                    p.setAttribute('contenteditable', 'true');
                    p.innerHTML = line;
                    editorContent.appendChild(p);
                });
                if (editorContent.children.length === 0) {
                    var p = document.createElement('p');
                    p.className = 'sw-para';
                    p.setAttribute('contenteditable', 'true');
                    editorContent.appendChild(p);
                }
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
            var lines = sourceArea.value.split(/\n\n+/);
            lines.forEach(function (line) {
                line = line.trim();
                if (line !== '') paragraphs.push(line);
            });
        } else {
            // Collect from contenteditable elements
            var paras = editorContent.querySelectorAll('.sw-para');
            paras.forEach(function (p) {
                var html = p.innerHTML.trim();
                if (html !== '' && html !== '<br>') {
                    paragraphs.push(html);
                }
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

    /* ================================================================
     * Story theme picker
     * ================================================================ */
    const storyThemeBtn = document.getElementById('sw-apply-story-theme-btn');
    if (storyThemeBtn) {
        storyThemeBtn.addEventListener('click', async function () {
            const select = document.getElementById('sw-story-theme-select');
            const storyId = select?.dataset.storyId;
            const theme = select?.value || '';
            storyThemeBtn.disabled = true;
            storyThemeBtn.textContent = 'Saving…';
            try {
                const resp = await fetch(apiBase + '/api.php?action=set_story_theme', {
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

});
