<?php

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The listing side of an audit / ATS notification test: listings → properties
 * → attribute → subtype → type, trimmed to the columns those paths read.
 *
 * Sits on top of CreatesAuthTables (roles, users, agents) — call that first.
 * Same approach as the other feature tests (the full migration suite is
 * MySQL-only); no foreign keys.
 */
trait CreatesListingTables
{
    private int $listingSubtypeId = 0;

    protected function createListingTables(): void
    {
        Schema::create('property_types', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->timestamps();
        });
        Schema::create('property_subtypes', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('property_type_id');
            $t->string('name');
            $t->timestamps();
        });
        Schema::create('property_attributes', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('property_subtype_id')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('properties', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('property_attribute_id')->nullable();
            $t->string('status')->default('active');
            $t->string('ats_status')->nullable();
            $t->date('ats_expiration_date')->nullable();
            $t->text('ats_remarks')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('listings', function (Blueprint $t) {
            $t->id();
            $t->string('code');
            $t->string('name');
            $t->string('slug')->nullable();
            $t->string('visibility')->default('public');
            $t->text('featured_photo')->nullable();
            $t->unsignedBigInteger('property_id');
            $t->unsignedBigInteger('agent_id');
            $t->string('verification_status')->nullable();
            $t->text('audit_notes')->nullable();
            $t->text('audit_checklist')->nullable();
            $t->text('audit_edited_fields')->nullable();
            $t->text('agent_edited_fields')->nullable();
            $t->unsignedBigInteger('audited_by')->nullable();
            $t->timestamp('audited_at')->nullable();
            $t->timestamp('re_submitted_at')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        $typeId = DB::table('property_types')->insertGetId(['name' => 'Condominium']);
        $this->listingSubtypeId = DB::table('property_subtypes')->insertGetId(['property_type_id' => $typeId, 'name' => 'Studio']);
    }

    private function roleId(string $name): int
    {
        return DB::table('roles')->where('name', $name)->value('id')
            ?? DB::table('roles')->insertGetId(['name' => $name]);
    }

    /**
     * A user with the given role (and an agents row in the given state), plus
     * one listing they own.
     *
     * @param  array<string, mixed>  $property  extra properties columns (ATS dates and so on)
     * @return array{listing: int, agent: int, user: int, email: string}
     */
    protected function seedOwnedListing(
        int $n,
        string $agentStatus = 'active',
        string $roleName = 'agent',
        bool $agentTrashed = false,
        array $property = [],
    ): array {
        $email = "agent{$n}@example.test";
        $userId = DB::table('users')->insertGetId([
            'name' => "Agent {$n}", 'email' => $email, 'password' => 'x',
            'role_id' => $this->roleId($roleName), 'last_online_at' => now(),
        ]);
        $agentId = DB::table('agents')->insertGetId([
            'user_id' => $userId, 'first_name' => 'Agent', 'last_name' => (string) $n,
            'status' => $agentStatus, 'deleted_at' => $agentTrashed ? now() : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $attributeId = DB::table('property_attributes')->insertGetId(['property_subtype_id' => $this->listingSubtypeId]);
        $propertyId = DB::table('properties')->insertGetId($property + [
            'property_attribute_id' => $attributeId, 'status' => 'active',
        ]);
        $listingId = DB::table('listings')->insertGetId([
            'code' => "FH-{$n}", 'name' => "Listing {$n}", 'slug' => "listing-{$n}",
            'property_id' => $propertyId, 'agent_id' => $agentId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['listing' => $listingId, 'agent' => $agentId, 'user' => $userId, 'email' => $email];
    }
}
