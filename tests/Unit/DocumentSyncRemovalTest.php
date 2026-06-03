<?php

namespace VragenAI\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;
use VragenAI\ApiClient;
use VragenAI\DocumentSync;
use VragenAI\Tests\TestCase;

class DocumentSyncRemovalTest extends TestCase
{
    public function test_removing_canonical_language_deletes_document(): void
    {
        Functions\when('is_wp_error')->justReturn(false);

        $client = Mockery::mock(ApiClient::class);
        $client->shouldReceive('findByExternalReference')
            ->with('wp_post_10')
            ->andReturn(['data' => [['id' => 'doc-1', 'attributes' => ['meta_data' => ['languages' => ['nl', 'en']]]]]]);
        $client->shouldReceive('deleteDocument')->with('doc-1')->once()->andReturn([]);
        $client->shouldNotReceive('updateDocument');

        (new DocumentSync($client))->removeTranslation([
            'external_reference' => 'wp_post_10',
            'language' => 'nl',
            'canonical_language' => 'nl',
            'remaining_languages' => ['en'],
        ]);
    }

    public function test_removing_secondary_language_drops_it_from_languages(): void
    {
        Functions\when('is_wp_error')->justReturn(false);

        $client = Mockery::mock(ApiClient::class);
        $client->shouldReceive('findByExternalReference')
            ->with('wp_post_10')
            ->andReturn(['data' => [['id' => 'doc-1', 'attributes' => ['meta_data' => ['languages' => ['nl', 'en'], 'author' => 'Jane']]]]]);
        $client->shouldReceive('updateDocument')
            ->with('doc-1', ['meta_data' => ['languages' => ['nl'], 'author' => 'Jane']])
            ->once()
            ->andReturn([]);
        $client->shouldNotReceive('deleteDocument');

        (new DocumentSync($client))->removeTranslation([
            'external_reference' => 'wp_post_10',
            'language' => 'en',
            'canonical_language' => 'nl',
            'remaining_languages' => ['nl'],
        ]);
    }
}
