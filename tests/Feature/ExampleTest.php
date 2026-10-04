<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The dashboard is behind auth, so an anonymous visitor is redirected to
     * the login screen. (The stock Laravel version of this test asserted a 200
     * and had been failing since authentication was added.)
     */
    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    public function test_login_screen_renders(): void
    {
        $this->get('/login')->assertStatus(200);
    }
}
