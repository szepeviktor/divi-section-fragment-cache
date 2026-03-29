<?php

declare(strict_types=1);

/**
 * Plugin Name: Divi Section Fragment Cache
 * Description: Fragment cache for top-level Divi sections, skipping sections marked with dsec-no-cache.
 * Version: 0.1.0
 * Requires PHP: 7.4
 */

namespace SzepeViktor\DiviSectionFragmentCache;

final class Plugin
{
    private const NO_CACHE_CLASS = 'dsec-no-cache';

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

            $output .= $this->getCachedSection((int) $postId, $sectionIndex, $section);

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

        if ($this->containsNoCacheClass($rendered)) {
            return $rendered;
        }

        \set_transient($cacheKey, $rendered, \DAY_IN_SECONDS);

        return $rendered;
    }

    private function containsNoCacheClass(string $rendered): bool
    {
        $matched = \preg_match_all(
            '/\bclass\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i',
            $rendered,
            $matches,
            \PREG_SET_ORDER
        );

        if ($matched === false || $matched === 0) {
            return false;
        }

        foreach ($matches as $match) {
            if ($match[1] !== '') {
                $classValue = $match[1];
            } elseif ($match[2] !== '') {
                $classValue = $match[2];
            } else {
                $classValue = $match[3];
            }

            $classes = \preg_split('/\s+/', \trim($classValue));

            if ($classes === false) {
                continue;
            }

            if (\in_array(self::NO_CACHE_CLASS, $classes, true)) {
                return true;
            }
        }

        return false;
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
