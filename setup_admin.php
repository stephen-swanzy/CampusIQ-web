<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/security.php';

$db = getDb();

$name = 'Stephen Swanzy';
$email = 'swanzyak47@gmail.com';
$password = '0557560806ak';
$password_hash = campusPasswordHash($password);
$role = 'super_admin';
$phone = '+233 557 560806';

try {
    // Delete if exists
    $db->prepare('DELETE FROM users WHERE email = :email')->execute(['email' => $email]);
    
    // Insert fresh record with correctly hashed password
    $stmt = $db->prepare('INSERT INTO users (name, email, password_hash, role, phone, status) VALUES (:name, :email, :password_hash, :role, :phone, "active")');
    $stmt->execute([
        'name' => $name,
        'email' => $email,
        'password_hash' => $password_hash,
        'role' => $role,
        'phone' => $phone,
    ]);
    
    echo "<h2 style='color: green;'>✓ Admin Account Created!</h2>";
    echo "<div style='padding: 20px; background-color: #e8f5e9; border-left: 4px solid green;'>";
    echo "<p><strong>Email:</strong> " . htmlspecialchars($email) . "</p>";
    echo "<p><strong>Password:</strong> " . htmlspecialchars($password) . "</p>";
    echo "<p><strong>Role:</strong> Super Admin</p>";
    echo "<p><strong>Account Status:</strong> Active</p>";
    echo "</div>";
    echo "<p style='margin-top: 30px;'><a href='login.php' style='background-color: #0066cc; color: white; padding: 10px 20px; text-decoration: none;'>Go to Login</a></p>";
    
} catch (Exception $e) {
    echo "<h2 style='color: red;'>Error: " . htmlspecialchars($e->getMessage()) . "</h2>";
}
?>
