<?php

namespace App\Controllers;

use App\Models\PurchasingOrder;
use App\Models\WorkOrder;
use App\Models\WorkOrderConfirmation;
use App\Support\LoggerHelper;
use App\Support\ResponseHelper;
use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class WorkOrderController
{
    public function issue(Request $request, Response $response, array $args): Response
    {
        $orderId = (int) ($args['id'] ?? 0);
        $order = PurchasingOrder::with(['details', 'workOrder'])->find($orderId);

        if (!$order) {
            return ResponseHelper::error($response, 'Purchasing order not found', null, 404);
        }

        if ($order->workOrder) {
            return ResponseHelper::error($response, 'Work order has already been issued for this purchasing order.', null, 400);
        }

        if ($order->details->isEmpty()) {
            return ResponseHelper::error($response, 'Cannot issue work order: Order has no items.', null, 422);
        }

        $body = (array) $request->getParsedBody();
        $orderNumber = trim($body['orderNumber'] ?? 'SPK-' . date('Y-m') . '-' . str_pad((string) $order->id, 4, '0', STR_PAD_LEFT));
        $issueDate = trim($body['issueDate'] ?? date('Y-m-d'));
        $orderType = in_array($body['orderType'] ?? '', ['COC', 'Non-COC']) ? $body['orderType'] : 'COC';
        $projectName = trim($body['projectName'] ?? '') ?: null;
        $orderNotes = trim($body['orderNotes'] ?? '') ?: null;

        if (WorkOrder::where('work_order_number', $orderNumber)->exists()) {
            return ResponseHelper::error($response, 'Work order number already in use', null, 422);
        }

        $workOrder = Capsule::connection()->transaction(function () use ($order, $orderNumber, $issueDate, $orderType, $projectName, $orderNotes) {
            $wo = WorkOrder::create([
                'purchasing_order_id' => $order->id,
                'work_order_number'   => $orderNumber,
                'issued_date'         => $issueDate,
                'status'              => 'in_approval',
                'type'                => $orderType,
                'project_name'        => $projectName,
                'notes'               => $orderNotes,
            ]);

            $order->status = 'spk_issued';
            $order->save();

            return $wo;
        });

        LoggerHelper::info("Work order issued: {$workOrder->work_order_number} for PO {$order->po_number}");

        return ResponseHelper::success($response, [
            'id' => $workOrder->id,
            'orderNumber' => $workOrder->work_order_number,
            'issueDate' => $workOrder->issued_date,
            'status' => $workOrder->status,
            'orderType' => $workOrder->type,
            'projectName' => $workOrder->project_name,
            'purchasingOrderId' => $workOrder->purchasing_order_id,
        ], 'Work order issued successfully', 201);
    }

    public function confirmMarketing(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $workOrder = WorkOrder::with('confirmations')->find($id);

        if (!$workOrder) {
            return ResponseHelper::error($response, 'Work order not found', null, 404);
        }

        $existing = $workOrder->confirmations->where('approval_type', 'marketing')->first();
        if ($existing) {
            return ResponseHelper::error($response, 'Work order has already been confirmed by marketing.', null, 400);
        }

        $user = $request->getAttribute('user');
        $confirmation = WorkOrderConfirmation::create([
            'work_order_id' => $workOrder->id,
            'approval_type' => 'marketing',
            'user_id' => $user->id,
            'confirmed_at' => date('Y-m-d H:i:s'),
        ]);

        LoggerHelper::info("Work order {$workOrder->work_order_number} confirmed by marketing (User {$user->id})");

        return ResponseHelper::success($response, [
            'workOrderId' => $workOrder->id,
            'approvalType' => 'marketing',
            'confirmedBy' => $user->name,
            'confirmedAt' => $confirmation->confirmed_at,
            'status' => $workOrder->status,
        ], 'Work order confirmed successfully by marketing');
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $workOrder = WorkOrder::with([
            'purchasingOrder.buyer',
            'purchasingOrder.details.product',
            'confirmations.user',
        ])->find($id);

        if (!$workOrder) {
            return ResponseHelper::error($response, 'Work order not found', null, 404);
        }

        $confirmations = $workOrder->confirmations->map(fn ($c) => [
            'approvalType' => $c->approval_type,
            'confirmedBy' => $c->user?->name,
            'confirmedAt' => $c->confirmed_at,
        ]);

        return ResponseHelper::success($response, [
            'id' => $workOrder->id,
            'workOrderNumber' => $workOrder->work_order_number,
            'issueDate' => $workOrder->issued_date,
            'status' => $workOrder->status,
            'type' => $workOrder->type,
            'projectName' => $workOrder->project_name,
            'notes' => $workOrder->notes,
            'purchasingOrder' => [
                'id' => $workOrder->purchasingOrder?->id,
                'orderNumber' => $workOrder->purchasingOrder?->po_number,
                'buyerCode' => $workOrder->purchasingOrder?->buyer?->buyer_code,
                'itemsCount' => $workOrder->purchasingOrder?->details->count(),
            ],
            'confirmations' => $confirmations,
        ], 'Work order retrieved successfully');
    }
}
