<?php

declare(strict_types=1);

/**
 * Plugin Name: Divi Section Fragment Cache
 * Description: Fragment cache for top-level Divi sections, skipping denylisted shortcodes.
 * Version: 0.1.0
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
        if (
            \is_admin()
            || \is_feed()
            || \is_preview()
            || !\is_singular()
            || \strpos($content, '[et_pb_section') === false
        ) {
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

        if (\is_string($cached)) {
            return $cached;
        }

        $rendered = $this->renderSection($section);

        \set_transient($cacheKey, $rendered, \DAY_IN_SECONDS);

        return $rendered;
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

add_action('template_redirect', static function () {
    (new Plugin())->register();
}, 10, 0);
