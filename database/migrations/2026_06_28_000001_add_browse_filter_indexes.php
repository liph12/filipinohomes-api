<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Speed up the public /properties browse filter (Listing::scopeFilter) by
 * indexing the numeric filter columns so those predicates are index-backed:
 * price (listings) and bedroom_count / bathroom_count (property_attributes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('property_attributes', function (Blueprint $table) {
            $table->index('bedroom_count', 'property_attributes_bedroom_count_idx');
            $table->index('bathroom_count', 'property_attributes_bathroom_count_idx');
        });

        Schema::table('listings', function (Blueprint $table) {
            $table->index('price', 'listings_price_idx');
        });
    }

    public function down(): void
    {
        Schema::table('property_attributes', function (Blueprint $table) {
            $table->dropIndex('property_attributes_bedroom_count_idx');
            $table->dropIndex('property_attributes_bathroom_count_idx');
        });

        Schema::table('listings', function (Blueprint $table) {
            $table->dropIndex('listings_price_idx');
        });
    }
};
