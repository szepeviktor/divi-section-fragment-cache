# Divi Section Fragment Cache

WordPress plugin that fragment-caches top-level Divi sections and skips caching for sections that render the `dsec-no-cache` HTML class.

## What it does

- Hooks into `the_content` on singular frontend requests.
- Splits the content into top-level `[et_pb_section]...[/et_pb_section]` blocks.
- Renders each eligible section once and stores the rendered HTML in a transient for one day.
- Bypasses the cache for sections whose rendered HTML contains the `dsec-no-cache` class.
- Sections marked with `dsec-no-cache` are rendered on every request and are never stored in transients.

## Important limitation

This plugin only works reliably when the content is made only of `et_pb_section` shortcodes.

If the content contains other top-level markup or shortcode structures outside Divi sections, those parts are passed through unchanged and the cache strategy may not match the layout you expect.

## Opt out of caching

Add the `dsec-no-cache` CSS class anywhere in a section's rendered HTML to bypass fragment caching for that top-level section.

This is useful for sections that include user-specific, time-sensitive, or otherwise dynamic output.

## Installation

1. Copy the plugin into your WordPress plugins directory.
2. Activate **Divi Section Fragment Cache**.
3. Add the `dsec-no-cache` class to any section that should always be rendered dynamically.

## Notes

- Cached fragments are stored in transients for `DAY_IN_SECONDS`.
- Cache keys are based on the post ID, section index, and section content hash.
- Sections with unbalanced `et_pb_section` shortcode nesting are not cached.
