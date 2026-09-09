<?php

namespace App\Controllers;

use App\Models\Buyer;
use App\Models\ProductMaster;
use App\Models\PurchasingOrder;
use App\Models\PurchasingOrderDetail;
use App\Support\LoggerHelper;
use App\Support\ResponseHelper;
use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class OrderController
{
    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $keyword = trim($params['keyword'] ?? '');
        $status = trim($params['status'] ?? '');
        $buyerId = isset($params['buyerId']) ? (int) $params['buyerId'] : null;
        $page = max(1, (int) ($params['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($params['perPage'] ?? 15)));

        $query = PurchasingOrder::with(['buyer', 'user', 'details.product', 'workOrder']);

        if (!empty($keyword)) {
            $query->where('po_number', 'like', "%{$keyword}%");
        }

        if (!empty($status)) {
            $query->where('status', $status);
        }

        if ($buyerId) {
            $query->where('buyer_id', $buyerId);
        }

        $total = $query->count();
        $orders = $query->orderBy('id', 'desc')
            ->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get()
            ->map(fn ($order) => $this->formatOrderSummary($order));

        $meta = [
            'currentPage' => $page,
            'perPage' => $perPage,
            'total' => $total,
            'lastPage' => (int) ceil($total / $perPage),
        ];

        return ResponseHelper::success($response, $orders, 'Purchasing orders retrieved successfully', 200, $meta);
    }

    public function store(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $user = $request->getAttribute('user');

        $buyerId = (int) ($body['buyerId'] ?? 0);
        $orderNumber = trim($body['orderNumber'] ?? '');
        $orderDate = trim($body['orderDate'] ?? date('Y-m-d'));
        $targetDate = trim($body['targetDate'] ?? date('Y-m-d'));
        $orderItems = (array) ($body['orderItems'] ?? []);

        // Validation boundary
        if (empty($buyerId) || !Buyer::where('id', $buyerId)->where('is_active', true)->exists()) {
            return ResponseHelper::error($response, 'Validation failed', ['buyerId' => ['Valid active buyer is required']], 422);
        }

        if (empty($orderNumber) || PurchasingOrder::where('po_number', $orderNumber)->exists()) {
            return ResponseHelper::error($response, 'Validation failed', ['orderNumber' => ['Unique order number is required']], 422);
        }

        if (empty($orderItems)) {
            return ResponseHelper::error($response, 'Validation failed', ['orderItems' => ['At least one order item is required']], 422);
        }

        // Atomic transaction
        $order = Capsule::connection()->transaction(function () use ($buyerId, $user, $orderNumber, $orderDate, $targetDate, $orderItems) {
            $po = PurchasingOrder::create([
                'buyer_id' => $buyerId,
                'user_id' => $user->id,
                'po_number' => $orderNumber,
                'po_date' => $orderDate,
                'production_period' => $targetDate,
                'status' => 'draft',
            ]);

            foreach ($orderItems as $item) {
                PurchasingOrderDetail::create([
                    'purchasing_order_id' => $po->id,
                    'product_id' => (int) ($item['productId'] ?? 0),
                    'packing_id' => !empty($item['packingId']) ? (int) $item['packingId'] : null,
                    'finishing_type_id' => !empty($item['finishingId']) ? (int) $item['finishingId'] : null,
                    'stuffing_id' => !empty($item['stuffingId']) ? (int) $item['stuffingId'] : null,
                    'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
                    'cbm' => isset($item['cubicMeter']) ? (float) $item['cubicMeter'] : null,
                    'construction' => in_array($item['construction'] ?? '', ['KD', 'FA']) ? $item['construction'] : null,
                ]);
            }

            return $po;
        });

        $order->load(['buyer', 'user', 'details.product']);
        LoggerHelper::info("Purchasing order created: {$order->po_number} by User {$user->id}");

        return ResponseHelper::success($response, $this->formatOrderSummary($order), 'Purchasing order created successfully', 201);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $order = PurchasingOrder::with([
            'buyer',
            'user',
            'details.product.materials.material',
            'details.packing',
            'details.finishingType',
            'details.stuffing',
            'workOrder.confirmations',
        ])->find($id);

        if (!$order) {
            return ResponseHelper::error($response, 'Purchasing order not found', null, 404);
        }

        $formatted = [
            'id' => $order->id,
            'orderNumber' => $order->po_number,
            'orderDate' => $order->po_date,
            'targetDate' => $order->production_period,
            'status' => $order->status,
            'buyer' => [
                'id' => $order->buyer?->id,
                'buyerCode' => $order->buyer?->buyer_code,
                'description' => $order->buyer?->description,
            ],
            'creator' => [
                'id' => $order->user?->id,
                'name' => $order->user?->name,
            ],
            'orderItems' => $order->details->map(function ($d) {
                return [
                    'id' => $d->id,
                    'quantity' => $d->quantity,
                    'cubicMeter' => (float) $d->cbm,
                    'construction' => $d->construction,
                    'product' => [
                        'id' => $d->product?->id,
                        'productCode' => $d->product?->product_code,
                        'productName' => $d->product?->product_name,
                        'unit' => $d->product?->unit,
                    ],
                    'packing' => $d->packing ? ['id' => $d->packing->id, 'packingName' => $d->packing->packing_name] : null,
                    'finishing' => $d->finishingType ? ['id' => $d->finishingType->id, 'finishingName' => $d->finishingType->name] : null,
                    'stuffing' => $d->stuffing ? ['id' => $d->stuffing->id, 'stuffingName' => $d->stuffing->stuffing_name] : null,
                ];
            }),
            'workOrder' => $order->workOrder ? [
                'id' => $order->workOrder->id,
                'workOrderNumber' => $order->workOrder->work_order_number,
                'status' => $order->workOrder->status,
                'type' => $order->workOrder->type,
                'confirmationsCount' => $order->workOrder->confirmations->count(),
            ] : null,
        ];

        return ResponseHelper::success($response, $formatted, 'Purchasing order retrieved successfully');
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $order = PurchasingOrder::find($id);

        if (!$order) {
            return ResponseHelper::error($response, 'Purchasing order not found', null, 404);
        }

        if ($order->status !== 'draft') {
            return ResponseHelper::error($response, 'Cannot edit order: Only draft orders can be modified.', null, 400);
        }

        $body = (array) $request->getParsedBody();

        if (!empty($body['orderNumber'])) {
            $duplicate = PurchasingOrder::where('po_number', $body['orderNumber'])->where('id', '!=', $id)->exists();
            if ($duplicate) {
                return ResponseHelper::error($response, 'Order number already in use', null, 422);
            }
            $order->po_number = $body['orderNumber'];
        }

        if (!empty($body['buyerId'])) {
            $order->buyer_id = (int) $body['buyerId'];
        }

        if (!empty($body['orderDate'])) {
            $order->po_date = $body['orderDate'];
        }

        if (!empty($body['targetDate'])) {
            $order->production_period = $body['targetDate'];
        }

        $order->save();
        LoggerHelper::info("Purchasing order updated: {$order->po_number}");

        return ResponseHelper::success($response, $this->formatOrderSummary($order), 'Purchasing order updated successfully');
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $order = PurchasingOrder::with('workOrder')->find($id);

        if (!$order) {
            return ResponseHelper::error($response, 'Purchasing order not found', null, 404);
        }

        if ($order->status !== 'draft' || $order->workOrder) {
            return ResponseHelper::error($response, 'Cannot delete order because work order has already been issued.', null, 400);
        }

        $poNumber = $order->po_number;
        $order->delete();
        LoggerHelper::info("Purchasing order deleted: {$poNumber}");

        return ResponseHelper::success($response, null, 'Purchasing order deleted successfully');
    }

    public function simulate(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $orderItems = (array) ($body['orderItems'] ?? []);

        if (empty($orderItems)) {
            return ResponseHelper::error($response, 'Order items are required for simulation', null, 422);
        }

        $totalQuantity = 0;
        $totalEstimatedCbm = 0.0;
        $bomUsage = [];

        foreach ($orderItems as $item) {
            $qty = max(1, (int) ($item['quantity'] ?? 1));
            $totalQuantity += $qty;

            $cbm = isset($item['cubicMeter']) ? (float) $item['cubicMeter'] : 0.0;
            $productId = (int) ($item['productId'] ?? 0);

            $product = ProductMaster::with(['materials.material'])->find($productId);
            if ($product) {
                // If CBM not provided, calculate from dimensions (cm to m3)
                if ($cbm <= 0 && $product->dimension_length && $product->dimension_width && $product->dimension_height) {
                    $itemVol = ($product->dimension_length * $product->dimension_width * $product->dimension_height) / 1000000;
                    $cbm = $itemVol * $qty;
                }
                $totalEstimatedCbm += $cbm;

                // Calculate BOM usage
                foreach ($product->materials as $pm) {
                    $mat = $pm->material;
                    if ($mat) {
                        $key = $mat->id;
                        if (!isset($bomUsage[$key])) {
                            $bomUsage[$key] = [
                                'materialId' => $mat->id,
                                'materialName' => $mat->material_name,
                                'grade' => $mat->grade,
                                'woodForm' => $mat->wood_form,
                                'totalEstimatedUsage' => 0.0,
                            ];
                        }
                        $bomUsage[$key]['totalEstimatedUsage'] += ((float) $pm->quantity) * $qty;
                    }
                }
            }
        }

        $simulationResult = [
            'totalItems' => count($orderItems),
            'totalQuantity' => $totalQuantity,
            'estimatedCbm' => round($totalEstimatedCbm, 3),
            'bomSummary' => array_values($bomUsage),
            'readyForSpk' => true,
        ];

        return ResponseHelper::success($response, $simulationResult, 'Order draft simulated successfully');
    }

    private function formatOrderSummary(PurchasingOrder $order): array
    {
        return [
            'id' => $order->id,
            'orderNumber' => $order->po_number,
            'orderDate' => $order->po_date,
            'targetDate' => $order->production_period,
            'status' => $order->status,
            'buyer' => [
                'id' => $order->buyer?->id,
                'buyerCode' => $order->buyer?->buyer_code,
            ],
            'creator' => [
                'id' => $order->user?->id,
                'name' => $order->user?->name,
            ],
            'itemsCount' => $order->details->count(),
            'totalQuantity' => $order->details->sum('quantity'),
            'hasWorkOrder' => (bool) $order->workOrder,
        ];
    }
}
