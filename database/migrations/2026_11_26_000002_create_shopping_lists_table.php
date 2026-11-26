<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopping_lists', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('merchant_id')->nullable()->index();
            $table->unsignedInteger('radius_m')->default(5000);
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->timestamps();
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE shopping_lists ADD COLUMN location GEOGRAPHY(Point, 4326)');
            DB::statement('CREATE INDEX shopping_lists_location_gist ON shopping_lists USING GIST (location)');
        }

        Schema::create('shopping_list_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('shopping_list_id')->constrained('shopping_lists')->cascadeOnDelete();
            $table->uuid('material_id')->index();
            $table->decimal('qty', 12, 3);
            $table->string('unit', 20);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopping_list_items');
        Schema::dropIfExists('shopping_lists');
    }
};
