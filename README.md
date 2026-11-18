# feed-item-brief

Written from scratch; not derived from any third-party plugin.

A WordPress plugin that turns one feed item into a short summary draft for
an editor, with the source link, byline and publish time kept in an
attribution block the plugin renders itself. It works with any importer
through its own hook, with WordPress core feeds through a reference
adapter, or with no importer at all, and it refuses to summarise paywalled
stubs, empty items, sponsored posts and stale news.

This project came out of the work on indianopinion.org and is written from
scratch; it is independent of and not affiliated with any third-party
plugin it integrates with.

Measured in production: about one generated summary in four ran past the
stated length ceiling, and an instruction to count words was printed back
into published copy. This plugin never asks the model to count, strips
self-narration lines, checks length in code and retries instead of cutting.

## Contents

- [Install](#install)
- [Quick start](#quick-start)
- [Configuration](#configuration)
- [Hooks](#hooks)
- [How it works](#how-it-works)
- [Is it working?](#is-it-working)
- [Limitations](#limitations)
- [FAQ](#faq)
- [Licence](#licence)

## Install

WordPress 6.4 or later, PHP 8.1 or later, with the dom, json and mbstring
extensions.

With Composer, from the plugin folder or a Composer-managed site:

```sh
composer config repositories.feed-item-brief vcs https://github.com/IndianOpinion/feed-item-brief
composer config repositories.llm-json-php vcs https://github.com/IndianOpinion/llm-json-php
composer require indianopinion/feed-item-brief
```

Neither package is on Packagist yet, so both are `vcs` repositories. In a
clone of this repository, `composer install` is enough. The plugin loads
`vendor/autoload.php` when it is there.

Without Composer:

1. Copy this repository (or a release archive) to
   `wp-content/plugins/feed-item-brief/`. The plugin then uses its own
   autoloader in `src/autoload.php`.
2. For model calls, copy
   [llm-json-php](https://github.com/IndianOpinion/llm-json-php) to
   `wp-content/plugins/feed-item-brief/lib/llm-json-php/`. The plugin
   requires its `bootstrap.php` when present. Skip this step if your site
   supplies its own summariser (see the FAQ).
3. Activate "Feed Item Brief".

As a must-use plugin: put the folder at
`wp-content/mu-plugins/feed-item-brief/` and copy
[`examples/mu-plugin-loader.php`](examples/mu-plugin-loader.php) to
`wp-content/mu-plugins/feed-item-brief-loader.php`. Tables are created on
the first load; cron events on the next `init`.

## Quick start

1. Put the key in `wp-config.php` (preferred over the settings screen):

   ```php
   define('FEED_ITEM_BRIEF_API_KEY', 'your-provider-key');
   define('FEED_ITEM_BRIEF_MODEL', 'provider/model-name');
   ```

2. Settings > Feed Item Brief: add a feed (one per line,
   `URL | source name | author user id | time zone | enabled`), tick
   "Enabled" and save. The switch stays off until a model and key are set,
   or a summariser is hooked in.
3. Wait for WP-Cron, or run it now:

   ```sh
   wp feed-item-brief run
   wp feed-item-brief log
   ```

4. Open the drafts. Each one has a "Source review" panel with the source,
   the byline, the date and why that date was chosen, and any flags.

To brief one article by hand: Posts > Brief from URL, or
`wp feed-item-brief make https://example.org/story --title="..." --body-file=story.html`.

From your own importer:

```php
do_action('feed_item_brief_submit', [
    'url' => $link,
    'title' => $title,
    'body' => $html,
    'source_name' => 'Example News',
    'byline' => ['Jane Roe'],
    'published' => '2026-11-14T09:00:00Z',
    'guid' => $id,
], 'my-importer');
```

Register `my-importer` as an adapter first; see
[`examples/custom-adapter.php`](examples/custom-adapter.php). The call
records the item and returns at once: no model call, no page fetch and no
post insert happens in the importer's request.

## Configuration

Settings > Feed Item Brief. Constants win over settings and the screen says
which values come from constants.

| Setting | Default | Notes |
|---|---|---|
| Enabled | off | The kill switch. `FEED_ITEM_BRIEF_DISABLED` set to true wins. Read at the moment of every model call. |
| Provider | OpenRouter | OpenRouter, OpenAI, or any chat completions endpoint (with a base URL). |
| Model | empty | You pick. `FEED_ITEM_BRIEF_MODEL` wins. The plugin ships no model choice. |
| API key | none | Write-only field. `FEED_ITEM_BRIEF_API_KEY` is preferred. Never printed or logged. |
| Daily cap | 25 calls | Model calls per site-local day, retries included. Link-only drafts do not count. 0 means no calls. `FEED_ITEM_BRIEF_DAILY_CAP` wins. |
| Post status | draft | Or pending. Never publish or future. |
| Author | first administrator at activation | Per feed override in the feed list. |
| Title | model headline | Or the original title. |
| Paywalled items | link-only draft | Or summarise the public excerpt (40 words or more), or skip. |
| Items with no body | skip | Or link-only draft. |
| Fetch source pages | off | One request per item for page dates, titles and paywall signals. |
| Maximum age | 72 hours | Older items are not summarised. 0 means no limit. |
| Model timeout | 45 seconds | |
| Disclosure line | on | "This summary was drafted with the help of software and checked by an editor." Editable. |
| Use gen-queue | on when present | Shown only when the gen-queue plugin is active. |
| Sponsored phrases, paywall phrases | built-in lists | One phrase per line. Filters apply on top. |
| Error log | off | One line per final status, prefixed `[feed-item-brief]`. |
| Feeds | none | Up to 200. Ten feeds per run in rotation, ten items per feed. |

## Hooks

| Hook | Type | Purpose |
|---|---|---|
| `feed_item_brief_submit` | action `(array $item, string $adapterId)` | Submit one item. |
| `feed_item_brief_submit_batch` | action `(array $items, string $adapterId, string $sourceKey)` | Submit the items of one fetch together, so a feed that stamps every item with its build time is caught. |
| `feed_item_brief_register_adapters` | action `(Registry $registry)` | `$registry->add(new MyAdapter())`. |
| `feed_item_brief_resolve_url` | filter `(string $url, Item $item)` | Unwrap aggregator or redirect links. Ignored unless the result is a valid http(s) URL. |
| `feed_item_brief_tracking_params` | filter `(string[] $names)` | Query arguments dropped from the article key. |
| `feed_item_brief_fetch_body` | filter `(?string $html, Item $item)` | Attach an extractor for items with no body. Asked once. |
| `feed_item_brief_classify` | filter `(BodyClass $class, Item $item)` | Last word on classification. |
| `feed_item_brief_sponsored_phrases`, `feed_item_brief_paywall_phrases` | filter `(string[])` | Phrase lists. |
| `feed_item_brief_not_article_patterns` | filter `(string[] $regexes)` | URL path patterns for video, gallery, tag, author and search pages. |
| `feed_item_brief_not_article_types` | filter `(string[] $types)` | JSON-LD types that mark a page as not an article. |
| `feed_item_brief_min_words` | filter `(int)` | Below this, an item has no body. Default 15. |
| `feed_item_brief_max_age` | filter `(int $seconds)` | Default 259200. |
| `feed_item_brief_future_tolerance` | filter `(int $seconds)` | How far ahead a claimed time may be. Default 600. |
| `feed_item_brief_summarise` | filter `(?array $result, array $request, Item $item)` | Plug in your own summariser. Return the answer array, a `WP_Error`, or null to decline. |
| `feed_item_brief_prompt` | filter `(array $messages, Item $item, string $mode)` | Replace or adjust the prompt. |
| `feed_item_brief_max_source_chars` | filter `(int)` | Source text sent to the model. Default 12000. |
| `feed_item_brief_length` | filter `(array{min,max} $range, string $mode)` | Word targets for `full`, `short`, `teaser`. |
| `feed_item_brief_banned_lines` | filter `(string[])` | Line prefixes removed from output. |
| `feed_item_brief_single_source_phrases` | filter `(string[], string $lang)` | Phrases that imply several sources. |
| `feed_item_brief_relative_time_words` | filter `(string[], string $lang)` | Words flagged in the review panel. |
| `feed_item_brief_number_words` | filter `(array $lists)` | Number words per language for the number check. English ships. |
| `feed_item_brief_post_args` | filter `(array $args, Record $record)` | Adjust the insert. The status is forced back to draft or pending. |
| `feed_item_brief_attribution_html` | filter `(string $html, Attribution $a)` | Restyle the block. Must keep a link to the source URL whose text contains the source name, or it is ignored. |
| `feed_item_brief_disclosure_text` | filter `(string)` | Disclosure line. |
| `feed_item_brief_canonical` | filter `(bool, int $postId, Attribution $a)` | Return true to print `<link rel="canonical">` to the source. Off by default. |
| `feed_item_brief_drafted` | action `(int $postId, Record $record)` | After a draft is written. |
| `feed_item_brief_skipped` | action `(Record $record, string $reason)` | After an item ends without a draft. |
| `feed_item_brief_enabled` | filter `(bool)` | Can only turn the plugin off. |
| `feed_item_brief_capability`, `feed_item_brief_make_capability` | filter `(string)` | Default `manage_options` and `edit_others_posts`. |
| `feed_item_brief_batch_size`, `feed_item_brief_run_budget`, `feed_item_brief_cron_interval` | filter | Queue run: 5 items, 20 seconds, every 5 minutes. |
| `feed_item_brief_feed_interval`, `feed_item_brief_feeds_per_run`, `feed_item_brief_items_per_feed` | filter | Core feeds: every 30 minutes, 10 feeds, 10 items. |
| `feed_item_brief_body_retention_days`, `feed_item_brief_row_retention_days` | filter `(int)` | Default 7 and 90. |

Functions: `feed_item_brief_submit()`, `feed_item_brief_is_enabled()`,
`feed_item_brief_calls_today()`, `feed_item_brief_cap()`,
`feed_item_brief_attribution($postId)`.

WP-CLI: `wp feed-item-brief status | run [--limit=<n>] | make <url> [...] |
log [--status=] [--since=] [--format=table|json] | retry <id> | check <url>`.

## How it works

1. **Intake.** An item arrives through the hook, the batch hook, the
   "Brief from URL" screen or WP-CLI. It is validated (http or https URL,
   a title with letters), cleaned (literal escape sequences such as
   `’` decoded first, tags stripped, bylines tidied, emails dropped)
   and keyed: mobile, AMP and tracking variants of one URL share one
   article key. A second copy of a story becomes a `duplicate` row; a
   changed body becomes an `update` note on the draft. Links to your own
   site are rejected.
2. **Date.** The claimed time is parsed in the feed's zone when it has
   none. Future times, a batch where every item has the same time, and
   epoch or pre-1995 values are not trusted. A re-dated item keeps its
   earliest claim. The page's own metadata wins when it differs by more
   than six hours. Then a date in the URL, then the time first seen. The
   reason is stored and shown to the editor.
3. **Queue.** WP-Cron runs every five minutes, newest first, five items,
   twenty seconds. Each item is leased, so overlapping runs never process
   one item twice.
4. **Classify.** Video, gallery, tag, author, search and live pages are
   skipped. Sponsored and press-release items are skipped. Pages that ask
   for `nosnippet` or `noarchive` become link-only drafts. Items under 15
   words have no body. Paywalled teasers follow the paywall mode.
5. **Gate.** Before every model call: the switch, the auth pause, the
   daily cap (an atomic conditional increment), then spend-meter when it
   is present. Any doubt means no call.
6. **Model.** One call through llm-json-php with a JSON schema, or through
   your `feed_item_brief_summarise` filter. Provider errors back off 5 and
   30 minutes; the third failure ends the item. A 401 or 403 pauses calls
   for an hour; so does a 402.
7. **Check.** Self-narration lines are removed. Every number and every
   quote of four words or more must be in the source. Phrases that imply
   several sources fail. Over-length text fails; it is never cut. The
   wrong language fails. A failure gets one retry with the reasons named;
   a second failure is `check_failed`. Relative time words are flagged,
   not rejected.
8. **Draft.** A post in draft (or pending), with the summary as paragraph
   blocks and the attribution block last. The block is dynamic: readers
   see it rendered from post meta. Its saved markup is a complete static
   fallback, so the credit survives if the plugin is removed. If an editor
   deletes the block, it is appended again on `the_content`. A brief whose
   attribution is missing at publish time is put back to draft.

Data lives in two tables (`{prefix}feed_item_brief_items`,
`{prefix}feed_item_brief_daily`) and post meta. Bodies are cleared seven
days after an item ends; rows without a post go after 90 days. Uninstall
drops the tables, options, transients and cron events, and keeps posts.

## Is it working?

- Settings > Feed Item Brief shows calls today against the cap, when the
  cap was reached, the last queue run (red after 30 minutes) and the queue
  length, plus failing feeds.
- Tools > Feed Item Brief log lists every record with its status and
  reason, with "Retry" for `check_failed`, `provider_failed`,
  `insert_failed`, `capped` and (after a confirmation) `stale`.
- `wp feed-item-brief check <url>` fetches, classifies and dates one URL
  without a model call and without writing anything.
- `wp feed-item-brief status` prints the switch, the cap, calls today and
  counts by status.

## Limitations

- The "registrable host" is the host without a leading `www.`, `m.`,
  `mobile.` or `amp.` label. There is no public suffix list, so
  `news.example.co.uk` and `example.co.uk` are different sites.
- Only English number words ship. For other languages a number in the
  summary that is not in the source as digits is flagged for the editor,
  not rejected. Add lists with `feed_item_brief_number_words`.
- A relative time word counts as supported when it appears anywhere in the
  source, not only in the sentence the claim came from.
- The page parser reads the head, JSON-LD blocks and the first `<time>` in
  the first `<article>`. It is not an extraction engine.
- The gen-queue integration uses `gen_queue_register_type()` on
  `gen_queue_register_types` and `gen_queue_enqueue()`, only when present.
  The spend-meter integration (contract `SPEND_METER_API` 1) uses the
  `spend_meter_pre_spend` filter with an intent array, then the
  `spend_meter_record` or `spend_meter_release` action. A `null` decision
  means no meter answered; anything else the plugin does not understand
  is treated as a refusal.
- The block editor script has no build step and is not covered by the PHP
  tests.

## FAQ

**Can I run it without llm-json-php?** Yes. Hook
`feed_item_brief_summarise` and return an array with `headline`,
`summary` (one to three strings), `language` and `insufficient`. The
switch, the cap, the checks, the retry and the attribution all still
apply; the settings screen hides the provider fields. See
[`examples/own-summariser.php`](examples/own-summariser.php).

**Why is the paywall default "link-only"?** So nothing is written from an
excerpt without an editor choosing to. The teaser mode is one setting away.

**Why is page fetching off by default?** So the plugin sends no traffic to
publishers unless you opt in. With it on you get better dates and paywall
signals.

**Can the plugin publish?** No. Any status other than draft or pending is
forced back, and a filter that tries gets a `_doing_it_wrong()` notice.

**Can an editor remove the credit?** Not by editing the post. The block is
locked in the editor, re-rendered on output if deleted, and checked at
publish. Corrections to the source name, URL, byline and time go through
the review panel, need the settings capability and are recorded.

**Does it add a canonical link to the source?** Not by default. Return true
from `feed_item_brief_canonical` to add one.

**Which languages do the attribution strings use?** The site's language,
through the `feed-item-brief` text domain.

Design notes and every decision are in
[`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md). The requirements are in
[`docs/PRD.md`](docs/PRD.md).

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

## Licence

MIT. See [LICENSE](LICENSE). Copyright (c) 2026 Indian Opinion.
