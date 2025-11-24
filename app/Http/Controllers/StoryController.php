<?php

namespace App\Http\Controllers;

use App\Models\Story;
use App\Models\StoryReaction;
use App\Models\StoryComment;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class StoryController extends Controller
{
    /**
     * Get all stories (with filters)
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = Story::with(['author', 'reactions', 'comments'])
                         ->active();

            // Filter by type
            if ($request->has('type')) {
                $query->ofType($request->type);
            }

            // Filter by category
            if ($request->has('category')) {
                $query->where('category', $request->category);
            }

            // Filter by visibility
            if ($request->has('visibility')) {
                $query->where('visibility', $request->visibility);
            }

            // Only pinned stories
            if ($request->has('pinned') && $request->pinned) {
                $query->pinned();
            }

            // Recent stories (within 24 hours by default)
            if ($request->has('recent')) {
                $hours = $request->get('hours', 24);
                $query->recent($hours);
            }

            // Search
            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('title', 'like', "%{$search}%")
                      ->orWhere('content', 'like', "%{$search}%");
                });
            }

            // Sort
            $sortBy = $request->get('sort_by', 'created_at');
            $sortOrder = $request->get('sort_order', 'desc');
            $query->orderBy($sortBy, $sortOrder);

            $stories = $query->paginate($request->get('per_page', 15));

            return response()->json([
                'stories' => $stories
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch stories',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get a single story
     */
    public function show(Story $story): JsonResponse
    {
        try {
            // Increment view count for authenticated user
            if (Auth::check()) {
                $story->incrementViews(Auth::id());
            }

            $story->load([
                'author',
                'reactions.user',
                'comments' => function($query) {
                    $query->approved()
                          ->whereNull('parent_id')
                          ->with(['user', 'replies.user'])
                          ->orderBy('created_at', 'desc');
                }
            ]);

            return response()->json([
                'story' => $story
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch story',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create a new story
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'title' => 'nullable|string|max:255',
            'content' => 'nullable|string',
            'type' => 'required|in:photo,video,text,announcement,achievement,event',
            'media' => 'nullable|array',
            'media.*' => 'nullable|string', // URLs to media files
            'thumbnail' => 'nullable|string',
            'visibility' => 'required|in:public,students,staff,parents,guardians,teachers,admin_only,class_specific',
            'visible_to_classes' => 'nullable|array',
            'visible_to_classes.*' => 'integer|exists:classes,id',
            'is_pinned' => 'nullable|boolean',
            'expires_at' => 'nullable|date|after:now',
            'allow_comments' => 'nullable|boolean',
            'allow_reactions' => 'nullable|boolean',
            'tags' => 'nullable|array',
            'category' => 'nullable|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            // Auto-get school_id from tenant context
            $schoolId = $this->getSchoolIdFromTenant($request);
            if (!$schoolId) {
                return response()->json([
                    'error' => 'School not found',
                    'message' => 'Unable to determine school from tenant context'
                ], 400);
            }

            $storyData = array_merge($request->all(), [
                'school_id' => $schoolId,
                'user_id' => Auth::id(),
                'is_active' => true,
            ]);

            $story = Story::create($storyData);

            return response()->json([
                'message' => 'Story created successfully',
                'story' => $story->load('author')
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to create story',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update a story
     */
    public function update(Request $request, Story $story): JsonResponse
    {
        // Check if user owns the story or is admin
        if ($story->user_id !== Auth::id() && !in_array(Auth::user()->role, ['super_admin', 'school_admin', 'admin'])) {
            return response()->json([
                'error' => 'Unauthorized',
                'message' => 'You can only update your own stories'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|string|max:255',
            'content' => 'sometimes|string',
            'type' => 'sometimes|in:photo,video,text,announcement,achievement,event',
            'media' => 'sometimes|array',
            'media.*' => 'nullable|string',
            'thumbnail' => 'sometimes|string',
            'visibility' => 'sometimes|in:public,students,staff,parents,guardians,teachers,admin_only,class_specific',
            'visible_to_classes' => 'sometimes|array',
            'is_pinned' => 'sometimes|boolean',
            'expires_at' => 'sometimes|date|after:now',
            'allow_comments' => 'sometimes|boolean',
            'allow_reactions' => 'sometimes|boolean',
            'is_active' => 'sometimes|boolean',
            'tags' => 'sometimes|array',
            'category' => 'sometimes|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            $story->update($request->all());

            return response()->json([
                'message' => 'Story updated successfully',
                'story' => $story->load('author')
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to update story',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a story
     */
    public function destroy(Story $story): JsonResponse
    {
        // Check if user owns the story or is admin
        if ($story->user_id !== Auth::id() && !in_array(Auth::user()->role, ['super_admin', 'school_admin', 'admin'])) {
            return response()->json([
                'error' => 'Unauthorized',
                'message' => 'You can only delete your own stories'
            ], 403);
        }

        try {
            $story->delete();

            return response()->json([
                'message' => 'Story deleted successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to delete story',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * React to a story
     */
    public function react(Request $request, Story $story): JsonResponse
    {
        if (!$story->allow_reactions) {
            return response()->json([
                'error' => 'Reactions are disabled for this story'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'reaction_type' => 'required|in:like,love,celebrate,support,insightful,curious',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            // Check if user already reacted
            $existingReaction = StoryReaction::where('story_id', $story->id)
                                            ->where('user_id', Auth::id())
                                            ->first();

            if ($existingReaction) {
                // Update reaction
                $existingReaction->update(['reaction_type' => $request->reaction_type]);
                $message = 'Reaction updated successfully';
            } else {
                // Create new reaction
                StoryReaction::create([
                    'story_id' => $story->id,
                    'user_id' => Auth::id(),
                    'reaction_type' => $request->reaction_type,
                ]);
                $story->increment('reactions_count');
                $message = 'Reaction added successfully';
            }

            return response()->json([
                'message' => $message,
                'reactions_count' => $story->fresh()->reactions_count
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to react to story',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove reaction from a story
     */
    public function unreact(Story $story): JsonResponse
    {
        try {
            $reaction = StoryReaction::where('story_id', $story->id)
                                    ->where('user_id', Auth::id())
                                    ->first();

            if (!$reaction) {
                return response()->json([
                    'error' => 'You have not reacted to this story'
                ], 404);
            }

            $reaction->delete();
            $story->decrement('reactions_count');

            return response()->json([
                'message' => 'Reaction removed successfully',
                'reactions_count' => $story->fresh()->reactions_count
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to remove reaction',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Comment on a story
     */
    public function comment(Request $request, Story $story): JsonResponse
    {
        if (!$story->allow_comments) {
            return response()->json([
                'error' => 'Comments are disabled for this story'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'comment' => 'required|string|max:1000',
            'parent_id' => 'nullable|exists:story_comments,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            $comment = StoryComment::create([
                'story_id' => $story->id,
                'user_id' => Auth::id(),
                'parent_id' => $request->parent_id,
                'comment' => $request->comment,
                'is_approved' => true, // Auto-approve, can add moderation later
            ]);

            $story->increment('comments_count');

            return response()->json([
                'message' => 'Comment added successfully',
                'comment' => $comment->load('user'),
                'comments_count' => $story->fresh()->comments_count
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to add comment',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a comment
     */
    public function deleteComment(Story $story, StoryComment $comment): JsonResponse
    {
        // Check if user owns the comment or is admin
        if ($comment->user_id !== Auth::id() && !in_array(Auth::user()->role, ['super_admin', 'school_admin', 'admin'])) {
            return response()->json([
                'error' => 'Unauthorized',
                'message' => 'You can only delete your own comments'
            ], 403);
        }

        try {
            $comment->delete();
            $story->decrement('comments_count');

            return response()->json([
                'message' => 'Comment deleted successfully',
                'comments_count' => $story->fresh()->comments_count
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to delete comment',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Share a story (increment share count)
     */
    public function share(Story $story): JsonResponse
    {
        try {
            $story->incrementShares();

            return response()->json([
                'message' => 'Story shared successfully',
                'shares_count' => $story->fresh()->shares_count
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to share story',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get story analytics (admin only)
     */
    public function analytics(Story $story): JsonResponse
    {
        try {
            $analytics = [
                'views_count' => $story->views_count,
                'reactions_count' => $story->reactions_count,
                'comments_count' => $story->comments_count,
                'shares_count' => $story->shares_count,
                'reactions_breakdown' => StoryReaction::where('story_id', $story->id)
                                                      ->select('reaction_type', DB::raw('count(*) as count'))
                                                      ->groupBy('reaction_type')
                                                      ->get(),
                'top_viewers' => $story->views()
                                       ->with('user:id,name,email')
                                       ->latest('viewed_at')
                                       ->limit(10)
                                       ->get(),
            ];

            return response()->json([
                'analytics' => $analytics
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch analytics',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}

