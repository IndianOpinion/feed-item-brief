# Changelog

All notable changes to this project are documented here. The format is
based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-11-19

### Added

- Submission hook, batch hook and function form, with an adapter interface
  and registry, a reference adapter for WordPress core feeds, a "Brief
  from URL" screen and WP-CLI commands (`status`, `run`, `make`, `log`,
  `retry`, `check`).
- Item validation and clean-up: URL checks, titles, plain-text bodies,
  literal escape decoding, bylines without emails, source names from the
  resolved URL, article keys that merge mobile, AMP and tracking variants,
  duplicates and source updates.
- Publish time resolution with reasons: assumed zones, missing, future and
  uniform claims, re-dated items, page metadata, URL dates and first seen.
- Classification: not an article, sponsored, no body, paywalled teaser,
  short and full, plus `nosnippet` and `noarchive` pages as link-only.
- Model calls through llm-json-php with a JSON schema, or through the
  `feed_item_brief_summarise` filter; back-off, auth and credit pauses.
- Output checks: narration lines removed, numbers and quotes checked
  against the source, single-source phrases, length and language, one
  retry; relative time words flagged for the editor.
- Drafts with a dynamic, locked attribution block that has a static
  fallback, is restored on output if deleted, is validated when restyled,
  and is checked at publish.
- Daily cap with an atomic counter, kill switch, optional gen-queue and
  spend-meter integration, retention, uninstall, admin notices and a
  review panel with corrections and history.
