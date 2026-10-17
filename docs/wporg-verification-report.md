# WP.org review verification — `plume` (Review ID `R plume/niklasjohansson/22Jun26/T3 22Aug26/4.2`)

Verified 2026-10-17 against the published release asset. Read-only: no plugin code was changed.

## 1. Verdict

**NOT READY.** The release is `plume-1.13.3.zip`, version `1.13.3`, SHA-256 `65a4e4b289e12041a535aac337fc6bf6726c336bda17441b509b7a93a98db3dc`. All four reviewer issues are fixed in this zip. One issue of the same kind as R2-1 is still open (finding 3.1): a Contributor can read other users' drafts and private posts through chat. It is a one-line fix and needs a new patch release before resubmitting.

How the candidate was chosen:
- `origin/main` (`08cf88e`) has only `chore` commits since `v1.13.3`. Under `.releaserc.json` those cut no release, so 1.13.3 is the newest release.
- The asset is from GitHub Release `v1.13.3`. Its SHA-256 matches the digest GitHub reports.
- `plume.php` `Version`, `PLUME_VERSION` and the `readme.txt` `Stable tag` all read `1.13.3`.
- In the zip, `includes/`, `plume.php`, `readme.txt`, `uninstall.php` and `assets/` are byte-identical to tag `v1.13.3` (`diff -rq`). `includes/` on `main` is also identical to the tag. Only rebuilt JS/CSS bundles differ, from dependency bumps.
- If the zip were rebuilt locally from `main`, it would have the same filename but a different hash. **Upload the GitHub Release asset, not a local build.**
- PR #972 is open, not merged.

## 2. Issue table

| ID | Reviewers' claim | Status | Evidence | How verified |
|---|---|---|---|---|
| R3-1 | `/plans/{id}/execute` lets `edit_posts` alone publish, or create/update posts of other types | **Fixed** | `includes/Modules/Chat/PlansRestController.php:57`: the route uses `check_execute_permission`. Lines 225–274 load the plan and check `create_posts` of the post type (via `PostTypeCaps::resolve`, `includes/Tools/PostTypeCaps.php:34`), `edit_post` on the target for updates, and the type's `publish_posts` when the final status is `publish`. The write path re-checks the same: `includes/Tools/PostWriter.php:58–71` (create) and `:85` (publish), and `:146` (`edit_post`) and `:171` (publish/private) for updates. Commit `73037f0` (#955), released in 1.13.2. | Zip inspection and code reading. PHPUnit: 12 existing `PlansRestControllerTest` cases plus 4 `PostWriterTest` cases pass. 5 ad-hoc cases were run in a scratch copy and not committed (see below). |
| R2-1 | `ChatRestController::get_messages()` has no conversation-ownership check | **Fixed** | `includes/Modules/Chat/ChatRestController.php:259–262` returns 403 unless the caller owns the conversation. Every other conversation route also checks ownership: `get_pending_plan` `:284`, `send_message` `:351`, `update_conversation` `:657`, and `delete_conversation` `:685` (owner or `manage_options`). Commit `137eff1` (#949). | Zip inspection. PHPUnit tests `get_messages_returns_403_when_conversation_not_owned` and `..._when_owned` pass. |
| R2-2 | `/images/generate` must require `upload_files` | **Fixed** | `includes/Modules/Images/ImagesModule.php:106–108` uses `current_user_can( 'upload_files' )`. Commit `137eff1` (#949). | Zip inspection and code reading. No unit test asserts this capability. |
| R2-3 | Unprefixed global `njDevTools`, and any other unprefixed global | **Fixed** | Renamed in `511b439` (#951), then all dev tools removed in `9d75e67` (#959). `DevToolsPage.php` is not in the zip. `njDevTools` and `DevTools` appear only in the zip's `CHANGELOG.md` and nowhere in its code. The PHPCS `PrefixAllGlobals` sniff (prefix `plume`) reports 0 issues on the unzipped plugin, excluding `vendor/`. Every PHP file is in the `Plume\` namespace. Constants are `PLUME_*`; options, transients and user meta are `plume_*`; script handles are `plume-*`; localised JS globals are `plumeData` and `plumeDashboard`; `localStorage` keys are `plume_*`. | Zip grep, PHPCS sniff, manual sweep. |

R3-1 cases. All checked against `check_execute_permission`; "existing" means a committed test, "ad-hoc" means one I ran in a scratch copy.

| Case | Expected | Actual |
|---|---|---|
| Contributor, create, `status=publish` | 403 `rest_cannot_publish` | 403 `rest_cannot_publish` (existing test, for stored status and for request override) |
| Contributor, create, `post_type=page` | 403 | 403 `rest_forbidden` (existing test with `publish_posts` held; ad-hoc test with draft) |
| Author (`edit_posts` + `publish_posts`), create and publish `post` | allowed | `true` (existing `allows_an_editor` test grants exactly the Author caps for posts) |
| Update on another user's post (no `edit_post`) | 403 | 403 (existing test asserts `edit_post` is checked against the target ID) |
| Update with `status=publish` override | publish cap enforced | 403 `rest_cannot_publish` without it (existing); `true` with it (ad-hoc) |
| Expired or missing plan | 404 from the handler, not 403 | callback returns `true`, then `execute_plan` returns 404 (existing tests). An update plan whose post was deleted also returns `true`, and `PostWriter` then returns 422 (ad-hoc). |
| Unregistered or custom post type | handled | Unregistered type: 403 (ad-hoc). Custom types use that type's own `cap->create_posts` / `publish_posts`. `PostWriter::create` also requires the type to be in `plume_allowed_post_types` (`PostWriter.php:58`). |
| Contributor, `status=pending` | allowed | `true` (ad-hoc) |

Other write paths:
- Chat tool calls (`ToolExecutor::execute`, `includes/Tools/ToolExecutor.php:45–63`) never create or publish posts. `plan_post` and `plan_update` only store a plan transient (`:276`, `:340`, `:496–500`). The only way to write a post is `/plans/{id}/execute`, followed by `PostWriter`.
- The `generate_seo_meta` tool writes post meta only after `edit_post` (`:527`).
- `ToolExecutor::clamp_plan_status` (`:445`) downgrades `publish` to `pending` for users who cannot publish.

## 3. Other findings (most likely to be flagged first)

**3.1 Contributor can read other users' drafts and private posts via the chat tool `get_recent_posts`.** Likelihood: high. This is the "similar occurrence" of R2-1. Confirmed on WordPress 6.8.3 running the zipped plugin:
- I made an Editor's draft and an Editor's private post, then called `get_recent_posts` as a Contributor with `status` set to `draft`, `private` and `any`.
- Each call returned the title, status and a 100-word excerpt of the Editor's posts, although core `current_user_can( 'read_post', … )` is `false` for both.
- Cause, in `includes/Tools/ToolExecutor.php:88–98`: the model-supplied `status` (free-text in `ToolRegistry.php:144–148`) goes straight to `WP_Query`. An explicit `post_status` skips WP_Query's read checks.
- Proposed fix, in the loop at `ToolExecutor.php:101`. Tested: after it, the Contributor sees only their own draft.

```php
foreach ( $query->posts as $post ) {
	// An explicit post_status bypasses WP_Query's read checks, so filter per post.
	if ( ! \user_can( $user_id, 'read_post', $post->ID ) ) {
		continue;
	}
	$posts[] = [
```

**3.2 `get_post_content` uses its own read rule instead of `read_post`.** Likelihood: low. At `includes/Tools/ToolExecutor.php:135–141`:
- Non-published posts are allowed when the caller is the author or has `edit_others_posts` (the capability for posts, not the target type's).
- Published, password-protected posts are returned in full to any `edit_posts` user.
- Fix: replace the block with `if ( ! \user_can( $user_id, 'read_post', $post_id ) || ( post_password_required( $post ) && ! \user_can( $user_id, 'edit_post', $post_id ) ) ) { return [ 'error' => 'Not authorised to read this post.' ]; }`

**3.3 SEO apply writes alt text to the featured image without checking the attachment.** Likelihood: low. `includes/Modules/Seo/SeoModule.php:368–372` updates `_wp_attachment_image_alt` on the thumbnail after checking `edit_post` on the parent post only. Fix: wrap it in `if ( $thumb_id && \current_user_can( 'edit_post', $thumb_id ) )`. The chat path should use `user_can( $user_id, … )`.

**3.4 Unprefixed variable in `uninstall.php`, hidden by `phpcs:ignore`.** Likelihood: low. At `uninstall.php:21`, `$prefix` is function-scoped at runtime, because `uninstall_plugin()` includes the file. A grep-based review may still flag it. Fix: rename it to `$plume_option_like` (lines 21, 22, 25) and drop the ignore.

**3.5 Hidden files in `vendor/`.** Likelihood: low. Plugin Check did not flag them. Affected files: `vendor/psr/event-dispatcher/.gitignore`, `vendor/psr/event-dispatcher/.editorconfig`, `vendor/nette/utils/.phpstorm.meta.php` and `vendor/league/commonmark/.phpstorm.meta.php`. Fix: add `--exclude='.*'` before the includes in `bin/build-wporg.sh`'s `rsync`.

Route sweep: everything else is proportionate. There are no `wp_register_ability` or `wp_ajax_*` handlers.

| Route / handler | Permission | Handler does | Proportionate? |
|---|---|---|---|
| `GET/POST /conversations`, `GET/POST /conversations/{id}/messages`, `GET …/pending-plan`, `PATCH/DELETE /conversations/{id}` | `edit_posts` + per-conversation owner check in handler | read/write own chat; POST messages calls paid AI | Yes |
| `GET /providers`, `GET /search-posts` | `edit_posts` | provider list; `publish`-only titles | Yes |
| `POST /plans/{id}/execute` | `check_execute_permission` (see R3-1) | create/update post | Yes |
| `DELETE /plans/{id}` | `edit_posts`; key scoped to current user | delete own transient | Yes |
| `POST /generate` (`GeneratorModule.php:90`) | `edit_posts` | paid AI call; creates a **draft `post`** only (`:178–187`), never publishes | Yes (`edit_posts` = `post`'s `create_posts`) |
| `POST /seo/generate`, `/seo/apply` | `edit_post` on `post_id` | paid AI call; writes excerpt and SEO meta | Yes (see 3.3) |
| `POST /images/generate` | `upload_files` | paid AI call; creates attachment | Yes |
| `GET/POST /settings`, `POST /onboarding`, `POST /test-key`, `GET /usage` | `manage_options` | site settings, API keys, usage | Yes |
| `POST /tier-update` | `__return_true`; authenticated in `TierUpdateWebhookController::handle()` by HMAC-SHA256 over `timestamp.body`, using the per-site secret `plume_tier_sync_secret`, `hash_equals`, ±skew window, replay transient, 1 KiB cap | writes tier option | Yes |
| `GET /activation-verify` | `__return_true`; `ActivationVerifyRestController::handle()` requires a 64-hex challenge that matches a 300 s transient set by `SiteRegistration`, consumed once | returns `verified: true` and deletes transient; no data exposed | Yes |
| `admin_post_*` (`SiteUnreachableNotice.php:224–228`, `TierSyncBackfillNotice.php:287–291`) | `manage_options` + `check_admin_referer` | retry verification / rotate secret | Yes |

Checks run:
- **PHPCS** (`phpcs.xml.dist`, PHPCS 3.13.6 / WPCS 3.4.1): 0 errors, 0 warnings.
- **PHPStan** 1.12.33 at level 5 with the baseline: no errors.
- **PHPUnit** (Unit suite): 535 tests and 1,215 assertions pass. There is 1 PHP warning, from a test stub missing `post_excerpt` at `SeoModule.php:222`; it is test-only.
- **Plugin Check** 2.1.0 on the unzipped release: 0 errors and 6 warnings. All 6 are `AIProvider.DirectIntegration` (advisory, suggesting `wp_ai_client_prompt()`), in `TestKeyRestController.php` and the three providers.

## 4. Is #972 relevant?

No. It changes only `src/admin` JS/CSS, rebuilt `assets/admin/*` (including the generated `index.asset.php`) and JS tests. It touches no PHP route, capability or global, and it is unmerged.

## 5. Reply facts

1. `/plans/{id}/execute` now checks the target post type's `create_posts`, `edit_post` on the target post for updates, and the post type's `publish_posts` before any `publish` status. `PostWriter` repeats these checks before writing.
2. `get_messages()`, and every other conversation-scoped route, returns 403 unless the current user owns the conversation.
3. `/images/generate` requires `upload_files`.
4. The developer tools, including the `njDevTools` global, have been removed. The PHPCS `PrefixAllGlobals` sniff reports no unprefixed globals in the submitted zip.
5. The two public routes (`/tier-update`, `/activation-verify`) are authenticated in their handlers: an HMAC signature with a timestamp and replay check for the first, and a single-use 256-bit challenge for the second.

(After 3.1 is fixed, add: "Chat read tools now filter results by `read_post`.")

## 6. Not verified

- No end-to-end HTTP requests through REST with real user sessions; R3-1 was checked with unit tests calling the permission callback. The 3.1 probe called `ToolExecutor` directly through WP-CLI on a real WordPress install.
- No integration suites (`phpunit-integration.xml.dist`, `phpunit-real-integration.xml.dist`) and no JS/Playwright tests.
- The Worker side of the HMAC and activation flows (`plume-proxy/`) was not checked.
- Plugin Check ran on WordPress 6.8.3 with SQLite, not on 7.x.
- The zip was not rebuilt in CI conditions. I used the published asset and confirmed it matches the tag.
- Tool setup differed from the repo's: `phpstan/phpstan` was used as the official 1.12.33 phar (Composer's GitHub zipball download is blocked here), and the other dev dependencies were installed from the `composer.lock` versions.
- The `readme.txt` external-services disclosure was not reviewed; the reviewers did not raise it.
