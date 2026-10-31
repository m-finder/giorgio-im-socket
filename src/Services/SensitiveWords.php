<?php

namespace GiorgioImSocket\Services;

use DfaFilter\SensitiveHelper;

class SensitiveWords
{
    /** @param list<string> $texts Message content and attachment names to check. */
    public function validate(array $texts): void
    {
        if (! config('im.sensitive_words.enabled', false)) {
            return;
        }

        $words = config('im.sensitive_words.words', []);
        $path = config('im.sensitive_words.path');
        if ($path) {
            abort_unless(is_file($path) && is_readable($path), 503, '发送失败，敏感词库不可用，请联系管理员。');
            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            abort_if($lines === false, 503, '发送失败，敏感词库不可用，请联系管理员。');
            $words = [...$words, ...$lines];
        }

        $words = array_values(array_filter(array_map(fn (string $word) => trim($this->normalise($word)), $words), fn (string $word) => $word !== '' && ! str_starts_with($word, '#')));
        abort_if($words === [], 503, '发送失败，敏感词库不可用，请联系管理员。');
        $filter = (new SensitiveHelper)->setTree($words);
        foreach ($texts as $text) {
            abort_if($filter->getBadWord($this->normalise($text), 1, 1) !== [], 422, '发送失败，消息包含敏感词，请修改后重试。');
        }
    }

    private function normalise(string $text): string
    {
        return mb_strtolower(mb_convert_kana(preg_replace('/\p{Cf}/u', '', $text), 'as', 'UTF-8'), 'UTF-8');
    }
}
