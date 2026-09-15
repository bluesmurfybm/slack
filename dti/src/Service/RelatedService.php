<?php

namespace Dti\Service;

use Dti\Entity\Topic;
use Dti\Repository\RelatedRepository;
use Dti\Repository\TopicRepository;

/**
 * 연관 아티클 점수. 분야·키워드·제목만 본다 — 팀·매거진이 같다는 건 내용이 비슷하다는 뜻이
 * 아니라서 뺐다. 난수를 쓰지 않는다: 같은 두 아티클은 언제 봐도 같은 점수여야 한다.
 */
final class RelatedService
{
    private const SAME_FIELD = 40;
    private const SHARED_KEYWORD = 22;
    private const SHARED_KEYWORD_CAP = 2;
    private const TITLE_WEIGHT = 10;
    private const TOKEN_MATCH = 0.8;
    private const MIN_SCORE = 50;

    public const MAX_RELATED = 3;

    public function __construct(
        private readonly TopicRepository $topics,
        private readonly RelatedRepository $related,
    ) {}

    public function score(Topic $a, Topic $b): int
    {
        $total = 0;
        if ($a->field !== '' && $a->field === $b->field) {
            $total += self::SAME_FIELD;
        }

        $mine = $this->keywordGrams($a);
        $matched = 0;
        foreach ($this->keywordGrams($b) as $grams) {
            foreach ($mine as $ours) {
                if ($this->overlap($grams, $ours) >= self::TOKEN_MATCH) {
                    $matched++;
                    break;
                }
            }
        }
        $total += min($matched, self::SHARED_KEYWORD_CAP) * self::SHARED_KEYWORD;
        $total += (int)round($this->dice($this->bigrams($a->title), $this->bigrams($b->title)) * self::TITLE_WEIGHT);

        return $total;
    }

    public function rebuild(): int
    {
        $topics = $this->topics->all();

        $pairs = [];
        foreach ($topics as $a) {
            foreach ($topics as $b) {
                if ($a->id === $b->id) continue;
                $score = $this->score($a, $b);
                if ($score >= self::MIN_SCORE) {
                    $pairs[] = [(int)$a->id, (int)$b->id, $score];
                }
            }
        }
        $this->related->replaceAll($pairs);

        return count($pairs);
    }

    /** @return array<int, array<string, bool>> 키워드 토큰별 bigram 집합 */
    private function keywordGrams(Topic $topic): array
    {
        $out = [];
        foreach (preg_split('~[\s,/·]+~u', mb_strtolower($topic->keywords)) as $token) {
            if ($token === '') continue;
            $grams = $this->bigrams($token);
            if ($grams) $out[] = $grams;
        }
        return $out;
    }

    /** @return array<string, bool> bigram 집합. 키가 곧 원소다 */
    private function bigrams(string $text): array
    {
        $normalized = preg_replace('~[^0-9a-z가-힣]~u', '', mb_strtolower($text));
        // mb_str_split 이어야 한다 — substr 은 UTF-8 한글을 바이트로 잘라 점수가 달라진다
        $chars = mb_str_split((string)$normalized);

        $out = [];
        for ($i = 0, $n = count($chars) - 1; $i < $n; $i++) {
            $out[$chars[$i] . $chars[$i + 1]] = true;
        }
        return $out;
    }

    private function dice(array $a, array $b): float
    {
        if (!$a || !$b) return 0.0;
        return 2 * count(array_intersect_key($a, $b)) / (count($a) + count($b));
    }

    private function overlap(array $a, array $b): float
    {
        if (!$a || !$b) return 0.0;
        return count(array_intersect_key($a, $b)) / min(count($a), count($b));
    }
}
