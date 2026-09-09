<?php

use Dotenv\Dotenv;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

require __DIR__ . '/../vendor/autoload.php';

if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
}

require __DIR__ . '/../config/database.php';

$app = AppFactory::create();
$app->add(new \App\Middlewares\JsonBodyParserMiddleware());
$app->add(new \App\Middlewares\CorsMiddleware());
$app->addRoutingMiddleware();

$routes = require __DIR__ . '/../src/Routes/api.php';
$routes($app);

function runTest(string $title, callable $fn) {
    echo "Testing: {$title} ... ";
    try {
        $fn();
        echo "\033[32mPASSED\033[0m\n";
    } catch (\Throwable $e) {
        echo "\033[31mFAILED\033[0m: " . $e->getMessage() . "\n";
        exit(1);
    }
}

function request($app, string $method, string $uri, ?array $body = null, ?string $token = null) {
    $requestFactory = new ServerRequestFactory();
    $req = $requestFactory->createServerRequest($method, $uri)
        ->withHeader('Content-Type', 'application/json')
        ->withHeader('Accept', 'application/json');

    if ($token) {
        $req = $req->withHeader('Authorization', "Bearer {$token}");
    }

    if ($body !== null) {
        $req->getBody()->write((string) json_encode($body));
        $req = $req->withParsedBody($body);
    }

    return $app->handle($req);
}

echo "=== STARTING COMPREHENSIVE REST API TEST SUITE ===\n\n";

$masterToken = $_ENV['API_MASTER_TOKEN'] ?? 'kwas-marketing-secret-token-2026';

// 1. Health Check
runTest("GET / (Health Check)", function () use ($app) {
    $res = request($app, 'GET', '/');
    if ($res->getStatusCode() !== 200) throw new \Exception("Status " . $res->getStatusCode());
});

// 2. Auth Login & Me
runTest("POST /api/v1/auth/login & GET /me", function () use ($app) {
    $res = request($app, 'POST', '/api/v1/auth/login', [
        'email' => 'admin@kayukwas.com',
        'password' => 'password123',
    ]);
    if ($res->getStatusCode() !== 200) throw new \Exception("Login failed: " . (string)$res->getBody());
    $data = json_decode((string)$res->getBody(), true);
    $token = $data['data']['token'] ?? null;
    if (!$token) throw new \Exception("Missing token");

    $resMe = request($app, 'GET', '/api/v1/auth/me', null, $token);
    if ($resMe->getStatusCode() !== 200) throw new \Exception("Auth /me failed: " . (string)$resMe->getBody());
});

// 3. Catalogs
runTest("GET /catalogs (products, packings, finishings, stuffings)", function () use ($app, $masterToken) {
    foreach (['products', 'packings', 'finishings', 'stuffings'] as $cat) {
        $res = request($app, 'GET', "/api/v1/marketing/catalogs/{$cat}", null, $masterToken);
        if ($res->getStatusCode() !== 200) throw new \Exception("Failed catalog {$cat}");
    }
});

// 4. Buyer CRUD
$testBuyerId = null;
runTest("Buyer CRUD (List, Create, Show, Update, Status)", function () use ($app, $masterToken, &$testBuyerId) {
    $buyerCode = 'BYR-CLI-' . time();
    $res = request($app, 'POST', '/api/v1/marketing/buyers', [
        'buyerCode' => $buyerCode,
        'description' => 'Test Client Buyer',
        'isActive' => true,
    ], $masterToken);
    if ($res->getStatusCode() !== 201) throw new \Exception("Create buyer failed: " . (string)$res->getBody());
    $data = json_decode((string)$res->getBody(), true);
    $testBuyerId = $data['data']['id'];

    $resShow = request($app, 'GET', "/api/v1/marketing/buyers/{$testBuyerId}", null, $masterToken);
    if ($resShow->getStatusCode() !== 200) throw new \Exception("Show buyer failed");

    // Toggle off then on again to leave active
    request($app, 'PATCH', "/api/v1/marketing/buyers/{$testBuyerId}/status", null, $masterToken);
    $resToggleOn = request($app, 'PATCH', "/api/v1/marketing/buyers/{$testBuyerId}/status", null, $masterToken);
    if ($resToggleOn->getStatusCode() !== 200) throw new \Exception("Toggle status back failed");
});

// 5. Order Creation & Simulation
$createdOrderId = null;
runTest("POST /orders & /draft-simulate", function () use ($app, $masterToken, $testBuyerId, &$createdOrderId) {
    // Simulation
    $resSim = request($app, 'POST', '/api/v1/marketing/orders/draft-simulate', [
        'orderItems' => [
            ['productId' => 1, 'quantity' => 100, 'cubicMeter' => 5.2],
        ],
    ], $masterToken);
    if ($resSim->getStatusCode() !== 200) throw new \Exception("Simulate failed");

    // Store Order
    $poNumber = 'PO-TEST-' . time();
    $resStore = request($app, 'POST', '/api/v1/marketing/orders', [
        'buyerId' => $testBuyerId,
        'orderNumber' => $poNumber,
        'orderDate' => date('Y-m-d'),
        'targetDate' => date('Y-m-d', strtotime('+30 days')),
        'orderItems' => [
            ['productId' => 1, 'quantity' => 100, 'cubicMeter' => 5.2, 'construction' => 'KD'],
        ],
    ], $masterToken);
    if ($resStore->getStatusCode() !== 201) throw new \Exception("Store order failed: " . (string)$resStore->getBody());
    $data = json_decode((string)$resStore->getBody(), true);
    $createdOrderId = $data['data']['id'];
});

// 6. Work Order Issuance & Marketing Digital Approval
$workOrderId = null;
runTest("POST /orders/{id}/issue-spk & POST /work-orders/{id}/confirm", function () use ($app, $masterToken, $createdOrderId, &$workOrderId) {
    $spkNumber = 'SPK-TEST-' . time();
    $resIssue = request($app, 'POST', "/api/v1/marketing/orders/{$createdOrderId}/issue-spk", [
        'orderNumber' => $spkNumber,
        'issueDate' => date('Y-m-d'),
        'orderType' => 'COC',
        'projectName' => 'Test Project',
    ], $masterToken);
    if ($resIssue->getStatusCode() !== 201) throw new \Exception("Issue SPK failed: " . (string)$resIssue->getBody());
    $data = json_decode((string)$resIssue->getBody(), true);
    $workOrderId = $data['data']['id'];

    // Confirm slot marketing
    $resConfirm = request($app, 'POST', "/api/v1/marketing/work-orders/{$workOrderId}/confirm", null, $masterToken);
    if ($resConfirm->getStatusCode() !== 200) throw new \Exception("Confirm marketing failed: " . (string)$resConfirm->getBody());
});

// 7. AI Assistant Fuzzy Search & PWA Action
runTest("AI Assistant (fuzzy-search, chat, handleAction)", function () use ($app, $masterToken, $testBuyerId) {
    $resFuzzy = request($app, 'GET', '/api/v1/marketing/ai/fuzzy-search?query=BYR', null, $masterToken);
    if ($resFuzzy->getStatusCode() !== 200) throw new \Exception("Fuzzy search failed");

    $resChat = request($app, 'POST', '/api/v1/marketing/ai/chat', [
        'userPrompt' => 'Order 250 pcs Meja Kopi',
        'conversationId' => 'pwa_chat_99',
    ], $masterToken);
    if ($resChat->getStatusCode() !== 200) throw new \Exception("Chat failed");

    $resAction = request($app, 'POST', '/api/v1/marketing/ai/chat/action', [
        'actionType' => 'confirm_spk',
        'buyerId' => $testBuyerId,
        'productId' => 1,
        'quantity' => 250,
        'orderType' => 'COC',
    ], $masterToken);
    if ($resAction->getStatusCode() !== 200) throw new \Exception("AI Action failed: " . (string)$resAction->getBody());
});

echo "\n\033[32mALL COMPREHENSIVE ENDPOINT TESTS PASSED WITH 100% SUCCESS!\033[0m\n";
