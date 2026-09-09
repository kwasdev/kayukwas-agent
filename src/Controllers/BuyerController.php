<?php

namespace App\Controllers;

use App\Models\Buyer;
use App\Support\LoggerHelper;
use App\Support\ResponseHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class BuyerController
{
    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $keyword = trim($params['keyword'] ?? '');
        $page = max(1, (int) ($params['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($params['perPage'] ?? 15)));

        $query = Buyer::query();

        if (!empty($keyword)) {
            $query->where(function ($q) use ($keyword) {
                $q->where('buyer_code', 'like', "%{$keyword}%")
                  ->orWhere('description', 'like', "%{$keyword}%");
            });
        }

        if (isset($params['isActive'])) {
            $query->where('is_active', filter_var($params['isActive'], FILTER_VALIDATE_BOOLEAN));
        }

        $total = $query->count();
        $items = $query->orderBy('buyer_code')
            ->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get()
            ->map(fn ($b) => [
                'id' => $b->id,
                'buyerCode' => $b->buyer_code,
                'description' => $b->description,
                'isActive' => $b->is_active,
            ]);

        $meta = [
            'currentPage' => $page,
            'perPage' => $perPage,
            'total' => $total,
            'lastPage' => (int) ceil($total / $perPage),
        ];

        return ResponseHelper::success($response, $items, 'Buyers retrieved successfully', 200, $meta);
    }

    public function store(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $buyerCode = trim($body['buyerCode'] ?? '');
        $description = trim($body['description'] ?? '');
        $isActive = isset($body['isActive']) ? (bool) $body['isActive'] : true;

        if (empty($buyerCode)) {
            return ResponseHelper::error($response, 'Validation failed', ['buyerCode' => ['Buyer code is required']], 422);
        }

        if (Buyer::where('buyer_code', $buyerCode)->exists()) {
            return ResponseHelper::error($response, 'Validation failed', ['buyerCode' => ['Buyer code already exists']], 422);
        }

        $buyer = Buyer::create([
            'buyer_code' => $buyerCode,
            'description' => $description ?: null,
            'is_active' => $isActive,
        ]);

        LoggerHelper::info("Buyer created: {$buyer->buyer_code}");

        return ResponseHelper::success($response, [
            'id' => $buyer->id,
            'buyerCode' => $buyer->buyer_code,
            'description' => $buyer->description,
            'isActive' => $buyer->is_active,
        ], 'Buyer created successfully', 201);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $buyer = Buyer::find($id);

        if (!$buyer) {
            return ResponseHelper::error($response, 'Buyer not found', null, 404);
        }

        return ResponseHelper::success($response, [
            'id' => $buyer->id,
            'buyerCode' => $buyer->buyer_code,
            'description' => $buyer->description,
            'isActive' => $buyer->is_active,
        ], 'Buyer retrieved successfully');
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $buyer = Buyer::find($id);

        if (!$buyer) {
            return ResponseHelper::error($response, 'Buyer not found', null, 404);
        }

        $body = (array) $request->getParsedBody();
        $buyerCode = trim($body['buyerCode'] ?? $buyer->buyer_code);

        if (empty($buyerCode)) {
            return ResponseHelper::error($response, 'Validation failed', ['buyerCode' => ['Buyer code cannot be empty']], 422);
        }

        $duplicateCheck = Buyer::where('buyer_code', $buyerCode)->where('id', '!=', $id)->exists();
        if ($duplicateCheck) {
            return ResponseHelper::error($response, 'Validation failed', ['buyerCode' => ['Buyer code already in use']], 422);
        }

        $buyer->buyer_code = $buyerCode;
        if (array_key_exists('description', $body)) {
            $buyer->description = trim($body['description'] ?? '') ?: null;
        }
        if (array_key_exists('isActive', $body)) {
            $buyer->is_active = (bool) $body['isActive'];
        }
        $buyer->save();

        LoggerHelper::info("Buyer updated: {$buyer->buyer_code}");

        return ResponseHelper::success($response, [
            'id' => $buyer->id,
            'buyerCode' => $buyer->buyer_code,
            'description' => $buyer->description,
            'isActive' => $buyer->is_active,
        ], 'Buyer updated successfully');
    }

    public function toggleStatus(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $buyer = Buyer::find($id);

        if (!$buyer) {
            return ResponseHelper::error($response, 'Buyer not found', null, 404);
        }

        $buyer->is_active = !$buyer->is_active;
        $buyer->save();

        LoggerHelper::info("Buyer status toggled: {$buyer->buyer_code} (Active: " . ($buyer->is_active ? 'true' : 'false') . ")");

        return ResponseHelper::success($response, [
            'id' => $buyer->id,
            'buyerCode' => $buyer->buyer_code,
            'isActive' => $buyer->is_active,
        ], 'Buyer status updated successfully');
    }
}
