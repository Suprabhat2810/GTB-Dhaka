<?php
require_once 'config.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

function authenticate($requiredRole = null) {
    // Try to get the Authorization header from $_SERVER
    $token = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

    // If not found, try getallheaders()
    if (empty($token) && function_exists('getallheaders')) {
        $headers = getallheaders();
        $token = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }

    if (!$token || !str_starts_with($token, 'Bearer ')) {
        jsonResponse("error", "Authentication token required.", [], 401);
    }

    $token = str_replace('Bearer ', '', $token);
    try {
        $decoded = JWT::decode($token, new Key(JWT_SECRET, 'HS256'));
        if ($requiredRole && $decoded->role !== $requiredRole) {
            jsonResponse("error", "Unauthorized access.", [], 403);
        }
        return $decoded;
    } catch (Exception $e) {
        jsonResponse("error", "Invalid token: " . $e->getMessage(), [], 401);
    }
}