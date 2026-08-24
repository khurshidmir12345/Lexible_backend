<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Word;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    protected Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Admin::create([
            'name' => 'Test Admin',
            'email' => 'admin@lexible.test',
            'password' => 'secret123',
            'role' => 'admin',
            'is_active' => true,
        ]);
    }

    public function test_panel_is_behind_a_login(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_dashboard_renders_for_an_admin(): void
    {
        $this->actingAs($this->admin, 'admin')->get('/admin')->assertSuccessful();
    }

    public function test_word_list_renders_with_review_tabs(): void
    {
        Word::create([
            'word' => 'beautiful',
            'part_of_speech' => 'adjective',
            'transcription' => '/ˈbjuːtɪfəl/',
            'translations' => ['uz' => ['chiroyli'], 'ru' => ['красивый']],
            'emoji' => '🌸',
        ]);

        $this->actingAs($this->admin, 'admin')
            ->get('/admin/words')
            ->assertSuccessful()
            ->assertSee('beautiful')
            ->assertSee('Tekshirish navbati');
    }

    public function test_player_list_renders(): void
    {
        \App\Models\User::create([
            'telegram_id' => 111222333,
            'first_name' => 'Dilnoza',
            'username' => 'dilnoza',
            'native_lang' => 'uz',
            'streak_days' => 12,
        ]);

        $this->actingAs($this->admin, 'admin')
            ->get('/admin/users')
            ->assertSuccessful()
            ->assertSee('Dilnoza')
            ->assertSee('12 kun');
    }

    public function test_dashboard_shows_the_overview_stats(): void
    {
        \App\Models\Word::create(['word' => 'apple', 'translations' => ['uz' => ['olma']]]);

        // Filament renders widgets as lazy Livewire components, so the numbers
        // are not in the first response — assert the widgets are mounted.
        $this->actingAs($this->admin, 'admin')
            ->get('/admin')
            ->assertSuccessful()
            ->assertSeeLivewire(\App\Filament\Widgets\OverviewStats::class)
            ->assertSeeLivewire(\App\Filament\Widgets\ActivityChart::class);
    }

    public function test_word_edit_form_renders(): void
    {
        $word = Word::create(['word' => 'book', 'translations' => ['uz' => ['kitob']]]);

        $this->actingAs($this->admin, 'admin')
            ->get("/admin/words/{$word->id}/edit")
            ->assertSuccessful()
            ->assertSee('Tarjimalar');
    }
}
