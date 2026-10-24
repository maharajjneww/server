<?php

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Core\JWT;

class AuthMiddleware {
    public function handle(Request $request): void {
        $token = $request->getBearerToken();

        if (!$token) {
            Response::unauthorized('Bearer authentication token is missing.');
        }

        $payload = JWT::verify($token);

        if (!$payload) {
            Response::unauthorized('Authentication token has expired or is invalid.');
        }

        $request->setUser($payload);
    }
}
