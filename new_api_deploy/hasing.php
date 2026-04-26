<?php

$users = [
    ["name" => "Dr. Sandeep Kataria", "email" => "katariasandeep_1010@gmail.com", "password" => "Sandeep123"],
    ["name" => "Ranvir Kumar", "email" => "ranvirkataria786@gmail.com", "password" => "Ranvir123"],
    ["name" => "Raman Kumar", "email" => "ramansingla.kumar86@gmail.com", "password" => "Raman123"],
    ["name" => "Charanjit Kaur", "email" => "charanjitkaur478@gmail.com", "password" => "Charanjit123"],
    ["name" => "Komaldeep Kaur", "email" => "komaldeep1984kaur@gmail.com", "password" => "Komaldeep123"],
    ["name" => "Amanpreet Kaur", "email" => "amanpreetk1217@gmail.com", "password" => "Amanpreet123"],
];

foreach ($users as $user) {
    $hash = password_hash($user['password'], PASSWORD_BCRYPT);
    echo "Name: {$user['name']}\n";
    echo "Email: {$user['email']}\n";
    echo "Hash: $hash\n\n";
}