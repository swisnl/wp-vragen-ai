<?php

namespace VragenAI\Tests\Unit;

use Brain\Monkey\Functions;
use VragenAI\ApiClient;
use VragenAI\DocumentSync;
use VragenAI\Tests\TestCase;

class DocumentSyncAttachmentPostTest extends TestCase
{
    public function test_on_save_post_schedules_sync_for_attachment_with_inherit_status(): void
    {
        Functions\when('wp_is_post_revision')->justReturn(false);
        Functions\when('wp_is_post_autosave')->justReturn(false);
        Functions\when('get_option')->justReturn(['post_types' => ['attachment']]);
        Functions\expect('as_schedule_single_action')
            ->once()
            ->with(\Mockery::type('int'), 'vragenai_sync_post', ['post_id' => 42], 'vragenai');

        $post = new \WP_Post([
            'ID' => 42,
            'post_type' => 'attachment',
            'post_status' => 'inherit',
        ]);

        (new DocumentSync($this->dummyClient()))->onSavePost(42, $post);
    }

    private function dummyClient(): ApiClient
    {
        return \Mockery::mock(ApiClient::class);
    }
}
