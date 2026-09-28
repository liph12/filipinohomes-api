<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Natcon\Models\NatconEvent;
use App\Natcon\Models\RaffleWinner;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * /api/admin/natcon/raffle/* — the live raffle's winners.
 *
 * The rules worth a test: a draw is recorded ONCE however many times the
 * stage retries it (the client key), a convention's winners are its own
 * (2025's stay visible from 2026's admin, and never mix), clearing empties
 * one convention only.
 *
 * Builds its own minimal tables — the full migration suite is MySQL-only.
 */
class NatconRaffleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->foreignId('role_id')->nullable();
            $table->timestamps();
        });
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
        // The auth stack looks a user's agent row up on every request.
        Schema::create('agents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id');
            $table->string('status')->default('active');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('natcon_events', function (Blueprint $table) {
            $table->id();
            $table->integer('year');
            $table->string('name')->nullable();
            $table->string('short_name')->nullable();
            $table->date('starts_on')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });
        Schema::create('natcon_raffle_winners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('natcon_event_id');
            $table->string('client_key', 64);
            $table->string('raffle', 120);
            $table->string('name');
            $table->string('contact')->nullable();
            $table->string('prize')->nullable();
            $table->timestamp('drawn_at');
            $table->timestamps();
            $table->softDeletes();
            $table->foreignId('deleted_by')->nullable();
            $table->unique(['natcon_event_id', 'client_key']);
            $table->unique(['natcon_event_id', 'raffle', 'name']);
        });

        $roleId = Role::forceCreate(['name' => 'admin'])->id;
        Sanctum::actingAs(User::forceCreate([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => 'secret',
            'role_id' => $roleId,
        ]));
    }

    private function event(int $year, bool $active = false): NatconEvent
    {
        return NatconEvent::create([
            'year' => $year,
            'name' => "National Real Estate Convention {$year}",
            'short_name' => "NATCON {$year}",
            'starts_on' => "{$year}-10-18",
            'is_active' => $active,
        ]);
    }

    private function draw(NatconEvent $event, string $key, string $name, string $prize, string $raffle = 'Main Raffle'): array
    {
        return $this->postJson('/api/admin/natcon/raffle/winners', [
            'event_id' => $event->id,
            'client_key' => $key,
            'raffle' => $raffle,
            'name' => $name,
            'contact' => '0917 000 0000',
            'prize' => $prize,
            'drawn_at' => '2026-10-18T12:00:00+08:00',
        ])->json('data');
    }

    public function test_a_retried_draw_is_recorded_once(): void
    {
        $event = $this->event(2026, active: true);

        $this->draw($event, 'k1', 'Ana Cruz', 'iPhone 16');
        $this->draw($event, 'k1', 'Ana Cruz', 'iPhone 16');

        $rows = $this->getJson("/api/admin/natcon/raffle/winners?event_id={$event->id}")->assertOk()->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame('Ana Cruz', $rows[0]['name']);
        $this->assertSame('iPhone 16', $rows[0]['prize']);
        $this->assertSame('Main Raffle', $rows[0]['raffle']);
    }

    public function test_each_convention_keeps_its_own_winners_and_the_year_list_says_which_have_any(): void
    {
        $last = $this->event(2025);
        $this->event(2026, active: true);

        $this->draw($last, 'a', 'Ben Uy', 'TV');
        $this->draw($last, 'b', 'Cel Tan', 'Bike');

        // 2026's admin, looking back: 2025's two winners, and nothing of its own yet.
        $this->assertCount(2, $this->getJson("/api/admin/natcon/raffle/winners?event_id={$last->id}")->json('data'));
        $this->assertCount(0, $this->getJson('/api/admin/natcon/raffle/winners')->json('data'));

        $years = collect($this->getJson('/api/admin/natcon/raffle/years')->assertOk()->json('data'))->keyBy('year');
        $this->assertTrue($years->has(2025));
        $this->assertSame(2, $years[2025]['winners']);
        $this->assertFalse($years->has(2026), 'A year with no draws is not offered.');
    }

    public function test_clearing_empties_that_convention_only(): void
    {
        $last = $this->event(2025);
        $event = $this->event(2026, active: true);
        $this->draw($last, 'a', 'Ben Uy', 'TV');
        $this->draw($event, 'r1', 'Ana Cruz', 'iPhone 16');

        $this->postJson('/api/admin/natcon/raffle/winners/clear', ['event_id' => $event->id])->assertOk();

        $this->assertCount(0, $this->getJson("/api/admin/natcon/raffle/winners?event_id={$event->id}")->json('data'));
        $this->assertCount(1, $this->getJson("/api/admin/natcon/raffle/winners?event_id={$last->id}")->json('data'));
    }

    public function test_a_winner_can_be_removed(): void
    {
        $event = $this->event(2026, active: true);
        $row = $this->draw($event, 'r1', 'Ana Cruz', 'iPhone 16');

        $this->deleteJson("/api/admin/natcon/raffle/winners/{$row['id']}")->assertOk();

        $this->assertCount(0, $this->getJson("/api/admin/natcon/raffle/winners?event_id={$event->id}")->json('data'));
    }

    public function test_a_person_wins_once_per_raffle_but_may_win_in_another(): void
    {
        $event = $this->event(2026, active: true);
        $this->draw($event, 'k1', 'Ana Cruz', 'iPhone 16');

        $this->postJson('/api/admin/natcon/raffle/winners', [
            'event_id' => $event->id, 'client_key' => 'k2', 'raffle' => 'Main Raffle', 'name' => 'Ana Cruz', 'prize' => 'TV', 'drawn_at' => '2026-10-18T12:05:00+08:00',
        ])->assertStatus(422);

        $this->draw($event, 'k3', 'Ana Cruz', 'Hotel stay', 'Sponsor Night Raffle');
        $this->assertCount(2, $this->getJson("/api/admin/natcon/raffle/winners?event_id={$event->id}")->json('data'));
    }

    public function test_a_removed_winner_is_hidden_not_gone_and_may_be_drawn_again(): void
    {
        $event = $this->event(2026, active: true);
        $row = $this->draw($event, 'k1', 'Ana Cruz', 'iPhone 16');

        $this->deleteJson("/api/admin/natcon/raffle/winners/{$row['id']}")->assertOk();
        $this->assertCount(0, $this->getJson("/api/admin/natcon/raffle/winners?event_id={$event->id}")->json('data'));
        $hidden = RaffleWinner::withTrashed()->find($row['id']);
        $this->assertNotNull($hidden, 'The row is hidden, not deleted.');
        $this->assertSame(auth()->id(), $hidden->deleted_by, 'It says who removed it.');

        // Drawn again in the same raffle: the hidden row comes back rather than a unique-index error.
        $again = $this->draw($event, 'k2', 'Ana Cruz', 'Hotel stay');
        $this->assertSame($row['id'], $again['id']);
        $this->assertNull(RaffleWinner::find($row['id'])->deleted_by, 'Restored: nobody has it removed any more.');
        $rows = $this->getJson("/api/admin/natcon/raffle/winners?event_id={$event->id}")->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame('Hotel stay', $rows[0]['prize']);
    }

    public function test_the_raffle_title_is_required(): void
    {
        $event = $this->event(2026, active: true);

        $this->postJson('/api/admin/natcon/raffle/winners', [
            'event_id' => $event->id, 'client_key' => 'k1', 'name' => 'Ana Cruz', 'prize' => 'TV', 'drawn_at' => '2026-10-18T12:00:00+08:00',
        ])->assertStatus(422);
    }

    public function test_a_non_admin_is_refused(): void
    {
        $roleId = Role::forceCreate(['name' => 'client'])->id;
        Sanctum::actingAs(User::forceCreate(['name' => 'Client', 'email' => 'client@example.com', 'password' => 'x', 'role_id' => $roleId]));
        $this->event(2026, active: true);

        $this->getJson('/api/admin/natcon/raffle/winners')->assertForbidden();
    }
}
