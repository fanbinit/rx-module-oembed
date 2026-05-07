# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

> The global rules in the `CLAUDE.md` at the Rhymix framework root (Module Loading Pattern, PHP Writing Rules, CSRF, Cache Key conventions, etc.) apply as-is. This document only covers the flows and constraints specific to the oembed module.

## Completion Rules

When a user-requested task is finished, commit and push the changes. The following must be observed:

- Before committing, run `git status` / `git diff` to verify the change scope. If files outside the requested scope are mixed in, notify the user first.
- Options that bypass safety checks (`--no-verify`, `--no-gpg-sign`, force push, etc.) are only used when the user explicitly requests them.
- Never auto-perform a force push to protected branches (`dev`, `main`) — always confirm with the user first.
- If the user has asked to hold/review the work, or if the work is incomplete, defer the push.

## Module Identity

A PSR-4 module (`Rhymix\Modules\Oembed\*`) that converts URLs into **iframe embeds** or **OG preview cards** via the CKEditor 4 paste hook and the `procOembedFetch` action. It replaces the legacy `preview` module; when `compatible_mode` is enabled, legacy preview-era markup (`preview_card_*`, `media_embed_wrapper`, `.instagram-media`, `.fb-post`, `.twitter-tweet`) continues to render correctly on view pages. Bundled providers (`Config::BUNDLED_PROVIDERS`): YouTube, Facebook, Instagram, X (Twitter), Reddit. Additional providers shipped in `providers/` (Vimeo, Dailymotion, Chzzk, SOOP, CodePen, JSFiddle) live alongside the bundled ones but are *not* seeded into `enabled_providers` — the operator must turn them on explicitly in the admin (intentional security policy). The legacy Imgur provider was removed because Imgur's embed CDN doesn't send CORS headers reliably and we no longer want to ship a provider that needs special-cased crossorigin handling — old `imgur-embed-pub` markup in archived posts will no longer auto-load embed.js.

> ⚠️ Must not be activated alongside the `preview` module. `Config::isPreviewModuleActive()` checks for the existence of `modules/preview/conf/info.xml` and the admin screen surfaces a conflict badge.

## Core Data Flow (paste → stored body → output)

1. **Paste (browser)** — `tpl/js/_ckeditor.js`
   - Only standalone URLs are extracted and immediately replaced with a `<p data-oembed-pending="1">` placeholder.
   - `oembed:failed_hosts` in `sessionStorage` (1-hour TTL) blocks re-attempts for the same host.
   - When calling `procOembedFetch`, the CSRF token is sent via both header (`X-CSRF-Token`) and form field (`_rx_csrf_token`).
   - On response, the placeholder is swapped via `CKEDITOR.dom.element.createFromHtml` — **never inject via `innerHTML` directly** (that bypasses the CKEditor sanitizer).

2. **Conversion (server)** — `controllers/Controller.php::procOembedFetch`
   - `RemoteFetcher::normalizeUrl` → `Registry::match` is attempted → on a hit, `provider->fetchInfo($url)` (an optional hook) is invoked to pull `width / height` (for iframe aspect-ratio correction) and `thumbnail_url` from external metadata such as oEmbed. When `thumbnail_url` is present, it is attached through the same `ImageAttacher` flow as OG cards, and the embed wrapper carries `data-oembed-file-srl` along with the response's `upload_target_srl` / `file_srl` in the same pattern. Then `provider->buildEmbed()` output is **wrapped in `<div editor_component="oembed" data-url="…">…</div>`** and returned.
   - On miss: `RemoteFetcher::fetchHtml` → `OpenGraph::parse` → `ImageAttacher::attach` (downloads OG image, stages the temp file under `RX_BASEDIR/files/attach/chunks/` so file.controller.php:1061 routes it through the chunked-upload move path, then calls `FileController::insertFile($fileInfo, $moduleSrl, $uploadTargetSrl, 0, false, $editorSequence)` — `manual_insert=false` so the board's allowed-extension / size / total-attach-size policies and `adjustUploadedImage` (EXIF rotate, board-level format conversion) all apply; policy violations throw `msg_not_allowed_filetype` / `msg_exceeds_limit_size` which are caught and trigger the og.image URL fallback. On save the file is linked to the new `document_srl` like any other attachment) → `CardRenderer::render` → wrapped in the same `<div editor_component="oembed" data-url="…">` shape. The response also carries `upload_target_srl` (for the JS to sync into the form's `document_srl` hidden field — same pattern as `procFileUpload`) and `file_srl` (so the JS can call `procFileDelete` to claw back the attachment if the placeholder is gone before the response lands, e.g. user undid the paste — without this, a stale response would orphan-attach an image the body never references).
   - Response shape: `{ kind: 'embed' | 'card' | 'fail', wrapped_html, url, provider? }`.
   - The wrapper carries: `editor_component="oembed"` (CKEditor widget hint), `contenteditable="false"` (single-block selection inside the editor), `data-url` (debugging/tooling reference), `data-oembed-type` (one of `social` / `multimedia` / `card` — used by style.css for type-specific visualization), and on provider-matched embeds also `data-oembed-provider` (class basename like `Instagram`/`Facebook`/`X`/`Youtube` — used by style.css to pick the right label/badge). Embed dimensions, card metadata, etc. all live inside the inner HTML, so they are not duplicated on the wrapper.

2.5. **Orphan attachment cleanup** — `EventHandlers::pruneOrphanedOembedFiles` is bound to the **after**-trigger of `document.insertDocument` / `document.updateDocument` / `comment.insertComment` / `comment.updateComment`. It collects the `data-oembed-file-srl="N"` values from the body to form a reference set, then filters the files attached to the same `upload_target_srl` whose `source_filename` matches the `oembed_<16hex>.<ext>` pattern issued by ImageAttacher as oembed-attachment candidates. Candidates not in the reference set are reclaimed via `deleteFile`. Why the after position: at the before stage, body validation / security checks / extra_vars upload have not yet run, so if any of those throws, only the physical attachment file is deleted while the document rolls back — existing card images break (`Storage::delete` is non-transactional). The after stage runs right after `setFilesValid` and just before `$oDB->commit()`, past nearly every failure path. Why not use the `upload_target_type` column as the identifier: `setFilesValid` overwrites that column to `'doc'` on every save, so the oembed marker is wiped at re-edit time. `source_filename` is never changed by any flow and matches consistently for new posts and N-times-edited posts alike. The collision probability with regular upload attachments is essentially zero (16 hex digits). If a user manually strips the `data-oembed-file-srl` attribute via source-edit, that attachment is reclaimed too (a card with a broken attribute no longer works in the body anyway, so consistency holds).

3. **Output (body render)** — The `wrapped_html` produced at paste time is **frozen into the body and rendered as-is**. The Rhymix editor core does not post-process `<div editor_component="oembed">` (this module does not register a separate EditorHandler), so changes to a Provider's `buildEmbed` output **are not retroactively applied to existing posts** — re-save the body to apply the change. The one exception is the external SDK `<script>`: `_render.js` scans the body DOM on `DOMContentLoaded`, and when a provider's `getEmbedAssets()` selector matches it injects the SDK into `document.head`. So changing an SDK URL takes effect immediately without touching the stored body markup (the body only needs to keep the CSS class the selector targets).

## Provider Extension Contract

Just drop a single file at `providers/{Name}.php` — `Registry::scanProviderClasses()` discovers it via `glob`. After adding a new file, click admin → oEmbed → Provider Management → **Refresh Provider Cache** (= `Registry::flush()`), or recompile the Rhymix cache.

```php
namespace Rhymix\Modules\Oembed\Providers;
use Rhymix\Modules\Oembed\Models\Provider;

class Vimeo extends Provider {
  public string $name = 'Vimeo';
  public string $type = self::TYPE_MULTIMEDIA;       // or TYPE_SOCIAL — controls 16:9 vs 4:3 default when dimensions are unset
  public array $hosts = ['vimeo.com', 'player.vimeo.com'];
  public array $patterns = [
    '~(?:https?:)?//(?:www\.|player\.)?vimeo\.com/(\d+)~i' => ['video_id'],
  ];
  public function buildEmbed(array $matchData, ?int $width = null, ?int $height = null): string { … }
}
```

Rules:
- **Use `~` as the PCRE delimiter.** URLs frequently contain `#` and `?`, which collide with the `#` delimiter (Youtube once broke for this exact reason — commit `237e542`). Standardize new patterns on `~` as well.
- The values in `$patterns` are **arrays of capture-group names** — the base `Provider::match()` maps them into `$captures[name]`.
- Pattern priority is "first match wins" in registration order. Put narrower patterns first and the most permissive single pattern last — X uses this to keep `/i/web/status/...` from being captured as `username=web`.
- Escape all user input via `htmlspecialchars(…, ENT_QUOTES, 'UTF-8')`. Use `rawurlencode` for URL path segments.
- **Override `fetchInfo()` when the provider exposes an oEmbed / metadata endpoint.** The default implementation returns `null`, and the Controller falls back to `getDimensions()`'s type-based default ratio using only the dimensions sent by the client. When a provider exposes an official oEmbed (like YouTube — `https://www.youtube.com/oembed?url=…&format=json`) that returns accurate per-video `width / height / thumbnail_url`, pulling the response through this hook means (a) the iframe matches the video's true aspect ratio (Shorts 9:16, 21:9 cinematic, etc.), and (b) the thumbnail is auto-registered as a body attachment via `ImageAttacher` so it shows up in board auto-thumbnails / attachment lists / RSS previews. All return keys are optional (`width`, `height`, `thumbnail_url`) — partial returns are handled by caller-side fallback. External calls **must** go through `RemoteFetcher` (`fetchJson` for JSON responses, `fetchHtml` for HTML) to pass the SSRF guard / timeout / size cap / content-type guard. `fetchHtml` only allows `text/html` / `application/xhtml`, so oEmbed JSON must be retrieved with `fetchJson` — calling `fetchHtml` always returns null at the content-type guard. Network or parse failures absorbed as `null` automatically fall back to the default ratio.
- **Never put `<script>` inside `buildEmbed()` output.** The wrapped HTML is stored in the document body and HTMLPurifier strips `<script>` at save time — the post can fail to save outright. If your provider needs an external SDK (Instagram embed.js, Facebook sdk.js, X widgets.js, etc.) to activate the markup, override `getEmbedAssets()` instead. It returns a list of `['selector' => '<css selector>', 'script' => '<https url>', 'crossorigin' => bool, 'normalize' => [...]]`. The selector is passed to client-side `document.querySelector` — when at least one matching node exists in the rendered DOM, `_render.js` injects a `<script>` for that URL into `document.head` (deduplicated by URL). Selectors can target multiple classes in one entry (Facebook uses `.fb-post, .fb-video` for a single SDK URL). Detection runs on `DOMContentLoaded`, so it sees the final DOM after all module/addon/sanitizer post-processing — this is more robust than scanning the rendered HTML server-side, where addon execution order vs `before display` would matter. Set `crossorigin => true` only when the SDK's CDN sends CORS headers AND the official snippet uses `crossorigin="anonymous"` (Facebook does, Instagram/X do not). Forcing anonymous CORS mode on a CDN that doesn't allow it makes the browser block the script load. The optional `normalize` field is a list of `{detect, addClass}` rules that run before the SDK detection pass — Instagram uses `[{detect: 'blockquote[data-instgrm-permalink]:not(.instagram-media)', addClass: 'instagram-media'}]` and X uses `data-oembed-tweet-id` as its anchor, because CKEditor ACF / HTMLPurifier commonly drop the SDK-required class while leaving `data-*` attributes intact. Use a detect selector that survives sanitizers (custom data attributes are usually safe; class allowlists are not).

## SSRF / Outbound Call Guard

External calls **must go through `Models\RemoteFetcher`**. Direct `HTTP::get` / `file_get_contents` is forbidden.

- `RemoteFetcher::isUrlSafe()` — http/https only; blocks localhost / `*.localhost` / RFC1918 / link-local / `169.254.169.254` (AWS metadata) / `fd00:ec2::254`. The host is resolved via `dns_get_record(A|AAAA)` and every returned IP must be public.
- The validated IP set from that lookup is **pinned to the cURL handle via `CURLOPT_RESOLVE`** (one entry per IP, `host:port:ip`, IPv6 bracketed). Without this, cURL would do a separate connect-time DNS lookup that an attacker-controlled authoritative DNS could answer with `127.0.0.1` / RFC1918 / `169.254.169.254` even though the validation lookup returned a public IP — a classic DNS-rebinding TOCTOU SSRF bypass. The pin makes cURL skip the second lookup entirely and connect to one of the IPs the guard already cleared. The validated IP list is plumbed via `inspectUrl()` → `buildResolveEntries()`; public `isUrlSafe()` / `isHostSafe()` keep their boolean signatures for compat.
- The `on_redirect` callback only allows redirects whose **`(host, port)` tuple is identical to the first hop**. A `CURLOPT_RESOLVE` entry only applies to the exact `host:port` it was registered for, so any change — cross-host *or* same-host different-port (including http→https upgrade across 80↔443) — would resolve unpinned at connect time and re-introduce the same TOCTOU. Both are blocked. Same-origin redirects keep working because cURL reuses the same handle's resolve cache.
- Limits: 3-second timeout, 5 redirects max, HTML 2 MB (truncated when exceeded), images 5 MB (rejected when exceeded — returns `null`), URL 2048 chars.
- All failures return `null`. Callers fall back gracefully.

## iframe Whitelist Policy

**Do not auto-register hosts.** Per site security policy, the operator must approve hosts manually at System → Settings → Security → External Multimedia Allow (`Rhymix\Framework\Filters\MediaFilter`) for the iframe to survive on the rendered page. The admin Provider screen uses `MediaFilter::matchWhitelist()` to show per-host registration status as badges and surfaces a summary of unregistered hosts. Do not reintroduce auto-whitelisting code (it was intentionally removed in v0.4.0).

The hosts the admin screen displays come from **`Provider::getEmbedHosts()`**, not `$hosts`. The two are deliberately separated:

- `$hosts` — candidates matched against the input URL's host at paste time (paste detection only).
- `getEmbedHosts()` — the actual hosts of the iframe / SDK src embedded into the body. These are the hosts that must be registered in the MediaFilter whitelist to survive the output stage. Simple iframe providers (like YouTube, where the input domain and embed domain are the same) don't need to override this — the default implementation returns `$hosts` as-is.

Before this split, SDK-conversion providers like X / Reddit used the workaround of mixing SDK hosts into `$hosts`, which blurred the meaning of paste matching. They were separated in v0.5.0.

## EventHandlers Routing Subtleties

`controllers/EventHandlers.php::injectEditorAssets` injects the paste hook / card assets on the `before display` trigger. Two pitfalls:

1. **`act` fallback is required** — When the URL has no `act`, Rhymix `ModuleHandler` routes to the module's `default_index_act` but does not populate that value into `Context::get('act')` (`classes/module/ModuleHandler.class.php:355-358`). So a view request that arrives with only `mid=board` won't match `dispBoardContent`. Use `current_module_info` + `ModuleModel::getModuleActionXml` to fall back to `default_index_act` / `admin_index_act` (commit `9bf9521`).
2. **Editor asset injection branching** — `WRITE_ACTS` (write document/comment) checks via `EditorModel::getEditorConfig` whether the editor is ckeditor, then injects `_ckeditor.js` + adds `tpl/css/style.css` via `Context::addCssFile` (for the host page chrome and CKEditor's contentsCss propagation), and *additionally* reads `tpl/css/style.css` content fresh on every request and emits it as inline `window.oembedEditorCss`. `_render.js` is *not* loaded on write pages. The reason the editor-scoped portion ships inline rather than only via `Context::addCssFile`: Rhymix stamps the URL with `?t=<minified mtime>` for cache busting, but the minified copy's mtime can stay stale across deploys, and CKEditor 4 captures that stale URL in `contentsCss` at editor init — meaning the iframe loads an outdated CSS forever. `_ckeditor.js` calls `CKEDITOR.addCss(window.oembedEditorCss)` for new instances and directly inserts a `<style>` into existing instances' documents (covers iframe and divarea modes uniformly). `VIEW_ACTS` (`dispBoardContent` / `dispDocumentPrint` / `dispDocumentPreview` — the front-end body-render surfaces only) adds `style.css` plus `_render.js` together with an inline `window.oembedEmbedAssets` payload (see Provider Extension Contract). The editor-only rules in `style.css` are scoped to `.rhymix_content.cke_editable, .cke_wysiwyg_div`, so loading the same file on view pages is harmless — the scoped selectors don't match outside the editor. `_render.js` does the actual DOM scan + SDK injection on `DOMContentLoaded`; in compatible mode it additionally activates legacy `iframe[data-src]` placeholders (`window.oembedCompatibleMode`). If neither any provider declares assets nor compatible mode is on, `_render.js` is not loaded. Admin review surfaces are deliberately excluded: `dispDocumentAdminDeclared` / `dispCommentAdminDeclared` (and their per-srl log variants) emit the body / comment only via `getTitle()` / `getSummary(N)` (= `strip_tags()` + `escape()`), so no iframe / card markup ever lands in the page DOM and `_render.js` would have nothing to activate; `dispTrashAdminView` *does* render `{$oOrigin->content|noescape}` raw, but it's an admin-only review surface where activating embeds and pulling third-party SDKs only widens the admin XSS surface — leave the body unstyled rather than re-enable activation there. If you remove `dispBoardContent` (or the print / preview surfaces) from `VIEW_ACTS`, body cards and provider SDK injection both break on the read path.

## Config / Skin

- Module config lives in `Models\Config` (static cache + `ModuleModel::getModuleConfig('oembed')`). Keys: `compatible_mode` (Y/N), `skin` (directory name), `enabled_providers` (array — whitelist).
- On a fresh install, `Install::moduleInstall()` seeds `enabled_providers` with `Config::BUNDLED_PROVIDERS` (`Youtube`, `Facebook`, `Instagram`, `X`, `Reddit`) only. Sites that previously held only `disabled_providers` are migrated once by `Install::moduleUpdate()` via `enabled_providers = allKeys − disabled_providers`, and the `disabled_providers` key is removed.
- Skins live at `skins/{name}/card.blade.php` + `card.css` + `skin.xml`. `Config::getSkin()` falls back to `default` when the directory is missing.
- `CardRenderer::render()` does `\Context::set('oembed_card', …)` then `TemplateHandler::compile($skinPath, 'card')` — it compiles whichever exists, Blade or the XE template (`card.*`).

## Admin Form Data Model (a once-confusing point)

`procOembedAdminInsertConfig` handles both screens (`dispOembedAdminConfig` / `dispOembedAdminProviders`) under a single action. Both forms identify their origin via a hidden `<input name="screen" value="config|providers">`, and the handler branches on `Context::get('screen')`. (It used to branch on `Context::get('act')`, but at that point `act` is the handler's own name — no branch was ever true, so settings were silently ignored — issue #4.)

**The Provider screen stores the enabled list directly as a whitelist** (`config->enabled_providers`). Only providers checked in the form are eligible for paste matching. Dropping a new file into `providers/` does nothing until an operator explicitly enables it in the admin — this is intentional security policy (issue #3). The Registry-side enforcement is `Models\Registry::filterEnabled()` — when `enabled_providers` is empty, it returns `[]` and every paste match fails.

## Build / Test

This module has no separate build/test scripts. It does not use `vendor/` or `node_modules/` either (both pinned in .gitignore). Verification:
- PHP changes → recompile the Rhymix cache, or admin → oEmbed → Provider Management → Refresh Cache.
- JS/CSS changes → hard reload in the browser. `localStorage.setItem('oembed:debug', '1')` makes `_ckeditor.js` emit console debug logs.
- Reset failed-host cache → `sessionStorage.removeItem('oembed:failed_hosts')`, or open a new tab/session.

## Common Stuck Points

- **Delimiter collisions when editing PCRE patterns** — Standardize on `~`. With `#` you'll silent-miss whenever the URL contains a fragment / query because of `#` and `?`.
- **`failed_hosts` looking like a permanent ban** — TTL is 1 hour (commit `b3b44f8`). To clear immediately, delete the sessionStorage entry.
- **iframe clicks not working inside the CKEditor area** — Intentional. The editor-only rules in `tpl/css/style.css` block iframe interaction only inside the wysiwyg area (commit `c632b5c`). The selectors are scoped to `.rhymix_content.cke_editable, .cke_wysiwyg_div`, so they don't apply on the read page and playback there works normally.
- **Assets not injected because `act` is empty** — Make sure the `default_index_act` fallback in `EventHandlers::injectEditorAssets` is always traversed (commit `9bf9521`).
- **Body shows a bare `<div>` instead of a card/embed** — The `wrapped_html` from paste time is frozen as-is, so the `<iframe>` or card markup must be saved alongside it in the body. If the body is post-processed externally or the sanitizer drops child nodes, only the empty div is left. Also, if the iframe's host is not registered at System → Security → External Multimedia Allow, `MediaFilter` strips the iframe at the output stage.
- **Instagram/Facebook/X embeds appear in the body but don't activate** — In browser DevTools → Network, check whether `_render.js` loaded and whether a `<script>` with `data-oembed-sdk="..."` was dynamically added to `<head>`. The SDK is injected only when the selector returned by the provider's `getEmbedAssets()` matches the body DOM. If the body sanitizer strips that class, the selector doesn't match and the SDK is skipped — in that case a `normalize` rule must restore the class using a surviving data attribute as anchor. Historically the SDK was stored in the body as a `<script>` inside `buildEmbed()`, but HTMLPurifier removed it and the post failed to save outright — never put `<script>` back into the body.

## Other Editor Integrations

The integration guide for editors other than CKEditor 4 lives in `docs/editor-integration.md`. The essentials: call `procOembedFetch`, then insert `wrapped_html` into the body via the editor's safe-insertion API. The wrapped markup is stored as-is and rendered as-is — no server-side post-processing happens — so the integration side just needs to make sure the editor's sanitizer does not strip the inner `<iframe>` / card nodes.
