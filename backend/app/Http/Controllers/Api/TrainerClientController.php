<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TrainerClientController extends Controller
{
    public function index(Request $request)
    {
        $trainer = $request->user();

        if (!$trainer->is_trainer) {
            return response()->json([
                'message' => 'Only trainers can access the client roster.',
            ], 403);
        }

        $clients = DB::table('trainer_clients as tc')
            ->join('users as clients', 'clients.id', '=', 'tc.client_id')
            ->where('tc.trainer_id', $trainer->id)
            ->where('tc.status', 'active')
            ->whereNull('clients.deleted_at')
            ->select([
                'tc.id as relationship_id',
                'clients.id',
                'clients.name',
                'clients.email',
                'tc.status as relationship_status',
                'tc.scopes',
                'tc.created_at as connected_at',
            ])
            ->orderBy('tc.id')
            ->cursorPaginate(ApiResponse::perPage($request));

        $clients->through(function ($client) {
            if (is_string($client->scopes)) {
                $client->scopes = json_decode($client->scopes, true) ?? [];
            }

            return $client;
        });

        return ApiResponse::page($clients);
    }
}