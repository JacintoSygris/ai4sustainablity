<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\EsrsTopicResource;
use App\Repositories\EsrsTopicRepository;
use Illuminate\Http\Request;

class EsrsTopicController extends Controller
{
    public function __construct(private EsrsTopicRepository $repository)
    {
    }

    public function index(Request $request)
    {
        $validated = $request->validate([
            'esrs_code' => ['nullable', 'string', 'max:10'],
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:200'],
        ]);

        $topics = $this->repository->list($validated);

        return EsrsTopicResource::collection($topics);
    }
}
