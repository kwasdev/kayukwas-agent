<?php

namespace App\Middlewares;

use App\Models\User;
use App\Support\ResponseHelper;
use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Response as SlimResponse;

class AuthMiddleware implements MiddlewareInterface
{
    protected ?string $requiredPermission;

    public function __construct(?string $requiredPermission = null)
    {
        $this->requiredPermission = $requiredPermission;
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        $authHeader = $request->getHeaderLine('Authorization');
        $apiKey = $request->getHeaderLine('X-API-Key');
        $token = null;

        if (preg_match('/Bearer\s+(\S+)/i', $authHeader, $matches)) {
            $token = $matches[1];
        }

        $masterToken = $_ENV['API_MASTER_TOKEN'] ?? null;

        // 1. Check Master Token (for AI Agent / Machine-to-Machine)
        if (($token && $masterToken && $token === $masterToken) || ($apiKey && $masterToken && $apiKey === $masterToken)) {
            // Find first active superuser or marketing user
            $user = User::where('is_active', true)->first();
            if ($user) {
                $request = $request->withAttribute('user', $user);
                return $this->checkPermissionAndProceed($request, $handler, $user);
            }
        }

        // 2. Check Personal Access Token from Database
        if ($token) {
            $hashedToken = hash('sha256', $token);
            $tokenRecord = Capsule::table('personal_access_tokens')
                ->where('token', $hashedToken)
                ->orWhere('token', $token)
                ->first();

            if ($tokenRecord) {
                $user = User::find($tokenRecord->tokenable_id);
                if ($user && $user->is_active) {
                    $request = $request->withAttribute('user', $user);
                    return $this->checkPermissionAndProceed($request, $handler, $user);
                }
            }
        }

        $response = new SlimResponse();
        return ResponseHelper::error($response, 'Unauthorized access: Valid Bearer token required.', null, 401);
    }

    private function checkPermissionAndProceed(Request $request, RequestHandler $handler, User $user): Response
    {
        if ($this->requiredPermission && !$user->hasPermission($this->requiredPermission)) {
            $response = new SlimResponse();
            return ResponseHelper::error($response, "Forbidden: Missing permission '{$this->requiredPermission}'.", null, 403);
        }

        return $handler->handle($request);
    }
}
