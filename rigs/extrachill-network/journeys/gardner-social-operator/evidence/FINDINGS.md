# Gardner Studio social-operator journey — findings (extrachill-network#293)

Run against a real, disposable 11-site network boot (`node
rigs/extrachill-network/run.mjs`, `extrachill_journeys: ["gardner-social-operator"]`),
every component from its owning repo's latest release except
`extrachill-network` itself (this branch). The journey reaches a real,
specific blocker before completing; see below.

## Two real bugs found and fixed during this port

1. **The canonical article must live on the real main site.** The seed
   originally created the "canonical Extra Chill article" on
   `studio.extrachill.com`'s own database (matching the old single-plugin
   sandbox, whose `wp core multisite-convert` bootstrap made "studio" and
   "main" the same blog). On the real network, Studio's own
   `social_source_attribution()` (`inc/social-drafts.php`) validates a
   draft's declared source by switching to `ec_get_blog_id('main')` and
   comparing the ACTUAL post's permalink there -- it always fails closed
   (`social_publish_attribution_invalid`) unless the article genuinely
   lives on the real main site. Fixed: the article (+ its own featured-image
   media) now seeds on `extrachill.com`; the Studio draft's own media stays
   on studio's own library, matching a real operator's own composer.
   `attribution_post.site_id` must also be the article's real site, not
   `get_current_blog_id()` (studio, wherever the delegated job executes);
   `SocialShareTracker::get_shares()`/`count_shares()` read postmeta on the
   article's own site too (shares are recorded there via
   `DelegatedCrossPostAction::with_site()`).
2. **A diagnostic-call bug of my own** (fixed before it reached a PR):
   calling `datamachine/submit-delegated-operation` directly for raw-error
   diagnostics with the ALREADY-`normalize_input()`-processed array (which
   `normalize_input()` itself adds a `post_site_id` key to) tripped
   `normalize_input()`'s own "the canonical post site is owner-controlled"
   guard on re-entry. Not a signal about the real
   `enqueue_social_publish()`/`retry-social-publish` path, which never
   pre-normalizes its own input this way.

## Blocking finding: filed upstream, not worked around

**[Extra-Chill/data-machine#3553](https://github.com/Extra-Chill/data-machine/issues/3553)**
-- a brand-new delegated operation reliably fails with
`delegated_operation_persist_failed` ("The delegated operation could not be
persisted.") in this sandbox. Traced to `DelegatedOperationService::persistJobReference()`
→ `EngineData::mutate()` → `Jobs::compare_and_swap_engine_data()`, which does
an exact-string `WHERE engine_data = <freshly re-encoded JSON>` compare-and-swap
against the stored `engine_data` column. On a genuinely fresh job (created in
the same request, no concurrent writer possible), this CAS never matches here,
exhausting all 3 retry attempts. Action Scheduler is confirmed loaded (ruled
out); the ability chain resolves correctly through job creation and loading
(only the CAS-based reference write fails). Root cause (a genuine
encode/decode round-trip instability that would also affect production, vs.
a SQLite-vs-MySQL text-column behavior difference specific to the WP Codebox
Playground sandbox) is unconfirmed and needs someone with real-MySQL
visibility -- filed with full reproduction detail rather than guessed at
further here.

This blocks the deterministic scenario matrix downstream of the first
delegated submission: partial delivery, safe retry, final share history,
and the Instagram comments read/reply checks are all unexercised in this
run as a direct consequence, not as separate findings.

## What passed cleanly before the block

- Canonical team/grant capability fixture (Gardner: `manage_brand_socials` +
  `access_studio`; ordinary team user: neither) is valid.
- The real Data Machine execution-owner agent bootstrap
  (`DirectoryManager::get_default_agent_user_id()`, `Agents::create_if_missing()`)
  works.
- Instagram/Bluesky fixture account config persists via the real
  `InstagramAuth`/`BlueskyAuth` classes.
- Ordinary team users are denied at both the custom REST
  (`/datamachine/v1/socials/post`, 403) and durable-ability
  (`datamachine/enqueue-social-publish`'s `check_permissions()`) boundaries;
  Gardner is granted at both.
- WordPress Core's own future-post scheduling
  (`wp_update_post()` + `future` status) and the `publish_future_post` →
  `publish` due-cron transition both work exactly as production does, with
  zero provider effects recorded before the due time (verified against the
  real `pre_http_request`-based provider stub).

## Not ported: the adaptive/adversarial-exploration campaign and typed artifact ledgers

See the journey's own `README.md` "What did not carry over, and why" for
the two explicit, named, reasoned scope boundaries (the old recipe's
adversarial-campaign extension, and its typed `wp_upload_dir()` artifact
ledgers) -- neither is a silently dropped capability.
