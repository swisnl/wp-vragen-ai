<?php

namespace VragenAI\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;
use VragenAI\ApiClient;
use VragenAI\Search\SearchService;
use VragenAI\Tests\TestCase;

class SearchServiceTest extends TestCase
{
    /**
     * Common WordPress stubs for a configured, monolingual (nl_NL) site where
     * every post resolves to itself.
     */
    private function stubConfiguredSite(): void
    {
        Functions\when('get_option')->justReturn(['customer' => 'acme', 'token' => 'tok']);
        Functions\when('get_locale')->justReturn('nl_NL');
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('is_wp_error')->alias(static fn ($thing): bool => $thing instanceof \WP_Error);
        Functions\when('wp_json_encode')->alias(static fn ($data): string => (string) json_encode($data));
        Functions\when('get_post')->alias(static fn ($id): \WP_Post => new \WP_Post(['ID' => (int) $id]));
    }

    public function test_search_maps_external_references_to_post_ids(): void
    {
        $this->stubConfiguredSite();

        $client = Mockery::mock(ApiClient::class);
        $client->shouldReceive('searchDocuments')
            ->once()
            ->withArgs(static function (array $params): bool {
                return ($params['query'] ?? null) === 'hello'
                    && ($params['page[offset]'] ?? null) === 0
                    && ($params['page[limit]'] ?? null) === 10
                    && ! empty($params['fields[documents]']);
            })
            ->andReturn([
                'data' => [
                    ['attributes' => ['external_reference' => 'wp_post_42']],
                    ['attributes' => ['external_reference' => 'wp_post_7']],
                    // Foreign reference from another source sharing the knowledge base.
                    ['attributes' => ['external_reference' => 'drupal_node_3']],
                ],
                'meta' => ['total' => 2],
            ]);

        $result = (new SearchService($client))->search('hello', 0, 10);

        $this->assertSame(['ids' => [42, 7], 'total' => 2], $result);
    }

    public function test_search_forwards_alpha_and_max_distance(): void
    {
        $this->stubConfiguredSite();

        $client = Mockery::mock(ApiClient::class);
        $client->shouldReceive('searchDocuments')
            ->once()
            ->withArgs(static function (array $params): bool {
                return ($params['alpha'] ?? null) === 0.3 && ($params['maxDistance'] ?? null) === 0.8;
            })
            ->andReturn(['data' => [], 'meta' => ['total' => 0]]);

        (new SearchService($client))->search('hello', 0, 10, ['alpha' => 0.3, 'max_distance' => 0.8]);
    }

    public function test_search_returns_null_on_api_error(): void
    {
        $this->stubConfiguredSite();

        $client = Mockery::mock(ApiClient::class);
        $client->shouldReceive('searchDocuments')->once()->andReturn(new \WP_Error('boom'));

        $this->assertNull((new SearchService($client))->search('hello', 0, 10));
    }

    public function test_search_returns_null_when_not_configured(): void
    {
        Functions\when('get_option')->justReturn([]);
        Functions\when('get_locale')->justReturn('nl_NL');

        $client = Mockery::mock(ApiClient::class);
        $client->shouldNotReceive('searchDocuments');

        $this->assertNull((new SearchService($client))->search('hello', 0, 10));
    }

    public function test_search_returns_null_for_blank_query(): void
    {
        $this->stubConfiguredSite();

        $client = Mockery::mock(ApiClient::class);
        $client->shouldNotReceive('searchDocuments');

        $this->assertNull((new SearchService($client))->search('   ', 0, 10));
    }

    public function test_related_is_seeded_by_the_source_reference_without_over_fetching(): void
    {
        $this->stubConfiguredSite();

        $client = Mockery::mock(ApiClient::class);
        $client->shouldReceive('similarDocuments')
            ->once()
            ->withArgs(static function (string $ref, array $params): bool {
                // Seeded by the source post's external reference; the limit is
                // exact — the endpoint already excludes the seed from results.
                return $ref === 'wp_post_100' && ($params['page[limit]'] ?? null) === 3;
            })
            ->andReturn([
                'data' => [
                    ['attributes' => ['external_reference' => 'wp_post_55']],
                    ['attributes' => ['external_reference' => 'wp_post_88']],
                ],
                'meta' => ['total' => 2],
            ]);

        $result = (new SearchService($client))->related(100, 3);

        $this->assertNotNull($result);
        $this->assertSame([55, 88], $result['ids']);
    }

    public function test_related_sends_max_distance_as_the_distance_param(): void
    {
        $this->stubConfiguredSite();

        $client = Mockery::mock(ApiClient::class);
        $client->shouldReceive('similarDocuments')
            ->once()
            // The similar endpoint names it "distance", not "maxDistance".
            ->withArgs(static fn (string $ref, array $params): bool => ($params['distance'] ?? null) === 0.4)
            ->andReturn(['data' => [], 'meta' => ['total' => 0]]);

        (new SearchService($client))->related(100, 3, ['max_distance' => 0.4]);
    }
}
