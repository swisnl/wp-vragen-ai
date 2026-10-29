<?php

namespace VragenAI\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;
use VragenAI\ApiClient;
use VragenAI\DocumentSync;
use VragenAI\Tests\TestCase;

class DocumentSyncSelectionTest extends TestCase
{
    private function shouldSyncPost(\WP_Post $post): bool
    {
        $sync = new DocumentSync(Mockery::mock(ApiClient::class));

        $method = new \ReflectionMethod(DocumentSync::class, 'shouldSyncPost');
        $method->setAccessible(true);

        return (bool) $method->invoke($sync, $post);
    }

    public function test_published_post_is_synced(): void
    {
        $this->assertTrue($this->shouldSyncPost(new \WP_Post([
            'post_status' => 'publish',
        ])));
    }

    public function test_password_protected_post_is_not_synced(): void
    {
        $this->assertFalse($this->shouldSyncPost(new \WP_Post([
            'post_status' => 'publish',
            'post_password' => 'hunter2',
        ])));
    }

    public function test_draft_and_private_posts_are_not_synced(): void
    {
        $this->assertFalse($this->shouldSyncPost(new \WP_Post(['post_status' => 'draft'])));
        $this->assertFalse($this->shouldSyncPost(new \WP_Post(['post_status' => 'private'])));
    }

    public function test_parentless_attachment_is_synced(): void
    {
        $this->assertTrue($this->shouldSyncPost(new \WP_Post([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'post_parent' => 0,
        ])));
    }

    public function test_attachment_of_password_protected_parent_is_not_synced(): void
    {
        Functions\when('get_post')->justReturn(new \WP_Post([
            'ID' => 5,
            'post_status' => 'publish',
            'post_password' => 'hunter2',
        ]));

        $this->assertFalse($this->shouldSyncPost(new \WP_Post([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'post_parent' => 5,
        ])));
    }

    public function test_attachment_of_published_parent_is_synced(): void
    {
        Functions\when('get_post')->justReturn(new \WP_Post([
            'ID' => 5,
            'post_status' => 'publish',
        ]));

        $this->assertTrue($this->shouldSyncPost(new \WP_Post([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'post_parent' => 5,
        ])));
    }

    public function test_parentless_attachment_with_own_password_is_not_synced(): void
    {
        $this->assertFalse($this->shouldSyncPost(new \WP_Post([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'post_parent' => 0,
            'post_password' => 'hunter2',
        ])));
    }

    public function test_sync_post_directly_removes_document_for_non_syncable_post(): void
    {
        Functions\when('get_locale')->justReturn('nl');
        Functions\when('is_wp_error')->alias(static fn ($thing): bool => $thing instanceof \WP_Error);

        $client = Mockery::mock(ApiClient::class);
        $client->shouldReceive('findByExternalReference')
            ->with('wp_post_5')
            ->andReturn(['data' => [['id' => 'doc-5', 'attributes' => ['meta_data' => ['languages' => ['nl']]]]]]);
        $client->shouldReceive('deleteDocument')->with('doc-5')->once()->andReturn([]);
        $client->shouldReceive('createDocument')->never();
        $client->shouldReceive('updateDocument')->never();

        (new DocumentSync($client))->syncPostDirectly(new \WP_Post([
            'ID' => 5,
            'post_status' => 'publish',
            'post_password' => 'hunter2',
        ]));
    }

    public function test_sync_post_removes_document_for_non_syncable_post(): void
    {
        Functions\when('get_locale')->justReturn('nl');
        Functions\when('is_wp_error')->alias(static fn ($thing): bool => $thing instanceof \WP_Error);
        Functions\when('get_post')->justReturn(new \WP_Post([
            'ID' => 7,
            'post_type' => 'post',
            'post_status' => 'future',
        ]));

        $client = Mockery::mock(ApiClient::class);
        $client->shouldReceive('findByExternalReference')
            ->with('wp_post_7')
            ->andReturn(['data' => [['id' => 'doc-7', 'attributes' => ['meta_data' => ['languages' => ['nl']]]]]]);
        $client->shouldReceive('deleteDocument')->with('doc-7')->once()->andReturn([]);
        $client->shouldReceive('createDocument')->never();
        $client->shouldReceive('updateDocument')->never();

        (new DocumentSync($client))->syncPost(7);
    }

    public function test_on_save_post_schedules_removal_when_post_becomes_password_protected(): void
    {
        Functions\when('wp_is_post_revision')->justReturn(false);
        Functions\when('wp_is_post_autosave')->justReturn(false);
        Functions\when('get_option')->justReturn(['post_types' => ['post']]);
        Functions\when('get_locale')->justReturn('nl');
        Functions\expect('as_schedule_single_action')
            ->once()
            ->with(Mockery::type('int'), 'vragenai_delete_document', Mockery::type('array'), 'vragenai');

        $post = new \WP_Post([
            'ID' => 5,
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_password' => 'hunter2',
        ]);

        (new DocumentSync(Mockery::mock(ApiClient::class)))->onSavePost(5, $post);
    }

    public function test_on_save_post_schedules_sync_for_published_post(): void
    {
        Functions\when('wp_is_post_revision')->justReturn(false);
        Functions\when('wp_is_post_autosave')->justReturn(false);
        Functions\when('get_option')->justReturn(['post_types' => ['post']]);
        Functions\expect('as_schedule_single_action')
            ->once()
            ->with(Mockery::type('int'), 'vragenai_sync_post', ['post_id' => 5], 'vragenai');

        $post = new \WP_Post([
            'ID' => 5,
            'post_type' => 'post',
            'post_status' => 'publish',
        ]);

        (new DocumentSync(Mockery::mock(ApiClient::class)))->onSavePost(5, $post);
    }

    public function test_on_save_post_schedules_removal_when_post_is_scheduled(): void
    {
        Functions\when('wp_is_post_revision')->justReturn(false);
        Functions\when('wp_is_post_autosave')->justReturn(false);
        Functions\when('get_option')->justReturn(['post_types' => ['post']]);
        Functions\when('get_locale')->justReturn('nl');
        Functions\expect('as_schedule_single_action')
            ->once()
            ->with(Mockery::type('int'), 'vragenai_delete_document', Mockery::type('array'), 'vragenai');

        $post = new \WP_Post([
            'ID' => 5,
            'post_type' => 'post',
            'post_status' => 'future',
        ]);

        (new DocumentSync(Mockery::mock(ApiClient::class)))->onSavePost(5, $post);
    }

    public function test_on_save_post_schedules_removal_for_non_public_custom_status(): void
    {
        Functions\when('wp_is_post_revision')->justReturn(false);
        Functions\when('wp_is_post_autosave')->justReturn(false);
        Functions\when('get_option')->justReturn(['post_types' => ['post']]);
        Functions\when('get_locale')->justReturn('nl');
        Functions\expect('as_schedule_single_action')
            ->once()
            ->with(Mockery::type('int'), 'vragenai_delete_document', Mockery::type('array'), 'vragenai');

        $post = new \WP_Post([
            'ID' => 5,
            'post_type' => 'post',
            'post_status' => 'in-review',
        ]);

        (new DocumentSync(Mockery::mock(ApiClient::class)))->onSavePost(5, $post);
    }

    public function test_sync_post_directly_removes_document_when_filter_excludes_it(): void
    {
        Functions\when('get_locale')->justReturn('nl');
        Functions\when('is_wp_error')->alias(static fn ($thing): bool => $thing instanceof \WP_Error);
        Functions\when('apply_filters')->alias(
            static fn (string $hook, mixed $value = null, mixed $arg = null): mixed => $hook === 'vragenai_should_index_post' ? false : $value,
        );

        $client = Mockery::mock(ApiClient::class);
        $client->shouldReceive('findByExternalReference')
            ->with('wp_post_5')
            ->andReturn(['data' => [['id' => 'doc-5', 'attributes' => ['meta_data' => ['languages' => ['nl']]]]]]);
        $client->shouldReceive('deleteDocument')->with('doc-5')->once()->andReturn([]);
        $client->shouldReceive('createDocument')->never();
        $client->shouldReceive('updateDocument')->never();

        (new DocumentSync($client))->syncPostDirectly(new \WP_Post([
            'ID' => 5,
            'post_type' => 'post',
            'post_status' => 'publish',
        ]));
    }

    public function test_on_save_post_reevaluates_child_attachments_when_attachments_are_synced(): void
    {
        Functions\when('wp_is_post_revision')->justReturn(false);
        Functions\when('wp_is_post_autosave')->justReturn(false);
        Functions\when('get_option')->justReturn(['post_types' => ['post', 'attachment']]);
        Functions\when('get_posts')->justReturn([11, 12]);

        // The parent's own sync, plus one re-evaluation per child attachment.
        Functions\expect('as_schedule_single_action')
            ->once()
            ->with(Mockery::type('int'), 'vragenai_sync_post', ['post_id' => 5], 'vragenai');
        Functions\expect('as_schedule_single_action')
            ->once()
            ->with(Mockery::type('int'), 'vragenai_sync_post', ['post_id' => 11], 'vragenai');
        Functions\expect('as_schedule_single_action')
            ->once()
            ->with(Mockery::type('int'), 'vragenai_sync_post', ['post_id' => 12], 'vragenai');

        $post = new \WP_Post([
            'ID' => 5,
            'post_type' => 'post',
            'post_status' => 'publish',
        ]);

        (new DocumentSync(Mockery::mock(ApiClient::class)))->onSavePost(5, $post);
    }

    public function test_on_save_post_does_not_reevaluate_children_when_attachments_are_not_synced(): void
    {
        Functions\when('wp_is_post_revision')->justReturn(false);
        Functions\when('wp_is_post_autosave')->justReturn(false);
        Functions\when('get_option')->justReturn(['post_types' => ['post']]);
        Functions\when('as_schedule_single_action')->justReturn(1);
        Functions\expect('get_posts')->never();

        $post = new \WP_Post([
            'ID' => 5,
            'post_type' => 'post',
            'post_status' => 'publish',
        ]);

        (new DocumentSync(Mockery::mock(ApiClient::class)))->onSavePost(5, $post);
    }
}
