<?php

namespace App\Http\Controllers;

use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        try {
            // Check connectivity, read permissions and the P0 migration without writing.
            DB::table('sessions')->limit(1)->value('id');
        } catch (QueryException) {
            return response()->json(['status' => 'unavailable'], 503);
        }

        return response()->json(['status' => 'ok', 'database' => 'ok']);
    }
}
