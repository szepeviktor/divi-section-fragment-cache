# Divi Section Fragment Cache

WordPress plugin that fragment-caches top-level Divi sections and skips caching for sections that contain denylisted shortcodes.

## What it does

- Hooks into `the_content` on singular frontend requests.
- Splits the content into top-level `[et_pb_section]...[/et_pb_section]` blocks.
- Renders each eligible section once and stores a render snapshot in a transient for one day.
- Bypasses the cache for sections that contain shortcodes from the deny list.
- Bypasses the plugin entirely during Divi frontend builder requests detected by `et_core_is_fb_enabled()`.
- Replays captured Divi side effects on cache hit before returning cached HTML.

## Snapshot contents

The current cache payload is not HTML-only.

Depending on what Divi records during section rendering, the plugin may store and replay:

- `html`
- `animation_data`
- `link_options_data`
- scroll effects state
- WordPress lazy-loading disable state
- enqueued stylesheet handles
- enqueued script handles
- generated CSS deltas
- font queue deltas
- `et_pb_subjects_cache` deltas

## Important limitation

This plugin only works reliably when the content is made only of `et_pb_section` shortcodes.

If the content contains other top-level markup or shortcode structures outside Divi sections, those parts are passed through unchanged and the cache strategy may not match the layout you expect.

The plugin also relies on inferred Divi runtime side effects rather than an official Divi snapshot API, so compatibility should still be treated as partial rather than complete.

To reduce editor risk, the plugin does not run during Divi frontend builder requests.

## Deny list customization

The plugin ships with a built-in deny list for known dynamic shortcodes in these plugins:

- `gravityforms`
- `divi-event-calendar-module`

You will likely need to customize this deny list for your own site so that any shortcode producing user-specific, time-sensitive, or otherwise dynamic output is excluded from fragment caching.

At the moment the deny list is defined in `divi-section-fragment-cache.php` as the `Plugin::DENYLIST` constant, so customization currently means editing that list in code.

## Installation

1. Copy the plugin into your WordPress plugins directory.
2. Activate **Divi Section Fragment Cache**.
3. Review the deny list before using it in production.

## Notes

- Cached render snapshots are stored in transients for `DAY_IN_SECONDS`.
- Cache keys are based on the post ID, section index, and section content hash.
- Sections with unbalanced `et_pb_section` shortcode nesting are not cached.
