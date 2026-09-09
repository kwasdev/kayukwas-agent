<?php

namespace App\Controllers;

use App\Models\User;
use App\Support\LoggerHelper;
use App\Support\ResponseHelper;
use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AuthController
{
    public function login(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $email = trim($body['email'] ?? '');
        $password = $body['password'] ?? '';

        if (empty($email) || empty($password)) {
            return ResponseHelper::error($response, 'Email and password are required.', null, 422);
        }

        $user = User::where('email', $email)->first();

        if (!$user || !password_verify($password, $user->password)) {
            return ResponseHelper::error($response, 'Invalid credentials.', null, 401);
        }

        if (!$user->is_active) {
            return ResponseHelper::error($response, 'Account is inactive. Contact administrator.', null, 403);
        }

        // Generate plain random token
        $plainToken = bin2hex(random_bytes(32));
        $hashedToken = hash('sha256', $plainToken);

        Capsule::table('personal_access_tokens')->insert([
            'tokenable_type' => 'App\\Models\\User',
            'tokenable_id'   => $user->id,
            'name'           => 'marketing-api-token',
            'token'          => $hashedToken,
            'abilities'      => json_encode(['*']),
            'created_at'     => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);

        LoggerHelper::info("User {$user->email} logged in successfully via API.");

        return ResponseHelper::success($response, [
            'token' => $plainToken,
            'tokenType' => 'Bearer',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phoneNumber' => $user->phone_number,
                'isSuperuser' => $user->isSuperuser(),
            ],
        ], 'Login successful');
    }

    public function logout(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        if ($user) {
            Capsule::table('personal_access_tokens')
                ->where('tokenable_id', $user->id)
                ->delete();
            LoggerHelper::info("User {$user->email} logged out via API.");
        }

        return ResponseHelper::success($response, null, 'Logged out successfully');
    }

    public function me(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        if (!$user) {
            return ResponseHelper::error($response, 'Unauthenticated', null, 401);
        }

        $user->load(['userRoles.permissions']);
        $permissions = [];
        foreach ($user->userRoles as $role) {
            foreach ($role->permissions as $perm) {
                $permissions[] = $perm->name;
            }
        }

        return ResponseHelper::success($response, [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phoneNumber' => $user->phone_number,
            'isSuperuser' => $user->isSuperuser(),
            'roles' => $user->userRoles->pluck('name')->toArray(),
            'permissions' => array_values(array_unique($permissions)),
        ], 'Profile retrieved successfully');
    }
}
