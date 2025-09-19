<?php

use App\Models\CourierProvider;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create((new CourierProvider())->getTable(), function (Blueprint $table) {
            $table->id();
            $table->string('provider_name');
            $table->integer('city_id');
            $table->string('city_name');
            $table->integer('zone_id');
            $table->string('zone_name');
            $table->integer('area_id')->nullable();
            $table->string('area_name')->nullable();
            $table->boolean('home_delivery_available')->default(false);
            $table->boolean('pickup_available')->default(false);
            $table->timestamps();
            $table->softDeletes();

            // Add unique constraint for upsert operations
            $table->unique(['provider_name', 'city_id', 'zone_id', 'area_id'], 'courier_provider_location_unique');

            // Keep existing indexes for query performance
            $table->index('provider_name');
            $table->index('city_name');
            $table->index('zone_name');
            $table->index('area_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists((new CourierProvider())->getTable());
    }
};
