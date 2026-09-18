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
