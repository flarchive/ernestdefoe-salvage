<?php

namespace Ernestdefoe\Salvage\Content;

use DOMElement;
use DOMXPath;
use s9e\TextFormatter\Utils;

/**
 * Reads and edits a post's stored XML without parsing it again.
 *
 * Re-parsing would run every formatter (mentions, Scribe, fof/formatting...)
 * a second time, as the wrong actor and against today's configuration. Here
 * only the url of URL tags and the src of IMG tags change; every other byte
 * of the post is left exactly as its formatters wrote it. Scribe, Markdown,
 * BBCode and autolinked URLs all store links and images as these two tags.
 */
final class Xml extends Utils
{
    public const TAGS = ['URL' => 'url', 'IMG' => 'src'];

    /**
     * @return array{links: list<string>, images: list<string>}
     */
    public static function urls(?string $xml): array
    {
        $found = ['links' => [], 'images' => []];

        if ($xml === null || $xml === '' || $xml[1] !== 'r') {
            return $found;
        }

        foreach (['links' => 'URL', 'images' => 'IMG'] as $kind => $tag) {
            foreach (self::getAttributeValues($xml, $tag, self::TAGS[$tag]) as $url) {
                if (strlen($url) <= 2048 && preg_match('~^https?://~i', $url)) {
                    $found[$kind][] = $url;
                }
            }

            $found[$kind] = array_values(array_unique($found[$kind]));
        }

        return $found;
    }

    /**
     * @param array<string, string> $replace old URL => new URL
     * @param array<string, true>   $unwrap  URLs whose link or image is taken
     *                                       out, keeping the text it wrapped
     */
    public static function rewrite(string $xml, array $replace, array $unwrap = []): string
    {
        if ($replace !== []) {
            foreach (self::TAGS as $tag => $attribute) {
                $xml = self::replaceAttributes($xml, $tag, function (array $attributes) use ($attribute, $replace) {
                    $value = $attributes[$attribute] ?? null;

                    if ($value !== null && isset($replace[$value])) {
                        $attributes[$attribute] = $replace[$value];
                    }

                    return $attributes;
                });
            }
        }

        return $unwrap === [] ? $xml : self::unwrap($xml, $unwrap);
    }

    /**
     * Replaces each matching URL/IMG element with its own content, minus the
     * <s>/<e> markup markers ("[", "](https://...)"), so the post shows the
     * link text and an edit unparses to plain text, not the old link.
     *
     * @param array<string, true> $urls
     */
    private static function unwrap(string $xml, array $urls): string
    {
        $dom = self::loadXML($xml);
        $xpath = new DOMXPath($dom);
        $changed = false;

        foreach (self::TAGS as $tag => $attribute) {
            /** @var DOMElement $node */
            foreach (iterator_to_array($xpath->query("//$tag") ?: []) as $node) {
                if (! isset($urls[$node->getAttribute($attribute)])) {
                    continue;
                }

                foreach (iterator_to_array($node->childNodes) as $child) {
                    if ($child instanceof DOMElement && ($child->nodeName === 's' || $child->nodeName === 'e')) {
                        continue;
                    }

                    $node->parentNode?->insertBefore($child, $node);
                }

                $node->parentNode?->removeChild($node);
                $changed = true;
            }
        }

        return $changed ? self::saveXML($dom) : $xml;
    }
}
