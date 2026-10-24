<?php

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;

class AdminMiddleware {
    public function handle(Request $request): void {
        $user = $request->getUser();

        if (!$user || !isset($user['role_id']) || (int)$user['role_id'] !== 3) {
            Response::forbidden('Access denied. Administrator privileges required.');
        }
    }
}
