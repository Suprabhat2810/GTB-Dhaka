<?php
require 'new_api_deploy/config.php';

$pdo = getPDO();

// Check payment status
$stmt = $pdo->prepare('SELECT id, student_id, payment_received, amount FROM payments WHERE student_id = 8');
$stmt->execute();
$payment = $stmt->fetch(PDO::FETCH_ASSOC);

echo "=== PAYMENT STATUS ===\n";
echo json_encode($payment, JSON_PRETTY_PRINT) . "\n\n";

// Check personal_info lock status
$stmt = $pdo->prepare('SELECT id, student_id, lock_form_student FROM personal_info WHERE student_id = 8');
$stmt->execute();
$personalInfo = $stmt->fetch(PDO::FETCH_ASSOC);

echo "=== PERSONAL INFO ===\n";
echo json_encode($personalInfo, JSON_PRETTY_PRINT) . "\n\n";

// Check student final_registration_number
$stmt = $pdo->prepare('SELECT id, name, final_registration_number FROM students WHERE id = 8');
$stmt->execute();
$student = $stmt->fetch(PDO::FETCH_ASSOC);

echo "=== STUDENT INFO ===\n";
echo json_encode($student, JSON_PRETTY_PRINT) . "\n\n";

// Check approval status
$stmt = $pdo->prepare('SELECT student_id, approved FROM approvals WHERE student_id = 8');
$stmt->execute();
$approval = $stmt->fetch(PDO::FETCH_ASSOC);

echo "=== APPROVAL STATUS ===\n";
echo json_encode($approval, JSON_PRETTY_PRINT) . "\n";
?>
