<?php
/**
 * ==========================================
 * ADMIN TEST & DEBUG FILE
 * هذا الملف لفحص جدول الأدمن والباسورد
 * احذف هذا الملف بعد الانتهاء من الفحص!
 * ==========================================
 */

define('APP_ACCESS', true);
require_once 'config.php';

echo "<h1 style='font-family: Arial; direction: rtl;'>🔍 فحص إعدادات الأدمن</h1>";
echo "<div style='font-family: Arial; direction: rtl; line-height: 2;'>";

// Test 1: Check if admin_users table exists
echo "<h3>1️⃣ فحص وجود جدول admin_users:</h3>";
try {
    $stmt = $pdo->query("SHOW TABLES LIKE 'admin_users'");
    $table_exists = $stmt->rowCount() > 0;
    
    if ($table_exists) {
        echo "✅ <span style='color: green;'>جدول admin_users موجود</span><br>";
    } else {
        echo "❌ <span style='color: red;'>جدول admin_users غير موجود - يرجى استيراد admin_setup.sql</span><br>";
        exit;
    }
} catch (PDOException $e) {
    echo "❌ خطأ: " . $e->getMessage() . "<br>";
    exit;
}

// Test 2: Check admin records
echo "<h3>2️⃣ فحص سجلات الأدمن:</h3>";
try {
    $stmt = $pdo->query("SELECT id, username, email, role, is_active FROM admin_users");
    $admins = $stmt->fetchAll();
    
    if (count($admins) > 0) {
        echo "✅ <span style='color: green;'>وجد " . count($admins) . " حساب أدمن</span><br>";
        echo "<table border='1' cellpadding='10' style='margin-top: 10px; border-collapse: collapse;'>";
        echo "<tr><th>ID</th><th>Username</th><th>Email</th><th>Role</th><th>Active</th></tr>";
        
        foreach ($admins as $admin) {
            $active_status = $admin['is_active'] ? '✅ نشط' : '❌ معطل';
            echo "<tr>";
            echo "<td>{$admin['id']}</td>";
            echo "<td><strong>{$admin['username']}</strong></td>";
            echo "<td>{$admin['email']}</td>";
            echo "<td>{$admin['role']}</td>";
            echo "<td>{$active_status}</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "⚠️ <span style='color: orange;'>لا توجد حسابات أدمن - يرجى استيراد admin_setup.sql</span><br>";
    }
} catch (PDOException $e) {
    echo "❌ خطأ: " . $e->getMessage() . "<br>";
}

// Test 3: Check admin password hash
echo "<h3>3️⃣ فحص الباسورد للأدمن الافتراضي:</h3>";
try {
    $stmt = $pdo->prepare("SELECT username, password FROM admin_users WHERE username = 'admin'");
    $stmt->execute();
    $admin = $stmt->fetch();
    
    if ($admin) {
        echo "✅ وجد مستخدم: <strong>{$admin['username']}</strong><br>";
        echo "📌 Password Hash: <code style='background: #f0f0f0; padding: 5px; display: block; margin: 10px 0; word-break: break-all;'>{$admin['password']}</code>";
        
        // Test password verification
        $test_password = 'Admin@123456';
        $is_valid = password_verify($test_password, $admin['password']);
        
        if ($is_valid) {
            echo "✅ <span style='color: green; font-weight: bold;'>الباسورد صحيح! يمكنك تسجيل الدخول بـ: Admin@123456</span><br>";
        } else {
            echo "❌ <span style='color: red; font-weight: bold;'>الباسورد غير صحيح! محتاج تحديث</span><br>";
            
            // Generate new hash
            $new_hash = password_hash($test_password, PASSWORD_BCRYPT);
            echo "<br><strong>🔧 الحل: نفذ هذا الكود SQL:</strong><br>";
            echo "<textarea readonly style='width: 100%; height: 100px; margin: 10px 0; padding: 10px; font-family: monospace;'>";
            echo "UPDATE admin_users SET password = '$new_hash' WHERE username = 'admin';";
            echo "</textarea>";
            echo "<button onclick=\"copyToClipboard()\">📋 نسخ الكود</button>";
        }
    } else {
        echo "❌ <span style='color: red;'>لم يتم العثور على مستخدم 'admin'</span><br>";
    }
} catch (PDOException $e) {
    echo "❌ خطأ: " . $e->getMessage() . "<br>";
}

// Test 4: Check functions.php
echo "<h3>4️⃣ فحص الدوال المطلوبة:</h3>";
$required_functions = ['require_admin_login', 'get_admin_data', 'log_admin_activity'];
$missing_functions = [];

foreach ($required_functions as $func) {
    if (function_exists($func)) {
        echo "✅ الدالة <code>{$func}()</code> موجودة<br>";
    } else {
        echo "❌ الدالة <code>{$func}()</code> غير موجودة<br>";
        $missing_functions[] = $func;
    }
}

if (!empty($missing_functions)) {
    echo "<br>⚠️ <strong>يرجى تحديث ملف functions.php</strong><br>";
}

// Test 5: Create new admin with correct password
echo "<h3>5️⃣ إنشاء/تحديث حساب الأدمن:</h3>";
echo "<form method='post' style='background: #f9f9f9; padding: 20px; border-radius: 5px; margin: 10px 0;'>";
echo "<label>اسم المستخدم:</label><br>";
echo "<input type='text' name='create_username' value='admin' style='padding: 8px; margin: 5px 0; width: 300px;'><br>";

echo "<label>كلمة المرور:</label><br>";
echo "<input type='text' name='create_password' value='Admin@123456' style='padding: 8px; margin: 5px 0; width: 300px;'><br>";

echo "<label>البريد الإلكتروني:</label><br>";
echo "<input type='email' name='create_email' value='admin@platform.com' style='padding: 8px; margin: 5px 0; width: 300px;'><br>";

echo "<button type='submit' name='create_admin' style='padding: 10px 20px; background: #28a745; color: white; border: none; border-radius: 5px; cursor: pointer; margin-top: 10px;'>🔧 إنشاء/تحديث الحساب</button>";
echo "</form>";

// Handle form submission
if (isset($_POST['create_admin'])) {
    $username = $_POST['create_username'];
    $password = $_POST['create_password'];
    $email = $_POST['create_email'];
    $hashed = password_hash($password, PASSWORD_BCRYPT);
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO admin_users (username, email, password, full_name, role, is_active)
            VALUES (?, ?, ?, 'Super Administrator', 'super_admin', 1)
            ON DUPLICATE KEY UPDATE 
                password = ?,
                email = ?,
                is_active = 1
        ");
        $stmt->execute([$username, $email, $hashed, $hashed, $email]);
        
        echo "<div style='background: #d4edda; color: #155724; padding: 15px; border-radius: 5px; margin: 10px 0;'>";
        echo "✅ <strong>تم إنشاء/تحديث الحساب بنجاح!</strong><br>";
        echo "Username: <strong>$username</strong><br>";
        echo "Password: <strong>$password</strong><br>";
        echo "<br><a href='admin/login.php' style='background: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block; margin-top: 10px;'>🔐 اذهب لصفحة تسجيل الدخول</a>";
        echo "</div>";
    } catch (PDOException $e) {
        echo "<div style='background: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px; margin: 10px 0;'>";
        echo "❌ خطأ: " . $e->getMessage();
        echo "</div>";
    }
}

echo "</div>";

echo "<hr style='margin: 30px 0;'>";
echo "<p style='color: red; font-weight: bold; font-family: Arial;'>⚠️ تحذير: احذف هذا الملف (test_admin.php) بعد الانتهاء من الإصلاح!</p>";
?>

<script>
function copyToClipboard() {
    const textarea = document.querySelector('textarea');
    textarea.select();
    document.execCommand('copy');
    alert('✅ تم نسخ الكود SQL!');
}
</script>

<style>
body {
    max-width: 900px;
    margin: 0 auto;
    padding: 20px;
    font-family: Arial, sans-serif;
}
code {
    background: #f0f0f0;
    padding: 2px 5px;
    border-radius: 3px;
}
</style>
