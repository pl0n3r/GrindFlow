<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class LaravelFoundationTest extends TestCase
{
    public function test_health_route_responds(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_home_route_renders_grindflow(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('GrindFlow');
    }

    public function test_livewire_is_installed(): void
    {
        $this->assertTrue(class_exists(Livewire::class));
    }

    #[Group('database')]
    public function test_database_connection_is_available(): void
    {
        $result = DB::selectOne('select 1 as value');

        $this->assertNotNull($result);
        $this->assertSame(1, (int) $result->value);
    }
}
