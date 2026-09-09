<?php

use App\Controllers\AiAssistantController;
use App\Controllers\AuthController;
use App\Controllers\BuyerController;
use App\Controllers\CatalogController;
use App\Controllers\OrderController;
use App\Controllers\WorkOrderController;
use App\Middlewares\AuthMiddleware;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return function (App $app) {
    // Health check endpoint
    $app->get('/', function ($request, $response) {
        return \App\Support\ResponseHelper::success($response, [
            'service' => 'KWaS Marketing REST API',
            'version' => '1.0.0',
            'status' => 'healthy',
            'timestamp' => date('Y-m-d H:i:s'),
        ], 'KWaS Marketing REST API is running');
    });

    // API V1 Group
    $app->group('/api/v1', function (RouteCollectorProxy $v1) {

        // Authentication (Public)
        $v1->post('/auth/login', [AuthController::class, 'login']);

        // Authenticated Auth Endpoints
        $v1->group('/auth', function (RouteCollectorProxy $auth) {
            $auth->post('/logout', [AuthController::class, 'logout']);
            $auth->get('/me', [AuthController::class, 'me']);
        })->add(new AuthMiddleware());

        // Marketing Module Group (Protected)
        $v1->group('/marketing', function (RouteCollectorProxy $marketing) {

            // Master Catalogs (Read-Only / Reference)
            $marketing->get('/catalogs/products', [CatalogController::class, 'products']);
            $marketing->get('/catalogs/packings', [CatalogController::class, 'packings']);
            $marketing->get('/catalogs/finishings', [CatalogController::class, 'finishings']);
            $marketing->get('/catalogs/stuffings', [CatalogController::class, 'stuffings']);

            // Buyer Management
            $marketing->get('/buyers', [BuyerController::class, 'index']);
            $marketing->post('/buyers', [BuyerController::class, 'store']);
            $marketing->get('/buyers/{id}', [BuyerController::class, 'show']);
            $marketing->put('/buyers/{id}', [BuyerController::class, 'update']);
            $marketing->patch('/buyers/{id}/status', [BuyerController::class, 'toggleStatus']);

            // Purchasing Orders (PO)
            $marketing->get('/orders', [OrderController::class, 'index']);
            $marketing->post('/orders', [OrderController::class, 'store']);
            $marketing->post('/orders/draft-simulate', [OrderController::class, 'simulate']);
            $marketing->get('/orders/{id}', [OrderController::class, 'show']);
            $marketing->put('/orders/{id}', [OrderController::class, 'update']);
            $marketing->delete('/orders/{id}', [OrderController::class, 'destroy']);

            // Work Orders (SPK) Marketing Flow
            $marketing->post('/orders/{id}/issue-spk', [WorkOrderController::class, 'issue']);
            $marketing->post('/work-orders/{id}/confirm', [WorkOrderController::class, 'confirmMarketing']);
            $marketing->get('/work-orders/{id}', [WorkOrderController::class, 'show']);

            // AI Assistant Endpoints (Dual-Channel: WhatsApp & PWA Chatbot)
            $marketing->get('/ai/fuzzy-search', [AiAssistantController::class, 'fuzzySearch']);
            $marketing->post('/ai/chat', [AiAssistantController::class, 'chat']);
            $marketing->post('/ai/chat/action', [AiAssistantController::class, 'handleAction']);

        })->add(new AuthMiddleware());
    });
};
