<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\DoubleMaterialityGuide;

class DoubleMaterialityGuideController extends Controller
{
    public function show()
    {
        return response()->json(['data' => DoubleMaterialityGuide::toArray()]);
    }

    public function templateCsv(string $template)
    {
        $csv = DoubleMaterialityGuide::templateCsv($template);

        if (! $csv) {
            return response()->json(['message' => 'Template not found.'], 404);
        }

        return response($csv['content'], 200, [
            'Content-Disposition' => 'attachment; filename='.$csv['filename'],
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
