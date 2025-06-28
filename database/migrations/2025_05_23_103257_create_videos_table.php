<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void // Use `void` for type hinting in newer Laravel versions
    {
        Schema::create('videos', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description');
            $table->string('thumbnail_url');
            $table->string('trailer_url');
            $table->string('full_video_url');
            // Add the new column to store Cloudinary public IDs as JSON
            $table->json('cloudinary_public_ids')->nullable(); // Can be null if data predates Cloudinary upload

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('videos');
    }
};
