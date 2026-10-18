<?php

namespace Tests\Feature;

use App\Services\ContentPublisher;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\WorkflowTestCase;

class ContentBoundaryTest extends WorkflowTestCase
{
    public function test_content_service_and_http_share_permission_translation_and_revision_rules(): void
    {
        $id = app(ContentPublisher::class)->publish($this->admin, 'lt', 'Sintetinis projektas');
        $this->actingAs($this->admin)->postJson(route('probe.content'), ['id' => $id, 'revision' => 1, 'locale' => 'lt', 'title' => 'Atnaujintas projektas'])->assertOk();
        $this->assertDatabaseHas('probe_contents', ['id' => $id, 'revision' => 2, 'title' => 'Atnaujintas projektas']);
        $this->actingAs($this->admin)->postJson(route('probe.content'), ['id' => $id, 'revision' => 1, 'locale' => 'lt', 'title' => 'Stale edit'])->assertUnprocessable();
        $this->actingAs($this->buyer)->postJson(route('probe.content'), ['locale' => 'en', 'title' => 'Unauthorized'])->assertForbidden();
        $this->actingAs($this->admin)->postJson(route('probe.content'), ['locale' => 'ru', 'title' => 'Public RU'])->assertUnprocessable();
    }

    public function test_non_admin_cannot_publish_through_service_adapter(): void
    {
        $this->expectException(AuthorizationException::class);
        app(ContentPublisher::class)->publish($this->buyer, 'en', 'Unauthorized');
    }
}
