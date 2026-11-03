<?php

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The tables the sign-in paths touch, trimmed to the columns they use.
 *
 * Same approach as the other feature tests (the full migration suite is
 * MySQL-only), shared because the OTP, password-login, user-deletion,
 * dormancy and staff-account tests all walk the same users → agents →
 * tokens → login_logs → audits chain. No foreign keys, like the rest.
 */
trait CreatesAuthTables
{
    protected function createAuthTables(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('verification')->nullable();
            $table->unsignedBigInteger('role_id')->nullable();
            $table->boolean('role_locked')->default(false);
            $table->boolean('admin_emails_muted')->default(false);
            $table->timestamp('active_at')->nullable();
            $table->timestamp('last_online_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('agents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('last_name');
            $table->string('mobile_no')->nullable();
            $table->string('status')->default('active');
            $table->string('region')->nullable();
            $table->string('lr_state')->nullable();
            $table->string('lr_email')->nullable()->unique();
            $table->date('birthdate')->nullable();
            $table->timestamp('birthdate_checked_at')->nullable();
            $table->string('gender')->nullable();
            $table->date('member_since')->nullable();
            $table->timestamp('profile_updated_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('user_info', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->text('device')->nullable();
            $table->string('country')->default('Unknown');
            $table->string('state')->default('Unknown');
            $table->string('city')->default('Unknown');
            $table->timestamps();
        });

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('device_tokens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('personal_access_token_id')->nullable();
            $table->string('expo_token')->unique();
            $table->string('platform', 16)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('login_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('ip_address')->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamp('logged_in_at')->nullable();
            $table->timestamps();
        });

        Schema::create('audits', function (Blueprint $table) {
            $table->id();
            $table->string('user_type')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('event');
            $table->string('auditable_type')->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->text('old_values')->nullable();
            $table->text('new_values')->nullable();
            $table->text('url')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 1023)->nullable();
            $table->string('tags')->nullable();
            $table->string('category', 64)->nullable();
            $table->string('source', 64)->nullable();
            $table->string('client', 16)->nullable();
            $table->string('device_platform', 16)->nullable();
            $table->string('subject_label')->nullable();
            $table->string('user_role', 32)->nullable();
            $table->string('user_name')->nullable();
            $table->string('description', 500)->nullable();
            $table->timestamps();
        });
    }
}
