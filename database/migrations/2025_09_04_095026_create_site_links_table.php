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
        Schema::create('site_links', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->string('title');
            $table->string('url');
            // 30sec, 1m, 5m, 30m, 1h, 12, 24h in seconds
            $table->enum('duration',['30','60','300','1800','3600','43200','86400']);
            $table->enum('is_active',['active','inactive'])->default('active');
            // notifyers
            $table->boolean('is_notify')->default(true);
            $table->boolean('is_disabled')->default(false);
            $table->boolean('notify_email')->default(false);
            $table->boolean('notify_sms')->default(false);
            $table->boolean('notify_push')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('site_links');
    }
};
