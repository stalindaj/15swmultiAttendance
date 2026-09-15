<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AccountsAndAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/scan')->assertRedirect('/login');
        $this->postJson('/scan/record', ['qr_text' => 'X Y, Z'])->assertUnauthorized();
    }

    public function test_phone_account_logs_in_by_username_and_lands_on_the_scanner(): void
    {
        $this->scanner();

        $this->post('/login', ['username' => 'gate1', 'password' => 'password'])->assertRedirect('/scan');
        $this->get('/scan')->assertInertia(fn (Assert $page) => $page->component('Scan')->where('auth.user.name', 'Gate 1'));
    }

    public function test_wrong_password_and_disabled_accounts_are_refused(): void
    {
        $this->scanner();
        User::factory()->create(['username' => 'gate9', 'is_active' => false]);

        $this->post('/login', ['username' => 'gate1', 'password' => 'nope'])->assertSessionHasErrors('username');
        $this->post('/login', ['username' => 'gate9', 'password' => 'password'])->assertSessionHasErrors('username');
        $this->assertGuest();
    }

    public function test_login_is_throttled_per_account(): void
    {
        $this->freezeTime();
        $this->scanner();
        foreach (range(1, 5) as $_) {
            $this->post('/login', ['username' => 'gate1', 'password' => 'nope']);
        }

        $this->post('/login', ['username' => 'gate1', 'password' => 'password'])
            ->assertSessionHasErrors(['username' => 'Too many attempts. Try again in 60 seconds.']);
        $this->assertGuest();
    }

    public function test_phone_accounts_cannot_open_records_pages(): void
    {
        $gate = $this->scanner();
        $event = Event::factory()->create();

        $this->actingAs($gate)->get('/')->assertRedirect('/scan');
        $this->actingAs($gate)->get('/accounts')->assertRedirect('/scan');
        $this->actingAs($gate)->get("/events/{$event->id}")->assertRedirect('/scan');
        $this->actingAs($gate)->get("/events/{$event->id}/export")->assertRedirect('/scan');
        $this->actingAs($gate)->post('/events', ['name' => 'Sneaky', 'event_date' => '2026-09-17'])->assertRedirect('/scan');
        $this->actingAs($gate)->getJson('/search?q=dela')->assertForbidden();
        $this->assertSame(1, Event::count());
    }

    public function test_records_pages_stay_on_the_pc_even_for_the_admin(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/')->assertOk();
        $this->actingAs($admin)->withHeader('X-Attendance-Remote', '1')->get('/')->assertRedirect('/scan');
    }

    public function test_admin_creates_phone_accounts(): void
    {
        $this->actingAs($this->admin())
            ->post('/accounts', ['name' => 'Gate 2', 'username' => 'gate2', 'password' => 'secret12', 'role' => 'scanner'])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('users', ['username' => 'gate2', 'role' => 'scanner', 'is_active' => true]);
        $this->post('/logout');
        $this->post('/login', ['username' => 'gate2', 'password' => 'secret12'])->assertRedirect('/scan');
    }

    public function test_logging_a_phone_out_ends_its_session(): void
    {
        $gate = $this->scanner();
        $this->post('/login', ['username' => 'gate1', 'password' => 'password']);
        $this->getJson('/scan/ping')->assertOk();

        $gate->logOutEverywhere();
        $this->app['auth']->forgetGuards();   // next request loads the account fresh, as a real request would

        $this->getJson('/scan/ping')->assertUnauthorized();
        $this->assertGuest();
    }

    public function test_disabling_an_account_logs_the_phone_out(): void
    {
        $admin = $this->admin();
        $gate = $this->scanner();

        $this->actingAs($admin)->post("/accounts/{$gate->id}/active")->assertSessionHas('success');
        $this->assertFalse($gate->fresh()->is_active);

        $this->actingAs($admin)->post("/accounts/{$admin->id}/active")->assertSessionHas('error');
        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_password_reset_logs_the_phone_out(): void
    {
        $gate = $this->scanner();
        $before = $gate->session_version;

        $this->actingAs($this->admin())->post("/accounts/{$gate->id}/password", ['password' => 'newpass1']);

        $this->assertGreaterThan($before, $gate->fresh()->session_version);
        $this->post('/logout');
        $this->post('/login', ['username' => 'gate1', 'password' => 'newpass1'])->assertRedirect('/scan');
    }
}
