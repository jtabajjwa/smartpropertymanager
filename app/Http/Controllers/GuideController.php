<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * Closing an in-app tip or the dashboard's getting-started guide, and
 * bringing them all back. Stored per user; staff in staff mode don't see them.
 */
class GuideController extends Controller
{
    public function dismiss(Request $request, string $key)
    {
        if ($request->user() instanceof User) {
            $request->user()->dismissTip($key);
        }

        return $request->expectsJson() ? response()->noContent() : back();
    }

    public function restore(Request $request)
    {
        if ($request->user() instanceof User) {
            $request->user()->restoreTips();
        }

        return redirect()->route('dashboard')->with('success', 'Tips and the getting-started guide are showing again.');
    }
}
