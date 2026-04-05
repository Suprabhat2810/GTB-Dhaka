<?php
require 'new_api_deploy/config.php';

$pdo = getPDO();

// Get all students
$stmt = $pdo->prepare('SELECT id, name, email, program, temporary_serial_number, final_registration_number FROM students ORDER BY id DESC LIMIT 10');
$stmt->execute();
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "=== ALL STUDENTS (Last 10) ===\n";
foreach ($students as $student) {
    echo "ID: {$student['id']} | Name: {$student['name']} | Email: {$student['email']} | Temp Serial: {$student['temporary_serial_number']} | Final Reg: " . ($student['final_registration_number'] ?: 'Not Assigned') . "\n";
}

// Check which student is logged in (from the screenshot - email: admin@inventory.com, name: SuprabhatTechDev)
echo "\n=== CHECKING STUDENT: SuprabhatTechDev ===\n";
$stmt = $pdo->prepare('SELECT id, name, email, temporary_serial_number, final_registration_number FROM students WHERE email = ?');
$stmt->execute(['admin@inventory.com']);
$student = $stmt->fetch(PDO::FETCH_ASSOC);
if ($student) {
    echo "Found student:\n";
    echo json_encode($student, JSON_PRETTY_PRINT) . "\n";
    
    // Check their payment and personal_info
    $stmt = $pdo->prepare('SELECT id, payment_received, amount FROM payments WHERE student_id = ?');
    $stmt->execute([$student['id']]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "\nPayment Status: " . ($payment ? ($payment['payment_received'] ? 'RECEIVED' : 'NOT RECEIVED') . " (Amount: " . ($payment['amount'] ?? 'N/A') . ")" : 'NO RECORD') . "\n";
    
    $stmt = $pdo->prepare('SELECT id, lock_form_student FROM personal_info WHERE student_id = ?');
    $stmt->execute([$student['id']]);
    $info = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "Form Locked: " . ($info ? ($info['lock_form_student'] ? 'YES' : 'NO') : 'NO RECORD') . "\n";
    
    // Check approval
    $stmt = $pdo->prepare('SELECT approved FROM approvals WHERE student_id = ?');
    $stmt->execute([$student['id']]);
    $approval = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "Approved: " . ($approval ? ($approval['approved'] ? 'YES' : 'NO') : 'NO RECORD') . "\n";
} else {
    echo "No student found with email: admin@inventory.com\n";
}
?>
