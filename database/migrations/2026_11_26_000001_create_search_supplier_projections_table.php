<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_supplier_projections', function (Blueprint $table): void {
            $table->uuid('supplier_id')->primary();
            $table->string('store_name');
            $table->text('address')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('province', 100)->nullable();
            $table->string('whatsapp_number', 30)->nullable();
            $table->jsonb('opening_hours')->nullable();
            $table->boolean('is_approved')->default(false)->index();
            $table->boolean('is_accepting_booking')->default(true)->index();
            $table->boolean('is_deposit_sufficient')->default(true)->index();
            $table->jsonb('products_snapshot')->default('[]');
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE search_supplier_projections ADD COLUMN location GEOGRAPHY(Point, 4326)');
            DB::statement('CREATE INDEX search_supplier_projections_location_gist ON search_supplier_projections USING GIST (location)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('search_supplier_projections');
    }
};
