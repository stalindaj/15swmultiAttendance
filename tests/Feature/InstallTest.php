<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Setup on hosting without a terminal: /install (token-guarded) and the "Update database" button. */
class InstallTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'a-long-random-setup-token';

    protected function setUp(): void
    {
        parent::setUp();
        config(['attendance.install_token' => self::TOKEN]);
    }

    public function test_setup_page_does_not_exist_without_the_token(): void
    {
        $this->get('/install')->assertNotFound();
        $this->get('/install?token=wrong')->assertNotFound();
        $this->post('/install/admin', ['token' => 'wrong', 'name' => 'X', 'username' => 'x', 'password' => 'secret123', 'password_confirmation' => 'secret123'])
            ->assertNotFound();

        config(['attendance.install_token' => '']);
        $this->get('/install?token=')->assertNotFound();
    }

    public function test_setup_page_shows_server_checks(): void
    {
        $this->get('/install?token='.self::TOKEN)
            ->assertOk()
            ->assertSee('Server checks')
            ->assertSee('Database connection')
            ->assertSee('Create records account');   // no admin yet
    }

    public function test_setup_runs_migrations(): void
    {
        $this->post('/install/migrate', ['token' => self::TOKEN])
            ->assertOk()
            ->assertSee('Database updated.')
            ->assertSee('All tables are up to date.');
    }

    public function test_setup_creates_the_first_records_account_only_once(): void
    {
        $this->post('/install/admin', ['token' => self::TOKEN, 'name' => 'Records PC', 'username' => 'admin',
            'password' => 'secret123', 'password_confirmation' => 'nope'])
            ->assertSee('The password field confirmation does not match.');
        $this->assertSame(0, User::count());

        $this->post('/install/admin', ['token' => self::TOKEN, 'name' => 'Records PC', 'username' => 'admin',
            'password' => 'secret123', 'password_confirmation' => 'secret123'])
            ->assertSee('Records account created.');
        $this->assertTrue(User::where('username', 'admin')->sole()->isAdmin());

        $this->post('/install/admin', ['token' => self::TOKEN, 'name' => 'Someone', 'username' => 'second',
            'password' => 'secret123', 'password_confirmation' => 'secret123'])
            ->assertSee('A records account already exists.');
        $this->assertSame(1, User::count());

        $this->post('/login', ['username' => 'admin', 'password' => 'secret123'])->assertRedirect('/');
    }

    public function test_records_account_can_update_the_database_from_the_dashboard(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/')->assertInertia(fn (Assert $page) => $page->where('pendingMigrations', 0));
        $this->actingAs($admin)->post('/system/update-database')->assertSessionHas('success', 'The database was already up to date.');

        $this->actingAs($this->scanner())->post('/system/update-database')->assertRedirect('/scan');
    }
}
