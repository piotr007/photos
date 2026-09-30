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
        Schema::create('photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('album_id')->constrained()->cascadeOnDelete();

            // Public identity of the photo (URL: /galleries/{album}/{photo}); unique within an album.
            $table->string('slug');
            $table->string('title')->nullable();
            $table->string('caption', 500)->nullable();
            $table->longText('description')->nullable();
            $table->string('alt')->nullable();

            // Processed WebP derivatives (paths relative to the media disk root). No original is kept.
            $table->string('path_large');
            $table->string('path_medium');
            $table->string('path_thumb');
            $table->string('original_filename')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();

            $table->boolean('is_cover')->default(false);
            $table->unsignedInteger('sort_order')->default(0);

            // Shooting metadata: read from EXIF on upload, editable in the CMS.
            $table->timestamp('taken_at')->nullable();
            $table->string('location')->nullable();
            $table->string('camera')->nullable();
            $table->string('lens')->nullable();
            $table->string('focal_length', 32)->nullable();
            $table->string('aperture', 32)->nullable();
            $table->string('shutter_speed', 32)->nullable();
            $table->unsignedInteger('iso')->nullable();

            $table->timestamps();

            $table->unique(['album_id', 'slug']);
            $table->index(['album_id', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('photos');
    }
};
