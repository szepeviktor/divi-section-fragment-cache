## Project

This repository contains a WordPress plugin that fragment-caches Divi top-level sections extracted from `the_content`.

Repository goal so far:
- speed up Divi page rendering
- cache top-level `[et_pb_section]...[/et_pb_section]` fragments
- skip sections containing known dynamic shortcodes
- preserve the Divi request-scoped side effects needed for cached sections to behave like freshly rendered sections

---

## Critical architectural insight

### Divi render has side effects

When Divi renders shortcode/layout content, it does not only return HTML.

It also performs request-scoped side effects, such as:
- collecting `animation_data`
- collecting `link_options_data`
- collecting generated CSS through builder style managers
- queueing fonts
- sometimes updating metadata or other internal state

This means:

> Caching only rendered HTML is not equivalent to replaying a Divi render pass.

A cached section may output the correct HTML, but still miss required Divi runtime state for the current request.

---

## Why this matters for this plugin

The plugin:
- hooks into `the_content`
- splits top-level `et_pb_section` blocks
- renders each section individually via `apply_filters('et_builder_render_layout', $section)`
- stores a render snapshot in a transient
- bypasses the plugin entirely during Divi frontend builder requests detected by `et_core_is_fb_enabled()`
- on cache hit, replays stored side effects before returning the cached HTML

That means the plugin is already moving away from an HTML-only fragment cache and toward a Divi render snapshot cache.

However, Divi compatibility is still incomplete because replay coverage is partial and based on inferred runtime state rather than an official Divi snapshot API.

---

## Current plugin summary

Current behavior, simplified:
- filter `the_content`
- skip frontend builder requests detected by `et_core_is_fb_enabled()`
- split content into top-level `[et_pb_section]...[/et_pb_section]`
- determine cacheability using a shortcode denylist
- key cache by:
  - post ID
  - section index
  - md5 of section source
- render with:
  - `apply_filters('et_builder_render_layout', $section)`
- store:
  - `html`
  - `animation_data`
  - `link_options_data`
  - generated CSS deltas
  - font queue deltas
  - `et_pb_subjects_cache` deltas
- backend:
  - transients
  - fixed `DAY_IN_SECONDS` TTL

### Current weaknesses

#### 1. Snapshot coverage is still partial

The plugin already captures and replays several Divi side effects, but that still does not prove full Divi equivalence.

Future regressions may still come from request-scoped state that is not yet captured or is replayed imperfectly.

#### 2. Denylist-based dynamic detection

This is a weak abstraction.
A shortcode denylist can never fully describe:
- user-specific output
- time-sensitive output
- query-sensitive output
- external-data-dependent output
- context-dependent output

#### 3. `sectionIndex` as cache identity

This is unstable.
If a new section is inserted before another section, all following indices shift.

#### 4. Built on shortcode text slicing

This is fragile and tied to Divi legacy shortcode storage.
Divi 5 is moving away from shortcode-based storage.

---

## Required mental model for future code changes

Treat a Divi section render as producing a render snapshot, not just HTML.

A render snapshot may contain:
- `html`
- `animation_data`
- `link_options_data`
- generated style side effects
- font queue side effects
- metadata side effects
- other Divi runtime state if needed

The plugin has already started this transition.
Future changes should extend the snapshot model, not collapse it back into HTML-only caching.

---

## Current snapshot model

At the time of writing, cached payloads may contain:

```php
[
    'html' => $html,
    'animation_data' => [...],
    'link_options_data' => [...],
    'generated_css' => '...',
    'critical_css' => '...',
    'fonts_queue' => [...],
    'user_fonts_queue' => [...],
    'subjects_cache' => [...],
]
```

Backward compatibility matters:
- old cache entries may still be raw HTML strings
- older array payloads may omit some newer keys
- replay logic should stay tolerant of partially populated payloads

---

## Animation data remains the reference pattern

`animation_data` is still the clearest example of how Divi side-effect capture and replay should work.

### Capture pattern

Pseudo-logic:

```php
$before = et_builder_handle_animation_data();
$html   = apply_filters('et_builder_render_layout', $section);
$after  = et_builder_handle_animation_data();

$delta = diff_by_class($before, $after);
```

### Replay pattern

Pseudo-logic:

```php
foreach ($payload['animation_data'] as $entry) {
    et_builder_handle_animation_data($entry);
}

return $payload['html'];
```

### Diff rule

Use the animation entry `class` field as the identity when diffing.

Reason:
- Divi uses class-oriented matching and deduplication patterns for these entries
- the rendered HTML and the stored entry must continue to refer to the same class names

### Timing requirement

Replay must happen before Divi outputs footer-side JS data.

In practice, replaying during the existing `the_content` processing phase is early enough.

---

## Coding constraints for implementation

### 1. Be conservative

Do not redesign the entire plugin in one step.

Prefer incremental changes that keep the patch reviewable and preserve current behavior.
Protect builder and editor contexts before optimizing normal frontend requests.

### 2. Maintain backward compatibility where reasonable

When reading cache:
- accept old cache payloads that may contain only a string HTML value
- accept array payloads with `html`
- replay only the side effects that exist in the payload

### 3. Write small testable helpers

Prefer helper extraction for:
- Divi snapshot reads
- indexing entries by class
- diffing side-effect deltas
- replaying captured side effects

### 4. Guard Divi-specific calls

Always check `function_exists()` or `class_exists()` before calling Divi functions or methods.

### 5. Do not assume more about payload shapes than necessary

Only rely on stable facts you actually need, such as:
- payload is array-like
- `class` exists and is string for valid class-keyed entries

Avoid overfitting to optional keys unless required for correctness.

---

## Immediate implementation direction

The first milestone, `animation_data` capture and replay, is already implemented.

The next changes should focus on one of these:
- strengthening correctness of the existing snapshot and replay logic
- adding targeted regression tests
- improving observability with optional debug logging
- tightening cache identity and invalidation

Do not treat the repository as if it were still at the pre-snapshot phase.

---

## Suggested near-term work

### Phase 1: verify existing side-effect replay

Review and test the current handling of:
- `animation_data`
- `link_options_data`
- generated CSS
- font queues
- `et_pb_subjects_cache`

### Phase 2: logging and debug hooks

Add optional debug logging for:
- number of captured entries per side-effect type
- keys or classes captured
- keys or classes replayed

Keep logs easy to disable.

### Phase 3: abstract side effects more explicitly

Introduce clearer internal naming around:
- capture side effects
- store side effects
- replay side effects

That can be done without a large refactor.

---

## Non-goals for small changes

Do not try to solve all of these in the same patch:
- switching away from shortcode slicing
- moving from denylist to opt-in cache policy
- replacing transients with object cache
- redesigning invalidation end to end
- restructuring the plugin into a multi-file architecture unless there is a concrete need

Those are valid future directions, but they are separate from small correctness-oriented fixes.

---

## Future architecture directions

These are good future improvements, but they are secondary to keeping the current snapshot model correct.

### Better cache policy

Move from shortcode denylist toward:
- explicit opt-in cache markers
- configurable cache policy registry
- dependency-aware vary rules

### Better cache identity

Move away from:
- section index

Toward:
- stable section identifier
- content hash plus explicit fragment identity
- versioned cache keys

### Better invalidation

Move from:
- fixed TTL only

Toward:
- versioned keys
- targeted invalidation on content, template, or library changes

### Better Divi compatibility

Move away from:
- dependence on raw shortcode layout slicing
- inferred partial replay of runtime state

Toward:
- a model closer to Divi’s real layout and render tree
- a more explicit and testable render snapshot abstraction

---

## Practical coding instructions for Codex / agent

When modifying this plugin:

1. Read the existing `divi-section-fragment-cache.php` carefully.
2. Preserve current behavior unless directly changing it for correctness or compatibility.
3. Do not assume Divi render is pure.
4. Keep patches minimal and reviewable.
5. Prefer helper extraction over deeply inlined logic.
6. Ensure cache hit paths replay stored side effects before returning HTML.
7. Ensure cache miss paths capture side effects around the actual section render.
8. Keep Divi frontend builder requests out of the cache path unless there is a very strong reason not to.
9. Maintain backward compatibility with older cached payloads where reasonable.
10. Keep all comments in English.

---

## Short version for the implementer

The central constraint is:

> Divi section rendering mutates request-level runtime state, so caching only HTML is not enough.

The plugin already implements a partial render snapshot cache.

The next job is not to add the first side effect.
The next job is to make the existing snapshot model more correct, more observable, and better aligned with Divi’s real runtime behavior.
