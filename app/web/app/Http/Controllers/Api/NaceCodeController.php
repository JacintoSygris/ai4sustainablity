<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\NaceCodeResource;
use App\Repositories\NaceCodeRepository;
use Illuminate\Http\Request;

class NaceCodeController extends Controller
{
    public function __construct(private NaceCodeRepository $repository)
    {
    }

    public function index(Request $request)
    {
        $validated = $request->validate([
            'level' => ['nullable', 'integer', 'min:0', 'max:6'],
            'parent_code' => ['nullable', 'string'],
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:200'],
        ]);

        $codes = $this->repository->list($validated);

        return NaceCodeResource::collection($codes);
    }
}
