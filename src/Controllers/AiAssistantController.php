<?php

namespace App\Controllers;

use App\Models\Buyer;
use App\Models\FinishingType;
use App\Models\Packing;
use App\Models\ProductMaster;
use App\Models\PurchasingOrder;
use App\Models\PurchasingOrderDetail;
use App\Models\WorkOrder;
use App\Models\WorkOrderConfirmation;
use App\Support\LoggerHelper;
use App\Support\ResponseHelper;
use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AiAssistantController
{
    public function fuzzySearch(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $query = trim($params['query'] ?? '');

        if (empty($query)) {
            return ResponseHelper::error($response, 'Query parameter is required', null, 422);
        }

        $buyers = Buyer::where('is_active', true)
            ->where(function ($q) use ($query) {
                $q->where('buyer_code', 'like', "%{$query}%")
                  ->orWhere('description', 'like', "%{$query}%");
            })
            ->take(5)
            ->get()
            ->map(fn ($b) => [
                'id' => $b->id,
                'buyerCode' => $b->buyer_code,
                'description' => $b->description,
            ]);

        $products = ProductMaster::with(['materials.material'])
            ->where('is_active', true)
            ->where(function ($q) use ($query) {
                $q->where('product_name', 'like', "%{$query}%")
                  ->orWhere('product_code', 'like', "%{$query}%");
            })
            ->take(5)
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'productCode' => $p->product_code,
                'productName' => $p->product_name,
                'mainMaterial' => $p->materials->first()?->material?->material_name,
            ]);

        $finishings = FinishingType::where('name', 'like', "%{$query}%")
            ->take(5)
            ->get()
            ->map(fn ($f) => ['id' => $f->id, 'name' => $f->name]);

        $packings = Packing::where('packing_name', 'like', "%{$query}%")
            ->take(5)
            ->get()
            ->map(fn ($pk) => ['id' => $pk->id, 'name' => $pk->packing_name]);

        return ResponseHelper::success($response, [
            'buyers' => $buyers,
            'products' => $products,
            'finishings' => $finishings,
            'packings' => $packings,
        ], 'Fuzzy search completed');
    }

    public function chat(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $prompt = trim($body['userPrompt'] ?? '');
        $user = $request->getAttribute('user');

        if (empty($prompt)) {
            return ResponseHelper::error($response, 'Prompt is required', null, 422);
        }

        LoggerHelper::info("AI Chat received from User {$user->name}: '{$prompt}'");

        // Extract entities from natural language
        $lowerPrompt = strtolower($prompt);
        
        // Find matching buyer
        $buyer = Buyer::where('is_active', true)
            ->get()
            ->first(fn ($b) => str_contains($lowerPrompt, strtolower($b->buyer_code)) || (!empty($b->description) && str_contains($lowerPrompt, strtolower($b->description))));
        
        if (!$buyer) {
            $buyer = Buyer::where('is_active', true)->first();
        }

        // Find matching product
        $product = ProductMaster::with('materials.material')
            ->where('is_active', true)
            ->get()
            ->first(fn ($p) => str_contains($lowerPrompt, strtolower($p->product_name)) || str_contains($lowerPrompt, strtolower((string)$p->product_code)));

        if (!$product) {
            $product = ProductMaster::where('is_active', true)->first();
        }

        // Extract quantity (find numbers in prompt)
        $quantity = 100;
        if (preg_match('/(\d+)\s*(pcs|buah|unit|lembar)?/i', $prompt, $matches)) {
            $quantity = max(1, (int) $matches[1]);
        }

        // Calculate approximate CBM
        $unitCbm = ($product && $product->dimension_length && $product->dimension_width && $product->dimension_height)
            ? ($product->dimension_length * $product->dimension_width * $product->dimension_height) / 1000000
            : 0.048;
        $estimatedCbm = round($unitCbm * $quantity, 3);

        $sessionId = 'draft_' . bin2hex(random_bytes(6));
        $buyerCode = $buyer ? $buyer->buyer_code : 'BYR-SAMPLE';
        $productName = $product ? $product->product_name : 'Standard Product';
        $mainMaterial = $product?->materials->first()?->material?->material_name ?? 'Kayu Jati Grade A';

        $replyText = "Saya telah memproses laporan pesanan Anda dan menyusun draf rincian SPK:";

        $cardData = [
            'draftSessionId' => $sessionId,
            'buyerId' => $buyer?->id,
            'buyerCode' => $buyerCode,
            'productId' => $product?->id,
            'productName' => $productName,
            'quantity' => $quantity,
            'estimatedCbm' => $estimatedCbm,
            'mainWood' => $mainMaterial,
            'targetDate' => date('Y-m-d', strtotime('+30 days')),
        ];

        return ResponseHelper::success($response, [
            'replyText' => $replyText,
            'hasInteractiveCard' => true,
            'cardType' => 'spk_draft_preview',
            'cardData' => $cardData,
            'actionButtons' => [
                ['label' => '✓ Terbitkan & Tanda Tangan SPK', 'action' => 'confirm_spk', 'style' => 'primary'],
                ['label' => 'Batal', 'action' => 'cancel', 'style' => 'secondary'],
            ],
        ], 'AI message processed');
    }

    public function handleAction(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $action = trim($body['actionType'] ?? '');
        $user = $request->getAttribute('user');

        if ($action !== 'confirm_spk') {
            return ResponseHelper::error($response, 'Invalid or unsupported action', null, 422);
        }

        $buyerId = (int) ($body['buyerId'] ?? 1);
        $productId = (int) ($body['productId'] ?? 1);
        $quantity = max(1, (int) ($body['quantity'] ?? 100));
        $targetDate = trim($body['targetDate'] ?? date('Y-m-d', strtotime('+30 days')));
        $orderType = in_array($body['orderType'] ?? '', ['COC', 'Non-COC']) ? $body['orderType'] : 'COC';

        $poNumber = 'PO-AI-' . date('Ymd') . '-' . rand(100, 999);
        $spkNumber = 'SPK-AI-' . date('Ymd') . '-' . rand(100, 999);

        $result = Capsule::connection()->transaction(function () use ($user, $buyerId, $productId, $quantity, $targetDate, $poNumber, $spkNumber, $orderType) {
            $po = PurchasingOrder::create([
                'buyer_id' => $buyerId,
                'user_id' => $user->id,
                'po_number' => $poNumber,
                'po_date' => date('Y-m-d'),
                'production_period' => $targetDate,
                'status' => 'spk_issued',
            ]);

            PurchasingOrderDetail::create([
                'purchasing_order_id' => $po->id,
                'product_id' => $productId,
                'quantity' => $quantity,
                'construction' => 'KD',
            ]);

            $wo = WorkOrder::create([
                'purchasing_order_id' => $po->id,
                'work_order_number' => $spkNumber,
                'issued_date' => date('Y-m-d'),
                'status' => 'in_approval',
                'type' => $orderType,
                'project_name' => 'Auto-Generated via AI Assistant',
            ]);

            WorkOrderConfirmation::create([
                'work_order_id' => $wo->id,
                'approval_type' => 'marketing',
                'user_id' => $user->id,
                'confirmed_at' => date('Y-m-d H:i:s'),
            ]);

            return ['po' => $po, 'wo' => $wo];
        });

        LoggerHelper::info("AI Action executed: PO {$poNumber} & SPK {$spkNumber} created & confirmed by marketing");

        return ResponseHelper::success($response, [
            'poNumber' => $result['po']->po_number,
            'workOrderNumber' => $result['wo']->work_order_number,
            'status' => $result['wo']->status,
            'approvalType' => 'marketing',
            'confirmedBy' => $user->name,
            'confirmedAt' => date('Y-m-d H:i:s'),
        ], 'Work order issued and confirmed successfully via AI Action');
    }
}
