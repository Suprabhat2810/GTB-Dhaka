<?php
require_once '../config.php';
require_once '../vendor/autoload.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    $data = json_decode(file_get_contents("php://input"), true);
    $email = filter_var($data['email'] ?? '', FILTER_SANITIZE_EMAIL);
    $password = $data['password'] ?? '';
    $role = $data['role'] ?? '';

    if (!$email || !$password || !$role) {
        jsonResponse("error", "Email, password, and role are required.", [], 400);
    }

    if (!in_array($role, ['admin', 'student'])) {
        jsonResponse("error", "Invalid role.", [], 400);
    }

    try {
        // Determine the table based on role
        $table = $role === 'admin' ? 'admins' : 'students';
        $stmt = $pdo->prepare("SELECT * FROM $table WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password'])) {
            jsonResponse("error", "Invalid credentials.", [], 401);
        }

        // Generate a simple JWT token (for demonstration; use a proper JWT library in production)
        $token = base64_encode(json_encode([
            'id' => $user['id'],
            'email' => $user['email'],
            'role' => $role,
            'exp' => time() + (60 * 60 * 24) // Token expires in 24 hours
        ]));

        jsonResponse("success", "Login successful.", [
            'token' => $token,
            'role' => $role
        ], 200);
    } catch (PDOException $e) {
        $log->error("Login failed: " . $e->getMessage());
        jsonResponse("error", "Login failed.", [], 500);
    }
} else {
    jsonResponse("error", "Method not allowed.", [], 405);
}