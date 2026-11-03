<?php

namespace Tests\Feature;

use App\Mail\LoginOtpMailer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesAuthTables;
use Tests\TestCase;

/**
 * POST /api/auth-request-verify-otp signs in only with a code that was
 * actually issued. `users.verification` doubles as a status column
 * ('pending' / 'verified' / NULL), and the old raw-column match let
 * "otp":"verified" sign in as anyone who had ever finished a login — admins
 * included — while an omitted otp matched every NULL row.
 *
 * Client users throughout: with no agent row, TeamSyncService, the deferred
 * LR backfill and the dormancy check all return before any network call.
 */
class OtpVerifyHardeningTest extends TestCase
{
    use CreatesAuthTables;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createAuthTables();
        DB::table('roles')->insert(['id' => 3, 'name' => 'client']);

        // Nothing here should reach Leuterio Realty; fail loudly if it does.
        Http::preventStrayRequests();
    }

    private function client(?string $verification, string $email = 'client@example.com'): User
    {
        return User::forceCreate([
            'name'         => 'Test Client',
            'email'        => $email,
            'password'     => 'not-used-here',
            'role_id'      => 3,
            'verification' => $verification,
        ]);
    }

    private function verify(array $payload): TestResponse
    {
        return $this->postJson('/api/auth-request-verify-otp', $payload);
    }

    private function assertNotSignedIn(User $user): void
    {
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
        $this->assertSame(0, DB::table('user_info')->where('user_id', $user->id)->count());
    }

    public function test_the_verified_status_word_is_not_a_code(): void
    {
        $user = $this->client('verified');

        $this->verify(['email' => $user->email, 'otp' => 'verified'])
            ->assertStatus(403)
            ->assertJsonPath('message', 'Invalid one time pin.');

        $this->assertNotSignedIn($user);
        $this->assertSame('verified', $user->fresh()->verification);
    }

    public function test_the_pending_status_word_is_not_a_code(): void
    {
        $user = $this->client('pending');

        $this->verify(['email' => $user->email, 'otp' => 'pending'])->assertStatus(403);

        $this->assertNotSignedIn($user);
    }

    public function test_a_missing_or_empty_code_is_a_validation_error(): void
    {
        $user = $this->client(null);

        $this->verify(['email' => $user->email])->assertStatus(422)->assertJsonValidationErrors('otp');
        $this->verify(['email' => $user->email, 'otp' => ''])->assertStatus(422)->assertJsonValidationErrors('otp');

        $this->assertNotSignedIn($user);
    }

    public function test_a_user_without_a_pending_code_cannot_sign_in_with_a_guess(): void
    {
        $user = $this->client(null);

        $this->verify(['email' => $user->email, 'otp' => 'ABC123'])->assertStatus(403);

        $this->assertNotSignedIn($user);
    }

    public function test_the_issued_code_signs_in_exactly_once(): void
    {
        $user = $this->client('K7Q2ZP');

        $this->verify([
            'email'     => $user->email,
            'otp'       => 'K7Q2ZP',
            'user_info' => ['country' => 'Philippines', 'state' => 'Cebu', 'city' => 'Cebu City'],
        ])
            ->assertOk()
            ->assertJsonPath('user.email', $user->email)
            ->assertJsonMissingPath('user.verification')
            ->assertJsonStructure(['token']);

        $this->assertSame('verified', $user->fresh()->verification);
        $this->assertSame(1, DB::table('personal_access_tokens')->count());
        $this->assertSame('Cebu City', DB::table('user_info')->where('user_id', $user->id)->value('city'));

        // Used up: the column now holds the status word, never a code.
        $this->verify(['email' => $user->email, 'otp' => 'K7Q2ZP'])->assertStatus(403);
        $this->assertSame(1, DB::table('personal_access_tokens')->count());
    }

    public function test_the_code_ignores_case_and_surrounding_spaces(): void
    {
        $user = $this->client('K7Q2ZP');

        $this->verify(['email' => $user->email, 'otp' => ' k7q2zp '])->assertOk();
    }

    public function test_a_wrong_code_is_rejected_and_the_issued_one_still_works(): void
    {
        $user = $this->client('K7Q2ZP');

        $this->verify(['email' => $user->email, 'otp' => 'K7Q2ZQ'])->assertStatus(403);
        $this->assertNotSignedIn($user);

        $this->verify(['email' => $user->email, 'otp' => 'K7Q2ZP'])->assertOk();
    }

    public function test_a_code_only_works_for_the_email_it_was_sent_to(): void
    {
        $this->client('K7Q2ZP', 'first@example.com');
        $other = $this->client('M4N5P6', 'second@example.com');

        $this->verify(['email' => $other->email, 'otp' => 'K7Q2ZP'])->assertStatus(403);

        $this->assertSame('M4N5P6', $other->fresh()->verification);
    }

    public function test_an_unknown_email_gets_the_same_403(): void
    {
        $this->verify(['email' => 'nobody@example.com', 'otp' => 'K7Q2ZP'])
            ->assertStatus(403)
            ->assertJsonPath('message', 'Invalid one time pin.');
    }

    public function test_malformed_payloads_are_validation_errors(): void
    {
        $user = $this->client('K7Q2ZP');

        $this->verify(['email' => 'not-an-email', 'otp' => 'K7Q2ZP'])->assertStatus(422)->assertJsonValidationErrors('email');
        $this->verify(['email' => $user->email, 'otp' => ['K7Q2ZP']])->assertStatus(422)->assertJsonValidationErrors('otp');
        // A string user_info used to 500 on `$userInfo['user_id'] = …`.
        $this->verify(['email' => $user->email, 'otp' => 'K7Q2ZP', 'user_info' => 'x'])->assertStatus(422)->assertJsonValidationErrors('user_info');

        $this->assertNotSignedIn($user);
    }

    public function test_a_code_sent_by_auth_send_otp_signs_in(): void
    {
        Mail::fake();
        $user = $this->client('verified');

        $this->postJson('/api/auth-send-otp', ['email' => $user->email])->assertOk();

        $code = null;
        Mail::assertSent(LoginOtpMailer::class, function (LoginOtpMailer $mail) use (&$code) {
            $code = $mail->verification;

            return true;
        });
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{6}$/', (string) $code);

        $this->verify(['email' => $user->email, 'otp' => strtolower($code)])->assertOk();
    }
}
