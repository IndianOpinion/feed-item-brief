# Architecture

How feed-item-brief is put together, how an item flows through it, and
every decision taken while building it from [the PRD](PRD.md). Case
numbers refer to PRD section 4; test numbers to PRD section 12.

## Modules

| Module | Role |
|---|---|
| `Plugin` | Builds the services, registers every hook, owns cron, the manual path (`make`), retention and the gen-queue job handler. |
| `Item` | Immutable, validated item (cases 7 to 12, 17, 19). Built by `Item::fromArray()`; throws `InvalidItemException` with `bad_url` or `no_title`. |
| `Text`, `Url`, `Byline` | Pure helpers: escape decoding, HTML to paragraphs, word counts (CJK aware), sentence-safe cutting; URL validation and the article key; byline clean-up. |
| `Intake` | Submission: adapter id, validation, URL filter, self check, duplicates and updates, date at submission, stale check, insert. Never throws into the importer. |
| `DateParser`, `DateResolver`, `ResolvedDate` | Claimed time parsing and the resolution order of section 4.3, with reason codes. |
| `PageFetcher`, `PageMeta` | Optional single fetch through `wp_safe_remote_get()`, and a head, JSON-LD and first-`<article>` parser. |
| `Classifier`, `BodyClass` | Section 4.4, first match wins. |
| `Gate`, `Reservation` | The money gate: switch, auth pause, daily cap, spend-meter. |
| `Summariser`, `Prompt`, `OutputChecker`, `NumberWords`, `CheckResult`, `Summary` | The model call, the generic prompt and schema, the output checks and the one retry. |
| `Model\ModelClient`, `Model\LlmJsonClient` | The only seam to a provider. `LlmJsonClient` is the only class that touches llm-json-php. |
| `Processor` | Queue runs, leases, the per-record pipeline, back-off, final statuses, log retries. |
| `Drafter`, `Attribution`, `AttributionRenderer`, `Meta` | The draft insert, the attribution values, the block markup and its validation, post meta keys. |
| `Frontend` | Block registration, the `the_content` guard, the publish guard, the opt-in canonical link. |
| `ReviewPanel` | Meta registration, REST visibility, the review meta box, corrections with history. |
| `Repository`, `WpdbRepository`, `Installer`, `Uninstaller` | Storage, schema with column checks, clean removal. |
| `Registry`, `Adapter`, `Adapter\CoreFeedAdapter` | The adapter interface and the reference adapter for `fetch_feed()`. |
| `Integration\GenQueue`, `Integration\SpendMeter` | Optional companions, loaded only when present. |
| `Admin\Pages`, `Cli\Command`, `Notices`, `Log` | Screens, WP-CLI, admin notices, the optional error log line. |

## Data flow

```
importer / adapter / manual / CLI
        |
        v
   Intake::submit ---- rejected (bad_url, no_title, self)
        |          \--- duplicate / update (note on the draft)
        |           \-- stale
        v
   items table: queued (or paused when switched off)
        |
   cron every 5 min, or gen-queue job, or "make" at once
        v
   Processor::process (leased)
     switch off? -> paused
     page fetch (opt-in) -> title, language, canonical, page date
     stale? -> stale
     Classifier -> not_article | sponsored | no_body | paywalled (end)
                -> link_only (draft without a call)
                -> short | full | teaser (summarise)
     Gate: switch, pause, cap, spend-meter
     Summariser: filter or llm-json-php, checks, one retry
        -> thin | check_failed | provider error (back-off) | auth/credit pause
     Drafter: draft + attribution block + meta
        -> drafted | insert_failed (retry once)
```

## Storage

- `{prefix}feed_item_brief_items`: one row per submission. Times are Unix
  seconds in `BIGINT` columns, so there is no time zone ambiguity in the
  database. `final_at` marks a record that the queue never claims again.
  `meta` (JSON) keeps the submitted item fields, the feed time zone, the
  per-feed author, update notes and the page fetch result. `result` keeps
  a checked summary so an insert retry never pays for a second call.
- `{prefix}feed_item_brief_daily`: `day` (site-local `Y-m-d`) and `calls`.
- Options: `feed_item_brief_settings` (not autoloaded),
  `feed_item_brief_switch` (enabled and cap, autoloaded),
  `feed_item_brief_pause`, `feed_item_brief_state`, `feed_item_brief_feeds`,
  `feed_item_brief_feed_cursor`, `feed_item_brief_db_version`,
  `feed_item_brief_schema_error`.
- Post meta keys start with `_feed_item_brief_` (see `Meta`).

## Rulings on the PRD's open questions

1. **Paywall default is `link_only`.** Nothing is written from an excerpt
   unless an editor opts in with the teaser mode. Reason: a 40-word excerpt
   is the place where a model is most tempted to go beyond the text.
2. **Source changes after publish are logged only.** The `update` row
   records it; a published post is not touched. One-click "redraft from
   the updated source" is a roadmap item. Reason: changing published copy
   needs an editor's judgement, and a redraft spends money.
3. **No canonical link by default.** The filter `feed_item_brief_canonical`
   (bool, post id, `Attribution`) adds `<link rel="canonical">` to the
   source and removes core's own canonical on that post. Reason: SEO
   policy belongs to the site; an opt-in keeps both choices open.
4. **Attribution strings follow the site language** through the
   `feed-item-brief` text domain. Reason: the block is part of the site's
   page, read by the site's readers.
5. **English number words only, with a filter.**
   `feed_item_brief_number_words` receives and returns
   `language => ['units' => [...], 'scales' => [...]]`. For a language with
   no list, a number in the summary that is not in the source as digits is
   flagged in the review panel (`unverified_number`), not rejected. Reason:
   no false rejects, and the editor still sees it.
6. **The cap counts calls, retries included; link-only drafts do not use
   it.** A slot taken for a call that never happened (no summariser, no
   model or key configured, spend-meter deny) is given back. Reason: the
   cap is about money, and only calls cost money.
7. **Source page fetching is off by default.** This departs from the
   PRD's "default on" in 4.10. Reason: no traffic to publishers unless the
   operator opts in. With it off, an item with no title is rejected at
   submission (case 8 needs the page to fill it).

## Decisions taken while building

1. **`provider_failed` status.** The PRD's status list has no name for an
   item that failed at the provider three times. Without one it would sit
   in the queue forever or borrow a misleading name. It is final, shows in
   the log and can be retried.
2. **Back-off.** After the first and second provider failure the item
   waits 5 and 30 minutes. The third failure ends it (test 34). The
   120-minute step of case 39 is kept in `Processor::BACKOFF` but is not
   reached with three attempts.
3. **One attempt per llm-json-php call.** The library's own retry is set
   to one attempt per route, so every billed call passes the plugin's gate
   and counts against the cap. The plugin's queue does the retrying.
4. **Schema without length keywords.** `minLength`, `maxLength`,
   `minItems` and `maxItems` are left out of the JSON schema so strict
   structured output stays possible on every provider. The headline length
   (20 to 120 characters) and the paragraph count (1 to 3) are checked in
   code and are hard failures, like the rest of the schema.
5. **Date reasons.** `ResolvedDate` keeps how the time was chosen
   (`date_feed`, `date_page`, `date_bumped`, `date_url`,
   `date_first_seen`) and, separately, what was wrong with the claim
   (`assumed_tz`, `date_missing`, `date_future`, `date_uniform`). They are
   stored as one comma list, reason last. The review panel shows them
   unless the list is exactly `date_feed` or `date_page`.
6. **Two-phase dating.** The date is resolved at submission (no network)
   and refined with the page at processing. The batch check of case 23
   needs every claimed time of one fetch, which only exists at submission.
7. **Identical resubmissions add no row.** A record from the same adapter
   and source with the same body hash means the importer sent the item
   again (feeds are polled every 30 minutes). Recording a `duplicate` row
   each time would flood the log. The submission returns
   `accepted() === false`, status `duplicate`, reason `seen`.
8. **Updates.** Only a body that differs by more than white space counts.
   A note added before the draft exists is kept on the record and copied
   to the draft when it is written.
9. **Insert retry.** A failed insert keeps the checked summary on the
   record and is retried once after 60 seconds, so the second attempt
   costs no model call. A second failure is final.
10. **Registrable host.** There is no public suffix list. The plugin
    compares hosts after removing one leading `www.`, `m.`, `mobile.` or
    `amp.` label. This is the rule of case 11 and case 13, applied to the
    self check and the redirect check too.
11. **Relative time words.** A word counts as supported when it is
    anywhere in the source text, not only in the sentence the claim came
    from. Finding that sentence reliably is not possible without a
    language model. It is a flag, so a miss costs an editor a glance.
12. **Numbers from the date.** The day, month and year of the resolved
    publish time (in UTC and in the site zone) count as supported numbers,
    because the prompt gives the model that date to use.
13. **Narration clean-up.** Lines that start with a banned prefix, markdown
    headings, bullet lines and lines that are wholly bracketed are removed;
    inline `[...]` notes are removed. A summary left empty is a hard
    failure.
14. **Not-article by JSON-LD only without an article node.** A
    `NewsArticle` with an embedded `VideoObject` is still an article.
15. **Order of the robots check.** `nosnippet` or `noarchive` is applied
    after the not-article and sponsored checks, so paid placement never
    becomes even a link-only draft.
16. **Teaser text.** The closing paywall sentence, a trailing ellipsis,
    `[...]` and "Read more" are removed before the teaser is summarised or
    shown in a link-only draft.
17. **Review panel.** One meta box serves both editors; the block editor
    shows meta boxes in its sidebar. A separate block-editor plugin
    sidebar would need a build step for no extra function.
18. **REST.** Meta is registered with `show_in_rest`, an auth callback that
    requires `edit_post` for reads and refuses every write, and a
    `rest_prepare_{type}` filter that removes the plugin's meta for users
    who cannot edit the post. Corrections always go through the panel and
    its history.
19. **Corrections refresh the fallback.** After a correction the saved
    static block markup is rewritten, so the fallback matches the meta.
20. **Stale retry keeps the real date.** Retrying a `stale` record sets an
    "ignore age" flag on the record; the resolved time and the attribution
    are not changed.
21. **Companion contracts.** gen-queue: `gen_queue_enqueue($type,
    $payload, ['idempotency_key' => ...])`, handler through
    `gen_queue_register_handler()` when present and the action
    `gen_queue_handle_feed_item_brief.summarise`. spend-meter:
    `SPEND_METER_API` version 1, `spend_meter_pre_spend(['purpose',
    'caller'])` returning `['allowed' => true, 'reservation_id' => ...]`,
    then `spend_meter_record($id, $usage)` or `spend_meter_release($id)`.
    These names come from the PRD; anything else in a reply is a deny or a
    failed enqueue. A failed enqueue schedules one run of the plugin's own
    queue so the item is not stranded.
22. **Closed on money.** An unreadable switch option is off; an unreadable
    cap is 0; a counter error is "cap reached"; a spend-meter error is a
    deny.
23. **Settings screen enable guard.** "Enabled" cannot be saved on unless a
    summariser filter is hooked, or the library is installed and a model
    and key are set. This is the "off until a model and key are
    configured" default of section 7.1.
24. **Feed list format.** One feed per line, `URL | source name | author id
    | time zone | enabled`. Invalid URLs and unknown time zones are
    dropped on save. Failure counts survive edits.
25. **Text in drafts.** Titles and excerpts pass through
    `sanitize_text_field()`; each paragraph is plain text, escaped, inside a
    paragraph block; the whole content passes `wp_kses_post()`.
26. **Testing.** Unit tests run on a WordPress stub layer, an in-memory
    repository with the same lease and counter semantics, and a fake model
    client. `WpdbRepository` and the schema check run against a real MySQL
    server in CI (and locally when `FIB_TEST_DB_*` is set). SimplePie is a
    development dependency so the core adapter is tested on real RSS 2.0
    and Atom fixtures.

## What is not implemented

- One-click redraft after a source update (ruling 2, roadmap).
- Number-word lists other than English (ruling 5; sites add them).
- A block editor plugin sidebar (decision 17; the meta box covers it).
