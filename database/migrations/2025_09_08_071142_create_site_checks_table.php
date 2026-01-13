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
        Schema::create('site_checks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('site_link_id');
            $table->foreign('site_link_id')->references('id')->on('site_links')->onDelete('cascade');
            $table->enum('status',['up','down']);
            $table->bigInteger('response_time_ms')->nullable();
            $table->bigInteger('ssl_days_left')->nullable();
            $table->bigInteger('html_bytes')->nullable();
            $table->bigInteger('assets_bytes')->nullable();
            $table->timestamp('checked_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('site_checks');
    }
};
