<?php

declare(strict_types=1);

namespace AskoEducation\Cbm\Service\LabelMigration;

use Symfony\Component\Yaml\Yaml;

final class ConfigYamlLabelStripper
{
    private const ROOT_KEYS = ['title', 'description'];
    private const NESTED_KEYS = ['label', 'description', 'placeholder', 'labelChecked', 'labelUnchecked', 'linkTitle'];

    private const KEY_LINE = '/^(?<indent> *)(?<dash>- +)?(?<key>[A-Za-z_]\w*):(?:[ \t]+(?<value>\S.*))?$/';

    /**
     * @return list<LabelLine>
     */
    public function findLabels(string $content): array
    {
        $lines = $this->split($content);
        $labels = [];
        for ($i = 0, $count = count($lines); $i < $count; $i++) {
            if (!preg_match(self::KEY_LINE, $lines[$i], $match) || ($match['value'] ?? '') === '') {
                continue;
            }
            $keyIndent = strlen($match['indent']) + strlen($match['dash'] ?? '');
            if (!self::isLabelKey($match['key'], $keyIndent === 0)) {
                continue;
            }
            $end = $this->findEndOfValue($lines, $i, $keyIndent);
            $value = $this->parseValue($lines, $i, $end, $keyIndent, $match['key']);
            if (is_scalar($value)) {
                $labels[] = new LabelLine($i, $end, $keyIndent, $match['key'], (string) $value);
            }
            $i = $end;
        }
        return $labels;
    }

    /**
     * @param array<int, string> $replacements plain scalar replacement by LabelLine::$index
     * @param list<LabelLine> $labels
     */
    public function replaceValues(string $content, array $labels, array $replacements): string
    {
        $lines = $this->split($content);
        foreach ($labels as $label) {
            if (!isset($replacements[$label->index])) {
                continue;
            }
            $lines[$label->index] = substr($lines[$label->index], 0, $label->keyIndent) . $label->key . ': ' . $replacements[$label->index];
            for ($j = $label->index + 1; $j <= $label->end; $j++) {
                unset($lines[$j]);
            }
        }
        return implode("\n", $lines);
    }

    /**
     * @param list<LabelLine> $labels sorted by line, as returned by findLabels()
     */
    public function remove(string $content, array $labels): StrippedYaml
    {
        $lines = $this->split($content);
        $removed = [];
        $skipped = [];
        foreach ($labels as $label) {
            $head = substr($lines[$label->index], 0, $label->keyIndent);
            if (str_contains($head, '-')) {
                // "- label: Foo" opens the list item: hand the dash over to the next key of the item.
                $sibling = $this->findSibling($lines, $label->end, $label->keyIndent);
                if ($sibling === null) {
                    $skipped[] = $label;
                    continue;
                }
                $lines[$sibling] = $head . substr($lines[$sibling], $label->keyIndent);
            }
            for ($j = $label->index; $j <= $label->end; $j++) {
                unset($lines[$j]);
            }
            $removed[] = $label;
        }
        return new StrippedYaml(implode("\n", $lines), $removed, $skipped);
    }

    /**
     * Removes all label-like keys, used to prove that stripping changed nothing but labels.
     */
    public function withoutLabels(array $yaml, bool $isRoot = true): array
    {
        foreach ($yaml as $key => $value) {
            if (is_string($key) && self::isLabelKey($key, $isRoot)) {
                unset($yaml[$key]);
            } elseif (is_array($value)) {
                $yaml[$key] = $this->withoutLabels($value, false);
            }
        }
        return $yaml;
    }

    private static function isLabelKey(string $key, bool $isRoot): bool
    {
        return in_array($key, $isRoot ? self::ROOT_KEYS : self::NESTED_KEYS, true);
    }

    /**
     * @return list<string>
     */
    private function split(string $content): array
    {
        return preg_split('/\R/', $content);
    }

    /**
     * Last line of a (possibly multi-line or block scalar) value: all following lines indented deeper than the key.
     */
    private function findEndOfValue(array $lines, int $start, int $keyIndent): int
    {
        $end = $start;
        for ($j = $start + 1, $count = count($lines); $j < $count; $j++) {
            if (trim($lines[$j]) === '') {
                continue;
            }
            if ($this->indent($lines[$j]) <= $keyIndent) {
                break;
            }
            $end = $j;
        }
        return $end;
    }

    /**
     * Only looks ahead of $end, where remove() has not unset any lines yet; count() would be too small though.
     */
    private function findSibling(array $lines, int $end, int $keyIndent): ?int
    {
        for ($j = $end + 1, $last = array_key_last($lines); $j <= $last; $j++) {
            $trimmed = ltrim($lines[$j]);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }
            return $this->indent($lines[$j]) === $keyIndent && !str_starts_with($trimmed, '-') ? $j : null;
        }
        return null;
    }

    private function parseValue(array $lines, int $start, int $end, int $keyIndent, string $key): mixed
    {
        // Cutting at the key's column also removes "- " from the first line.
        $snippet = array_map(
            static fn(string $line): string => (string) substr($line, $keyIndent),
            array_slice($lines, $start, $end - $start + 1),
        );
        try {
            $parsed = Yaml::parse(implode("\n", $snippet));
        } catch (\Throwable) {
            return null;
        }
        return is_array($parsed) ? ($parsed[$key] ?? null) : null;
    }

    private function indent(string $line): int
    {
        return strlen($line) - strlen(ltrim($line, ' '));
    }
}
