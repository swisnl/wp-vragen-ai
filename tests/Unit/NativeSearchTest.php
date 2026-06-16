<?php

namespace VragenAI\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;
use VragenAI\ApiClient;
use VragenAI\Search\NativeSearch;
use VragenAI\Search\SearchService;
use VragenAI\Tests\TestCase;

class NativeSearchTest extends TestCase
{
    /** @param array<string, mixed> $settingsOverrides */
    private function stubSite(array $settingsOverrides = []): void
    {
        $settings = array_merge([
            'customer' => 'acme',
            'token' => 'tok',
            'post_types' => ['post', 'page'],
            'replace_native_search' => true,
            'search_language_fallback' => true,
        ], $settingsOverrides);

        Functions\when('is_admin')->justReturn(false);
        Functions\when('get_option')->alias(static fn (string $key, mixed $default = false) => $key === 'vragenai_settings' ? $settings : $default);
        Functions\when('get_locale')->justReturn('nl_NL');
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('is_wp_error')->alias(static fn ($thing): bool => $thing instanceof \WP_Error);
        Functions\when('wp_json_encode')->alias(static fn ($data): string => (string) json_encode($data));
        Functions\when('get_post')->alias(static fn ($id): \WP_Post => new \WP_Post(['ID' => (int) $id]));
        Functions\when('apply_filters')->returnArg(2);
        Functions\when('get_posts')->alias(static fn (array $args): array => array_map(
            static fn ($id): \WP_Post => new \WP_Post(['ID' => (int) $id]),
            $args['post__in'] ?? [],
        ));
    }

    private function nativeSearch(ApiClient $client): NativeSearch
    {
        return new NativeSearch(new SearchService($client));
    }

    public function test_replaces_results_in_relevance_order_and_sets_pagination(): void
    {
        $this->stubSite();

        $client = Mockery::mock(ApiClient::class);
        $client->shouldReceive('searchDocuments')->once()->andReturn([
            'data' => [
                ['attributes' => ['external_reference' => 'wp_post_42']],
                ['attributes' => ['external_reference' => 'wp_post_7']],
            ],
            'meta' => ['total' => 2],
        ]);

        $query = new \WP_Query(['s' => 'hello', 'posts_per_page' => 10, 'paged' => 1]);

        $posts = $this->nativeSearch($client)->maybeShortCircuit(null, $query);

        $this->assertIsArray($posts);
        $this->assertSame([42, 7], array_map(static fn (\WP_Post $p): int => $p->ID, $posts));
        $this->assertSame(2, $query->found_posts);
        $this->assertSame(1, $query->max_num_pages);
    }

    public function test_ignores_queries_that_are_not_a_front_end_main_search(): void
    {
        $this->stubSite();

        $client = Mockery::mock(ApiClient::class);
        $client->shouldNotReceive('searchDocuments');

        $query = new \WP_Query(['s' => 'hello']);
        $query->searchQuery = false;

        // Returns the incoming value untouched, so WordPress runs its own query.
        $this->assertNull($this->nativeSearch($client)->maybeShortCircuit(null, $query));
    }

    public function test_does_nothing_when_toggle_is_disabled(): void
    {
        $this->stubSite(['replace_native_search' => false]);

        $client = Mockery::mock(ApiClient::class);
        $client->shouldNotReceive('searchDocuments');

        $query = new \WP_Query(['s' => 'hello']);

        $this->assertNull($this->nativeSearch($client)->maybeShortCircuit(null, $query));
    }

    public function test_falls_back_to_native_search_on_api_error(): void
    {
        $this->stubSite();

        $client = Mockery::mock(ApiClient::class);
        $client->shouldReceive('searchDocuments')->once()->andReturn(new \WP_Error('boom'));

        $query = new \WP_Query(['s' => 'hello', 'posts_per_page' => 10, 'paged' => 1]);

        // Incoming $posts (null) is returned unchanged → WordPress falls back.
        $this->assertNull($this->nativeSearch($client)->maybeShortCircuit(null, $query));
        $this->assertSame(0, $query->found_posts);
    }
}
