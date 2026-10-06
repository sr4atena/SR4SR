<?php
/**
 * YouTube Data API v3 client: search → videos → commentThreads.
 *
 * Three things were measured against the real API (PLAN-voices §9) and are
 * encoded here rather than re-discovered:
 *   - `order=relevance` is the only usable ordering. `order=viewCount` returns
 *     a loosely matched set in which no result carries the game name; the top
 *     ten is therefore sorted by views locally, after `videos.list`.
 *   - titles arrive HTML-escaped and some use a typographic apostrophe, so the
 *     filter unescapes first and matches the two words separately.
 *   - only a third of the creators write "roblox", so the list is kept on
 *     topic by exclusion, never by requiring the word (§5).
 *
 * HTTP is the same injected callable the Roblox client uses, so tests run on
 * fixtures without a network.
 */
declare(strict_types=1);

namespace ManorLedger\Voices;

use Closure;
use ManorLedger\Roblox\CurlTransport;
use RuntimeException;

final class YouTubeClient
{
    public const DEFAULT_QUERIES = ["The Locust's Manor", "The Locust's Manor Roblox"];

    /** Both words must appear in the title; the apostrophe between them varies. */
    private const TITLE_TERMS = ['locust', 'manor'];

    /** Extend when a new impostor appears; a match drops the video only when Roblox is absent. */
    private const RIVAL_PLATFORMS = ['fortnite', 'minecraft', 'among us', 'geometry dash', "garry's mod", 'gmod'];

    private const HTTP_TIMEOUT = 30;

    private Closure $transport;

    /** @param ?callable $transport fn(list<array> $requests): list<array> */
    public function __construct(
        #[\SensitiveParameter] private readonly string $apiKey,
        ?callable $transport = null,
        private readonly string $baseUrl = 'https://www.googleapis.com/youtube/v3/',
    ) {
        $this->transport = $transport !== null ? Closure::fromCallable($transport) : Closure::fromCallable(new CurlTransport());
    }

    /**
     * @param  list<string> $queries
     * @param  list<string> $extraIds candidates found elsewhere (ArchiveSource): they skip the
     *                                title filter, which only screens search noise, but not the
     *                                other-platform exclusion
     * @param  int          $minSeconds shorter videos are left out (shorts: a few seconds of a
     *                                jump scare, nothing to summarise); unknown length is kept
     * @return array{videos: list<array<string, mixed>>, stats: array{candidates: int, excludedNonRoblox: int, fromArchive: int}}
     */
    public function topVideos(array $queries = self::DEFAULT_QUERIES, int $limit = 10, int $pagesPerQuery = 2, array $extraIds = [], int $minSeconds = 0): array
    {
        $ids = [];
        $tokens = array_fill_keys($queries, null);
        for ($page = 0; $page < $pagesPerQuery; $page++) {
            $wave = [];
            foreach ($queries as $query) {
                if ($page > 0 && ($tokens[$query] ?? null) === null) {
                    continue;
                }
                $wave[$query] = $this->request('search', [
                    'part' => 'snippet', 'q' => $query, 'type' => 'video',
                    'order' => 'relevance', 'maxResults' => '50',
                    'pageToken' => $tokens[$query] ?? '',
                ]);
            }
            if ($wave === []) {
                break;
            }
            $responses = ($this->transport)(array_values($wave));
            foreach (array_keys($wave) as $index => $query) {
                $json = $this->decode($responses[$index] ?? []);
                $tokens[$query] = isset($json['nextPageToken']) ? (string)$json['nextPageToken'] : null;
                foreach ($json['items'] ?? [] as $item) {
                    $id = (string)($item['id']['videoId'] ?? '');
                    if ($id !== '' && self::titleMatches((string)($item['snippet']['title'] ?? ''))) {
                        $ids[$id] = true;
                    }
                }
            }
        }

        $searched = $ids;
        foreach ($extraIds as $id) {
            if (preg_match('/^[A-Za-z0-9_-]{11}$/', (string)$id) === 1) {
                $ids[(string)$id] = true;
            }
        }
        $candidates = $this->videos(array_keys($ids));
        $kept = [];
        $excluded = 0;
        foreach ($candidates as $video) {
            if (($video['seconds'] ?? PHP_INT_MAX) < $minSeconds) {
                continue;
            }
            if (self::isOtherPlatform($video)) {
                $excluded++;
                continue;
            }
            unset($video['description'], $video['tags']);
            $kept[] = $video;
        }
        usort($kept, static fn (array $a, array $b): int => $b['views'] <=> $a['views']);
        $top = array_slice($kept, 0, $limit);

        return [
            'videos' => $top,
            'stats'  => ['candidates' => count($candidates), 'excludedNonRoblox' => $excluded,
                         'fromArchive' => count(array_filter($top, static fn (array $v): bool => !isset($searched[$v['id']])))],
        ];
    }

    /**
     * The Roblox games a description links to, as place ids. A creator who
     * plays several games in one video links each of them: that is how a
     * mixed video is told apart from one about this game.
     *
     * @return list<int>
     */
    public static function gameLinks(string $description): array
    {
        preg_match_all('~roblox\.com/(?:[a-z]{2}(?:-[a-z]{2})?/)?games/(\d{5,})|[?&]placeId=(\d{5,})~i', $description, $m);
        $ids = array_filter(array_merge($m[1] ?? [], $m[2] ?? []), static fn (string $id): bool => $id !== '');

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * The most recent of a set of candidates (ArchiveSource::recent), with
     * fresh statistics and the same other-platform rule as the top list.
     *
     * @param  list<string> $ids
     * @return list<array<string, mixed>>
     */
    public function recentVideos(array $ids, int $limit): array
    {
        $ids = array_values(array_filter($ids, static fn ($id): bool => preg_match('/^[A-Za-z0-9_-]{11}$/', (string)$id) === 1));
        $kept = [];
        foreach ($this->videos($ids) as $video) {
            if (!self::isOtherPlatform($video)) {
                unset($video['description'], $video['tags']);
                $kept[] = $video;
            }
        }
        usort($kept, static fn (array $a, array $b): int => strcmp((string)$b['publishedTime'], (string)$a['publishedTime']));

        return array_slice($kept, 0, max(0, $limit));
    }

    /**
     * Statistics, length and full snippets for a set of ids (50 per call, 1 unit each).
     *
     * @param  list<string> $ids
     * @return list<array<string, mixed>>
     */
    public function videos(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $requests = [];
        foreach (array_chunk($ids, 50) as $chunk) {
            $requests[] = $this->request('videos', ['part' => 'snippet,statistics,contentDetails', 'id' => implode(',', $chunk), 'maxResults' => '50']);
        }
        $videos = [];
        foreach (($this->transport)($requests) as $response) {
            foreach ($this->decode($response)['items'] ?? [] as $item) {
                $snippet = $item['snippet'] ?? [];
                $stats   = $item['statistics'] ?? [];
                $id      = (string)($item['id'] ?? '');
                if ($id === '') {
                    continue;
                }
                $videos[] = [
                    'id'           => $id,
                    'title'        => self::unescape((string)($snippet['title'] ?? '')),
                    'channel'      => self::unescape((string)($snippet['channelTitle'] ?? '')),
                    'channelId'    => (string)($snippet['channelId'] ?? ''),
                    'publishedAt'  => substr((string)($snippet['publishedAt'] ?? ''), 0, 10),
                    // The full timestamp, for ordering by recency within a day.
                    'publishedTime'=> (string)($snippet['publishedAt'] ?? ''),
                    'views'        => (int)($stats['viewCount'] ?? 0),
                    'likes'        => (int)($stats['likeCount'] ?? 0),
                    'commentCount' => (int)($stats['commentCount'] ?? 0),
                    'seconds'      => self::seconds((string)($item['contentDetails']['duration'] ?? '')),
                    'url'          => 'https://www.youtube.com/watch?v=' . $id,
                    'thumbnailUrl' => (string)($snippet['thumbnails']['medium']['url'] ?? $snippet['thumbnails']['default']['url'] ?? ''),
                    'description'  => self::unescape((string)($snippet['description'] ?? '')),
                    'gameLinks'    => self::gameLinks((string)($snippet['description'] ?? '')),
                    'tags'         => array_map(strval(...), $snippet['tags'] ?? []),
                ];
            }
        }

        return $videos;
    }

    /**
     * Top-level comments, most relevant first. A video with comments disabled
     * answers 403: that is a fact about the video, not an error of ours.
     *
     * @return array{fetched: int, comments: list<array{text: string, likes: int}>, disabled: bool}
     */
    public function comments(string $videoId, int $max = 60): array
    {
        $response = ($this->transport)([$this->request('commentThreads', [
            'part' => 'snippet', 'videoId' => $videoId, 'order' => 'relevance',
            'maxResults' => (string)min(100, max(1, $max)), 'textFormat' => 'plainText',
        ])])[0] ?? [];
        if ((int)($response['status'] ?? 0) === 403) {
            return ['fetched' => 0, 'comments' => [], 'disabled' => true];
        }
        $comments = [];
        foreach ($this->decode($response)['items'] ?? [] as $item) {
            $top = $item['snippet']['topLevelComment']['snippet'] ?? [];
            $comments[] = [
                'text'  => (string)($top['textOriginal'] ?? $top['textDisplay'] ?? ''),
                'likes' => (int)($top['likeCount'] ?? 0),
            ];
        }

        return ['fetched' => count($comments), 'comments' => $comments, 'disabled' => false];
    }

    public static function titleMatches(string $rawTitle): bool
    {
        $title = mb_strtolower(self::unescape($rawTitle), 'UTF-8');
        foreach (self::TITLE_TERMS as $term) {
            if (!str_contains($title, $term)) {
                return false;
            }
        }

        return true;
    }

    /** Exclusion rule (§5): another platform named, and Roblox nowhere in sight. */
    public static function isOtherPlatform(array $video): bool
    {
        $haystack = mb_strtolower(implode(' ', [
            (string)($video['title'] ?? ''),
            (string)($video['description'] ?? ''),
            implode(' ', $video['tags'] ?? []),
        ]), 'UTF-8');
        if (str_contains($haystack, 'roblox')) {
            return false;
        }
        foreach (self::RIVAL_PLATFORMS as $platform) {
            if (str_contains($haystack, $platform)) {
                return true;
            }
        }

        return false;
    }

    /** ISO 8601 duration ("PT4M13S") in seconds; null when YouTube gave none. */
    public static function seconds(string $duration): ?int
    {
        if (preg_match('/^P(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$/', $duration, $m) !== 1 || $duration === 'P') {
            return null;
        }
        $part = static fn (int $i): int => (int)($m[$i] ?? 0);

        return (($part(1) * 24 + $part(2)) * 60 + $part(3)) * 60 + $part(4);
    }

    public static function unescape(string $text): string
    {
        return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** @param array<string, string> $query */
    private function request(string $endpoint, array $query): array
    {
        $query = array_filter($query, static fn (string $v): bool => $v !== '');
        $query['key'] = $this->apiKey;

        return [
            'method' => 'GET',
            'url' => $this->baseUrl . $endpoint . '?' . http_build_query($query),
            'headers' => ['Accept' => 'application/json'],
            'body' => null,
            'timeout' => self::HTTP_TIMEOUT,
            'connectTimeout' => 10,
        ];
    }

    /** @return array<string, mixed> */
    private function decode(array $response): array
    {
        $status = (int)($response['status'] ?? 0);
        $body   = $response['body'] ?? false;
        if ($body === false || $body === '') {
            throw new RuntimeException('YouTube API: no response (HTTP ' . $status . ') ' . $this->redact((string)($response['error'] ?? '')));
        }
        $json = json_decode((string)$body, true);
        if (!is_array($json)) {
            throw new RuntimeException('YouTube API: non-JSON response (HTTP ' . $status . ')');
        }
        if (isset($json['error'])) {
            $reason = (string)($json['error']['errors'][0]['reason'] ?? '');
            throw new RuntimeException(sprintf(
                'YouTube API %d %s: %s',
                $status,
                $reason,
                $this->redact((string)($json['error']['message'] ?? 'unknown error')),
            ));
        }

        return $json;
    }

    /** The key travels in the query string: it must never reach a log or an exception. */
    private function redact(string $message): string
    {
        return $this->apiKey === '' ? $message : str_replace($this->apiKey, '[redacted]', $message);
    }
}
