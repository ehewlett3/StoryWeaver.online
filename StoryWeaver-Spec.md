# StoryWeaver — Development Specification
**Target:** storyweaver.online  
**Stack:** PHP 8.x (no framework, no build step, no database)  
**AI Coding Agent:** GitHub Copilot CLI with Claude Opus 4.6  
**Philosophy:** A single folder dropped into any PHP-capable web host is a complete, running application.

---

## 0. Reference Projects

Before writing any code, review these two source projects for patterns to carry forward:

| Project | Location | What to inherit |
|---|---|---|
| AI-Choose-Now | `https://github.com/ehewlett3/AI-Choose-Now` | AI generation loop, streaming narrative, JSON output format, prompt engineering, history reconstruction, image generation UX |
| WYSiteIWYG | Local files (at /Users/ehewlett/Codex/WYSiteIWYG) | In-place HTML editing, theme system, filesystem-first storage, shared menu propagation, drop-in auth model |

The feature reference site for WYSiteIWYG is `https://whatyou.site`.

---

## 1. Directory Structure

All application files live in a single folder. This folder can be placed anywhere inside a web root.

```
storyweaver/
├── index.php                  # Landing/story-list page
├── play.php                   # Active adventure session handler
├── node.php                   # Single story-node renderer
├── edit.php                   # In-place node editor
├── admin.php                  # Admin/editor dashboard
├── settings.php               # User settings + API key management
├── auth.php                   # Login / logout / password reset
├── api.php                    # Internal AJAX endpoint (AI calls, saves, flags)
│
├── _data/                     # All JSON state; PHP must have write access here
│   ├── users.json
│   ├── api_keys.json
│   └── themes.json
│
├── _themes/                   # CSS theme files (WYSite-style)
│   ├── default.css
│   └── [theme-name].css
│
├── stories/                   # Public story nodes (HTML files)
│   └── [story-id]/
│       └── [node-id].html
│
├── quarantine/                # Flagged-for-review nodes; not web-accessible to public
│   └── [story-id]/
│       └── [node-id].html
│
├── _assets/                   # Shared JS, icons, generated images
│   ├── sw.js                  # Shared front-end JS
│   └── images/
│       └── [node-id].[ext]    # AI-generated images
│
└── _mail/                     # Password-reset token store (JSON files, short-lived)
```

**Apache `.htaccess` rules** (generate these):
- Block direct HTTP access to `_data/`, `quarantine/`, `_mail/`
- All other paths serve normally

---

## 2. Data Schemas

### 2.1 `_data/users.json`

```json
{
  "users": [
    {
      "id": "usr_abc123",
      "username": "alice",
      "email": "alice@example.com",
      "password_hash": "<bcrypt>",
      "role": "admin",
      "created_at": "2025-01-01T00:00:00Z",
      "reset_token": null,
      "reset_expires": null
    }
  ]
}
```

Roles in ascending permission order: `viewer` (not logged in), `contributor`, `editor`, `admin`.

### 2.2 `_data/api_keys.json`

```json
{
  "keys": [
    {
      "id": "key_xyz789",
      "owner_user_id": "usr_abc123",
      "label": "My OpenAI Key",
      "provider": "openai",
      "base_url": "https://api.openai.com/v1",
      "api_key": "<encrypted-or-plaintext>",
      "model_text": "gpt-4o",
      "model_image": "dall-e-3",
      "scope": "all",
      "status": "active",
      "last_failure": null,
      "shared_by": "usr_abc123"
    }
  ]
}
```

- `scope`: `"self"` (owner only) or `"all"` (all users)
- `status`: `"active"` or `"unavailable"`
- `provider`: `"openai"`, `"anthropic"`, `"ollama"`, `"custom"` — determines how `base_url` and auth headers are constructed
- For Ollama/local: `base_url` = e.g. `http://192.168.1.10:11434/v1` (OpenAI-compatible endpoint)

### 2.3 Story Node HTML Format

Every story node is a **self-contained HTML file**. It must:

1. Contain all story content in structured `data-` attributes and semantic HTML so it can be read back as context.
2. Link back to its parent node.
3. List the choices the user had available (including the one they chose).
4. Be readable and playable standalone by anyone with access.

**Required HTML structure (generate nodes matching this template exactly):**

```html
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="sw-story-id" content="[story-id]">
  <meta name="sw-node-id" content="[node-id]">
  <meta name="sw-parent-id" content="[parent-node-id or empty for root]">
  <meta name="sw-choice-taken" content="[choice text that led here, or empty for root]">
  <meta name="sw-created-at" content="[ISO timestamp]">
  <meta name="sw-author-id" content="[user-id or 'anonymous']">
  <meta name="sw-flagged" content="false">
  <link rel="stylesheet" href="../../_themes/default.css">
  <title>[Story Title] — [Node ID]</title>
</head>
<body data-sw-node="true">

  <nav class="sw-breadcrumb">
    <a href="../../index.php">All Stories</a> ›
    <a href="[story-root-node].html">[Story Title]</a>
  </nav>

  <article class="sw-node-content">
    <p class="sw-para">[Paragraph one of story content]</p>
    <p class="sw-para">[Paragraph two of story content]</p>
    <!-- Additional paragraphs if manually extended -->
  </article>

  <div class="sw-images">
    <!-- Populated by image generation; empty if no images yet -->
  </div>

  <section class="sw-choices" data-choices-json='[
    {"id": 1, "text": "Go left into the forest", "node": "node_abc.html"},
    {"id": 2, "text": "Climb the tower", "node": "node_def.html"},
    {"id": 3, "text": "Call for help", "node": null}
  ]'>
    <h2>What do you do?</h2>
    <ul>
      <li><a href="node_abc.html">Go left into the forest</a></li>
      <li><a href="node_def.html">Climb the tower</a></li>
      <li>
        <a href="#" class="sw-choice-pending">Call for help</a>
        <!-- pending = choice exists but node not yet generated -->
      </li>
    </ul>
    <form class="sw-custom-choice" action="../../play.php" method="POST">
      <input type="hidden" name="story_id" value="[story-id]">
      <input type="hidden" name="parent_node_id" value="[node-id]">
      <input type="text" name="custom_choice" placeholder="Or type your own action…">
      <button type="submit">Continue →</button>
    </form>
  </section>

  <footer class="sw-node-footer">
    <a class="sw-back" href="[parent-node-id].html">← Back</a>
    <span class="sw-flag-concern">
      <a href="../../api.php?action=flag_concern&node=[node-id]">⚑ Flag for review</a>
    </span>
  </footer>

  <script src="../../_assets/sw.js"></script>
</body>
</html>
```

**Node ID format:** `node_[8-char-hex]` (e.g. `node_3f9a1b2c`)  
**Story ID format:** `story_[8-char-hex]`  
**File path:** `stories/[story-id]/[node-id].html`

---

## 3. AI Integration

### 3.1 Provider Abstraction

All AI calls go through a single PHP class `AIProvider` (`_lib/AIProvider.php`) that normalizes requests across:
- OpenAI-compatible REST APIs (OpenAI, many others)
- Anthropic Messages API
- Ollama (OpenAI-compatible `/v1/chat/completions`)
- Any custom `base_url` with OpenAI-compatible spec

The class selects the best available API key for the current user at call time, in this priority order:
1. Key scoped to `"self"` owned by the current user, status `"active"`
2. Key scoped to `"all"`, status `"active"`, most recently contributed
3. No key available → return `null` (AI features disabled for this request)

### 3.2 Text Generation — Strict JSON Output

**System prompt** (use this verbatim as the base; prepend scenario essentials if present):

```
You are a collaborative storytelling engine for a choose-your-own-adventure game.
Always respond with ONLY valid JSON matching this exact schema — no markdown fences, no extra keys, no preamble:

{
  "paragraphs": ["<paragraph 1>", "<paragraph 2>"],
  "choices": [
    {"id": 1, "text": "<short action phrase>"},
    {"id": 2, "text": "<short action phrase>"},
    {"id": 3, "text": "<short action phrase>"}
  ]
}

Rules:
- Exactly 2 paragraphs unless the user has explicitly requested more or fewer.
- Exactly 3 choices unless the last node is a story ending (then 0 choices and add "ending": true).
- Each paragraph: 3–6 sentences of vivid, present-tense narrative.
- Each choice: 4–12 words, active voice, no punctuation at end.
- Never include the player's previous choice as an available choice again.
- Never break the JSON schema.
```

**User message** (assembled from decision-tree context; see §3.3):

```
[STORY CONTEXT — oldest to newest]
<reconstructed story text and choices>

[PLAYER CHOICE]
<the choice the player just made>

Continue the story.
```

**Response parsing:** JSON decode the raw response. On parse failure: retry once with an explicit repair prompt; on second failure, return error to user and mark the key as `"unavailable"`.

### 3.3 Context Reconstruction (Adventure Memory)

When generating a new node:

1. Walk the **parent chain** of the current node by reading `sw-parent-id` meta tags up the file tree.
2. From each ancestor HTML file, extract:
   - `.sw-node-content` → story paragraphs
   - `sw-choice-taken` meta → the choice that led to the next node
3. Assemble oldest-to-newest into a single context string.
4. Truncate from the **oldest end** when the assembled string exceeds `MAX_CONTEXT_CHARS` (default: 12000). Never truncate the most recent 2 nodes.
5. Pass the truncated context as the user message prefix.

### 3.4 Image Generation

- Detect image-capable models: if the selected API key's `model_image` is non-empty and the provider supports image generation, show the 🖼️ button on nodes that have no images yet.
- Image prompt: `[most recent 1–2 story paragraphs] + [visual summary of up to 3 prior images if available]`.
- Save generated images to `_assets/images/[node-id]-[timestamp].[ext]`.
- Insert `<img>` tag into the node's `.sw-images` div and re-save the HTML file.
- If prior images are available and the provider supports multi-modal input, include the 1–2 most visually relevant prior images as base64 in the image-gen prompt.

### 3.5 API Key Failure Handling

- Wrap every AI call in try/catch.
- On HTTP 401/403 or JSON parse failure after retry: set `status = "unavailable"` for that key in `api_keys.json`.
- A key is only re-activated by the user who owns it, via their settings page.
- Unavailable keys are shown to their owners as ⚠️ in the settings UI.

---

## 4. User Management

### 4.1 Auth Flow

- Session-based auth via PHP `$_SESSION`.
- Login form at `auth.php?action=login`.
- Logout at `auth.php?action=logout`.
- `auth.php` is the only file with session/auth logic; all other PHP files `require_once` a shared `_lib/auth_check.php` helper.

### 4.2 Permission Matrix

| Feature | Not Logged In | Contributor | Editor | Admin |
|---|---|---|---|---|
| Read public story nodes | ✓ | ✓ | ✓ | ✓ |
| Play / generate new nodes | ✓ (if AI available) | ✓ | ✓ | ✓ |
| Edit node text | ✗ | Own nodes only | Any node | Any node |
| Add/manage own API keys | ✗ | ✓ | ✓ | ✓ |
| Share API key with all users | ✗ | ✓ | ✓ | ✓ |
| Flag for concern | ✓ | ✓ | ✓ | ✓ |
| Flag for review (quarantine) | ✗ | ✗ | ✓ | ✓ |
| Approve / restore from quarantine | ✗ | ✗ | ✓ | ✓ |
| Delete nodes | ✗ | ✗ | ✓ | ✓ |
| Manage themes (site-wide retheme) | ✗ | ✗ | ✗ | ✓ |
| Manage users | ✗ | ✗ | ✗ | ✓ |
| View concern queue | ✗ | ✗ | ✓ | ✓ |

### 4.3 Password Reset

- User requests reset at `auth.php?action=reset_request` (enters email).
- App generates a secure random token, stores it as `_mail/[token].json` with expiry (1 hour).
- Sends reset email via PHP `mail()`. If `mail()` is unavailable, display the reset link on-screen with a warning (graceful degradation).
- User clicks link → `auth.php?action=reset&token=[token]` → sets new password → token file deleted.

### 4.4 First-Run Bootstrap

- If `_data/users.json` does not exist, `index.php` redirects to `auth.php?action=setup`.
- Setup creates the first admin account and writes `users.json`.
- Setup page is inaccessible once `users.json` exists.

---

## 5. Pages & UI

### 5.1 Landing / Story List (`index.php`)

- Lists all stories in `stories/` by reading the root node of each story (the HTML file with no `sw-parent-id`).
- Displays: story title, creation date, node count (count HTML files in the story folder), author.
- "Begin New Story" button:
  - If AI available: opens a prompt modal (scenario essentials, optional starting text) → POST to `play.php`.
  - Always: shows a "Start Manually" option that creates a root node with a text editor.
- Nav: Login / Username / Settings (if logged in).

### 5.2 Play Handler (`play.php`)

This is a non-rendering PHP controller. It:
1. Receives a `story_id`, `parent_node_id`, and `choice` (or `custom_choice`) via POST.
2. Reconstructs story context (§3.3).
3. Calls AI (§3.2), or — if AI unavailable — redirects to `edit.php` to create the node manually.
4. On success: writes the new node HTML file, then redirects to `node.php?id=[new-node-id]`.
5. On AI failure: marks key unavailable, retries with next available key; if none, falls back to manual.

### 5.3 Node Renderer (`node.php`)

- Receives `?id=[node-id]&story=[story-id]`.
- Reads and outputs the corresponding HTML file from `stories/` or `quarantine/` (quarantine only if user is editor/admin).
- Adds an editor toolbar for eligible users (edit text, generate image, flag).
- The node HTML file itself is the canonical content source; `node.php` wraps it in the active theme shell.

### 5.4 In-Place Editor (`edit.php`)

Inspired by WYSiteIWYG's editing model:
- Loads the node HTML and makes `.sw-para` elements contenteditable.
- Rich-text toolbar (bold, italic, link) + source-mode toggle.
- Save writes updated paragraphs back into the HTML file on disk; does not touch choices, meta, or structure.
- Accessible to: the node's author (contributor), any editor, any admin.

### 5.5 Admin Dashboard (`admin.php`)

Tabs:
1. **Concern Queue** — list of nodes flagged for concern with reason text; links to node; editor/admin can dismiss or escalate to quarantine.
2. **Quarantine** — list of quarantined nodes; preview, approve (restore to `stories/`), or delete.
3. **API Keys** — all shared keys with status; admin can deactivate or delete any key.
4. **Users** — list users, change roles, delete accounts (admin only).
5. **Themes** — preview and apply site themes (admin only; see §6).

### 5.6 Settings (`settings.php`)

For any logged-in user:
- Manage own API keys: add, label, test (fires a minimal test request), set scope (`self`/`all`), reactivate flagged keys.
- Change password.
- Profile (username, email).

---

## 6. Theming (WYSite-Style)

- Active theme is stored in `_data/themes.json` as the filename of the active CSS in `_themes/`.
- `themes.json` also lists all available theme names and their preview thumbnails.
- **Theme Preview:** admin sees a floating preview panel listing available themes; clicking a theme applies it to the current view via a `?preview_theme=` query parameter.
- **Apply Theme:** rewrites the `<link rel="stylesheet">` tag in every HTML file under `stories/` and `quarantine/`. Uses PHP file read/regex replace/write — no database transaction needed.
- Themes are plain CSS files targeting the classes defined in §2.3. Provide at least two bundled themes: `default` (light) and `dark`.
- Only admin users can apply themes site-wide. Editors and contributors see the site in the active theme only.

---

## 7. Flag for Review (Quarantine)

### Triggering Quarantine

Endpoint: `api.php?action=flag_review&node_id=[id]` (POST, requires editor/admin session)

1. Identify the node's story folder.
2. Build the subtree of child nodes: recursively find all nodes that link **to** the flagged node or its descendants (follow `data-choices-json` links, excluding the `sw-back` link).
3. Move the flagged node and all descendant files from `stories/[story-id]/` to `quarantine/[story-id]/`.
4. Update any parent node's choices HTML: replace the now-broken link with a `class="sw-choice-quarantined"` placeholder visible only to editors/admins.
5. Log the action in a `quarantine_log` array within `_data/users.json` (who flagged, when, node ID).

### Restoring from Quarantine

Endpoint: `api.php?action=approve_node&node_id=[id]` (POST, requires editor/admin)

1. Move all files for the node and its descendants back from `quarantine/` to `stories/`.
2. Restore the choice link in the parent node.
3. Remove quarantine log entry.

### Deleting a Quarantined Node

Endpoint: `api.php?action=delete_node&node_id=[id]` (POST, requires editor/admin)

1. Delete all files for the node and its descendants from `quarantine/`.
2. Remove the choice placeholder from the parent node.

---

## 8. Flag for Concern

Endpoint: `api.php?action=flag_concern&node_id=[id]` (POST, any user including unauthenticated)

- Accepts an optional `reason` text field (max 500 chars).
- Appends to a `concern_queue` array in `_data/users.json`:
  ```json
  {
    "node_id": "node_abc123",
    "story_id": "story_xyz789",
    "reason": "Contains disturbing content",
    "flagged_by": "anonymous",
    "flagged_at": "2025-01-01T00:00:00Z",
    "status": "open"
  }
  ```
- Does **not** move or modify any files.
- Shows in the Concern Queue tab of `admin.php`.

---

## 9. API Endpoint Reference (`api.php`)

All actions are POST with JSON body unless noted. Return JSON `{"ok": true}` or `{"ok": false, "error": "..."}`.

| Action | Auth Required | Description |
|---|---|---|
| `generate_node` | Any (if AI available) | Reconstructs context, calls AI, writes node HTML, returns new node path |
| `save_node_text` | Contributor+ (own) / Editor+ (any) | Updates paragraphs in node HTML file |
| `generate_image` | Any (if image AI available) | Generates image, saves to `_assets/images/`, injects into node HTML |
| `flag_concern` | None | Adds to concern queue |
| `flag_review` | Editor+ | Moves node+subtree to quarantine |
| `approve_node` | Editor+ | Restores from quarantine |
| `delete_node` | Editor+ | Deletes from quarantine |
| `test_api_key` | Contributor+ | Tests a key with a minimal prompt, returns status |
| `save_api_key` | Contributor+ | Adds or updates a key in `api_keys.json` |
| `deactivate_api_key` | Owner or Admin | Sets key status to `"unavailable"` |
| `reactivate_api_key` | Owner only | Sets key status back to `"active"` |
| `apply_theme` | Admin only | Rewrites theme link in all HTML files |
| `dismiss_concern` | Editor+ | Sets concern queue item status to `"dismissed"` |

---

## 10. Installation & Deployment

The entire application requires:
- PHP 8.x with `mail()` available (optional, for password reset)
- Write permissions on: `_data/`, `stories/`, `quarantine/`, `_assets/images/`, `_mail/`
- An `.htaccess` file (auto-generated on first run if not present) blocking access to `_data/`, `quarantine/`, `_mail/`

**No database. No npm. No Composer. No build step.**

Provide a `README.md` with:
1. Upload folder to web host
2. Navigate to `yoursite.com/storyweaver/` and complete first-run setup
3. Optional: configure `_data/config.json` for SMTP settings (if not using PHP `mail()`)

---

## 11. Build Phases (for Copilot CLI)

Work through these phases in order. Complete and test each phase before starting the next.

### Phase 1 — Skeleton & Auth
- Directory structure with placeholder `.htaccess`
- `_data/users.json` schema + read/write helper (`_lib/users.php`)
- `auth.php`: login, logout, setup, password reset
- `_lib/auth_check.php`: session helper used by all pages
- `index.php`: bare list page confirming auth works

### Phase 2 — Story Node Engine
- Node HTML template and write function (`_lib/nodes.php`)
- `play.php`: creates a root node manually (no AI yet)
- `node.php`: reads and renders a node HTML file
- `edit.php`: in-place text editing with save-to-disk

### Phase 3 — AI Integration
- `_lib/AIProvider.php`: provider abstraction for OpenAI, Anthropic, Ollama
- `_data/api_keys.json` schema + read/write helper
- `settings.php`: add/test/manage API keys
- `api.php` actions: `generate_node`, `test_api_key`, `save_api_key`
- Context reconstruction (parent-chain walker)
- Wire `play.php` to call AI when a key is available

### Phase 4 — Image Generation
- `api.php` action: `generate_image`
- Image prompt assembly (current node + prior image context)
- Image injection into node HTML
- 🖼️ button in `node.php` when image model is available

### Phase 5 — Moderation (Flags & Quarantine)
- `api.php` actions: `flag_concern`, `flag_review`, `approve_node`, `delete_node`
- Subtree walker for quarantine moves
- `admin.php`: Concern Queue and Quarantine tabs

### Phase 6 — Theming & Admin
- `_themes/default.css` and `_themes/dark.css`
- `api.php` action: `apply_theme` (bulk HTML rewrite)
- `admin.php`: Users and Themes tabs
- Theme preview (`?preview_theme=` query param)

### Phase 7 — Polish & Hardening
- `.htaccess` generation on first run
- Input sanitization throughout
- Error pages (404 for missing nodes, 403 for quarantined nodes)
- Mobile-responsive CSS
- `README.md`

---

## 12. Coding Standards for This Project

- **No third-party PHP libraries** unless unavoidable (prefer PHP built-ins).
- **No JavaScript frameworks.** Vanilla JS only in `_assets/sw.js`.
- **All file writes are atomic:** write to a temp file, then `rename()` to the target. Never write partial files.
- **Sanitize all inputs** before writing to HTML or JSON files. Use `htmlspecialchars()` on output, `json_encode()`/`json_decode()` for data.
- **Passwords** stored as `password_hash($pass, PASSWORD_BCRYPT)`, verified with `password_verify()`.
- **Node IDs** generated with `bin2hex(random_bytes(4))`.
- **API keys** stored as-is in `api_keys.json` (no server-side encryption in v1; document this limitation).
- **All JSON data files** use UTF-8 with `JSON_PRETTY_PRINT` for human-readability.
- **Comment every function** with its purpose, parameters, and return value.
- **PHP error reporting** disabled in production; controlled by a `DEBUG` constant in `_data/config.json`.
