<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\FrontendWorkflowManifest;

class WorkflowController extends Controller
{
    public function show()
    {
        return response()->json(['data' => FrontendWorkflowManifest::toArray()]);
    }
}
