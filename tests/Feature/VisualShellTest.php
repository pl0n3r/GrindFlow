<?php

namespace Tests\Feature;

use Tests\TestCase;

class VisualShellTest extends TestCase
{
    public function test_home_renders_grindflow_visual_shell(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Control operativo')
            ->assertSee('Laravel core online')
            ->assertSee('css/grindflow.css');

        $this->assertFileExists(public_path('css/grindflow.css'));
    }

    public function test_mobile_workspace_navigation_keeps_all_links_scrollable_and_named(): void
    {
        $css = file_get_contents(public_path('css/grindflow.css'));

        $this->assertIsString($css);
        $this->assertStringContainsString('overflow-x: auto;', $css);
        $this->assertStringContainsString('.gf-sidebar .gf-navitem__text', $css);
        $this->assertStringNotContainsString('.gf-navitem:nth-of-type(n+5)', $css);
    }

    public function test_login_renders_grindflow_visual_shell(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('Bienvenido.')
            ->assertSee('Entrar al workspace')
            ->assertSee('MariaDB active')
            ->assertDontSee('PostgreSQL')
            ->assertDontSee('RLS enabled')
            ->assertSee('css/grindflow.css');
    }
}
