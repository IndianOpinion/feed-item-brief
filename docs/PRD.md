# PRD: feed-item-brief

Status: draft for review. Version 0.1.0. Plugin type: WordPress plugin.

Written from scratch; not derived from any third-party plugin.

One line: turns one feed item into an attributed summary draft with the
source link, byline and publish time kept, for any importer or none.
Handles paywalled stubs, items with no body, and items whose feed lies
about the date.

## 1. Problem

A news site that follows other publishers wants a short summary of an
item, written in its own words, that sends the reader to the original.
Doing that by hand is slow. Doing it with a language model and a feed
importer goes wrong in predictable ways.

- The attribution gets lost. The model is asked to "credit the source"
  and sometimes does, sometimes names the wrong outlet, sometimes drops
  the link. An editor later deletes the credit line by accident, and the
  published post now presents another newsroom's reporting as its own.
- The outlet name is wrong. The importer stores a host taken from the
  feed or the item's `guid`, which can belong to a feed service or a
  mirror, not the publisher of the article.
- Paywalled items arrive as a two-line teaser ending in "Subscribe to
  read more". The model, asked for 150 words, invents the other 130.
- Items with no body at all, only a title, get the same treatment.
- Feeds lie about dates. A feed stamps every item with the time the feed
  was built, sends dates in the future, sends no time zone, or bumps the
  date each time an old article is edited. The summary then says "today"
  about a story from last month.
- The model narrates its own working into the copy ("Word count: 112",
  "Note: the source does not say"), or implies there were several sources
  when there was one ("reports differ").

In production, about one generated summary in four ran past the stated
length ceiling while only a range was given, and the instruction to count
words was then printed back into published copy. Cutting long summaries
was worse: the last sentence is often the one that carries the
attribution or the key date.

feed-item-brief takes one item, decides whether it can be summarised
honestly, writes a draft for an editor, and attaches an attribution block
that the plugin renders itself and that cannot be removed.

## 2. Goals and non-goals

Goals:

1. Turn one item (URL, title, body, source name, byline, publish time)
   into a draft post: a short summary in the source's language plus a
   mandatory attribution block with the source name, a link to the
   original, the byline and the source's publish time.
2. Accept items from any importer through this plugin's own hook and
   adapter interface, from WordPress core's `fetch_feed()` through one
   reference adapter, and from no importer at all (a URL typed by an
   editor, or WP-CLI).
3. Classify every item before spending money: full text, teaser,
   paywalled stub, no body, not an article, sponsored, duplicate, stale.
   Only summarise what is actually there.
4. Resolve a trustworthy publish time from the feed date, the page's own
   metadata, the URL and the time the item was first seen, and record
   why that time was chosen.
5. Never publish. Output is a draft (or pending review) for a human.
6. Daily cap on model calls, and a kill switch, in this plugin's own
   settings, honoured even when a site-wide spend plugin is present.
7. Fail closed on money and attribution, fail open on the importer: a
   failure in this plugin never stops an importer from importing.

Non-goals:

- No adapter for any third-party importer is shipped or described.
  Others can write one against the interface in section 5.
- No multi-source synthesis, clustering, relevance gating, topic
  selection or scheduling across a day. One item in, one draft out.
- No full-article extraction engine. The plugin reads the body it is
  given and the page's head metadata. A separate extractor can be plugged
  in through a filter (section 5.3).
- No translation, no images, no featured image selection, no social
  posting.
- No house style. Prompts are generic and replaceable.
- No automatic publishing, ever, including on a schedule.
- No bypassing of paywalls, logins or robots rules.

## 3. Users and setting

- Who installs it: the operator of a WordPress site that links out to
  other publishers' reporting (a news digest, a trade newsletter site, a
  community news site, a research group's reading list).
- Who uses it daily: editors, who open the drafts, check them against
  the source, edit and publish.
- Where it runs: a single WordPress site or each site of a multisite
  network, on ordinary PHP hosting with WP-Cron or a real cron hitting
  `wp-cron.php`. Outbound HTTPS to the model provider is required.
  Outbound HTTPS to source pages is optional (section 4.4).
- What it talks to: a language model provider through the
  `indianopinion/llm-json-php` library, under the operator's own API key.
  Optionally `gen-queue` and `spend-meter` through their public hooks,
  only when present.

## 4. Behaviour

Every case is numbered. Tests in section 12 cite these numbers.

### 4.1 Entry points

1. **Importer hook.** An importer, or an adapter written for one, calls
   `do_action( 'feed_item_brief_submit', array $item, string $adapterId )`
   or the function form `feed_item_brief_submit( array $item, string
   $adapterId ): Submission`. The call validates and normalises the item,
   records it, and returns at once. No model call, no page fetch and no
   post insert happens inside the importer's request.
2. **Reference adapter.** The `core-feed` adapter polls the feeds listed
   in its settings with `fetch_feed()` and submits each new item
   (section 4.9).
3. **No importer.** An editor pastes a URL into the "Brief from URL"
   screen, optionally with a title, body, source name, byline and publish
   time. Missing fields are filled from the page's head metadata when page
   fetching is on. The item goes through the same pipeline and is
   processed at once (it still counts against the cap).
4. **WP-CLI.** `wp feed-item-brief make <url> [--title=] [--body-file=]
   [--source=] [--byline=] [--published=]` does the same as case 3.
5. A submission while the plugin is switched off (case 40) is still
   recorded, with status `paused`, so nothing an importer sends is lost.
   It is processed when the switch is turned back on, if it is not stale
   by then (case 28).
6. A submission from an adapter id that is not registered is recorded
   with adapter `unknown:<id>`, not rejected. A `_doing_it_wrong()` notice
   is raised once per request.

### 4.2 Normalisation and validation

7. `url` is required. It must parse, use `http` or `https`, and have a
   host. Anything else (empty, relative, `javascript:`, `data:`, `ftp:`)
   is rejected with reason `bad_url`. Leading and trailing white space is
   trimmed, HTML entities in the URL are decoded once.
8. `title` is required after normalisation. HTML tags are stripped,
   entities decoded, white space collapsed. An empty title, or one that
   is only punctuation, is rejected with reason `no_title`, unless page
   fetching is on and the page's `og:title` or `<title>` supplies one.
9. `body` is optional. It is reduced to plain text for classification and
   for the prompt: scripts, styles, figures captions marked as such,
   `<iframe>`, share widgets and forms are removed; block elements become
   paragraph breaks; entities are decoded; white space is collapsed. The
   original HTML is kept with the record until processing ends.
10. Literal escape sequences that leak from upstream JSON (`’`,
    ` `, `\n` as two characters) are decoded in title, body and
    byline before anything else reads them.
11. `source_name` is optional. If absent, the adapter's feed title is
    used; if that is absent, the registrable host of the article URL
    (the resolved URL of case 12, not the `guid` and not the feed host),
    without a leading `www.` or `m.` or `amp.`. The name is stored and
    shown exactly as resolved; the plugin never guesses a brand name.
12. The article URL is resolved before anything depends on it. The
    adapter's URL is used if valid. If it is not valid, the `guid` is used
    if it is a valid http(s) URL. A filter
    (`feed_item_brief_resolve_url`) lets a site unwrap aggregator or
    redirect links. The plugin does not follow redirects itself during
    submission.
13. The **article key** is the resolved URL with: scheme dropped, host
    lower-cased, a leading `www.`, `m.`, `mobile.` or `amp.` label
    removed, a trailing `/amp` or `/amp/` path segment and an `amp`,
    `outputType=amp` or `amp=1` query argument removed, tracking arguments
    removed (`utm_*`, `fbclid`, `gclid`, `mc_cid`, `mc_eid`, `ref`,
    `cmpid`, plus a filterable list), remaining arguments sorted,
    fragment removed, trailing slash removed. Its SHA-256 is stored.
14. A submission whose article key, or whose `guid` for the same
    adapter, is already recorded is a **duplicate**. If the first record
    produced a draft or is still in progress, the new submission is
    recorded as `duplicate` and points at the first. Same story, many
    feeds, one draft.
15. A duplicate whose plain-text body differs from the first record's
    body by more than a whitespace change is an **update**. The plugin
    does not write a new draft. If the first record's post is still a
    draft or pending, a note "The source changed after this draft was
    written" with the time is added to the post's review panel. If the
    post is published, nothing is changed on the post; the update is
    logged.
16. An item whose resolved URL is on the site's own host (`home_url()`
    host, any of the article-key host variants) is rejected with reason
    `self`.
17. `byline` may be a string or a list of strings. Each entry: tags
    stripped; a leading "By" / "by" removed; the RSS form
    `address@example.org (Name)` reduced to `Name`; a bare email address
    dropped entirely (an email address is never printed); entries longer
    than 100 characters, entries that equal the source name, and
    duplicates (case-insensitive) dropped. Two names join with " and ",
    three or more with commas and a final " and ". An empty result means
    no byline is shown; the plugin never invents one and never writes
    "Staff".
18. `published` and `updated` accept a Unix timestamp, an RFC 2822
    string or an ISO 8601 string. Parsing is described in 4.3.
19. `language` is optional, a BCP 47 tag. When absent, the feed's
    language is used, then the page's `<html lang>`, then `und`.

### 4.3 Publish time

The plugin keeps the **claimed time** (what the item said) and computes
a **resolved time** with a **date reason** code.

20. A claimed time with no time zone is read in the time zone set for the
    feed in the adapter, or UTC if none is set. The reason records
    `assumed_tz`.
21. A claimed time that cannot be parsed, is zero, is before 1995-01-01,
    or is the Unix epoch, counts as missing (`date_missing`).
22. A claimed time more than 10 minutes (filterable) after the time the
    item was first seen is in the future (`date_future`). It is not used.
23. When every item of one fetch of one feed carries the identical
    claimed time, and there are at least 3 items, the time is the feed's
    build time, not the items' (`date_uniform`). It is not used.
24. When a `guid` or article key was first seen earlier with an older
    claimed time, and the new claimed time is later, the item was
    re-dated by an edit. The earliest claimed time wins
    (`date_bumped`), and case 15 applies to the body.
25. When page fetching is on, the page's own metadata is read: JSON-LD
    `datePublished` and `dateModified` on an `Article`, `NewsArticle` or
    `BlogPosting` node, then `article:published_time`, then
    `<time datetime>` inside the first `<article>`. A page time that
    parses and is not in the future is preferred over the feed's claimed
    time when the two differ by more than 6 hours (`date_page`). When
    they agree within 6 hours, the feed time is kept (`date_feed`).
26. When neither feed nor page gives a usable time, a date in the URL
    path (`/2026/11/14/`, `/2026-11-14/`, `/20261114/`) sets the day;
    the time of day is taken from first-seen if first-seen is on that
    day, else noon in the feed's time zone (`date_url`).
27. When nothing gives a usable time, the resolved time is the first-seen
    time (`date_first_seen`), and the draft's review panel says the
    source gave no date.
28. An item whose resolved time is older than the maximum age (default
    72 hours, filterable, 0 means no limit) is not summarised: status
    `stale`. This check runs at submission and again just before the
    model call, so a backlog from a pause does not produce old news.
29. The attribution block shows the resolved time in the site's time
    zone and date format, inside `<time datetime="...">` with the ISO
    8601 UTC value. When the date reason is not `date_feed` or
    `date_page`, the review panel (never the public block) shows the
    claimed time and the reason, so an editor can check it.
30. The summary prompt receives the resolved time as the article's date,
    and is told not to describe relative time ("today", "yesterday").
    The output check (case 37) flags those words.

### 4.4 Body classification

Classification runs in order; the first match wins. Word counts use a
Unicode-aware word splitter (letters and marks of any script; a run of
CJK characters counts each character as a word divided by 2, rounded up).

31. **Not an article.** URL path or JSON-LD type says video, gallery,
    photo gallery, podcast episode index, live blog index, topic or tag
    page, author page, search page (filterable patterns and types). Status
    `not_article`, no draft. Such pages often carry text from another
    story beside them.
32. **Sponsored.** The URL path, title or first 400 characters of the
    body match the sponsored list (default: "sponsored", "partner
    content", "advertorial", "paid post", "promoted", "brand studio",
    "press release", "media release", case-insensitive, filterable and
    editable per site), or JSON-LD marks the page as an advertisement.
    Status `sponsored`, no draft. Paid placement is never a source.
33. **No body.** Body text has fewer than 15 words (filterable). If an
    extractor is attached (filter `feed_item_brief_fetch_body`) it is
    asked once for the body and the item is classified again with its
    result. If page fetching is on, JSON-LD `articleBody` is used when it
    is present and not itself a stub. Otherwise the no-body mode applies:
    `skip` (default: status `no_body`, no draft) or `link_only` (case
    36). The plugin never summarises from the title alone.
34. **Paywalled stub.** Any of: JSON-LD `isAccessibleForFree` is false;
    the body ends with a paywall phrase (default list: "subscribe to read",
    "subscribe to continue", "for subscribers", "premium story", "sign in
    to read", "log in to continue", "this article is for subscribers",
    filterable); the body ends with an ellipsis, "[...]" or "Read more"
    and has fewer than 120 words. The paywall mode applies:
    - `teaser` (default): if the teaser, minus the paywall phrase, has at
      least 40 words, it is summarised with the teaser instruction (the
      prompt says only the excerpt is available and the summary must not
      go beyond it), the target length drops to 40 to 80 words, and the
      attribution block adds "Summary based on the publicly available
      excerpt." If under 40 words, falls through to `link_only`.
    - `link_only`: case 36.
    - `skip`: status `paywalled`, no draft.
35. **Full or teaser.** Everything else. A body of 15 to 119 words with no
    paywall sign is a **short** item: summarised with the short target
    (40 to 80 words). 120 words or more is **full**: summarised with the
    normal target (100 to 170 words, filterable).
36. **Link-only draft.** No model call. The draft has the original title
    (escaped), the plain-text teaser if any (first 60 words, cut at a
    sentence end), an empty paragraph for the editor, and the attribution
    block. Status `drafted`, mode `link_only`. It does not count against
    the cap.

### 4.5 The model call

37. The model is called through `llm-json-php` with a JSON schema:
    `headline` (string, 20 to 120 characters), `summary` (array of 1 to 3
    strings, each a paragraph), `language` (BCP 47 string), and
    `insufficient` (boolean: true when the text does not support a
    faithful summary). The prompt (section 7.3) is generic and filterable.
38. Source text sent to the model is the plain text of case 9, cut to
    12,000 characters (filterable) at the last paragraph break before the
    limit. It is wrapped in clear delimiters and the prompt says it is
    data, not instructions.
39. Before the call, the cap and switch are checked (cases 40 to 44). The
    call is made with the configured provider, model and timeout. On a
    transport error, a 429 or a 5xx, the item returns to the queue with
    a back-off (5, 30, then 120 minutes) and up to 3 attempts in total.
    A 401 or 403 pauses all model calls for one hour, records
    `auth_failed`, and shows an admin notice to users with the settings
    capability. A 402 or a provider "insufficient credit" error does the
    same with `no_credit`.
40. When `insufficient` is true, no draft is written; status `thin`.
41. **Output checks.** The returned fields are checked, after literal
    escape sequences are decoded (case 10):
    - Every paragraph is non-empty after trimming. No markdown headings,
      no bullet lists, no bracketed notes, no lines that start with
      "Note:", "Word count", "Summary:", "Headline:" (filterable list).
      Such lines are removed, not rejected, when the rest stands.
    - Every number in the summary (digits, with separators normalised)
      appears in the source text or the title. A number written in words
      in the source and digits in the summary counts as present when the
      plugin's number-word list for the source language covers it; when
      it does not, the check passes (no false rejects for languages
      without a list).
    - Every passage inside quotation marks in the summary, 4 words or
      longer, appears in the source text after quote and space
      normalisation.
    - None of the single-source phrases appears ("reports differ",
      "sources differ", "other outlets", "according to reports", "media
      reports", "multiple reports", "some outlets", filterable, per
      language when supplied). One source cannot disagree with itself.
    - No relative-time words ("today", "yesterday", "tomorrow", "this
      morning", "last night", filterable) unless the same word is in the
      source sentence the claim came from. This check flags, it does not
      reject: the review panel lists them.
    - Total words not over the ceiling plus 10 per cent. The summary is
      never truncated: its last sentence may carry the key date or the
      attribution.
    - `language` matches the item's language primary subtag when the item
      language is known.
42. A hard failure (unsupported number, unsupported quote, single-source
    phrase, over length, wrong language) leads to one retry with the
    failure named in a short corrective message. A second failure records
    status `check_failed` with the failure list and writes no draft. The
    editor can retry from the log screen. Both calls count against the
    cap.
43. The model never writes the attribution, the link, the byline or the
    date. Those come from the record (section 4.6). The prompt says not
    to name the outlet more than once, and only where a claim needs it.

### 4.6 The draft and the attribution block

44. A draft post is inserted with `wp_insert_post()`: type `post`
    (filterable), status `draft` (or `pending` by setting; never
    `publish` or `future`, and any filter that returns either is ignored
    with a `_doing_it_wrong()` notice), author the configured user (or
    the adapter's per-feed author), title the model's headline (or the
    original title, by setting), content the summary paragraphs as
    paragraph blocks followed by the attribution block, excerpt the first
    paragraph cut at a sentence end under 300 characters. The post date
    is left for WordPress to set on publish.
45. The **attribution block** is a dynamic block named
    `feed-item-brief/attribution`. Its saved markup carries a complete
    static fallback, so it still reads correctly if the plugin is
    removed:
    - "Source: <a href="URL" rel="nofollow noopener">Source name</a>"
    - "By Byline" when there is a byline
    - "Published <time datetime="...">date and time</time>"
    - the excerpt note of case 34 when it applies
    - the disclosure line (section 7.1), on by default
    The rendered version reads the values from post meta, not from the
    saved markup.
46. **Not removable.** On `the_content`, for a post that has attribution
    meta, if the rendered content does not contain the block's wrapper
    (an editor deleted it, or switched to the classic editor and removed
    the comment), the block is rendered and appended. Removing the
    attribution requires deleting the post's attribution meta through
    code, which the plugin never offers in its UI.
47. Sites may restyle the block with the filter
    `feed_item_brief_attribution_html`. The returned HTML must still
    contain an anchor whose `href` equals the source URL and whose text
    contains the source name. If it does not, the plugin discards the
    filtered HTML, uses its own, and raises `_doing_it_wrong()` once per
    request.
48. Editing guard: the block's inspector controls show the values
    read-only. A user with the settings capability can correct the
    source name, URL, byline and time through the review panel (not the
    block), which updates meta and records who changed it and when. A
    corrected URL must pass case 7. The link cannot be set to empty.
49. On publish (`transition_post_status` to `publish` or `future`), if
    attribution meta is missing or its URL fails case 7, the post is put
    back to `draft` and an admin notice explains why. This is the one
    place the plugin fails closed against an editor.
50. Post meta stored on the draft (section 6) is registered with
    `register_post_meta`, `show_in_rest` true for the read-only fields
    an editor needs, and an auth callback that requires `edit_post`.
51. The **review panel** (block editor sidebar panel and a classic meta
    box) shows: source name and link, byline, claimed time, resolved
    time and date reason, body class (full, short, teaser, link only),
    flagged relative-time words, source-update notes (case 15), the
    model and the time of the call. It is shown only to users who can
    edit the post.

### 4.7 Queue and processing

52. Accepted submissions are stored as `queued`. A WP-Cron event (every
    5 minutes, filterable) processes up to 5 items per run (filterable)
    within a 20 second wall-clock budget. The run stops early when the
    budget is spent, the cap is reached or the switch is off.
53. Each item is claimed with a lease (row update with a token and an
    expiry of 5 minutes) so overlapping cron runs never process one item
    twice. An expired lease is reclaimable.
54. Order is newest resolved time first (filterable), so a backlog does
    not spend the cap on items that are about to go stale.
55. When `gen-queue` is present (`function_exists('gen_queue_enqueue')`
    after `plugins_loaded`) and the setting "Use gen-queue" is on, the
    plugin enqueues one job of type `feed_item_brief.summarise` per item
    with the record id as payload and an idempotency key of the article
    key hash, and its own cron runner is not scheduled. The job handler
    runs the same pipeline. If the enqueue returns an error, the item
    stays in the plugin's own queue.

### 4.8 Cap, kill switch and spend

56. **Kill switch.** The setting "Enabled" (default off after
    activation, until a model and key are configured) and the constant
    `FEED_ITEM_BRIEF_DISABLED` (true wins over the setting). When off: no
    model call, no page fetch, no draft. Submissions are recorded as
    `paused` (case 5). The switch is read at the moment of each model
    call, not only when an item is queued.
57. **Daily cap.** A maximum number of model calls per day (default 25;
    constant `FEED_ITEM_BRIEF_DAILY_CAP` overrides the setting; 0 means no
    calls). The day is the site's local day. Retries count. Link-only
    drafts do not. The counter is incremented atomically before the call
    (an `UPDATE ... SET n = n + 1 WHERE day = ? AND n < cap`), so two
    workers cannot both take the last slot.
58. When the cap is reached, queued items stay queued and are retried the
    next day if they are not stale by then. The log shows `capped` on the
    run, and the settings screen shows "Cap reached at HH:MM".
59. When `spend-meter` is present (`defined('SPEND_METER_API')` with a
    contract version the plugin supports), each call first asks
    `spend_meter_pre_spend` with purpose `feed-item-brief.summarise` and
    caller `feed-item-brief`. If the decision is not allowed, the call is
    not made and the item is requeued for the next day with reason
    `spend_denied`. After the call, `spend_meter_record` is called with
    the reservation id and the token usage reported by the provider, or
    `spend_meter_release` when the call failed before reaching the
    provider. The plugin's own cap and switch still apply first.
60. Manual "Brief from URL" and WP-CLI `make` obey the switch and the cap.
    WP-CLI may pass `--ignore-cap` only when the constant cap is not set,
    and the call is logged with the user.

### 4.9 Reference adapter: WordPress core feeds

61. Settings list feeds: URL (required, http or https), source name
    override, default author, time zone for dates without one, enabled
    flag. Up to 200 feeds.
62. A WP-Cron event (every 30 minutes, filterable) fetches each enabled
    feed with `fetch_feed()`, at most 10 feeds per run in rotation, and
    takes at most 10 items per feed per run (filterable), newest first.
63. Mapping from SimplePie: URL from the item permalink; `guid` from the
    item id; title from the item title; body from the full content when
    present, else the description; byline from the item authors' names
    (falling back to their emails, which case 17 then drops); claimed
    time from the item's date in UTC when the feed gave one; language
    from the feed; source name from the override, else the feed title.
64. A feed that returns a `WP_Error` is logged with the error code and
    the next feed is fetched. Three failures in a row mark the feed
    `failing` on the settings screen; a success clears it.
65. The adapter passes all items of one fetch together so case 23 can see
    them as one batch (`feed_item_brief_submit_batch`).
66. The adapter never changes `fetch_feed()` cache settings globally; it
    sets the cache lifetime only for its own calls through the
    `wp_feed_cache_transient_lifetime` filter, added and removed around
    each fetch.

### 4.10 Page fetching (optional)

67. When "Fetch source pages" is on (default on), the plugin fetches the
    article URL once per item with `wp_safe_remote_get()`: 8 second
    timeout, up to 3 redirects, the response read up to 1 MB, a plain
    User-Agent naming the plugin and the site URL. Only `<head>`, JSON-LD
    blocks and the first `<article>` start tag region are parsed. The page
    body is never stored.
68. A non-2xx response, a timeout or a non-HTML content type is not an
    error: the item proceeds with what the feed gave. The reason is
    logged.
69. The plugin honours a `robots` meta or `X-Robots-Tag` of `noarchive`
    or `nosnippet` on the page: the item is treated as `link_only`
    regardless of the body. It does not read `robots.txt`; the operator
    can turn page fetching off.
70. A redirect that lands on a different registrable host updates the
    resolved URL and the article key only if the final page declares a
    `<link rel="canonical">` on that host; otherwise the original URL is
    kept.

### 4.11 Failure modes

| Failure | What happens | Open or closed, and why |
|---|---|---|
| Importer passes a malformed item | Rejected with a reason, logged, importer continues | Open for the importer: never throw into its loop |
| Database write fails at submission | Logged to the PHP error log, submission returns a failed result, importer continues | Open for the importer |
| Model provider down | Item requeued with back-off, up to 3 attempts | Closed on output: no draft without a model result |
| Auth or credit error | Calls paused one hour, admin notice | Closed: stop spending when the account is broken |
| Output fails checks twice | `check_failed`, no draft | Closed: an unsupported number is worse than no draft |
| Cap or switch read fails (option missing, DB error) | Treated as off, no call | Closed on money |
| spend-meter errors or returns an unknown shape | Treated as a deny | Closed on money |
| Page fetch fails | Proceed with feed data | Open: the page is a helper, not a requirement |
| Attribution filter returns bad HTML | Plugin's own block used | Closed on attribution |
| Attribution meta missing at publish | Post returned to draft | Closed on attribution |
| Insert of the draft fails | Status `insert_failed`, retry once on the next run | Closed: no half-written post |
| Cron not running | Queue grows; settings screen shows the time of the last run in red after 30 minutes | Visible, not silent |

## 5. Public interface

Namespace `IndianOpinion\FeedItemBrief`. Function and hook prefix
`feed_item_brief_`. Text domain `feed-item-brief`.

### 5.1 Classes

- `Item`: immutable value object built by `Item::fromArray(array $data):
  Item`. Throws `InvalidItemException` with a reason code (`bad_url`,
  `no_title`) for invalid input. Getters for every field in 5.4.
- `Submission`: result of a submit: `accepted(): bool`, `status():
  string`, `reason(): ?string`, `recordId(): ?int`, `duplicateOf(): ?int`.
- `Adapter` (interface, 5.2) and `Registry` (add, get, all).
- `Classifier`: `classify(Item $item, ?PageMeta $page): BodyClass`.
- `DateResolver`: `resolve(Item $item, ?PageMeta $page, array $batch,
  ?Record $earlier, \DateTimeImmutable $firstSeen): ResolvedDate` with
  `time()` and `reason()`.
- `Summariser`: wraps the model call and the output checks; returns a
  `Summary` or throws `CheckFailedException` (with the failure list),
  `ProviderException`, `CapReachedException`, `DisabledException`.
- `AttributionRenderer`: `render(Attribution $a): string`.
- Exceptions extend `FeedItemBriefException`.

### 5.2 Adapter interface

```
interface Adapter
  id(): string                       slug, lower case, 1 to 40 chars
  label(): string                    shown in the admin
  boot(): void                       hook into the importer
  defaultAuthor(string $sourceKey): ?int
  timezone(string $sourceKey): ?string   for dates without a zone
```

Adapters register on the `feed_item_brief_register_adapters` action,
which passes the registry: `$registry->add(Adapter $adapter)`. A duplicate
id is rejected with `_doing_it_wrong()`. The reference adapter has id
`core-feed`; the manual screen and WP-CLI use the reserved id `manual`.

### 5.3 Hooks

| Hook | Type | Arguments | Purpose |
|---|---|---|---|
| `feed_item_brief_submit` | action | `array $item, string $adapterId` | Submit one item |
| `feed_item_brief_submit_batch` | action | `array $items, string $adapterId, string $sourceKey` | Submit the items of one fetch together |
| `feed_item_brief_register_adapters` | action | `Registry $registry` | Register adapters |
| `feed_item_brief_resolve_url` | filter | `string $url, Item $item` | Unwrap redirects or aggregator links. Result must pass case 7 or is ignored |
| `feed_item_brief_tracking_params` | filter | `string[] $names` | Query arguments removed from the article key |
| `feed_item_brief_fetch_body` | filter | `?string $html, Item $item` | Attach an extractor. Return null for none |
| `feed_item_brief_classify` | filter | `BodyClass $class, Item $item` | Last word on classification |
| `feed_item_brief_sponsored_phrases` | filter | `string[]` | Case 32 list |
| `feed_item_brief_paywall_phrases` | filter | `string[]` | Case 34 list |
| `feed_item_brief_not_article_patterns` | filter | `string[]` | Case 31 path patterns |
| `feed_item_brief_max_age` | filter | `int $seconds` | Default 259200 |
| `feed_item_brief_prompt` | filter | `array $messages, Item $item, string $mode` | Replace or adjust the prompt |
| `feed_item_brief_length` | filter | `array{min:int,max:int}, string $mode` | Word targets per mode |
| `feed_item_brief_banned_lines` | filter | `string[]` | Case 41 line prefixes |
| `feed_item_brief_single_source_phrases` | filter | `string[], string $lang` | Case 41 phrases |
| `feed_item_brief_relative_time_words` | filter | `string[], string $lang` | Case 41 words |
| `feed_item_brief_post_args` | filter | `array $args, Record $record` | Adjust the insert; status is forced back to draft or pending |
| `feed_item_brief_attribution_html` | filter | `string $html, Attribution $a` | Restyle, validated by case 47 |
| `feed_item_brief_disclosure_text` | filter | `string $text` | Disclosure line |
| `feed_item_brief_drafted` | action | `int $postId, Record $record` | After a draft is written |
| `feed_item_brief_skipped` | action | `Record $record, string $reason` | After an item ends without a draft |
| `feed_item_brief_enabled` | filter | `bool` | Can only turn off; returning true while the setting or constant is off has no effect |
| `feed_item_brief_capability` | filter | `string` | Settings capability, default `manage_options` |
| `feed_item_brief_make_capability` | filter | `string` | "Brief from URL" capability, default `edit_others_posts` |

### 5.4 Item array

| Key | Type | Required | Notes |
|---|---|---|---|
| `url` | string | yes | Article URL |
| `title` | string | yes | Plain text or HTML |
| `body` | string | no | HTML or plain text |
| `source_name` | string | no | Outlet name |
| `byline` | string or string[] | no | Author names |
| `published` | int or string | no | Claimed publish time |
| `updated` | int or string | no | Claimed update time |
| `guid` | string | no | Feed item id |
| `feed_url` | string | no | For logs and case 23 |
| `source_key` | string | no | Adapter's id for the feed, for author and zone lookups |
| `language` | string | no | BCP 47 |

Unknown keys are ignored. Strings over 1 MB (body) or 2,000 characters
(other fields) are cut before storage, and the record notes it.

### 5.5 Functions

- `feed_item_brief_submit( array $item, string $adapterId ): Submission`
- `feed_item_brief_is_enabled(): bool`
- `feed_item_brief_calls_today(): int` and `feed_item_brief_cap(): int`
- `feed_item_brief_attribution( int $postId ): ?Attribution`

### 5.6 Admin screens

- Settings > Feed Item Brief: switch, provider, model, key (write-only
  field; shows "set" or "not set", or "set by constant"), daily cap,
  post status, author, title source, paywall mode, no-body mode, page
  fetching, max age, disclosure line, gen-queue use, phrase lists, and
  the core-feed adapter's feed list. Counters: calls today, cap, last
  run, queue length.
- Tools > Feed Item Brief log: records with filters by status, reason,
  adapter and date; per-row actions "Retry" (for `check_failed`,
  `insert_failed`, `stale` with confirmation, `capped`) and "View
  draft". Retries go through the cap.
- Posts > Brief from URL: the manual form of case 3.
- Every form uses a nonce and a capability check.

### 5.7 WP-CLI

- `wp feed-item-brief status`: switch, cap, calls today, queue counts.
- `wp feed-item-brief run [--limit=<n>]`: process the queue now.
- `wp feed-item-brief make <url> [...]`: case 4.
- `wp feed-item-brief log [--status=] [--since=] [--format=table|json]`
- `wp feed-item-brief retry <record-id>`
- `wp feed-item-brief check <url>`: fetch, classify and resolve the date
  without a model call and without writing anything. Prints the class,
  the resolved time and the reason.

## 6. Data

- **Table** `{prefix}feed_item_brief_items`, one row per submission:
  id, adapter id, source key, article key hash (indexed), guid hash
  (indexed with adapter id), URL, title, source name, byline, language,
  claimed time, resolved time, date reason, body class, mode, status,
  reason, failure list (JSON), duplicate-of id, post id, attempts, lease
  token and expiry, plain-text body, body hash, created, updated.
- **Body retention:** the plain-text body and original HTML are cleared
  7 days after the record reaches a final status (filterable, minimum
  1 day), keeping the body hash for case 15.
- **Row retention:** rows without a post are deleted after 90 days
  (filterable). Rows with a post are kept while the post exists.
- **Counter table** `{prefix}feed_item_brief_daily`: day, calls. Rows
  older than 400 days are deleted.
- **Post meta** on drafts: source URL, source name, byline, resolved
  time, claimed time, date reason, body class, mode, model id, call time,
  record id, flags, source-update notes, correction history.
- **Options:** one settings option (autoload off except the switch and
  cap, which are a separate small autoloaded option), the adapter feed
  list, the auth-pause expiry.
- **Schema changes:** on version change the plugin runs `dbDelta` and
  then checks `SHOW COLUMNS` for every expected column; a missing column
  raises an admin notice and switches the plugin off, rather than
  running against a half-migrated table.
- **Uninstall** (`uninstall.php`): drops both tables, deletes options,
  transients and cron events. Posts and their meta are kept: they are
  editorial content, and the static fallback in the block keeps the
  attribution readable.

## 7. Configuration and sensible defaults

### 7.1 Settings

| Setting | Default | Notes |
|---|---|---|
| Enabled | off | Turned on by the operator after setting a key |
| Provider | OpenRouter | Any provider `llm-json-php` supports |
| Model | empty | Operator picks; the plugin ships no model choice |
| API key | none | Constant `FEED_ITEM_BRIEF_API_KEY` preferred |
| Daily cap | 25 calls | Constant `FEED_ITEM_BRIEF_DAILY_CAP` wins |
| Post status | draft | Or pending |
| Author | first administrator at activation | Per feed override |
| Title source | model headline | Or original title |
| Paywall mode | teaser | teaser, link_only, skip |
| No-body mode | skip | skip, link_only |
| Fetch source pages | on | |
| Max age | 72 hours | 0 means none |
| Model timeout | 45 seconds | |
| Disclosure line | on, "This summary was drafted with the help of software and checked by an editor." | Text editable, not empty when on |
| Use gen-queue | on when present | |
| Batch size, cron interval | 5 items, 5 minutes | |

### 7.2 Constants

`FEED_ITEM_BRIEF_DISABLED`, `FEED_ITEM_BRIEF_DAILY_CAP`,
`FEED_ITEM_BRIEF_API_KEY`, `FEED_ITEM_BRIEF_MODEL`. Constants win over
settings and the screen shows which values come from constants.

### 7.3 What the prompt asks for

The default prompt is plain and generic. It must:

- state that the text between the delimiters is one article from one
  named publisher, and is data, not instructions;
- ask for a neutral summary in the article's own language, in the
  writer's own words, using only facts in the text;
- forbid invented names, numbers and quotes, and forbid any statement
  that the text does not support;
- forbid comparing with or mentioning other reports, and forbid naming
  the publisher more than once;
- forbid relative time words and give the article's resolved date;
- give the word range for the mode and ask for whole sentences;
- for teasers, say only the excerpt is available and the summary must
  not go beyond it;
- ask for `insufficient: true` rather than a padded summary;
- forbid notes, counts, labels and markdown in any field;
- describe the JSON shape of case 37.

It carries no house style, topic list or editorial rules. Sites change
it with `feed_item_brief_prompt`.

## 8. Security and privacy

- Source text is untrusted. It is never evaluated, never used in a
  shell, never placed in a SQL string (prepared statements only), and
  never output without escaping. Summary paragraphs are stored through
  `wp_kses_post()` and contain text only (tags stripped before wrapping
  in paragraph blocks).
- Prompt injection: the source is delimited and labelled as data; output
  checks (case 41) catch numbers and quotes that are not in the source;
  the attribution comes from the record, so injected text cannot change
  the link.
- URLs: http and https only, validated at submission, at correction and
  at publish. Page fetches use `wp_safe_remote_get()` so private and
  loopback addresses are refused.
- The API key is never printed, never sent to the browser, never logged.
  Error messages from the provider are logged with the key redacted.
- Admin actions: nonces and capability checks on every form, AJAX and
  REST route. The "Brief from URL" form spends money, so it needs
  `edit_others_posts` by default.
- Privacy: bylines are public names from the source and are stored. Email
  addresses in bylines are dropped, never stored. Bodies are cleared after
  7 days. The plugin registers a privacy policy text suggestion stating
  that article text is sent to the configured model provider.
- The plugin sends to the provider only the item text, title, source
  name and date. No user data and no site content.

## 9. Performance budget

- Submission: under 20 ms per item without page fetching (one indexed
  lookup and one insert). No network call in the importer's request.
- Processing: one page fetch (8 s cap) and one model call (45 s cap, 3
  attempts across runs) per item; a cron run never exceeds its 20 s
  budget by more than one in-flight call.
- Front end: `the_content` cost for a post without attribution meta is
  one cached meta read; with it, one render of a small block. No queries
  on pages that are not single posts beyond the meta cache.
- Memory: bodies cut to 1 MB at intake, 12,000 characters to the model.

## 10. Compatibility

- PHP 8.1, 8.2, 8.3. WordPress 6.4 and later.
- Block editor and classic editor. In the classic editor the block
  markup is kept as HTML comments; case 46 covers its removal.
- Multisite: per site. Tables per site prefix. Network activation sets up
  each site on first load.
- Page caches and CDNs: drafts are never cached. A published post caches
  like any other.
- Runs with or without `gen-queue` and `spend-meter`. Requires
  `indianopinion/llm-json-php` (a `vcs` repository in `composer.json`,
  bundled in the release zip).
- Object caches: counters do not rely on an object cache; the counter
  table is the truth.

## 11. Observability

- The log screen and `wp feed-item-brief log` show every record with
  status and reason. Statuses: `queued`, `paused`, `processing`,
  `drafted`, `duplicate`, `update`, `stale`, `not_article`, `sponsored`,
  `no_body`, `paywalled`, `thin`, `check_failed`, `insert_failed`,
  `capped`, `spend_denied`, `auth_failed`, `no_credit`, `rejected`.
- The settings screen shows calls today against the cap, the time the cap
  was reached, the last cron run, queue length, and feeds marked
  `failing`.
- The review panel on each draft shows the date reason and any flags.
- Optional one line per final status to the PHP error log (setting, off
  by default), prefixed `[feed-item-brief]`.
- Actions `feed_item_brief_drafted` and `feed_item_brief_skipped` let a
  site forward events elsewhere.

## 12. Acceptance tests

Each test names the cases it covers. Tests run with PHPUnit against a
stub WordPress layer and a fake model client that returns scripted JSON.

1. (1) Submitting through the action stores a `queued` row and makes no
   HTTP call and no post.
2. (2, 61 to 63) The core adapter maps a fixture RSS 2.0 feed and an Atom
   feed through SimplePie into items with the right fields.
3. (3, 4) Manual and CLI submissions run the pipeline at once and count
   against the cap.
4. (5, 56) With the switch off, a submission is stored as `paused` and no
   model call happens; turning it on processes it.
5. (6) An unknown adapter id is recorded as `unknown:<id>` with one
   notice.
6. (7) `javascript:`, relative, empty and `ftp:` URLs are rejected with
   `bad_url`.
7. (8) An empty or punctuation-only title is rejected with `no_title`;
   with page fetching on, `og:title` fills it.
8. (9) A body with scripts, iframes and share widgets reduces to its
   paragraphs.
9. (10) `’` and ` ` written as literal text decode in title,
   body and byline.
10. (11, 12) Source name comes from the override, then the feed title,
    then the resolved URL host, never the guid host.
11. (13) `https://m.example.org/a/b/amp/?utm_source=x&id=2#top` and
    `http://www.example.org/a/b?id=2` give one article key.
12. (14) The same article from two feeds yields one draft and one
    `duplicate` row.
13. (15) A changed body for a drafted item adds a note to the draft and
    no new post; for a published post nothing changes.
14. (16) A URL on the site's own host is rejected with `self`.
15. (17) Bylines: "By Jane Roe", `jroe@example.org (Jane Roe)`, a bare
    email, the source name, a 150-character string and duplicates give
    "Jane Roe" only; three names join correctly; none gives no "By" line.
16. (18, 20) A date without a zone is read in the feed's zone and the
    reason is `assumed_tz`.
17. (21) Epoch, zero, 1990 and garbage dates count as missing.
18. (22) A date 2 hours ahead of first-seen is `date_future` and unused.
19. (23) Five items with one identical time are `date_uniform`; two such
    items are not.
20. (24) A guid seen earlier with an older date keeps the older date with
    `date_bumped`.
21. (25) JSON-LD `datePublished` 3 days older than the feed date wins with
    `date_page`; one 2 hours apart keeps `date_feed`.
22. (26) With no other date, `/2026/11/14/` in the path sets the day.
23. (27) With nothing, first-seen is used and the review panel says so.
24. (28) An item resolved 4 days old is `stale` at submission; one that
    turns stale while queued is `stale` at processing, with no call.
25. (29) The attribution block shows the site-zone time and an ISO UTC
    `datetime`.
26. (30, 41) A summary with "yesterday" not in the source is flagged in
    the review panel and not rejected.
27. (31) A `/video/` URL and a JSON-LD `VideoObject` page are
    `not_article`.
28. (32) "Sponsored" in the title, "partner content" in the path, and a
    press release body are `sponsored`.
29. (33) A title-only item is `no_body` in skip mode, `link_only` in that
    mode, and uses an attached extractor when one is filtered in.
30. (34) A teaser ending "Subscribe to read" with 50 words is summarised
    in teaser mode with the excerpt note; with 20 words it becomes a
    link-only draft; in skip mode it is `paywalled`.
31. (35) A 60-word body gets the short target; a 400-word body the full
    target.
32. (36) A link-only draft has the title, the teaser, the attribution
    block, and no model call.
33. (37, 38) The request carries the schema and the delimited, cut text.
34. (39) A 503 requeues with back-off; a third failure ends the item; a
    401 pauses calls for an hour and shows the notice.
35. (40) `insufficient: true` writes no draft and records `thin`.
36. (41, 42) A summary with a number not in the source is retried once
    with the failure named, and on a second failure is `check_failed`
    with no post.
37. (41) An invented 6-word quote fails; a real one passes after quote
    normalisation.
38. (41) "Reports differ" fails the single-source check.
39. (41) "Word count: 112" and "Note: ..." lines are removed and the rest
    is kept.
40. (41) An over-length summary is retried, never truncated.
41. (43) The rendered post contains the source link from the record even
    when the model output names a different outlet.
42. (44) A filter that sets status `publish` is overridden to `draft` with
    a notice.
43. (45) With the plugin's render callback absent, the saved static
    fallback still shows link, byline and time.
44. (46) Deleting the block from content still renders the attribution on
    `the_content`.
45. (47) A filter that drops the link is ignored; one that restyles it is
    used.
46. (48) A correction by an administrator updates meta and history; an
    empty URL is refused.
47. (49) Publishing a draft whose attribution meta was removed returns it
    to draft with a notice.
48. (50, 51) Meta is readable in REST only with `edit_post`; the panel is
    hidden from users who cannot edit.
49. (52, 53) Two overlapping runs never process one row twice; an expired
    lease is reclaimed.
50. (54) A backlog is processed newest first.
51. (55) With gen-queue present, one job per item is enqueued with the
    idempotency key and the own runner is not scheduled.
52. (57) With cap 2, three items give two calls and one `capped` row; two
    concurrent claims on the last slot give one call.
53. (58) Capped items run the next site-local day.
54. (59) A spend-meter deny makes no call; an allow is followed by exactly
    one record or release.
55. (60) `--ignore-cap` is refused when the cap constant is set.
56. (64) A failing feed is logged and the next feed is fetched; three
    failures mark it `failing`.
57. (65, 66) A batch submit lets case 23 see the batch; the cache lifetime
    filter is removed after the fetch.
58. (67, 68) A 404 page, a timeout and a PDF all let the item proceed on
    feed data.
59. (69) A page with `nosnippet` becomes a link-only draft.
60. (70) A cross-host redirect without a canonical keeps the original URL.
61. (Failure table) A database error at submission does not throw into
    the caller; an unknown spend-meter shape is a deny.
62. (Section 6) Uninstall drops tables and options and leaves posts and
    their attribution readable.
63. (19) Language falls back from the item to the feed, then the page's
    `<html lang>`, then `und`; a summary in another language fails case 41.
64. (44) A drafted post has the configured type, status, author, title
    source and an excerpt under 300 characters ending at a sentence, and
    no post date set.

## 13. Open questions

To be answered by the builder in `docs/ARCHITECTURE.md`.

1. Paywall default: is `teaser` (summarise a 40-word excerpt) the right
   default, or should the default be `link_only` so nothing is written
   from an excerpt without an editor?
2. Source updates after publish: should the plugin offer an editor a
   one-click "redraft from the updated source" on a published post, or
   only log the update as specified?
3. Generator without a model: should a site be able to plug in its own
   summariser through a filter, so the plugin works with no model and no
   `llm-json-php` dependency at all?
4. Canonical and SEO: should the plugin offer an opt-in
   `<link rel="canonical">` to the source, or leave all SEO to the site?
   The default here is none.
5. Attribution language: should the block's fixed strings ("Source",
   "By", "Published") follow the site language (WordPress i18n, as
   specified) or the source item's language?
6. Number check across languages: which number-word lists ship in 0.1.0,
   and how does a site add one?
7. Cap unit: calls (as specified, retries included) or items, and should
   link-only drafts have their own separate cap?
8. Page fetching default: on (better dates and paywall signals) or off
   (no traffic to publishers unless the operator opts in)?

## 14. Lessons from production

Written as rules. Each one cost real time once.

1. Take the outlet from the resolved article URL. Never from a host
   stored at import time or from the `guid`: importers often set the
   article URL after the post is inserted, and the guid can belong to a
   feed service.
2. Treat mobile, AMP and tracking variants of a URL as one article.
   The same article arrives through two feeds more often than expected.
3. Paid placement is never a source. Advertorials, partner pages and
   press-release wire items are dropped before any summary.
4. A page that is not an article can carry text from a different story.
   Video, gallery and index pages are skipped, not summarised.
5. Render attribution in code from stored fields. Never ask the model to
   write the link, the outlet or the byline.
6. Never truncate a generated summary to fit. Its last sentence often
   carries the attribution or the key date. Retry or reject instead.
7. Never ask the model to count its words. It prints the count into the
   copy. Strip self-check lines anyway.
8. Decode literal JSON escape sequences before any check or display.
9. With one source, any phrase that implies several sources is false.
   Forbid it in the prompt and check for it in the output.
10. A label about a single source's stance or quality reads as a verdict
    on the publisher. Do not add one.
11. Every number and every quote in the summary must be findable in the
    source. Check it in code; the prompt alone is not enough.
12. Keep the item's text with the queue record. Later steps must not
    depend on the importer's own post still existing.
13. Validate every URL before using it, and fall back to the next known
    URL, never to an empty link.
14. Read the kill switch at the moment of the call, not only when the
    item is queued. A backlog otherwise drains after the switch is off.
15. Check every column after a schema change. `dbDelta` can skip a
    column add without an error.
