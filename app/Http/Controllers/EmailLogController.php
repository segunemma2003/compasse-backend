<?php

namespace App\Http\Controllers;

use App\Models\EmailLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = EmailLog::orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }
        if ($request->filled('search')) {
            $term = $request->search;
            $query->where(function ($q) use ($term) {
                $q->where('to', 'like', "%{$term}%")
                  ->orWhere('subject', 'like', "%{$term}%");
            });
        }

        $logs = $query->paginate(25);

        return response()->json([
            'logs'     => $logs->items(),
            'total'    => $logs->total(),
            'per_page' => $logs->perPage(),
            'page'     => $logs->currentPage(),
        ]);
    }
}
