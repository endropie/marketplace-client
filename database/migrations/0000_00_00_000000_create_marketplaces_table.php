<?php

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
        Schema::create('marketplaces', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->enum('via', ['shopee', 'tokopedia', 'lazada', 'blibli']);
            $table->string('code');
            $table->string('name');
            $table->text('payload');
            $table->jsonb('option')->nullable();
            $table->timestamps();

            $table->unique(['via', 'code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('marketplaces');
    }
};
