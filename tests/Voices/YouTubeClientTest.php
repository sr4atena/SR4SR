<?php
declare(strict_types=1);

namespace ManorLedger\Tests\Voices;

use ManorLedger\Voices\YouTubeClient;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Fixtures only: the API shape, the title filter and the Roblox exclusion rule. */
final class YouTubeClientTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $sent = [];

    private function transport(array $waves): callable
    {
        return function (array $requests) use (&$waves): array {
            foreach ($requests as $request) {
                $this->sent[] = $request;
            }
            $bodies = array_shift($waves) ?? [];

            return array_map(static fn (string $body): array => ['status' => 200, 'body' => $body, 'headers' => []], $bodies);
        };
    }

    private static function fixture(string $name): string
    {
        return (string)file_get_contents(__DIR__ . '/../fixtures/voices/' . $name);
    }

    public function testTopTenIsRelevanceSearchedAndLocallySortedByViews(): void
    {
        $page1 = self::fixture('search-page1.json');
        $page2 = self::fixture('search-page2.json');
        $client = new YouTubeClient('K-E-Y', $this->transport([
            [$page1, $page1],
            [$page2, $page2],
            [self::fixture('videos.json')],
        ]));

        $result = $client->topVideos(YouTubeClient::DEFAULT_QUERIES, 10);

        self::assertCount(5, $this->sent, 'two pages of two queries, then one videos.list');
        foreach (array_slice($this->sent, 0, 4) as $request) {
            self::assertStringContainsString('order=relevance', $request['url']);
            self::assertStringNotContainsString('viewCount', $request['url']);
            self::assertStringContainsString('key=K-E-Y', $request['url']);
        }
        self::assertStringContainsString('pageToken=PAGE2', $this->sent[2]['url']);

        // Only the two on-topic videos survive; the Fortnite and Minecraft remakes go,
        // although they have far more views.
        self::assertSame(['CCCCCCCCCCC', 'AAAAAAAAAAA'], array_column($result['videos'], 'id'));
        self::assertSame(4, $result['stats']['candidates']);
        self::assertSame(2, $result['stats']['excludedNonRoblox']);
        self::assertSame("THE LOCUST'S MANOR full playthrough", $result['videos'][1]['title']);
        self::assertSame('Ghosty & Co', $result['videos'][1]['channel']);
        self::assertSame('2026-09-02', $result['videos'][1]['publishedAt']);
        self::assertSame(90216, $result['videos'][1]['views']);
        self::assertSame('https://www.youtube.com/watch?v=AAAAAAAAAAA', $result['videos'][1]['url']);
        self::assertArrayNotHasKey('description', $result['videos'][0], 'internal fields stay internal');
    }

    public function testArchiveCandidatesJoinTheSearchBeforeTheSortByViews(): void
    {
        $empty = '{"items":[]}';
        $client = new YouTubeClient('K', $this->transport([[$empty, $empty], [self::fixture('videos.json')]]));
        $result = $client->topVideos(YouTubeClient::DEFAULT_QUERIES, 10, 1, ['CCCCCCCCCCC', 'AAAAAAAAAAA', 'BBBBBBBBBBB', 'bad id']);

        // The search found nothing; the archive's candidates still make the top,
        // except the Fortnite remake, which the platform rule keeps out.
        self::assertSame(['CCCCCCCCCCC', 'AAAAAAAAAAA'], array_column($result['videos'], 'id'));
        self::assertSame(2, $result['stats']['fromArchive']);
        self::assertStringNotContainsString('bad', (string)end($this->sent)['url']);
    }

    public function testGameLinksAreReadFromEveryUrlShape(): void
    {
        self::assertSame([134208374070897, 97090732168175], YouTubeClient::gameLinks(
            "Game 1: https://www.roblox.com/games/134208374070897/MONOCHROME\n"
            . "Game 2: https://www.roblox.com/it/games/97090732168175/The-Locusts-Manor\n"
            . 'again https://roblox.com/games/start?placeId=97090732168175 · profile https://www.roblox.com/users/159985305/profile'));
        self::assertSame([], YouTubeClient::gameLinks('no links, only https://www.roblox.com/groups/123456/x'));
    }

    public function testTitleFilterIsCaseAndEntityInsensitive(): void
    {
        self::assertTrue(YouTubeClient::titleMatches('THE LOCUST&#39;S MANOR'));
        self::assertTrue(YouTubeClient::titleMatches('Jugué the locust’s manor y me arrepentí'));
        self::assertFalse(YouTubeClient::titleMatches('Top 10 Roblox horror games of 2026'));
        self::assertFalse(YouTubeClient::titleMatches('The Manor of secrets'));
    }

    public function testExclusionNeverRequiresTheWordRoblox(): void
    {
        $spanish = ['title' => "Jugué The Locust's Manor", 'description' => 'un juego de terror', 'tags' => []];
        self::assertFalse(YouTubeClient::isOtherPlatform($spanish), 'most creators never write "roblox"');
        self::assertTrue(YouTubeClient::isOtherPlatform(['title' => 'Locust Manor in Fortnite', 'description' => '', 'tags' => []]));
        self::assertTrue(YouTubeClient::isOtherPlatform(['title' => 'Locust Manor', 'description' => 'built in gmod', 'tags' => []]));
        self::assertFalse(
            YouTubeClient::isOtherPlatform(['title' => 'Roblox vs Minecraft horror', 'description' => '', 'tags' => []]),
            'naming Roblox as well keeps the video',
        );
    }

    public function testCommentsAreFlattenedAndDisabledCommentsAreNotAnError(): void
    {
        $client = new YouTubeClient('K', $this->transport([[self::fixture('comments.json')]]));
        $result = $client->comments('AAAAAAAAAAA', 60);
        self::assertSame(4, $result['fetched']);
        self::assertFalse($result['disabled']);
        self::assertSame(90, $result['comments'][0]['likes']);
        self::assertStringContainsString('order=relevance', $this->sent[0]['url']);

        $forbidden = new YouTubeClient('K', static fn (): array => [['status' => 403, 'body' => '{"error":{"message":"disabled comments"}}']]);
        self::assertSame(['fetched' => 0, 'comments' => [], 'disabled' => true], $forbidden->comments('AAAAAAAAAAA'));
    }

    public function testQuotaErrorsSurfaceWithoutLeakingTheKey(): void
    {
        $client = new YouTubeClient('SECRET-KEY', static fn (): array => [[
            'status' => 403,
            'body' => '{"error":{"code":403,"message":"Quota exceeded for key SECRET-KEY","errors":[{"reason":"quotaExceeded"}]}}',
        ]]);
        try {
            $client->videos(['AAAAAAAAAAA']);
            self::fail('a quota error must stop the run');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('quotaExceeded', $e->getMessage());
            self::assertStringNotContainsString('SECRET-KEY', $e->getMessage());
        }
    }

    public function testShortsAreLeftOutOfTheMostWatchedWhenAMinimumLengthIsSet(): void
    {
        $search = json_encode(['items' => [
            ['id' => ['videoId' => 'SSSSSSSSSSS'], 'snippet' => ['title' => "The Locust's Manor jump scare #shorts"]],
            ['id' => ['videoId' => 'LLLLLLLLLLL'], 'snippet' => ['title' => "The Locust's Manor full playthrough"]],
            ['id' => ['videoId' => 'UUUUUUUUUUU'], 'snippet' => ['title' => "The Locust's Manor, length unknown"]],
        ]]);
        $video = static fn (string $id, int $views, ?string $duration): array => ['id' => $id,
            'snippet' => ['title' => "The Locust's Manor", 'channelTitle' => 'C', 'description' => ''],
            'statistics' => ['viewCount' => (string)$views]] + ($duration === null ? [] : ['contentDetails' => ['duration' => $duration]]);
        $videos = json_encode(['items' => [$video('SSSSSSSSSSS', 90000, 'PT14S'), $video('LLLLLLLLLLL', 5000, 'PT12M3S'),
                                           $video('UUUUUUUUUUU', 100, null)]]);
        $client = new YouTubeClient('K', $this->transport([[$search], [$videos]]));

        $result = $client->topVideos(["The Locust's Manor"], 10, 1, [], 240);

        self::assertStringContainsString('contentDetails', $this->sent[1]['url']);
        self::assertSame(['LLLLLLLLLLL', 'UUUUUUUUUUU'], array_column($result['videos'], 'id'));
        self::assertSame(723, $result['videos'][0]['seconds']);
    }

    public function testDurationsAreReadInSeconds(): void
    {
        self::assertSame(253, YouTubeClient::seconds('PT4M13S'));
        self::assertSame(14, YouTubeClient::seconds('PT14S'));
        self::assertSame(3723, YouTubeClient::seconds('PT1H2M3S'));
        self::assertNull(YouTubeClient::seconds(''));
    }
}
