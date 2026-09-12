<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_root_redirects_to_static_app(): void
    {
        $response = $this->get('/');

        $response->assertRedirect('/app/index.html');
    }

    public function test_app_index_page_is_served(): void
    {
        $response = $this->get('/app/index.html');

        $response->assertStatus(200);
    }
}
