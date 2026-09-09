<?php

namespace App\Support;

use Psr\Http\Message\ResponseInterface as Response;

class ResponseHelper
{
    public static function success(Response $response, mixed $data = null, string $message = 'Success', int $statusCode = 200, ?array $meta = null): Response
    {
        $payload = [
            'status' => 'success',
            'message' => $message,
        ];

        if ($data !== null) {
            $payload['data'] = $data;
        }

        if ($meta !== null) {
            $payload['meta'] = $meta;
        }

        $response->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($statusCode);
    }

    public static function error(Response $response, string $message = 'Error', mixed $errors = null, int $statusCode = 400): Response
    {
        $payload = [
            'status' => 'error',
            'message' => $message,
        ];

        if ($errors !== null) {
            $payload['errors'] = $errors;
        }

        $response->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($statusCode);
    }
}
