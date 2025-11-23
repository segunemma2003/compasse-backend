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
        Schema::create('stories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            
            // Story details
            $table->string('title')->nullable();
            $table->text('content')->nullable();
            $table->enum('type', ['photo', 'video', 'text', 'announcement', 'achievement', 'event'])->default('text');
            
            // Media
            $table->json('media')->nullable(); // Array of image/video URLs
            $table->string('thumbnail')->nullable(); // For video stories
            
            // Visibility
            $table->enum('visibility', ['public', 'students', 'staff', 'parents', 'guardians', 'teachers', 'admin_only', 'class_specific'])->default('public');
            $table->json('visible_to_classes')->nullable(); // For class-specific stories
            
            // Story settings
            $table->boolean('is_pinned')->default(false); // Pin important stories
            $table->timestamp('expires_at')->nullable(); // Auto-expire stories (e.g., 24 hours)
            $table->boolean('is_active')->default(true);
            $table->boolean('allow_comments')->default(true);
            $table->boolean('allow_reactions')->default(true);
            
            // Engagement tracking
            $table->unsignedInteger('views_count')->default(0);
            $table->unsignedInteger('reactions_count')->default(0);
            $table->unsignedInteger('comments_count')->default(0);
            $table->unsignedInteger('shares_count')->default(0);
            
            // Categories/Tags
            $table->json('tags')->nullable();
            $table->string('category')->nullable(); // e.g., 'sports', 'academics', 'events'
            
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes
            $table->index(['school_id', 'is_active', 'expires_at']);
            $table->index(['type', 'visibility']);
            $table->index('created_at');
        });

        // Story views tracking
        Schema::create('story_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('story_id')->constrained('stories')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->timestamp('viewed_at');
            $table->timestamps();
            
            $table->unique(['story_id', 'user_id']); // Each user can view once
        });

        // Story reactions (likes, love, celebrate, etc.)
        Schema::create('story_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('story_id')->constrained('stories')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->enum('reaction_type', ['like', 'love', 'celebrate', 'support', 'insightful', 'curious'])->default('like');
            $table->timestamps();
            
            $table->unique(['story_id', 'user_id']); // Each user can react once
        });

        // Story comments
        Schema::create('story_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('story_id')->constrained('stories')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('parent_id')->nullable()->constrained('story_comments')->onDelete('cascade'); // For replies
            $table->text('comment');
            $table->boolean('is_approved')->default(true); // For moderation
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['story_id', 'is_approved']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('story_comments');
        Schema::dropIfExists('story_reactions');
        Schema::dropIfExists('story_views');
        Schema::dropIfExists('stories');
    }
};

