<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_remember_me_is_a_switch_not_a_checkbox(): void
    {
        $this->get('/admin/login')
            ->assertOk()
            ->assertSee('role="switch"', false)
            ->assertDontSee('type="checkbox"', false);
    }
}
