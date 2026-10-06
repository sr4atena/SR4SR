<?php
/**
 * Assembles data/voices.json: the ten most watched videos about the game, each
 * summarised, plus the synthesis across them.
 *
 * Degradation is the design: a missing transcript falls back to comments, a
 * failed summary is an honest card, a failed synthesis keeps yesterday's with
 * `status: stale` and its own date. Nothing here ever throws away a previous
 * good file.
 */
declare(strict_types=1);

namespace ManorLedger\Voices;

use Closure;
use ManorLedger\Storage\JsonStore;
use Throwable;

final class VoicesBuilder
{
    private Closure $log;
    private Closure $now;

    /**
     * @param list<string>          $queries
     * @param array<string, string> $primaryModels model id configured for `summary` and `synthesis`
     */
    public function __construct(
        private readonly YouTubeClient $youtube,
        private readonly CommentFilter $comments,
        private readonly TranscriptFetcher $transcripts,
        private readonly ThumbnailStore $thumbnails,
        private readonly VideoSummarizer $summarizer,
        private readonly Synthesizer $synthesizer,
        private readonly JsonStore $output,
        private readonly string $summaryCacheDir,
        private readonly array $queries = YouTubeClient::DEFAULT_QUERIES,
        private readonly array $primaryModels = [],
        private readonly string $host = 'workstation',
        ?callable $log = null,
        ?callable $now = null,
        private readonly ?ArchiveSource $archive = null,
        /** Audience rule for the "most recent" list: subscribers on the channel. */
        private readonly int $recentMinSubscribers = 1000,
        /** This game's Roblox place id: a video linking another game too is "mixed". */
        private readonly ?int $placeId = null,
    ) {
        $this->log = $log !== null ? Closure::fromCallable($log) : static fn (string $l): null => null;
        $this->now = $now !== null ? Closure::fromCallable($now) : static fn (): int => time();
    }

    /**
     * @param array{only?: list<string>, remodel?: bool, resynthesize?: bool, dryRun?: bool,
     *              refreshTranscripts?: bool, topN?: int} $options
     * @return array<string, mixed> the document written to disk
     */
    public function run(array $options = []): array
    {
        $only = $options['only'] ?? [];
        $topN = (int)($options['topN'] ?? 15);
        $recentN = (int)($options['recentN'] ?? 0);
        $topMinSeconds = (int)($options['topMinSeconds'] ?? 0);
        $previous = $this->output->read() ?? [];
        if (($options['dryRun'] ?? false) === true) {
            return $this->plan($topN + $recentN, $previous);
        }

        $now = gmdate('Y-m-d\TH:i:s\Z', ($this->now)());
        $this->log('searching YouTube: ' . implode(' / ', $this->queries));
        $extra = $this->archive?->ids() ?? ['ids' => [], 'note' => ''];
        if ($this->archive !== null) {
            $this->log('archive: ' . $extra['note']);
        }
        // Twice as many as needed, in order of views: a video with nothing to
        // summarise gives its place to the next most watched.
        $found = $this->youtube->topVideos($this->queries, $topN * 2, 2, $extra['ids'], $topMinSeconds);
        $top = [];
        $this->log(sprintf('%d candidates, %d excluded as non-Roblox, %d kept (%d found only through the archive)',
            $found['stats']['candidates'], $found['stats']['excludedNonRoblox'], count($top), $found['stats']['fromArchive'] ?? 0));

        $transcriptStatuses = array_fill_keys(TranscriptFetcher::STATUSES, 0);
        $regenerated = 0;
        $withTranscript = 0;
        $rows = [];
        $picked = [];
        $ctx = ['only' => $only, 'now' => $now, 'options' => $options];
        $skippedTop = 0;
        foreach ($found['videos'] as $video) {
            if (count($top) >= $topN) {
                break;
            }
            $id = (string)$video['id'];
            if (!$this->isAboutThisGame($video)) {
                $skippedTop++;
                $this->log($id . ': several games and the title names another or none, not listed: ' . mb_substr((string)$video['title'], 0, 60, 'UTF-8'));
                continue;
            }
            $row = $this->row($video, $ctx, $transcriptStatuses, $regenerated);
            if ($row['summary']['status'] !== 'ok') {
                $skippedTop++;
                $this->log($id . ': nothing to summarise (' . ($row['summary']['error'] ?? 'failed') . '), not listed among the most watched');
                continue;
            }
            $rows[$id] = $row;
            $picked[$id] = $video;
            $top[] = $video;
        }

        $this->log(sprintf('%d most watched kept, %d skipped for lack of material or another game in the title', count($top), $skippedTop));

        // Second list: the newest videos of creators with an audience. Only
        // the archive knows publication order and subscribers, so without it
        // the list is simply absent. A video enters only if it says
        // something: one with neither captions nor comments would be a card
        // with no summary, so the next candidate takes its place.
        $recentIds = [];
        $skipped = 0;
        if ($this->archive !== null && $recentN > 0) {
            $candidates = $this->archive->recent($recentN * 3, $this->recentMinSubscribers);
            $this->log('recent: ' . $candidates['note']);
            foreach ($this->youtube->recentVideos($candidates['ids'], $recentN * 3) as $video) {
                if (count($recentIds) >= $recentN) {
                    break;
                }
                $id = (string)$video['id'];
                if (!isset($rows[$id]) && !$this->isAboutThisGame($video)) {
                    $skipped++;
                    $this->log($id . ': several games and the title names another or none, not listed: ' . mb_substr((string)$video['title'], 0, 60, 'UTF-8'));
                    continue;
                }
                // One analysis per video: a video in both lists is summarised
                // once and counted once; the page shows it in both sections.
                $row = $rows[$id] ?? $this->row($video, $ctx, $transcriptStatuses, $regenerated);
                if ($row['summary']['status'] !== 'ok') {
                    $skipped++;
                    $this->log($id . ': nothing to summarise (' . ($row['summary']['error'] ?? 'failed') . '), not listed among the recent');
                    continue;
                }
                $rows[$id] = $row;
                $picked[$id] = $video;
                $recentIds[] = $id;
            }
        }
        $lists = ['top' => array_column($top, 'id'), 'recent' => $recentIds];
        foreach ($rows as $id => $row) {
            $rows[$id]['lists'] = array_values(array_filter(['top', 'recent'], static fn (string $l): bool => in_array($id, $lists[$l], true)));
        }
        $rows = array_values($rows);
        $withTranscript = count(array_filter($rows, static fn (array $r): bool => $r['transcript']['status'] === 'ok'));
        if ($recentIds !== []) {
            $this->log(sprintf('lists: %d most watched + %d most recent = %d videos to analyse (%d in both; skipped, for lack of material or another game in the title: %d most watched, %d recent)',
                count($top), count($recentIds), count($rows), count($top) + count($recentIds) - count($rows), $skippedTop, $skipped));
        }
        $stored = $this->thumbnails->store(array_values($picked));
        foreach (array_keys(array_filter($stored, static fn (bool $ok): bool => !$ok)) as $id) {
            $this->log($id . ': thumbnail unavailable, the view will show a placeholder');
        }
        $this->log('transcripts: ' . implode(', ', array_map(
            static fn (string $s, int $n): string => $n . ' ' . $s,
            array_keys($transcriptStatuses),
            $transcriptStatuses,
        )));
        if ($this->transcripts->isBlocked()) {
            // Not a fault of this machine and not one of the videos: the address
            // is in a corner for a while. Say it plainly, because the page will
            // show summaries that nobody refreshed tonight.
            $this->log('WARNING: YouTube refused caption requests from this address, so no further '
                . 'request was sent. It never says how long a refusal lasts: our own cooldown holds '
                . 'until ' . gmdate('Y-m-d H:i', (int)$this->transcripts->blockedUntil()) . ' UTC. '
                . 'Tonight\'s summaries come from the cache and from comments; run again after that, '
                . 'or with --clear-block once YouTube answers again.');
        }
        if ($rows !== [] && $transcriptStatuses['error'] * 2 > count($rows)) {
            // Captions disabled is a property of a video; `error` never is.
            $this->log('WARNING: more than half the transcripts failed with "error". That is this machine or a '
                . 'throttle, not the videos: check the virtualenv (make voices-venv) before trusting this file.');
        }

        $document = [
            'generatedAt' => $now,
            'host' => $this->host,
            'queries' => array_values($this->queries),
            'models' => ['summary' => $this->primaryModels['summary'] ?? null,
                         'synthesis' => $this->primaryModels['synthesis'] ?? null, 'fellBackTo' => null],
            'stats' => ['candidates' => $found['stats']['candidates'],
                        'excludedNonRoblox' => $found['stats']['excludedNonRoblox'],
                        'withTranscript' => $withTranscript],
            'videos' => $rows,
            // Order of each section on the page; every id is also in `videos`.
            'lists' => $lists,
            'listRules' => ['recentMinSubscribers' => $this->recentMinSubscribers],
            'synthesis' => [],
        ];
        // A synthesis of summaries that have since been rewritten is not the
        // synthesis of this file: redo it whenever anything below it changed.
        $document['synthesis'] = $this->synthesis($rows, $previous, $now,
            ($options['resynthesize'] ?? false) === true || $regenerated > 0);
        $document['models'] = $this->models($document);
        $this->output->write($document);
        $this->prune(array_column($rows, 'id'));
        $this->log('written ' . $this->output->path());

        return $document;
    }

    /**
     * Comments, transcript and summary of one video, as the row the page reads.
     *
     * @param array<string, mixed> $video
     * @param array{only: list<string>, now: string, options: array<string, mixed>} $ctx
     * @param array<string, int> $transcriptStatuses
     * @return array<string, mixed>
     */
    private function row(array $video, array $ctx, array &$transcriptStatuses, int &$regenerated): array
    {
        $only = $ctx['only'];
        $now = $ctx['now'];
        $options = $ctx['options'];
        $id = (string)$video['id'];
        $selected = $only === [] || in_array($id, $only, true);
        $comments = $this->youtube->comments($id, CommentFilter::MAX_KEPT);
        $kept = $this->comments->filter($comments['comments']);
        $transcript = $this->transcripts->fetch($id, ($options['refreshTranscripts'] ?? false) !== true);
        $transcriptStatuses[$transcript['status']]++;
        $cached = $this->summarizer->cached($id);
        // A mixed cache is not a result: --remodel redoes whatever the
        // fallback wrote, and keeps everything the primary model produced.
        $wrongModel = ($options['remodel'] ?? false) === true && $cached !== null
            && ($cached['model'] ?? null) !== ($this->primaryModels['summary'] ?? null);
        if ($wrongModel) {
            $this->log($id . ': cached summary came from ' . ($cached['model'] ?? 'nothing') . ', asking the primary model again');
        } elseif ($cached !== null && $selected && $only === []) {
            $this->log($id . ': summary cached');
        }
        $summary = $selected
            ? $this->summarizer->summarize($video, $transcript, $kept, $now, $only === [] && !$wrongModel)
            : ($cached ?? self::failedSummary($now));
        $produced = $selected && ($cached === null || $wrongModel || $only !== []);
        $regenerated += $produced && $summary['status'] === 'ok' ? 1 : 0;
        $this->log(sprintf('%s: %s · transcript %s (%d chars) · comments %d→%d · summary %s (%s)',
            $id, mb_substr((string)$video['title'], 0, 48, 'UTF-8'), $transcript['status'],
            $transcript['chars'], $comments['fetched'], count($kept), $summary['status'],
            $summary['model'] ?? ($summary['error'] ?? 'nessun modello')));

        return [
            'id' => $id, 'title' => $video['title'], 'channel' => $video['channel'],
            'channelId' => $video['channelId'], 'publishedAt' => $video['publishedAt'],
            'views' => $video['views'], 'likes' => $video['likes'], 'commentCount' => $video['commentCount'],
            'url' => $video['url'],
            'thumbnail' => '/media/yt/' . $id . '.jpg',
            'transcript' => ['status' => $transcript['status'], 'language' => $transcript['language'],
                             'generated' => $transcript['generated'], 'chars' => $transcript['chars']],
            'comments' => ['fetched' => $comments['fetched'], 'kept' => count($kept)],
            'summary' => $summary,
            'lists' => [],
            // Several games in one video: the card stays, the synthesis leaves
            // it out, because its captions and comments are partly about
            // another game and would lend it their praise and complaints.
            'mixed' => $this->isMixed($video),
        ];
    }

    /**
     * Whether a video belongs on this game's page. A video that links only
     * this game is about it, whatever language its title is in. A video that
     * links other games as well stays only if its title names this game: one
     * titled after another game ("… | House of The Locust Indonesia") or after
     * no game at all is mostly about something else, and its views are not
     * ours to show.
     *
     * @param array<string, mixed> $video
     */
    private function isAboutThisGame(array $video): bool
    {
        return !$this->isMixed($video) || YouTubeClient::titleMatches((string)$video['title']);
    }

    /** @param array<string, mixed> $video */
    private function isMixed(array $video): bool
    {
        if ($this->placeId === null) {
            return false;
        }

        return array_diff(array_map('intval', $video['gameLinks'] ?? []), [$this->placeId]) !== [];
    }

    /** @param list<array<string, mixed>> $rows */
    private function synthesis(array $rows, array $previous, string $now, bool $force): array
    {
        $summarised = array_values(array_filter($rows, static fn (array $r): bool => $r['summary']['status'] === 'ok' && !($r['mixed'] ?? false)));
        $mixed = count(array_filter($rows, static fn (array $r): bool => ($r['mixed'] ?? false) === true));
        if ($mixed > 0) {
            $this->log(sprintf('synthesis: %d mixed %s left out (several games in one video)', $mixed, $mixed === 1 ? 'video' : 'videos'));
        }
        $old = is_array($previous['synthesis'] ?? null) ? $previous['synthesis'] : [];
        if ($summarised === []) {
            $this->log('synthesis: no summarised video, keeping the previous one');

            return $old === [] ? self::emptySynthesis($now) : ['status' => 'stale'] + $old;
        }
        if (!$force && !$this->synthesisIsStale($old, $summarised, $now)) {
            $this->log('synthesis: still current, reused');

            return $old;
        }
        try {
            $synthesis = $this->synthesizer->synthesize($summarised, $now);
            $this->log('synthesis: ' . count($synthesis['improvements']) . ' improvements, '
                . count($synthesis['likes']) . ' likes, model ' . $synthesis['model']);

            return $synthesis;
        } catch (Throwable $e) {
            $this->log('synthesis failed: ' . $e->getMessage());

            return $old === [] ? self::emptySynthesis($now) : ['status' => 'stale'] + $old;
        }
    }

    /** Regenerated once a day, or whenever the list of videos it was based on changed. */
    private function synthesisIsStale(array $old, array $summarised, string $now): bool
    {
        if (($old['status'] ?? '') !== 'ok') {
            return true;
        }
        if (substr((string)($old['generatedAt'] ?? ''), 0, 10) !== substr($now, 0, 10)) {
            return true;
        }
        $considered = array_map('strval', $old['videosConsidered'] ?? []);
        sort($considered);
        $current = array_column($summarised, 'id');
        sort($current);

        return $considered !== $current;
    }

    /** The models that actually produced the text, so the page can say when the fallback ran. */
    private function models(array $document): array
    {
        $models = $document['models'];
        $used = [];
        foreach ($document['videos'] as $video) {
            $model = $video['summary']['model'] ?? null;
            if (is_string($model) && $model !== '') {
                $used[$model] = ($used[$model] ?? 0) + 1;
            }
        }
        if ($used !== []) {
            // The model that wrote most of the cards is the one to name.
            arsort($used);
            $models['summary'] = (string)array_key_first($used);
        }
        $synthesisModel = $document['synthesis']['model'] ?? null;
        if (is_string($synthesisModel) && $synthesisModel !== '') {
            $models['synthesis'] = $synthesisModel;
            $used[$synthesisModel] = ($used[$synthesisModel] ?? 0) + 1;
        }
        foreach (array_keys($used) as $model) {
            if ($model !== ($this->primaryModels['summary'] ?? null) && $model !== ($this->primaryModels['synthesis'] ?? null)) {
                $models['fellBackTo'] = $model;
            }
        }

        return $models;
    }

    /** A video that leaves the top ten keeps its cache for 30 days, then it goes. */
    private function prune(array $keepIds, int $days = 30): void
    {
        $cutoff = time() - $days * 86400;
        $removed = $this->thumbnails->prune($keepIds, $days);
        foreach (glob($this->summaryCacheDir . '/*.json') ?: [] as $file) {
            if (!in_array(basename($file, '.json'), $keepIds, true) && (int)filemtime($file) < $cutoff && unlink($file)) {
                $removed++;
            }
        }
        if ($removed > 0) {
            $this->log('pruned ' . $removed . ' stale cache files');
        }
    }

    /** @return array<string, mixed> what a real run would do, without doing any of it */
    private function plan(int $topN, array $previous): array
    {
        $cached = count(glob($this->summaryCacheDir . '/*.json') ?: []);
        $this->log('dry run, nothing will be sent');
        $this->log(sprintf('YouTube: %d search pages (%d units), 1 videos.list, up to %d commentThreads ≈ %d units',
            count($this->queries) * 2, count($this->queries) * 2 * 100, $topN, count($this->queries) * 200 + 1 + $topN));
        $this->log(sprintf('transcripts: up to %d runs of tools/transcript.py', $topN));
        $this->log(sprintf('model: up to %d summaries (%d already cached) + 1 synthesis', $topN, $cached));
        $this->log('output: ' . $this->output->path() . ' (previous run: ' . ($previous['generatedAt'] ?? 'none') . ')');

        return $previous;
    }

    /** Same keys as a good summary, so the view never has to test for their presence. */
    public static function failedSummary(string $now, ?string $error = null, array $basedOn = []): array
    {
        return ['status' => 'failed', 'generatedAt' => $now, 'model' => null, 'basedOn' => $basedOn, 'tone' => null,
                'likes' => [], 'improvements' => [], 'oneLine' => null, 'quotes' => []]
            + ($error !== null ? ['error' => $error] : []);
    }

    private static function emptySynthesis(string $now): array
    {
        return ['status' => 'stale', 'generatedAt' => $now, 'model' => null, 'videosConsidered' => [],
                'likes' => [], 'improvements' => [], 'verdict' => '', 'timeline' => []];
    }

    private function log(string $line): void
    {
        ($this->log)($line);
    }
}
