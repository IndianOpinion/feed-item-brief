=== Feed Item Brief ===
Contributors: indianopinion
Tags: rss, feed, summary, attribution, news
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 0.1.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Turns one feed item into an attributed summary draft with the source link, byline and publish time kept, for any importer or none.

== Description ==

Written from scratch; not derived from any third-party plugin.

Feed Item Brief takes one item from a feed, decides whether it can be summarised honestly, writes a short draft for an editor, and adds an attribution block with the source name, a link to the original, the byline and the publish time. The block is rendered by the plugin from stored fields, never by the model, and it cannot be removed by editing the post.

* Works with WordPress core feeds through a built-in adapter, with any importer through its own hook, or with no importer (Posts > Brief from URL, or WP-CLI).
* Skips video, gallery and index pages, sponsored posts, items with no body and stale news. Paywalled teasers become link-only drafts by default.
* Works out a trustworthy publish time when feeds lie about dates, and shows the editor why that time was chosen.
* Checks every number and quote in the summary against the source, rejects phrases that imply several sources, never truncates, and retries once.
* Drafts only. A daily cap on model calls and a kill switch in its own settings.

Model calls go through the llm-json-php library under your own API key, or through a summariser your site supplies with the `feed_item_brief_summarise` filter.

This project came out of the work on indianopinion.org and is written from scratch; it is independent of and not affiliated with any third-party plugin it integrates with.

== Installation ==

1. Upload the plugin folder to `wp-content/plugins/` and activate it.
2. Add `define('FEED_ITEM_BRIEF_API_KEY', '...');` and `define('FEED_ITEM_BRIEF_MODEL', '...');` to `wp-config.php`, or hook your own summariser.
3. Settings > Feed Item Brief: add feeds, tick Enabled, save.

== Frequently Asked Questions ==

= Does it publish anything? =

No. It writes drafts (or pending posts). Any other status is forced back.

= What is sent to the model provider? =

The item's text, title, publisher name and date. No visitor or user data.

= Does it fetch publisher pages? =

Only when "Fetch source pages" is on. It is off by default.

== Privacy ==

Article text from other publishers is sent to the language model provider you configure. Author names in attributions are the public bylines of the original articles. Email addresses in bylines are dropped and never stored. Article bodies are cleared seven days after processing ends.

== Changelog ==

= 0.1.0 =
* First release.
