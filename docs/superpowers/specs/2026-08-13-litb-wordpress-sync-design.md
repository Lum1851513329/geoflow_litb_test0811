# LITB WordPress Sync Design

## Goal

Publish GEOFlow's LITB blog articles to a self-hosted WordPress site (currently
a test instance at `http://47.103.117.122`) on an ongoing basis, and make
GEOFlow's own article pages declare that WordPress copy as the canonical URL
once it exists, to avoid duplicate-content SEO penalties across the two
public copies of the same content.

## Background

GEOFlow already has a full "article distribution" subsystem
(`App\Services\GeoFlow\DistributionOrchestrator`, `App\Jobs\ProcessArticleDistributionJob`,
`App\Services\GeoFlow\WordPressRestPublisher`, `App\Models\ArticleDistribution`,
`App\Models\DistributionChannel`, `App\Models\DistributionChannelSecret`) with a
working admin UI (`/geo_admin/distribution`) for creating/editing channels,
including a WordPress REST channel type that authenticates via Application
Passwords and pushes to `/wp-json/wp/v2/posts`.

However, this subsystem's entry point — `DistributionOrchestrator::enqueueForArticle()`
— is hard-gated on the article having a `task_id`:

```php
if (! $articleModel || ! $articleModel->task_id) {
    return;
}
```

All 69 currently-published LITB articles have `task_id = NULL` — they were
generated through the LITB quarterly-brief pipeline
(`App\Services\GeoFlow\LitbAssets\*`), which is unrelated to the older
`Task`-based generation flow that `task_id` and `distributionChannels` (a
`Task` pivot) belong to. So the existing "automatic" distribution pipeline has
never actually applied to any LITB article, and forcing LITB articles onto the
`Task` table (which carries fields like `title_library_id`, `prompt_id`,
`ai_model_id` that don't apply to LITB's generation flow) would be a poor fit.

This design adds a second, `Task`-independent entry point into the same
distribution machinery, plus a scheduled command to drive it, plus the
canonical-URL wiring on the public article page.

## Scope

In scope:
- A new `DistributionOrchestrator` method that enqueues a specific article to
  a specific channel without requiring `task_id`.
- A new artisan command that syncs all published, `task_id`-less articles to
  active WordPress channels, safe to run repeatedly (backfill + ongoing).
- A scheduler entry so new/updated articles get picked up automatically.
- `Article::wordpressCanonicalUrl()` and the one-line change in
  `ArticleController` that uses it.
- Manually creating the `DistributionChannel` (+ secret) for the test
  WordPress site via the existing admin flow's underlying data model (done as
  an operational step after this code ships, not part of the plan's tasks).

Out of scope:
- Changing how `WordPressRestPublisher`, `ProcessArticleDistributionJob`, or
  the admin distribution UI work — all reused as-is.
- Any change to the `Task`-based distribution flow or its existing tests.
- WordPress.com OAuth (dropped earlier in favor of the self-hosted test site).
- Anything about the LITB generation pipeline itself.

## Component 1: `DistributionOrchestrator::enqueueForArticleToChannel()`

New public method:

```php
public function enqueueForArticleToChannel(int|Article $article, DistributionChannel $channel, string $action = 'publish'): void
```

Behavior:
- Resolves `$article` to an `Article` model (same pattern as `enqueueForArticle`).
- Returns silently (no exception) if the article doesn't exist, or its status
  isn't `published` (mirrors `enqueueForArticle`'s `$canDistribute` check, but
  without the `publish_scope` logic since that's a `Task` concept — this path
  has no task).
- Returns silently if `$channel->status !== DistributionChannel::STATUS_ACTIVE`.
- Otherwise builds the payload and creates/updates the `ArticleDistribution`
  row and dispatches `ProcessArticleDistributionJob`, using the *same* logic
  `enqueueForArticle` uses per-channel today (the per-channel transaction
  block: lock the channel row, skip if an existing distribution row is
  currently `sending`, upsert the row to `status = queued` with a fresh
  `payload_hash`/`idempotency_key`, dispatch the job after commit).

Implementation approach: extract that per-channel transaction block out of
`enqueueForArticle`'s `foreach ($channels as $channel)` loop into a new
private method:

```php
private function distributeToChannel(Article $articleModel, DistributionChannel $channel, string $action, array $payload, string $payloadHash): void
```

`enqueueForArticle` calls it once per channel exactly as before (no behavior
change, existing tests must still pass unmodified). `enqueueForArticleToChannel`
builds its own payload the same way `enqueueForArticle` does
(`$this->payloadBuilder->build()` for delete, `$this->buildVerifiedPayload()`
otherwise) and then calls `distributeToChannel()` once, for the one channel
passed in.

Errors are caught and logged the same way `enqueueForArticle` does today
(wrap the body in try/catch, log via `$this->log(...)`), so a single failing
article doesn't take down a batch loop in the sync command.

## Component 2: `geoflow:distribution:sync-channel` command

New command `app/Console/Commands/SyncArticlesToDistributionChannelCommand.php`.

Signature: `geoflow:distribution:sync-channel {channel? : Specific channel ID; omit to sync all active WordPress REST channels}`

Behavior:
- Resolves the target channel(s): the given channel ID (must be
  `status = active`), or, if omitted, all `DistributionChannel` rows with
  `status = active` and `channel_type = wordpress_rest`.
- If no channels resolve, print a message and exit `0` (not an error — this
  is expected before any channel is configured).
- For each resolved channel, iterate published, task-less articles that have
  **no existing `ArticleDistribution` row at all for that channel** (any
  status), and call `$orchestrator->enqueueForArticleToChannel($article, $channel)`
  for each. This is the corrected behavior after an incident during rollout
  (see below) — the command only ever enqueues an article to a channel once;
  it does not re-publish already-synced articles.
- Print a summary: channels processed, articles considered, articles enqueued.

**Incident during rollout, and why the design changed:** the first version of
this command re-enqueued *every* published, task-less article on *every* run,
regardless of prior sync status. `WordPressRestPublisher::publish()` always
`POST`s to `/wp/v2/posts` (create) unconditionally — it has no "does this
already exist remotely" check, unlike `::update()` (which requires the
distribution row to already carry a `remote_id`, and falls back to `publish()`
if it doesn't). Because the scheduler was live and fired every 5 minutes
during rollout, this produced duplicate WordPress posts per article before
the bug was caught and fixed (cleaned up after the fact). The corrected
design — only enqueue articles with no distribution history for the channel —
sidesteps the create-vs-update ambiguity entirely: an article is sent to a
given channel exactly once, ever, by this command. A future iteration that
wants to *update* an already-synced article's remote copy after a content
edit would need to explicitly pass `action = 'update'`, which is out of scope
here (no LITB content is edited after publishing today).

## Component 3: Scheduler entry

In `routes/console.php`, alongside the existing `Schedule::command('geoflow:schedule-tasks')->everyMinute()`:

```php
Schedule::command('geoflow:distribution:sync-channel')->everyFiveMinutes();
```

No channel ID given — always targets "all active WordPress REST channels",
so adding a second WordPress channel later needs no scheduler change.

## Component 4: Canonical URL

New method on `App\Models\Article`:

```php
public function wordpressCanonicalUrl(): ?string
{
    $remoteUrl = $this->syncedRemoteDistributions()
        ->whereHas('channel', fn ($query) => $query->where('channel_type', 'wordpress_rest'))
        ->value('remote_url');

    return $remoteUrl !== null ? (string) $remoteUrl : null;
}
```

(`syncedRemoteDistributions()` already exists, already filters to
`status = synced`, non-empty `http(s)://` `remote_url`, ordered by
`updated_at` desc — this just adds the `wordpress_rest` channel-type filter
and takes the newest match.)

`ArticleDistribution::channel()` (`BelongsTo`, `app/Models/ArticleDistribution.php:56`)
already exists and is used for the `whereHas` above — no new relation needed.

`App\Http\Controllers\Site\ArticleController.php` line 83 changes from:

```php
'canonicalUrl' => route('site.article', $article->slug),
```

to:

```php
'canonicalUrl' => $article->wordpressCanonicalUrl() ?? route('site.article', $article->slug),
```

No other site controllers change — only the article detail page has a
WordPress-synced counterpart.

## Data Flow

1. Operator creates a `DistributionChannel` (`channel_type = wordpress_rest`,
   `endpoint_url = http://47.103.117.122`, `status = active`) and its
   `DistributionChannelSecret` (WordPress username + encrypted Application
   Password) — via the existing admin UI or an equivalent one-off script
   using the same models/encryption helper. This is an operational step, not
   a plan task.
2. Scheduler fires `geoflow:distribution:sync-channel` every 5 minutes.
3. For each active `wordpress_rest` channel, for each published/`task_id`-less
   article, `enqueueForArticleToChannel()` creates/updates an
   `ArticleDistribution` row and dispatches `ProcessArticleDistributionJob`.
4. The job (existing, unmodified) calls `WordPressRestPublisher::publish()`
   or `::update()`, which hits the WordPress REST API and, on success, sets
   the distribution row's `status = synced`, `remote_id`, `remote_url`.
5. Next time the article's page is requested, `ArticleController` finds the
   synced distribution and renders `<link rel="canonical">` pointing at the
   WordPress URL instead of GEOFlow's own.

## Testing

- Unit test for `DistributionOrchestrator::enqueueForArticleToChannel()`:
  published article + active channel → `ArticleDistribution` row created,
  job dispatched; non-published article → no-op; inactive channel → no-op.
  Existing `enqueueForArticle` tests must still pass unmodified (proves the
  extraction didn't change behavior).
- Feature test for `SyncArticlesToDistributionChannelCommand`: seeds a mix of
  published/draft articles with and without `task_id`, one active and one
  paused `wordpress_rest` channel; asserts only published + task-less
  articles against the active channel get `ArticleDistribution` rows.
- Unit test for `Article::wordpressCanonicalUrl()`: no distribution → null;
  synced `wordpress_rest` distribution → its `remote_url`; synced
  non-`wordpress_rest` distribution → null.
- Feature test for `ArticleController`: page renders self URL as canonical
  when no synced WordPress distribution exists, and the WordPress URL when
  one does.

## Global Constraints

- Do not modify `enqueueForArticle`'s existing behavior or its callers.
- Do not touch `docker-compose.yml`.
- Reuse `WordPressRestPublisher`, `ProcessArticleDistributionJob`,
  `DistributionPayloadBuilder` as-is — no changes to those files.
- New command must be safe to run repeatedly (idempotent in the sense of "no
  crashes, no duplicate `ArticleDistribution` rows per article+channel+action" —
  see the accepted re-send limitation above).
