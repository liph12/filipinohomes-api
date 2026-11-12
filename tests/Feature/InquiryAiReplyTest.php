<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Setting;
use App\Models\User;
use App\Services\ExpoPushService;
use App\Services\Inquiry\InquiryAiReplyService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\Concerns\CreatesAuthTables;
use Tests\TestCase;

/**
 * Inquiry Filipinohomes Assistant (`inquiries:ai-reply`): the "agent will confirm" reply
 * right after approval and the apology + office numbers when the agent still
 * hasn't answered. All messages are fixed text — the rules under test are
 * WHEN and WHETHER a message goes out, and what it says.
 */
class InquiryAiReplyTest extends TestCase
{
    use CreatesAuthTables;

    private User $client;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createAuthTables();
        DB::table('roles')->insert([
            ['id' => 1, 'name' => 'admin'],
            ['id' => 2, 'name' => 'agent'],
            ['id' => 3, 'name' => 'client'],
        ]);
        Schema::create('settings', function (Blueprint $t) {
            $t->id();
            $t->string('key')->unique();
            $t->longText('value')->nullable();
            $t->timestamps();
        });
        Schema::create('listings', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('code')->nullable();
            $t->decimal('price', 20, 2)->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('chats', function (Blueprint $t) {
            $t->id();
            $t->string('type');
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('type_id');
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('conversations', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('chat_id');
            $t->string('status')->default('pending');
            $t->unsignedBigInteger('agent_user_id')->nullable();
            $t->unsignedBigInteger('reviewed_by')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->unsignedBigInteger('closed_by')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('conversation_users', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('conversation_id');
            $t->timestamp('last_read_at')->nullable();
            $t->timestamp('archived_at')->nullable();
            $t->timestamp('removed_at')->nullable();
            $t->timestamp('purged_at')->nullable();
            $t->timestamps();
        });
        Schema::create('messages', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('conversation_id');
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('reply_to_id')->nullable();
            $t->string('type')->default('text');
            $t->text('body')->nullable();
            $t->json('attachments')->nullable();
            $t->timestamp('read_at')->nullable();
            $t->string('status')->default('active');
            $t->timestamps();
        });
        (require base_path('database/migrations/2026_11_11_000002_create_inquiry_ai_replies.php'))->up();

        Schema::table('users', function (Blueprint $t) {
            $t->string('mobile_no')->nullable();
            $t->string('avatar')->nullable();
        });

        Mail::fake();

        $this->client = User::forceCreate(['name' => 'Maria Santos', 'email' => 'maria@example.com', 'mobile_no' => '09171234567', 'password' => 'x', 'role_id' => 3]);
        $this->agent = User::forceCreate(['name' => 'Juan Dela Cruz', 'email' => 'juan@example.com', 'password' => 'x', 'role_id' => 2]);
        Schema::table('agents', fn (Blueprint $t) => $t->string('whats_app_no')->nullable());
        DB::table('agents')->insert(['user_id' => $this->agent->id, 'first_name' => 'JUAN MIGUEL', 'last_name' => 'Dela Cruz', 'mobile_no' => '0918 555 0101', 'whats_app_no' => '+63 918 555 0101']);
        DB::table('listings')->insert(['id' => 7, 'name' => '3BR House in Talisay', 'code' => 'CEB-0007', 'price' => 25000]);

        // Monday 10:00 Manila — inside default office hours.
        Carbon::setTestNow(Carbon::parse('2026-11-09 10:00', 'Asia/Manila'));

        // The listing owner is an active agent unless a test says otherwise.
        DB::table('login_logs')->insert(['user_id' => $this->agent->id, 'logged_in_at' => now()->subDays(2), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function makeAgentInactive(?int $lastLoginDaysAgo = null, ?int $lastActiveDaysAgo = null): void
    {
        DB::table('login_logs')->where('user_id', $this->agent->id)->delete();
        if ($lastLoginDaysAgo !== null) {
            DB::table('login_logs')->insert(['user_id' => $this->agent->id, 'logged_in_at' => now()->subDays($lastLoginDaysAgo), 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->agent->forceFill(['last_online_at' => $lastActiveDaysAgo !== null ? now()->subDays($lastActiveDaysAgo) : null])->save();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();
        parent::tearDown();
    }

    private function enable(array $overrides = []): void
    {
        Setting::set(InquiryAiReplyService::SETTINGS_KEY, json_encode([
            'enabled' => true,
            'enabled_since' => Carbon::now()->subDays(7)->toIso8601String(),
            ...$overrides,
        ]));
    }

    /** An accepted listing inquiry approved $minutesAgo, with the client's opening message. */
    private function inquiry(int $minutesAgo, string $message = "I'm interested in this property..."): int
    {
        $chatId = DB::table('chats')->insertGetId(['type' => 'listing', 'user_id' => $this->client->id, 'type_id' => 7, 'created_at' => now(), 'updated_at' => now()]);
        $conversationId = DB::table('conversations')->insertGetId([
            'chat_id' => $chatId,
            'status' => 'accepted',
            'agent_user_id' => $this->agent->id,
            'reviewed_at' => now()->subMinutes($minutesAgo),
            'created_at' => now()->subMinutes($minutesAgo + 5),
            'updated_at' => now(),
        ]);
        DB::table('messages')->insert([
            'conversation_id' => $conversationId,
            'user_id' => $this->client->id,
            'body' => $message,
            'created_at' => now()->subMinutes($minutesAgo + 5),
            'updated_at' => now(),
        ]);

        return $conversationId;
    }

    private function run_(): array
    {
        return app(InquiryAiReplyService::class)->run();
    }

    private function automated(int $conversationId)
    {
        return Message::where('conversation_id', $conversationId)->where('automated', true)->orderBy('id')->get();
    }

    public function test_nothing_is_sent_while_switched_off(): void
    {
        $id = $this->inquiry(2);

        expect($this->run_()['reason'])->toBe('disabled');
        expect($this->automated($id))->toHaveCount(0);
    }

    public function test_replies_right_after_approval_when_the_agent_has_not(): void
    {
        $this->enable();
        $id = $this->inquiry(2);

        $out = $this->run_();

        expect($out['acks'])->toBe(1);
        $reply = $this->automated($id)->first();
        expect($reply->user->name)->toBe('Filipinohomes Assistant')
            ->and($reply->body)->toContain('Hi Maria!')
            ->and($reply->body)->toContain('3BR House in Talisay')
            ->and($reply->body)->toContain('Juan Miguel has been notified and will get in touch with you to assist you further.')
            ->and($reply->body)->toContain("You can also reach Juan Miguel directly:\n📞 0918 555 0101\n💬 +63 918 555 0101")
            ->and($reply->body)->toContain("you further.\n\n🏠 Property code: CEB-0007\n🔖 Inquiry number: {$reply->conversation->chat_id}\n\nYou can also reach")
            ->and($reply->body)->toContain('To make sure we can reach you, may we kindly confirm that your contact details are correct?')
            ->and($reply->body)->not->toContain('If anything has changed')
            ->and($reply->body)->toContain('📱 09171234567')
            ->and($reply->body)->toContain('✉️ maria@example.com')
            ->and($reply->body)->not->toContain('viewing')
            ->and($reply->body)->not->toContain('Pag-IBIG')
            ->and($reply->body)->toEndWith("\n\n— Filipinohomes Assistant");
        // The assistant never joins the thread, so it gets no notifications
        // and doesn't count as a participant.
        expect(DB::table('conversation_users')->where('user_id', $reply->user_id)->exists())->toBeFalse();
    }

    public function test_does_not_repeat_or_answer_what_the_client_asked(): void
    {
        $this->enable();
        $id = $this->inquiry(2, 'Is Pag-IBIG financing accepted? How much?');

        $this->run_();

        $body = $this->automated($id)->first()->body;
        expect($body)->not->toContain('Pag-IBIG')
            ->and($body)->not->toContain('asked')
            ->and($body)->not->toContain('price');
    }

    public function test_lists_mobile_and_whatsapp_and_skips_the_agent_block_when_there_is_none(): void
    {
        $svc = app(InquiryAiReplyService::class);

        expect($svc->agentBlock(['agent' => 'Juan', 'agent_mobile' => '0918 555 0101', 'agent_whatsapp' => '0999 111 2222']))
            ->toBe("You can also reach Juan directly:\n📞 0918 555 0101\n💬 0999 111 2222")
            ->and($svc->agentBlock(['agent' => 'Juan', 'agent_mobile' => null, 'agent_whatsapp' => null]))->toBe('');
    }

    public function test_asks_for_a_mobile_number_when_none_is_on_file(): void
    {
        $this->client->forceFill(['mobile_no' => null])->save();
        $this->enable();
        $id = $this->inquiry(2);

        $this->run_();

        expect($this->automated($id)->first()->body)->toContain('📱 No mobile number yet — please share it here');
    }

    public function test_does_not_reply_once_the_agent_has_answered(): void
    {
        $this->enable();
        $id = $this->inquiry(2);
        DB::table('messages')->insert(['conversation_id' => $id, 'user_id' => $this->agent->id, 'body' => 'Hi! Yes it is available.', 'created_at' => now(), 'updated_at' => now()]);

        $this->run_();

        expect($this->automated($id))->toHaveCount(0);
    }

    public function test_each_message_is_sent_only_once(): void
    {
        $this->enable();
        $id = $this->inquiry(2);

        $this->run_();
        $this->run_();

        expect($this->automated($id))->toHaveCount(1);
    }

    public function test_never_reaches_back_into_inquiries_approved_before_it_was_switched_on(): void
    {
        $id = $this->inquiry(240);
        $this->enable(['enabled_since' => Carbon::now()->subMinutes(10)->toIso8601String()]);

        $this->run_();

        expect($this->automated($id))->toHaveCount(0);
    }

    public function test_an_ack_is_not_sent_for_an_inquiry_approved_hours_ago(): void
    {
        $this->enable(['escalation_enabled' => false]);
        $id = $this->inquiry(150);

        $this->run_();

        expect($this->automated($id))->toHaveCount(0);
    }

    public function test_gives_the_office_numbers_when_the_agent_still_has_not_replied_after_3_hours(): void
    {
        $push = Mockery::mock(ExpoPushService::class);
        $push->shouldReceive('notify')->once()->withArgs(fn ($user, $type) => $user->id === $this->agent->id && $type === 'inquiry');
        $this->app->instance(ExpoPushService::class, $push);

        $this->enable();
        $id = $this->inquiry(185);

        $out = $this->run_();

        expect($out['escalations'])->toBe(1);
        $body = $this->automated($id)->last()->body;
        expect($body)->toStartWith("Hi Maria, we're sorry for the wait. Juan Miguel hasn't been able to reply to your inquiry about\n3BR House in Talisay\nProperty code: CEB-0007\n\nFor faster assistance")
            ->and($body)->not->toContain('(032) 254-8900')
            ->and($body)->toContain("📱 (+63) 977-815-0888\n")
            ->and($body)->not->toContain('call or text')
            ->and($body)->toContain('Office hours: Mon–Sat, 8:00 AM–5:00 PM')
            ->and($body)->toContain('When you call, just give your name, some property details and our team will look up your inquiry.')
            ->and($body)->not->toContain('Please mention property code');
    }

    public function test_gives_the_admin_number_in_the_first_reply_when_the_agent_never_logged_in(): void
    {
        $push = Mockery::mock(ExpoPushService::class);
        $push->shouldReceive('notify')->once()->withArgs(fn ($user, $type, $title, $body) => str_contains($body, "haven't been active recently"));
        $this->app->instance(ExpoPushService::class, $push);
        $this->makeAgentInactive();
        $this->enable();
        $id = $this->inquiry(2);

        $out = $this->run_();

        expect($out['escalations'])->toBe(1)->and($out['acks'])->toBe(0);
        $msgs = $this->automated($id);
        expect($msgs)->toHaveCount(1);
        expect($msgs->first()->body)
            ->toStartWith("Hi Maria! Thank you for your inquiry about\n3BR House in Talisay\nProperty code: CEB-0007\n\nTo make sure you are assisted right away, please contact our office directly:\n📱 (+63) 977-815-0888\nOffice hours:")
            ->toContain('📱 09171234567')
            ->not->toContain('Juan Miguel')
            ->not->toContain("we're sorry");
        $rows = DB::table('inquiry_ai_replies')->where('conversation_id', $id)->pluck('source', 'stage');
        expect($rows['ack'])->toBe('inactive_agent')->and($rows['escalation'])->toBe('inactive_agent');

        // Nothing more: no 3-hour message, no ack, no thank-you on a client reply.
        Carbon::setTestNow(now()->addHours(4));
        $this->run_();
        expect(app(InquiryAiReplyService::class)->followupNow($id))->toBe('skipped');
        expect($this->automated($id))->toHaveCount(1);
    }

    public function test_an_agent_without_login_or_activity_for_15_days_counts_as_inactive(): void
    {
        $svc = app(InquiryAiReplyService::class);
        $this->enable();
        $id = $this->inquiry(2);
        $c = Conversation::find($id);
        $s = $svc->settings();

        $this->makeAgentInactive(lastLoginDaysAgo: 16);
        expect($svc->agentInactive($c, $s))->toBeTrue();

        $this->makeAgentInactive(lastLoginDaysAgo: 14);
        expect($svc->agentInactive($c, $s))->toBeFalse();

        // Logged in long ago but still uses the app (logins never expire): active.
        $this->makeAgentInactive(lastLoginDaysAgo: 90, lastActiveDaysAgo: 1);
        expect($svc->agentInactive($c, $s))->toBeFalse();

        // Never logged in, never active.
        $this->makeAgentInactive();
        expect($svc->agentInactive($c, $s))->toBeTrue();
    }

    public function test_inactive_agent_option_off_sends_the_normal_first_reply(): void
    {
        $this->makeAgentInactive();
        $this->enable(['inactive_agent_enabled' => false]);
        $id = $this->inquiry(2);

        expect($this->run_()['acks'])->toBe(1);
        expect($this->automated($id)->first()->body)->toContain('Juan Miguel has been notified');
    }

    public function test_inactive_agent_on_approval_gives_the_admin_number_right_away(): void
    {
        $this->makeAgentInactive(lastLoginDaysAgo: 30);
        $this->enable();
        $id = $this->inquiry(0);

        expect(app(InquiryAiReplyService::class)->ackNow($id))->toBe('sent');
        expect($this->automated($id)->first()->body)->toContain('please contact our office directly');
    }

    public function test_shows_the_office_whatsapp_when_set(): void
    {
        $this->app->instance(ExpoPushService::class, Mockery::mock(ExpoPushService::class)->shouldIgnoreMissing());
        $this->enable(['contact_whatsapp' => '0977 815 0888']);
        $id = $this->inquiry(185);

        $this->run_();

        expect($this->automated($id)->last()->body)->toContain("📱 (+63) 977-815-0888\n💬 0977 815 0888\nOffice hours:");
    }

    public function test_holds_the_office_numbers_until_office_hours(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-09 22:00', 'Asia/Manila'));
        $this->enable();
        $id = $this->inquiry(200);

        $this->run_();
        expect($this->automated($id)->where('body', 'like', '%sorry for the wait%'))->toHaveCount(0);

        // Next morning at 8:05 it goes out.
        $this->app->instance(ExpoPushService::class, Mockery::mock(ExpoPushService::class)->shouldIgnoreMissing());
        Carbon::setTestNow(Carbon::parse('2026-11-10 08:05', 'Asia/Manila'));
        $this->run_();
        expect($this->automated($id)->filter(fn ($m) => str_contains($m->body, 'sorry for the wait')))->toHaveCount(1);
    }

    public function test_the_daily_cap_stops_further_messages(): void
    {
        $this->enable(['daily_cap' => 1]);
        $a = $this->inquiry(2);
        $b = $this->inquiry(3);

        $this->run_();

        expect($this->automated($a)->count() + $this->automated($b)->count())->toBe(1);
    }

    public function test_replies_the_moment_the_inquiry_is_approved(): void
    {
        $this->enable();
        $id = $this->inquiry(0);

        app(InquiryAiReplyService::class)->replySoon($id);
        expect($this->automated($id))->toHaveCount(0); // not before the response is sent…

        $this->app->terminate(); // …but right after it
        expect($this->automated($id))->toHaveCount(1);

        // The 5-minute pass afterwards doesn't send it a second time.
        $this->run_();
        expect($this->automated($id))->toHaveCount(1);
    }

    public function test_approval_trigger_does_nothing_while_switched_off(): void
    {
        $id = $this->inquiry(0);

        app(InquiryAiReplyService::class)->replySoon($id);
        $this->app->terminate();

        expect($this->automated($id))->toHaveCount(0);
    }

    private function clientWrites(int $conversationId, string $body = '.'): void
    {
        $message = Message::create(['conversation_id' => $conversationId, 'user_id' => $this->client->id, 'body' => $body, 'type' => 'text']);
        app(InquiryAiReplyService::class)->clientReplied($message, Conversation::find($conversationId));
        $this->app->terminate();
    }

    public function test_thanks_the_client_once_when_they_answer_the_first_reply(): void
    {
        $this->enable();
        $id = $this->inquiry(2);
        $this->run_(); // first reply

        $this->clientWrites($id, 'Yes, correct po.');
        $this->clientWrites($id, 'Thanks!');

        $followups = $this->automated($id)->filter(fn ($m) => str_contains($m->body, 'Thank you for your response'));
        expect($followups)->toHaveCount(1)
            ->and($followups->first()->body)->toContain('Thank you for your response, Maria! Juan Miguel will get back to you as soon as possible.')
            ->and($followups->first()->body)->toContain("as soon as possible.\nIf you haven't heard from Juan Miguel within 3 hours, we will forward your inquiry to our office team");
    }

    public function test_no_follow_up_once_the_agent_has_replied(): void
    {
        $this->enable();
        $id = $this->inquiry(2);
        $this->run_();
        DB::table('messages')->insert(['conversation_id' => $id, 'user_id' => $this->agent->id, 'body' => 'Hello!', 'created_at' => now(), 'updated_at' => now()]);

        $this->clientWrites($id);

        expect($this->automated($id))->toHaveCount(1); // just the first reply
    }

    public function test_no_follow_up_without_a_first_reply_or_after_the_office_number(): void
    {
        $this->app->instance(ExpoPushService::class, Mockery::mock(ExpoPushService::class)->shouldIgnoreMissing());
        $this->enable();

        // No first reply was sent (the client wrote before it went out).
        $a = $this->inquiry(2);
        $this->clientWrites($a);
        expect($this->automated($a))->toHaveCount(0);

        // The office number was already given.
        $b = $this->inquiry(185);
        DB::table('inquiry_ai_replies')->insert(['conversation_id' => $b, 'stage' => 'ack', 'status' => 'sent', 'created_at' => now(), 'updated_at' => now()]);
        $this->run_(); // escalation
        $this->clientWrites($b);
        expect($this->automated($b)->filter(fn ($m) => str_contains($m->body, 'Thank you for your response')))->toHaveCount(0);
    }

    public function test_recognises_the_default_inquiry_text(): void
    {
        $svc = app(InquiryAiReplyService::class);

        expect($svc->isDefaultInquiry("I'm interested in this property..."))->toBeTrue()
            ->and($svc->isDefaultInquiry('  i’m interested in this property… '))->toBeTrue()
            ->and($svc->isDefaultInquiry("I'm interested in this property... Pag-IBIG ok?"))->toBeFalse();
    }

    public function test_unanswered_list_shows_only_inquiries_no_person_replied_to_and_filters_office_number(): void
    {
        Schema::create('team_agents', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('team_id')->nullable();
            $t->unsignedBigInteger('agent_id');
            $t->boolean('is_leader')->default(false);
            $t->string('status')->default('active');
        });
        Schema::create('teams', function (Blueprint $t) {
            $t->id();
            $t->string('name');
        });
        DB::table('teams')->insert(['id' => 1, 'name' => 'Team Talisay']);
        // The listing owner leads a team → shown as "team_leader".
        DB::table('team_agents')->insert(['team_id' => 1, 'agent_id' => DB::table('agents')->where('user_id', $this->agent->id)->value('id'), 'is_leader' => true]);
        foreach (['categories', 'property_attributes', 'property_subtypes', 'property_types'] as $t) {
            Schema::create($t, function (Blueprint $b) use ($t) {
                $b->id();
                $b->string('name')->nullable();
                if ($t === 'property_attributes') {
                    $b->unsignedBigInteger('property_subtype_id')->nullable();
                }
                if ($t === 'property_subtypes') {
                    $b->unsignedBigInteger('property_type_id')->nullable();
                }
            });
        }
        foreach (['properties', 'barangays', 'cities', 'provinces'] as $t) {
            Schema::create($t, function (Blueprint $b) use ($t) {
                $b->id();
                $b->string('name')->nullable();
                if ($t === 'properties') {
                    $b->unsignedBigInteger('address_id')->nullable();
                    $b->string('address')->nullable();
                    $b->text('photos')->nullable();
                    $b->unsignedBigInteger('property_attribute_id')->nullable();
                }
                if ($t === 'barangays') {
                    $b->unsignedBigInteger('city_id')->nullable();
                }
                if ($t === 'cities') {
                    $b->unsignedBigInteger('province_id')->nullable();
                }
            });
        }
        Schema::table('listings', function (Blueprint $t) {
            $t->unsignedBigInteger('property_id')->nullable();
            $t->text('featured_photo')->nullable();
            $t->unsignedBigInteger('category_id')->nullable();
        });
        $this->app->instance(ExpoPushService::class, Mockery::mock(ExpoPushService::class)->shouldIgnoreMissing());
        $this->enable();

        $waiting = $this->inquiry(2);                 // only the assistant has written
        $this->run_();
        $escalated = $this->inquiry(185);             // got the office number
        $this->run_();
        $answered = $this->inquiry(10);               // the agent replied
        DB::table('messages')->insert(['conversation_id' => $answered, 'user_id' => $this->agent->id, 'body' => 'Hi!', 'created_at' => now(), 'updated_at' => now()]);

        $admin = User::forceCreate(['name' => 'Admin', 'email' => 'admin2@example.com', 'password' => 'x', 'role_id' => 1]);
        Sanctum::actingAs($admin);

        $all = $this->getJson('/api/admin/inquiries/unanswered')->assertOk();
        expect(collect($all->json('data'))->pluck('conversation_id')->sort()->values()->all())->toBe([$waiting, $escalated])
            ->and($all->json('counts'))->toBe(['all' => 2, 'office_number' => 1, 'waiting' => 1, 'inactive_agent' => 0]);

        $office = $this->getJson('/api/admin/inquiries/unanswered?filter=office_number')->assertOk();
        expect(collect($office->json('data'))->pluck('conversation_id')->all())->toBe([$escalated])
            ->and($office->json('data.0.office_number_at'))->not->toBeNull()
            ->and($office->json('data.0.listing.code'))->toBe('CEB-0007')
            ->and($office->json('data.0.listing.price'))->toEqual(25000)
            ->and($office->json('data.0.client.role'))->toBe('client')
            ->and($office->json('data.0.agent.role'))->toBe('team_leader')
            ->and($office->json('data.0.team.name'))->toBe('Team Talisay')
            ->and($office->json('data.0.team.leader.name'))->toBe('JUAN MIGUEL Dela Cruz') // first + last, no middle; the page title-cases it
            ->and($office->json('data.0.team.leader.mobile'))->toBe('0918 555 0101');

        // Search by inquiry number (last number of the chat link), with or without "#".
        $chatNo = DB::table('conversations')->where('id', $waiting)->value('chat_id');
        foreach (["{$chatNo}", "#{$chatNo}"] as $q) {
            $found = $this->getJson('/api/admin/inquiries/unanswered?q='.urlencode($q))->assertOk();
            expect(collect($found->json('data'))->pluck('conversation_id')->all())->toBe([$waiting]);
        }

        // Listing owner with no login / activity for 15+ days.
        $this->makeAgentInactive(lastLoginDaysAgo: 20);
        $inactive = $this->getJson('/api/admin/inquiries/unanswered?filter=inactive_agent')->assertOk();
        expect($inactive->json('counts.inactive_agent'))->toBe(2)
            ->and(count($inactive->json('data')))->toBe(2);

        Sanctum::actingAs($this->agent);
        $this->getJson('/api/admin/inquiries/unanswered')->assertForbidden();
    }

    public function test_settings_are_admin_only_and_switching_on_records_the_start(): void
    {
        Sanctum::actingAs($this->agent);
        $this->getJson('/api/admin/inquiries/ai-reply')->assertForbidden();

        $admin = User::forceCreate(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'x', 'role_id' => 1]);
        Sanctum::actingAs($admin);
        $payload = [...InquiryAiReplyService::DEFAULTS, 'enabled' => true];
        unset($payload['enabled_since']);

        $res = $this->putJson('/api/admin/inquiries/ai-reply', $payload)->assertOk();

        expect($res->json('settings.enabled'))->toBeTrue()
            ->and($res->json('settings.enabled_since'))->not->toBeNull()
            ->and($res->json('settings.contact_mobile'))->toBe('(+63) 977-815-0888')
            ->and($res->json('settings'))->not->toHaveKey('contact_phone');
    }
}
