<?php

namespace App\Core;

class Request {
    private array $jsonBody = [];
    private array $queryParams = [];
    private array $headers = [];
    private ?array $user = null;

    public function __construct() {
        $this->queryParams = $_GET;
        $this->headers = getallheaders() ?: [];

        $rawInput = file_get_contents('php://input');
        if (!empty($rawInput)) {
            $decoded = json_decode($rawInput, true);
            if (is_array($decoded)) {
                $this->jsonBody = $decoded;
            }
        }
    }

    public function getMethod(): string {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public function getPath(): string {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH);
        return '/' . trim($path, '/');
    }

    public function input(string $key, mixed $default = null): mixed {
        return $this->jsonBody[$key] ?? $_POST[$key] ?? $this->queryParams[$key] ?? $default;
    }

    public function all(): array {
        return array_merge($this->queryParams, $_POST, $this->jsonBody);
    }

    public function getBearerToken(): ?string {
        $authHeader = $this->getHeader('Authorization') 
            ?? $this->getHeader('authorization')
            ?? $_SERVER['HTTP_AUTHORIZATION'] 
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] 
            ?? null;

        if ($authHeader && preg_match('/Bearer\s+(\S+)/i', $authHeader, $matches)) {
            return $matches[1];
        }

        if ($this->input('token')) {
            return (string)$this->input('token');
        }

        return null;
    }

    public function getHeader(string $name): ?string {
        foreach ($this->headers as $key => $val) {
            if (strcasecmp($key, $name) === 0) {
                return $val;
            }
        }
        return null;
    }

    public function setUser(array $user): void {
        $this->user = $user;
    }

    public function getUser(): ?array {
        return $this->user;
    }
}
