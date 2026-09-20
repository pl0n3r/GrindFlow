<?php

namespace Tests\Feature;

use Tests\TestCase;

class VisualShellTest extends TestCase
{
    public function test_home_renders_grindflow_visual_shell(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Tu contenido.')
            ->assertSee('En movimiento.')
            ->assertSee('Carga y organiza')
            ->assertSee('Define tus reglas')
            ->assertSee('Prepara y distribuye')
            ->assertSee('Observa resultados')
            ->assertSee('Creadores')
            ->assertSee('Estudios y agencias')
            ->assertSee(route('login'))
            ->assertSee('data-grindflow-version="'.config('version.number').'"', false)
            ->assertSee('GrindFlow · v'.config('version.number'))
            ->assertDontSee('Laravel core online')
            ->assertDontSee('PostgreSQL RLS')
            ->assertDontSee('Hostinger runtime')
            ->assertSee('css/grindflow.css');

        $this->assertFileExists(public_path('css/grindflow.css'));
    }

    public function test_release_footer_is_identical_on_public_login_and_authenticated_workspace(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('data-grindflow-version="'.config('version.number').'"', false);

        $this->get('/login')
            ->assertOk()
            ->assertSee('data-grindflow-version="'.config('version.number').'"', false);
    }

    public function test_mobile_workspace_navigation_keeps_all_links_scrollable_and_named(): void
    {
        $css = file_get_contents(public_path('css/grindflow.css'));

        $this->assertIsString($css);
        $mobileCss = strstr($css, '@media (max-width: 680px) {');

        $this->assertIsString($mobileCss);
        $this->assertSame(1, preg_match('/\\.gf-sidebar__nav\\s*\\{([^}]*)\\}/s', $mobileCss, $navigation));
        $this->assertStringContainsString('display: flex;', $navigation[1]);
        $this->assertStringContainsString('flex-wrap: nowrap;', $navigation[1]);
        $this->assertStringContainsString('overflow-x: auto;', $navigation[1]);

        $this->assertSame(1, preg_match('/\\.gf-sidebar \\.gf-navitem__text\\s*\\{([^}]*)\\}/s', $mobileCss, $labels));
        $this->assertStringContainsString('position: static;', $labels[1]);
        $this->assertStringNotContainsString('.gf-navitem:nth-of-type(n+5)', $mobileCss);
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
