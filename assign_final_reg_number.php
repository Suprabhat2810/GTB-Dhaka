<?php
// Quick script to manually assign final registration number for student_id 8
require 'new_api_deploy/config.php';

$pdo = getPDO();
$student_id = 8;

try {
    // Check current status
    $stmt = $pdo->prepare("SELECT id, name, final_registration_number FROM students WHERE id = ?");
    $stmt->execute([$student_id]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$student) {
        die("Student not found!\n");
    }
    
    echo "Student: {$student['name']}\n";
    echo "Current Final Reg Number: " . ($student['final_registration_number'] ?: 'NULL') . "\n\n";
    
    if (!empty($student['final_registration_number'])) {
        echo "Final registration number already assigned!\n";
        exit(0);
    }
    
    // Generate final registration number
    $serial = str_pad((string)$student_id, 2, "0", STR_PAD_LEFT);
    $month = date('m');
    $year = date('y');
    $finalRegistrationNumber = "GTB{$serial}{$month}{$year}";
    
    echo "Assigning: $finalRegistrationNumber\n";
    
    // Update student
    $stmt = $pdo->prepare("UPDATE students SET final_registration_number = ? WHERE id = ?");
    $stmt->execute([$finalRegistrationNumber, $student_id]);
    
    echo "✅ Successfully assigned final registration number: $finalRegistrationNumber\n";
    
    // Verify
    $stmt = $pdo->prepare("SELECT final_registration_number FROM students WHERE id = ?");
    $stmt->execute([$student_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "Verified: " . $result['final_registration_number'] . "\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
?>
