<?php
namespace App\Support;

use Illuminate\Contracts\Pagination\CursorPaginator;

class ApiResponse
{
    public static function one($data, int $status = 200)
    {
        return response()->json(['data' => $data], $status);
    }

    public static function page(CursorPaginator $p)
    {
        return response()->json([
            'data' => $p->items(),
            'meta' => ['next_cursor' => $p->nextCursor()?->encode(), 'has_more' => $p->hasMorePages()],
        ]);
    }

    public static function perPage(\Illuminate\Http\Request $r): int
    {
        return max(1, min((int) $r->query('per_page', 20), 100));
    }
}