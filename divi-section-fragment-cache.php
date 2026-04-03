<?php

declare(strict_types=1);

/**
 * Plugin Name: Divi Section Fragment Cache
 * Description: Fragment cache for top-level Divi sections, skipping denylisted shortcodes.
 * Version: 0.2.1
 * Requires PHP: 7.4
 */

namespace SzepeViktor\DiviSectionFragmentCache;

final class Plugin
{
    /**
     * @var string[]
     */
    private const DENYLIST = [
        'gravityform',
        'gravityforms',
        'diec_event_carousel',
        'diec_event_page',
        'decs_event_subscriber',
        'decm_event_filter',
        'decm_event_filter_child',
        'dcet_event_ticket',
        'decm_divi_event_calendar',
    ];

    public function register(): void
    {
        \add_filter('the_content', [$this, 'filterContent'], 10);
    }

    public function filterContent(string $content): string
    {
        if ($this->shouldBypassContentFilter($content)) {
            return $content;
        }

        $postId = \get_the_ID();

        if (!$postId) {
            return $content;
        }

        $parts = $this->splitTopLevelSections($content, (int) $postId);

        if ($parts === null) {
            return $content;
        }

        $output = '';
        $sectionIndex = 0;

        foreach ($parts as $part) {
            if (!$part['is_section']) {
                $output .= $part['content'];
                continue;
            }

            $section = $part['content'];

            if ($this->containsDenylistedShortcode($section)) {
                $output .= $this->renderSection($section);
            } else {
                $output .= $this->getCachedSection((int) $postId, $sectionIndex, $section);
            }

            $sectionIndex++;
        }

        return $output;
    }

    private function shouldBypassContentFilter(string $content): bool
    {
        if (
            \is_admin()
            || \is_feed()
            || \is_preview()
            || !\is_singular()
            || \strpos($content, '[et_pb_section') === false
        ) {
            return true;
        }

        if (\function_exists('et_core_is_fb_enabled') && \et_core_is_fb_enabled()) {
            return true;
        }

        return false;
    }

    private function renderSection(string $section): string
    {
        return (string) \apply_filters('et_builder_render_layout', $section);
    }

    private function getCachedSection(int $postId, int $sectionIndex, string $section): string
    {
        $cacheKey = \sprintf(
            'dsec:%d:%d:%s',
            $postId,
            $sectionIndex,
            \md5($section)
        );

        $cached = \get_transient($cacheKey);

        $payload = $this->normalizeCachedPayload($cached);

        if ($payload !== null) {
            $this->replaySectionSideEffects((int) $postId, $payload);

            return $payload['html'];
        }

        $snapshot = $this->captureSectionRenderSnapshot((int) $postId, $section);

        \set_transient($cacheKey, $snapshot, \DAY_IN_SECONDS);

        return $snapshot['html'];
    }

    /**
     * @param mixed $cached
     * @return array{
     *     html: string,
     *     animation_data: array<int, array<string, mixed>>,
     *     link_options_data: array<int, array<string, mixed>>,
     *     scroll_effects: array{desktop: array<int, array<string, mixed>>, tablet: array<int, array<string, mixed>>, phone: array<int, array<string, mixed>>},
     *     wp_lazy_loading_disabled: bool,
     *     enqueued_styles: array<int, string>,
     *     enqueued_scripts: array<int, string>,
     *     generated_css: string,
     *     critical_css: string,
     *     fonts_queue: array<string, array<string, mixed>>,
     *     user_fonts_queue: array<string, mixed>,
     *     subjects_cache: array<string, mixed>
     * }|null
     */
    private function normalizeCachedPayload($cached): ?array
    {
        if (!\is_array($cached) || !\is_string($cached['html'] ?? null)) {
            return null;
        }

        return [
            'html' => $cached['html'],
            'animation_data' => isset($cached['animation_data']) && \is_array($cached['animation_data'])
                ? \array_values($this->indexEntriesByClass($cached['animation_data']))
                : [],
            'link_options_data' => isset($cached['link_options_data']) && \is_array($cached['link_options_data'])
                ? \array_values($this->indexEntriesByClass($cached['link_options_data']))
                : [],
            'scroll_effects' => $this->normalizeScrollEffectsPayload($cached['scroll_effects'] ?? null),
            'wp_lazy_loading_disabled' => !empty($cached['wp_lazy_loading_disabled']),
            'enqueued_styles' => $this->normalizeStringListPayload($cached['enqueued_styles'] ?? null),
            'enqueued_scripts' => $this->normalizeStringListPayload($cached['enqueued_scripts'] ?? null),
            'generated_css' => \is_string($cached['generated_css'] ?? null) ? $cached['generated_css'] : '',
            'critical_css' => \is_string($cached['critical_css'] ?? null) ? $cached['critical_css'] : '',
            'fonts_queue' => isset($cached['fonts_queue']) && \is_array($cached['fonts_queue']) ? $cached['fonts_queue'] : [],
            'user_fonts_queue' => isset($cached['user_fonts_queue']) && \is_array($cached['user_fonts_queue']) ? $cached['user_fonts_queue'] : [],
            'subjects_cache' => isset($cached['subjects_cache']) && \is_array($cached['subjects_cache']) ? $cached['subjects_cache'] : [],
        ];
    }

    /**
     * @return array{
     *     html: string,
     *     animation_data: array<int, array<string, mixed>>,
     *     link_options_data: array<int, array<string, mixed>>,
     *     scroll_effects: array{desktop: array<int, array<string, mixed>>, tablet: array<int, array<string, mixed>>, phone: array<int, array<string, mixed>>},
     *     wp_lazy_loading_disabled: bool,
     *     enqueued_styles: array<int, string>,
     *     enqueued_scripts: array<int, string>,
     *     generated_css: string,
     *     critical_css: string,
     *     fonts_queue: array<string, array<string, mixed>>,
     *     user_fonts_queue: array<string, mixed>,
     *     subjects_cache: array<string, mixed>
     * }
     */
    private function captureSectionRenderSnapshot(int $postId, string $section): array
    {
        $beforeAnimationData = $this->getAnimationDataSnapshot();
        $beforeLinkOptionsData = $this->getLinkOptionsDataSnapshot();
        $beforeScrollEffects = $this->getScrollEffectsSnapshot();
        $beforeLazyLoadingDisabled = $this->isWordPressLazyLoadingDisabledForRequest();
        $beforeEnqueuedAssets = $this->getEnqueuedAssetsSnapshot();
        $beforeGeneratedCss = $this->getGeneratedCssSnapshot();
        $beforeFontQueues = $this->getFontQueueSnapshot();
        $beforeSubjectsCache = $this->getSubjectsCacheSnapshot($postId);
        $html = $this->renderSection($section);
        $afterAnimationData = $this->getAnimationDataSnapshot();
        $afterLinkOptionsData = $this->getLinkOptionsDataSnapshot();
        $afterScrollEffects = $this->getScrollEffectsSnapshot();
        $afterLazyLoadingDisabled = $this->isWordPressLazyLoadingDisabledForRequest();
        $afterEnqueuedAssets = $this->getEnqueuedAssetsSnapshot();
        $afterGeneratedCss = $this->getGeneratedCssSnapshot();
        $afterFontQueues = $this->getFontQueueSnapshot();
        $afterSubjectsCache = $this->getSubjectsCacheSnapshot($postId);
        $generatedCssDelta = $this->diffGeneratedCssSnapshot($beforeGeneratedCss, $afterGeneratedCss);

        return [
            'html' => $html,
            'animation_data' => $this->diffEntriesByClass($beforeAnimationData, $afterAnimationData),
            'link_options_data' => $this->diffEntriesByClass($beforeLinkOptionsData, $afterLinkOptionsData),
            'scroll_effects' => $this->diffScrollEffectsSnapshot($beforeScrollEffects, $afterScrollEffects),
            'wp_lazy_loading_disabled' => !$beforeLazyLoadingDisabled && $afterLazyLoadingDisabled,
            'enqueued_styles' => $this->diffStringList($beforeEnqueuedAssets['styles'], $afterEnqueuedAssets['styles']),
            'enqueued_scripts' => $this->diffStringList($beforeEnqueuedAssets['scripts'], $afterEnqueuedAssets['scripts']),
            'generated_css' => $generatedCssDelta['generated_css'],
            'critical_css' => $generatedCssDelta['critical_css'],
            'fonts_queue' => $this->diffAssociativeArray($beforeFontQueues['fonts_queue'], $afterFontQueues['fonts_queue']),
            'user_fonts_queue' => $this->diffAssociativeArray($beforeFontQueues['user_fonts_queue'], $afterFontQueues['user_fonts_queue']),
            'subjects_cache' => $this->diffAssociativeArray($beforeSubjectsCache, $afterSubjectsCache),
        ];
    }

    /**
     * @param mixed $items
     * @return array<int, string>
     */
    private function normalizeStringListPayload($items): array
    {
        if (!\is_array($items)) {
            return [];
        }

        $normalized = [];

        foreach ($items as $item) {
            if (!\is_string($item) || $item === '') {
                continue;
            }

            $normalized[] = $item;
        }

        return \array_values(\array_unique($normalized));
    }

    /**
     * @param mixed $scrollEffects
     * @return array{desktop: array<int, array<string, mixed>>, tablet: array<int, array<string, mixed>>, phone: array<int, array<string, mixed>>}
     */
    private function normalizeScrollEffectsPayload($scrollEffects): array
    {
        $normalized = [
            'desktop' => [],
            'tablet' => [],
            'phone' => [],
        ];

        if (!\is_array($scrollEffects)) {
            return $normalized;
        }

        foreach (\array_keys($normalized) as $device) {
            if (!isset($scrollEffects[$device]) || !\is_array($scrollEffects[$device])) {
                continue;
            }

            $normalized[$device] = $this->normalizeScrollEffectsEntries($scrollEffects[$device]);
        }

        return $normalized;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getAnimationDataSnapshot(): array
    {
        return $this->getClassKeyedDiviDataSnapshot('et_builder_handle_animation_data');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getLinkOptionsDataSnapshot(): array
    {
        return $this->getClassKeyedDiviDataSnapshot('et_builder_handle_link_options_data');
    }

    /**
     * @return array{desktop: array<int, array<string, mixed>>, tablet: array<int, array<string, mixed>>, phone: array<int, array<string, mixed>>}
     */
    private function getScrollEffectsSnapshot(): array
    {
        if (!\class_exists('\ET_Builder_Element') || !\is_array(\ET_Builder_Element::$_scroll_effects_fields ?? null)) {
            return $this->normalizeScrollEffectsPayload([]);
        }

        return $this->normalizeScrollEffectsPayload(\ET_Builder_Element::$_scroll_effects_fields);
    }

    private function isWordPressLazyLoadingDisabledForRequest(): bool
    {
        return \has_filter('wp_lazy_loading_enabled', '__return_false') !== false;
    }

    /**
     * @return array{styles: array<int, string>, scripts: array<int, string>}
     */
    private function getEnqueuedAssetsSnapshot(): array
    {
        global $wp_styles, $wp_scripts;

        return [
            'styles' => isset($wp_styles->queue) && \is_array($wp_styles->queue)
                ? $this->normalizeStringListPayload($wp_styles->queue)
                : [],
            'scripts' => isset($wp_scripts->queue) && \is_array($wp_scripts->queue)
                ? $this->normalizeStringListPayload($wp_scripts->queue)
                : [],
        ];
    }

    /**
     * @return array{styles: array<string, mixed>, internal_styles: array<string, mixed>, free_form_styles: string}
     */
    private function getGeneratedCssSnapshot(): array
    {
        if (!\class_exists('\ET_Builder_Element')) {
            return [
                'styles' => [],
                'internal_styles' => [],
                'free_form_styles' => '',
            ];
        }

        return [
            'styles' => \ET_Builder_Element::get_style_array(false),
            'internal_styles' => \ET_Builder_Element::get_style_array(true),
            'free_form_styles' => \ET_Builder_Element::get_free_form_styles(),
        ];
    }

    /**
     * @return array{fonts_queue: array<string, array<string, mixed>>, user_fonts_queue: array<string, mixed>}
     */
    private function getFontQueueSnapshot(): array
    {
        global $et_fonts_queue, $et_user_fonts_queue;

        return [
            'fonts_queue' => \is_array($et_fonts_queue) ? $et_fonts_queue : [],
            'user_fonts_queue' => \is_array($et_user_fonts_queue) ? $et_user_fonts_queue : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getSubjectsCacheSnapshot(int $postId): array
    {
        if ($postId <= 0) {
            return [];
        }

        $subjectsCache = \get_post_meta($postId, 'et_pb_subjects_cache', true);

        return \is_array($subjectsCache) ? $subjectsCache : [];
    }

    /**
     * @param string $functionName
     * @return array<int, array<string, mixed>>
     */
    private function getClassKeyedDiviDataSnapshot(string $functionName): array
    {
        if (!\function_exists($functionName)) {
            return [];
        }

        $snapshot = \call_user_func($functionName);

        if (!\is_array($snapshot)) {
            return [];
        }

        return \array_values($this->indexEntriesByClass($snapshot));
    }

    /**
     * @param array<int, mixed> $items
     * @return array<string, array<string, mixed>>
     */
    private function indexEntriesByClass(array $items): array
    {
        $indexed = [];

        foreach ($items as $item) {
            if (!\is_array($item) || !\is_string($item['class'] ?? null) || $item['class'] === '') {
                continue;
            }

            if (isset($indexed[$item['class']])) {
                continue;
            }

            $indexed[$item['class']] = $item;
        }

        return $indexed;
    }

    /**
     * @param array<int, array<string, mixed>> $before
     * @param array<int, array<string, mixed>> $after
     * @return array<int, array<string, mixed>>
     */
    private function diffEntriesByClass(array $before, array $after): array
    {
        $beforeByClass = $this->indexEntriesByClass($before);
        $afterByClass = $this->indexEntriesByClass($after);
        $delta = [];

        foreach ($afterByClass as $class => $entry) {
            if (isset($beforeByClass[$class])) {
                continue;
            }

            $delta[] = $entry;
        }

        return $delta;
    }

    /**
     * @param array<int, string> $before
     * @param array<int, string> $after
     * @return array<int, string>
     */
    private function diffStringList(array $before, array $after): array
    {
        $beforeLookup = \array_fill_keys($before, true);
        $delta = [];

        foreach ($after as $item) {
            if (isset($beforeLookup[$item])) {
                continue;
            }

            $delta[] = $item;
            $beforeLookup[$item] = true;
        }

        return $delta;
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @return array<string, mixed>
     */
    private function diffAssociativeArray(array $before, array $after): array
    {
        $delta = [];

        foreach ($after as $key => $value) {
            if (\array_key_exists($key, $before) && $before[$key] === $value) {
                continue;
            }

            $delta[$key] = $value;
        }

        return $delta;
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     * @return array<int, array<string, mixed>>
     */
    private function normalizeScrollEffectsEntries(array $entries): array
    {
        $normalized = [];

        foreach ($entries as $entry) {
            if (!\is_array($entry)) {
                continue;
            }

            $normalized[] = $entry;
        }

        return $normalized;
    }

    /**
     * @param array{desktop: array<int, array<string, mixed>>, tablet: array<int, array<string, mixed>>, phone: array<int, array<string, mixed>>} $before
     * @param array{desktop: array<int, array<string, mixed>>, tablet: array<int, array<string, mixed>>, phone: array<int, array<string, mixed>>} $after
     * @return array{desktop: array<int, array<string, mixed>>, tablet: array<int, array<string, mixed>>, phone: array<int, array<string, mixed>>}
     */
    private function diffScrollEffectsSnapshot(array $before, array $after): array
    {
        $delta = [
            'desktop' => [],
            'tablet' => [],
            'phone' => [],
        ];

        foreach (\array_keys($delta) as $device) {
            $beforeEntries = [];

            foreach ($before[$device] as $entry) {
                $beforeEntries[\serialize($entry)] = true;
            }

            foreach ($after[$device] as $entry) {
                $serialized = \serialize($entry);

                if (isset($beforeEntries[$serialized])) {
                    continue;
                }

                $beforeEntries[$serialized] = true;
                $delta[$device][] = $entry;
            }
        }

        return $delta;
    }

    /**
     * @param array{styles: array<string, mixed>, internal_styles: array<string, mixed>, free_form_styles: string} $before
     * @param array{styles: array<string, mixed>, internal_styles: array<string, mixed>, free_form_styles: string} $after
     * @return array{generated_css: string, critical_css: string}
     */
    private function diffGeneratedCssSnapshot(array $before, array $after): array
    {
        $styleDelta = $this->diffStyleMap($before['styles'], $after['styles']);
        $internalStyleDelta = $this->diffStyleMap($before['internal_styles'], $after['internal_styles']);
        $freeFormDelta = $this->diffFreeFormStyles($before['free_form_styles'], $after['free_form_styles']);

        return [
            'generated_css' => $this->renderStyleMap($styleDelta, false) . $this->renderStyleMap($internalStyleDelta, false) . $freeFormDelta,
            'critical_css' => $this->renderStyleMap($styleDelta, true) . $this->renderStyleMap($internalStyleDelta, true),
        ];
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @return array<string, mixed>
     */
    private function diffStyleMap(array $before, array $after): array
    {
        $delta = [];

        foreach ($after as $mediaQuery => $selectors) {
            if (!\is_array($selectors)) {
                continue;
            }

            $beforeSelectors = isset($before[$mediaQuery]) && \is_array($before[$mediaQuery]) ? $before[$mediaQuery] : [];

            foreach ($selectors as $selector => $settings) {
                if (!\is_array($settings)) {
                    continue;
                }

                $beforeSettings = isset($beforeSelectors[$selector]) && \is_array($beforeSelectors[$selector]) ? $beforeSelectors[$selector] : null;

                if ($beforeSettings === $settings) {
                    continue;
                }

                if (
                    $beforeSettings !== null
                    && \is_string($beforeSettings['declaration'] ?? null)
                    && \is_string($settings['declaration'] ?? null)
                    && ($settings['critical'] ?? null) === ($beforeSettings['critical'] ?? null)
                    && ($settings['priority'] ?? null) === ($beforeSettings['priority'] ?? null)
                    && \strpos($settings['declaration'], $beforeSettings['declaration']) === 0
                ) {
                    $settings['declaration'] = \ltrim(\substr($settings['declaration'], \strlen($beforeSettings['declaration'])));
                }

                if (($settings['declaration'] ?? '') === '') {
                    continue;
                }

                if (!isset($delta[$mediaQuery])) {
                    $delta[$mediaQuery] = [];
                }

                $delta[$mediaQuery][$selector] = $settings;
            }
        }

        return $delta;
    }

    private function diffFreeFormStyles(string $before, string $after): string
    {
        if ($after === '' || $after === $before) {
            return '';
        }

        if ($before !== '' && \strpos($after, $before) === 0) {
            return \wp_strip_all_tags(\substr($after, \strlen($before)));
        }

        return \wp_strip_all_tags($after);
    }

    /**
     * @param array<string, mixed> $stylesByMediaQueries
     */
    private function renderStyleMap(array $stylesByMediaQueries, bool $critical): string
    {
        if ($stylesByMediaQueries === [] || !\class_exists('\ET_Builder_Element')) {
            return '';
        }

        $output = '';
        $mediaQueries = \ET_Builder_Element::get_media_quries();
        $mediaQueriesOrder = \array_merge(['general'], \array_values(\is_array($mediaQueries) ? $mediaQueries : []));
        $stylesByMediaQueriesSorted = \array_merge(\array_flip($mediaQueriesOrder), $stylesByMediaQueries);

        foreach ($stylesByMediaQueriesSorted as $mediaQuery => $styles) {
            if (!\is_array($styles)) {
                continue;
            }

            \uasort($styles, ['ET_Builder_Element', 'compare_by_priority']);

            $mergedDeclarations = [];
            $wrapIntoMediaQuery = $mediaQuery !== 'general';

            foreach ($styles as $selector => $settings) {
                if (!\is_array($settings) || !\is_string($settings['declaration'] ?? null) || $settings['declaration'] === '') {
                    continue;
                }

                if ($critical === false && isset($settings['critical'])) {
                    continue;
                }

                if ($critical === true && empty($settings['critical'])) {
                    continue;
                }

                $declarationHash = \md5($settings['declaration']);

                if (\strpos($selector, ':-') !== false || \strpos($selector, '@keyframes') !== false) {
                    $uniqueKey = $declarationHash . '-' . \md5($selector . \serialize($settings));
                    $mergedDeclarations[$uniqueKey] = [
                        'declaration' => $settings['declaration'],
                        'selector' => $selector,
                    ];

                    if (isset($settings['priority'])) {
                        $mergedDeclarations[$uniqueKey]['priority'] = $settings['priority'];
                    }

                    continue;
                }

                if (!isset($mergedDeclarations[$declarationHash])) {
                    $mergedDeclarations[$declarationHash] = [
                        'selector' => '',
                        'priority' => '',
                    ];
                }

                $mergedDeclarations[$declarationHash] = [
                    'declaration' => $settings['declaration'],
                    'selector' => $mergedDeclarations[$declarationHash]['selector'] !== ''
                        ? $mergedDeclarations[$declarationHash]['selector'] . ', ' . $selector
                        : $selector,
                ];

                if (isset($settings['priority'])) {
                    $mergedDeclarations[$declarationHash]['priority'] = $settings['priority'];
                }
            }

            $mediaQueryOutput = '';

            foreach ($mergedDeclarations as $settings) {
                $mediaQueryOutput .= \sprintf(
                    "%3\$s%4\$s%1\$s { %2\$s }",
                    $settings['selector'],
                    $settings['declaration'],
                    "\n",
                    $wrapIntoMediaQuery ? "\t" : ''
                );
            }

            if ($wrapIntoMediaQuery && $mediaQueryOutput !== '') {
                $mediaQueryOutput = \sprintf(
                    "%3\$s%3\$s%1\$s {%2\$s%3\$s}",
                    $mediaQuery,
                    $mediaQueryOutput,
                    "\n"
                );
            }

            $output .= $mediaQueryOutput;
        }

        return $output;
    }

    /**
     * @param array{
     *     html: string,
     *     animation_data: array<int, array<string, mixed>>,
     *     link_options_data: array<int, array<string, mixed>>,
     *     scroll_effects: array{desktop: array<int, array<string, mixed>>, tablet: array<int, array<string, mixed>>, phone: array<int, array<string, mixed>>},
     *     wp_lazy_loading_disabled: bool,
     *     enqueued_styles: array<int, string>,
     *     enqueued_scripts: array<int, string>,
     *     generated_css: string,
     *     critical_css: string,
     *     fonts_queue: array<string, array<string, mixed>>,
     *     user_fonts_queue: array<string, mixed>,
     *     subjects_cache: array<string, mixed>
     * } $payload
     */
    private function replaySectionSideEffects(int $postId, array $payload): void
    {
        $this->replayClassKeyedDiviData('et_builder_handle_animation_data', $payload['animation_data']);
        $this->replayClassKeyedDiviData('et_builder_handle_link_options_data', $payload['link_options_data']);
        $this->replayScrollEffects($payload['scroll_effects']);
        $this->replayWordPressLazyLoadingDisabled($payload['wp_lazy_loading_disabled']);
        $this->replayEnqueuedAssets($payload['enqueued_styles'], $payload['enqueued_scripts']);
        $this->replayGeneratedCss($payload['generated_css'], $payload['critical_css']);
        $this->replayFontQueues($payload['fonts_queue'], $payload['user_fonts_queue']);
        $this->replaySubjectsCache($postId, $payload['subjects_cache']);
    }

    /**
     * @param string $functionName
     * @param array<int, array<string, mixed>> $entries
     */
    private function replayClassKeyedDiviData(string $functionName, array $entries): void
    {
        if (!\function_exists($functionName)) {
            return;
        }

        foreach ($this->indexEntriesByClass($entries) as $entry) {
            \call_user_func($functionName, $entry);
        }
    }

    /**
     * @param array{desktop: array<int, array<string, mixed>>, tablet: array<int, array<string, mixed>>, phone: array<int, array<string, mixed>>} $scrollEffects
     */
    private function replayScrollEffects(array $scrollEffects): void
    {
        if (!\class_exists('\ET_Builder_Element') || !\is_array(\ET_Builder_Element::$_scroll_effects_fields ?? null)) {
            return;
        }

        $current = $this->normalizeScrollEffectsPayload(\ET_Builder_Element::$_scroll_effects_fields);

        foreach (\array_keys($current) as $device) {
            $existingEntries = [];

            foreach ($current[$device] as $entry) {
                $existingEntries[\serialize($entry)] = true;
            }

            foreach ($scrollEffects[$device] as $entry) {
                $serialized = \serialize($entry);

                if (isset($existingEntries[$serialized])) {
                    continue;
                }

                $existingEntries[$serialized] = true;
                $current[$device][] = $entry;
            }
        }

        \ET_Builder_Element::$_scroll_effects_fields = $current;
    }

    private function replayWordPressLazyLoadingDisabled(bool $isDisabled): void
    {
        if (!$isDisabled || $this->isWordPressLazyLoadingDisabledForRequest()) {
            return;
        }

        \add_filter('wp_lazy_loading_enabled', '__return_false');
    }

    /**
     * @param array<int, string> $styles
     * @param array<int, string> $scripts
     */
    private function replayEnqueuedAssets(array $styles, array $scripts): void
    {
        if (\function_exists('wp_enqueue_style')) {
            foreach ($styles as $handle) {
                \wp_enqueue_style($handle);
            }
        }

        if (\function_exists('wp_enqueue_script')) {
            foreach ($scripts as $handle) {
                \wp_enqueue_script($handle);
            }
        }
    }

    private function replayGeneratedCss(string $generatedCss, string $criticalCss): void
    {
        if ($generatedCss === '' && $criticalCss === '') {
            return;
        }

        $managers = $this->ensureAdvancedStyleManagers();

        if ($managers === null) {
            return;
        }

        $advancedHasStaticFile = \method_exists($managers['advanced'], 'has_file')
            && $managers['advanced']->has_file()
            && empty($managers['advanced']->forced_inline);
        $deferredHasStaticFile = $managers['deferred'] === null
            || (
                \method_exists($managers['deferred'], 'has_file')
                && $managers['deferred']->has_file()
                && empty($managers['deferred']->forced_inline)
            );

        if ($advancedHasStaticFile && $deferredHasStaticFile) {
            return;
        }

        if ($criticalCss !== '') {
            $managers['advanced']->set_data($criticalCss, 40);
        }

        if ($generatedCss === '') {
            return;
        }

        if ($managers['deferred'] !== null) {
            $managers['deferred']->set_data($generatedCss, 40);
            return;
        }

        $managers['advanced']->set_data($generatedCss, 40);
    }

    /**
     * @return array{advanced: object, deferred: ?object}|null
     */
    private function ensureAdvancedStyleManagers(): ?array
    {
        if (!\class_exists('\ET_Builder_Element') || !\method_exists('\ET_Builder_Element', 'setup_advanced_styles_manager')) {
            return null;
        }

        if (\ET_Builder_Element::$advanced_styles_manager === null) {
            $result = \ET_Builder_Element::setup_advanced_styles_manager();
            \ET_Builder_Element::$advanced_styles_manager = $result['manager'];

            if (isset($result['deferred'])) {
                \ET_Builder_Element::$deferred_styles_manager = $result['deferred'];
            }

            if (!empty($result['add_hooks'])) {
                if (!\has_action('wp_footer', ['ET_Builder_Element', 'set_advanced_styles'])) {
                    \add_action('wp_footer', ['ET_Builder_Element', 'set_advanced_styles'], 19);
                }

                if (!\has_filter('et_core_page_resource_get_data', ['ET_Builder_Element', 'filter_page_resource_data'])) {
                    \add_filter('et_core_page_resource_get_data', ['ET_Builder_Element', 'filter_page_resource_data'], 10, 3);
                }
            }

            if (!\has_action('wp_footer', ['ET_Builder_Element', 'maybe_force_inline_styles'])) {
                \add_action('wp_footer', ['ET_Builder_Element', 'maybe_force_inline_styles'], 19);
            }
        }

        if (!\is_object(\ET_Builder_Element::$advanced_styles_manager)) {
            return null;
        }

        return [
            'advanced' => \ET_Builder_Element::$advanced_styles_manager,
            'deferred' => \is_object(\ET_Builder_Element::$deferred_styles_manager) ? \ET_Builder_Element::$deferred_styles_manager : null,
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $fontsQueue
     * @param array<string, mixed> $userFontsQueue
     */
    private function replayFontQueues(array $fontsQueue, array $userFontsQueue): void
    {
        if ($fontsQueue === [] && $userFontsQueue === []) {
            return;
        }

        global $et_fonts_queue, $et_user_fonts_queue;

        $et_fonts_queue = \array_merge(\is_array($et_fonts_queue) ? $et_fonts_queue : [], $fontsQueue);
        $et_user_fonts_queue = \array_merge(\is_array($et_user_fonts_queue) ? $et_user_fonts_queue : [], $userFontsQueue);
    }

    /**
     * @param array<string, mixed> $subjectsCache
     */
    private function replaySubjectsCache(int $postId, array $subjectsCache): void
    {
        if ($postId <= 0 || $subjectsCache === []) {
            return;
        }

        $currentSubjectsCache = \get_post_meta($postId, 'et_pb_subjects_cache', true);
        $currentSubjectsCache = \is_array($currentSubjectsCache) ? $currentSubjectsCache : [];
        $updatedSubjectsCache = \array_merge($currentSubjectsCache, $subjectsCache);

        if ($updatedSubjectsCache === $currentSubjectsCache) {
            return;
        }

        \update_post_meta($postId, 'et_pb_subjects_cache', $updatedSubjectsCache);
    }

    private function containsDenylistedShortcode(string $section): bool
    {
        $pattern = '/\[(?:' . \implode('|', \array_map('preg_quote', self::DENYLIST)) . ')\b/';

        return \preg_match($pattern, $section) === 1;
    }

    /**
     * @return array<int, array{is_section: bool, content: string}>|null
     */
    private function splitTopLevelSections(string $content, int $postId): ?array
    {
        $parts = [];
        $offset = 0;
        $length = \strlen($content);

        while ($offset < $length) {
            $start = \strpos($content, '[et_pb_section', $offset);

            if ($start === false) {
                $tail = \substr($content, $offset);

                if ($tail !== '') {
                    $parts[] = [
                        'is_section' => false,
                        'content' => $tail,
                    ];
                }

                break;
            }

            if ($start > $offset) {
                $parts[] = [
                    'is_section' => false,
                    'content' => \substr($content, $offset, $start - $offset),
                ];
            }

            $depth = 0;
            $cursor = $start;
            $end = null;

            while ($cursor < $length) {
                $nextOpen = \strpos($content, '[et_pb_section', $cursor);
                $nextClose = \strpos($content, '[/et_pb_section]', $cursor);

                if ($nextOpen === false && $nextClose === false) {
                    break;
                }

                if ($nextOpen !== false && ($nextClose === false || $nextOpen < $nextClose)) {
                    $depth++;
                    $cursor = $nextOpen + 14;
                    continue;
                }

                $depth--;
                $cursor = $nextClose + 16;

                if ($depth === 0) {
                    $end = $cursor;
                    break;
                }
            }

            if ($depth !== 0 || $end === null) {
                \error_log(
                    \sprintf(
                        'Divi Section Fragment Cache: unbalanced et_pb_section depth on post %d',
                        $postId
                    )
                );

                return null;
            }

            $parts[] = [
                'is_section' => true,
                'content' => \substr($content, $start, $end - $start),
            ];

            $offset = $end;
        }

        return $parts;
    }
}

\add_action('template_redirect', static function () {
    (new Plugin())->register();
}, 10, 0);
