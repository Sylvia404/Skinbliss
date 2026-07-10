<?php
// ============================================================
//  DATABASE CONFIGURATION – EDIT THESE
// ============================================================
$host = 'localhost';
$dbname = 'skinbliss';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die('Database connection failed: ' . $e->getMessage());
}

// ============================================================
//  SESSION
// ============================================================
session_start();

// ============================================================
//  HELPER: upload images
// ============================================================
function uploadProductImage($file) {
    $targetDir = 'uploads/';
    if (!is_dir($targetDir)) {
        if (!mkdir($targetDir, 0755, true)) {
            return false;
        }
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    if (!in_array($ext, $allowed)) return false;

    $filename = 'product_' . uniqid() . '.' . $ext;
    $targetPath = $targetDir . $filename;
    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        return $filename;
    }
    return false;
}

function uploadSettingImage($file, $prefix) {
    $targetDir = 'uploads/';
    if (!is_dir($targetDir)) {
        if (!mkdir($targetDir, 0755, true)) {
            return false;
        }
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    if (!in_array($ext, $allowed)) return false;

    $filename = $prefix . '_' . uniqid() . '.' . $ext;
    $targetPath = $targetDir . $filename;
    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        return $filename;
    }
    return false;
}

// ============================================================
//  AJAX ROUTER
// ============================================================
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'];

    // ---------- Admin login ----------
    if ($action === 'admin_login') {
        $input = json_decode(file_get_contents('php://input'), true);
        $username = $input['username'] ?? '';
        $password = $input['password'] ?? '';

        $stmt = $pdo->prepare("SELECT * FROM admin_users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_user'] = $user['username'];
            echo json_encode(['success' => true]);
        } else {
            http_response_code(401);
            echo json_encode(['error' => 'Invalid credentials']);
        }
        exit;
    }

    // ---------- Check admin session ----------
    if ($action === 'admin_check') {
        $loggedIn = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
        echo json_encode(['logged_in' => $loggedIn, 'user' => $loggedIn ? $_SESSION['admin_user'] : null]);
        exit;
    }

    // ---------- Admin logout ----------
    if ($action === 'admin_logout') {
        session_destroy();
        echo json_encode(['success' => true]);
        exit;
    }

    // ---------- Public: get products ----------
    if ($action === 'get_products') {
        $stmt = $pdo->query("SELECT * FROM products ORDER BY id");
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($products);
        exit;
    }

    // ---------- Public: get settings ----------
    if ($action === 'get_settings') {
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
        $settings = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
        echo json_encode($settings);
        exit;
    }

    // ---------- Public: place order (FIXED) ----------
    if ($action === 'place_order') {
        try {
            $rawInput = file_get_contents('php://input');
            $data = json_decode($rawInput, true);
            if (!$data || !isset($data['customer']) || !isset($data['items'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid order data. Received: ' . $rawInput]);
                exit;
            }

            $customer = $data['customer'];
            $items = $data['items'];
            $total = (int)$data['total'];

            if (empty($customer['name']) || empty($customer['phone']) || empty($customer['region']) || empty($customer['address'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Missing customer details.']);
                exit;
            }

            $orderId = 'SB' . date('ymd') . substr(md5(uniqid()), 0, 4);

            $pdo->beginTransaction();

            $sql = "INSERT INTO orders (id, customer_name, phone, region, address, payment_method, total) VALUES (?,?,?,?,?,?,?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $orderId,
                $customer['name'],
                $customer['phone'],
                $customer['region'],
                $customer['address'],
                $customer['payment'],
                $total
            ]);

            $sql = "INSERT INTO order_items (order_id, product_id, quantity, price) VALUES (?,?,?,?)";
            $stmt = $pdo->prepare($sql);
            foreach ($items as $item) {
                $stmt->execute([$orderId, $item['id'], $item['qty'], $item['price']]);
            }

            $pdo->commit();
            echo json_encode(['success' => true, 'order_id' => $orderId]);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            http_response_code(500);
            echo json_encode(['error' => 'Order failed: ' . $e->getMessage()]);
        }
        exit;
    }

    // ---------- Admin‑only actions ----------
    if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }

    // ---------- Admin: save settings ----------
    if ($action === 'save_settings') {
        $founderImageFile = $_FILES['founder_image'] ?? null;
        $ig1File = $_FILES['instagram_image_1'] ?? null;
        $ig2File = $_FILES['instagram_image_2'] ?? null;
        $ig3File = $_FILES['instagram_image_3'] ?? null;
        $ig4File = $_FILES['instagram_image_4'] ?? null;

        function handleSettingImage($file, $key, $pdo) {
            if ($file && $file['error'] === UPLOAD_ERR_OK) {
                $filename = uploadSettingImage($file, $key);
                if ($filename) {
                    $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
                    $stmt->execute([$key, $filename, $filename]);
                    return $filename;
                }
            }
            return null;
        }

        handleSettingImage($founderImageFile, 'founder_image', $pdo);
        handleSettingImage($ig1File, 'instagram_image_1', $pdo);
        handleSettingImage($ig2File, 'instagram_image_2', $pdo);
        handleSettingImage($ig3File, 'instagram_image_3', $pdo);
        handleSettingImage($ig4File, 'instagram_image_4', $pdo);

        echo json_encode(['success' => true, 'message' => 'Settings updated']);
        exit;
    }

    // ---------- Admin: save product ----------
    if ($action === 'save_product') {
        $id = $_POST['id'] ?? '';
        $name = $_POST['name'] ?? '';
        $category = $_POST['category'] ?? '';
        $size = $_POST['size'] ?? '';
        $price = (int)($_POST['price'] ?? 0);
        $desc = $_POST['desc'] ?? '';
        $badge = $_POST['badge'] ?? '';
        $tint = $_POST['tint'] ?? '#F6D6DE';
        $imageFile = $_FILES['image'] ?? null;

        $imageName = null;
        if ($imageFile && $imageFile['error'] === UPLOAD_ERR_OK) {
            $uploaded = uploadProductImage($imageFile);
            if ($uploaded) {
                $imageName = $uploaded;
            } else {
                echo json_encode(['error' => 'Image upload failed. Check folder permissions and file type.']);
                exit;
            }
        }

        if ($id !== '' && !$imageName) {
            $stmt = $pdo->prepare("SELECT image FROM products WHERE id = ?");
            $stmt->execute([$id]);
            $current = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($current && $current['image']) {
                $imageName = $current['image'];
            }
        }

        if ($id !== '') {
            $sql = "UPDATE products SET name=?, category=?, size=?, price=?, `desc`=?, badge=?, tint=?, image=? WHERE id=?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$name, $category, $size, $price, $desc, $badge, $tint, $imageName, $id]);
            echo json_encode(['success' => true, 'message' => 'Product updated']);
        } else {
            $newId = 'p' . substr(md5(uniqid()), 0, 6);
            $sql = "INSERT INTO products (id, name, category, size, price, `desc`, badge, tint, image) VALUES (?,?,?,?,?,?,?,?,?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$newId, $name, $category, $size, $price, $desc, $badge, $tint, $imageName]);
            echo json_encode(['success' => true, 'message' => 'Product added', 'id' => $newId]);
        }
        exit;
    }

    // ---------- Admin: delete product ----------
    if ($action === 'delete_product') {
        $id = $_GET['id'] ?? null;
        if (!$id) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing ID']);
            exit;
        }
        $stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(['success' => true]);
        exit;
    }

    // ---------- Admin: get orders (with product names) ----------
    if ($action === 'get_orders') {
        $orders = [];
        $stmt = $pdo->query("
            SELECT o.*, 
                   GROUP_CONCAT(CONCAT(p.name, ' (', oi.quantity, 'x)') SEPARATOR ', ') as items_summary
            FROM orders o
            LEFT JOIN order_items oi ON o.id = oi.order_id
            LEFT JOIN products p ON oi.product_id = p.id
            GROUP BY o.id
            ORDER BY o.placed_at DESC
        ");
        while ($order = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $itemsStmt = $pdo->prepare("
                SELECT oi.product_id, oi.quantity, oi.price, p.name as product_name
                FROM order_items oi
                LEFT JOIN products p ON oi.product_id = p.id
                WHERE oi.order_id = ?
            ");
            $itemsStmt->execute([$order['id']]);
            $order['items'] = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
            $orders[] = $order;
        }
        echo json_encode($orders);
        exit;
    }

    // Unknown action
    http_response_code(400);
    echo json_encode(['error' => 'Unknown action']);
    exit;
}
// ============================================================
//  END AJAX ROUTER – OUTPUT HTML
// ============================================================
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Skinbliss Tanzania — Skin that feels like bliss</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,300;9..144,500;9..144,600;9..144,700&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
  <style>
    /* ============================================================
       ALL STYLES – Dark/light mode, responsive, admin overlay,
       product detail modal, etc.
       ============================================================ */
    :root {
      --cream: #FBF2EC;
      --cream-deep: #F4E4D8;
      --blush: #F6D6DE;
      --blush-soft: #FCEAEF;
      --blush-deep: #EFB4C8;
      --rose: #B23F66;
      --rose-dark: #8C2F50;
      --plum: #3A2432;
      --plum-soft: #6E4E5C;
      --gold: #C9A467;
      --sage: #95AC88;
      --lilac: #D9C7E8;
      --white: #FFFFFF;
      --shadow-soft: 0 20px 40px -22px rgba(58,36,50,0.28);
      --shadow-lift: 0 30px 60px -20px rgba(58,36,50,0.35);
      --radius-lg: 32px;
      --radius-md: 20px;
      --radius-sm: 14px;
      --font-display: 'Fraunces', serif;
      --font-body: 'Plus Jakarta Sans', sans-serif;
    }
    html[data-theme="dark"] {
      --cream: #20161E;
      --cream-deep: #291C26;
      --blush: #4A2E3C;
      --blush-soft: #33222E;
      --blush-deep: #6B4058;
      --rose: #F0A8C4;
      --rose-dark: #F7CADD;
      --plum: #F7ECF1;
      --plum-soft: #D6BEC9;
      --gold: #E3C285;
      --sage: #B7CBA8;
      --lilac: #E4D4F2;
      --white: #2B1D28;
      --shadow-soft: 0 20px 40px -20px rgba(0,0,0,0.55);
      --shadow-lift: 0 30px 60px -20px rgba(0,0,0,0.7);
    }
    * { box-sizing: border-box; }
    html { scroll-behavior: smooth; }
    body {
      margin: 0;
      background: var(--cream);
      color: var(--plum);
      font-family: var(--font-body);
      -webkit-font-smoothing: antialiased;
      overflow-x: hidden;
      transition: background .4s ease, color .4s ease;
      position: relative;
    }
    body::before {
      content: '';
      position: fixed;
      inset: 0;
      pointer-events: none;
      z-index: 1;
      opacity: .05;
      mix-blend-mode: overlay;
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='120' height='120'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='2' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E");
    }
    img, svg { display: block; max-width: 100%; }
    a { color: inherit; text-decoration: none; }
    button { font-family: inherit; cursor: pointer; border: none; background: none; }
    h1, h2, h3 { font-family: var(--font-display); margin: 0; color: var(--plum); }
    p { margin: 0; }
    .wrap { max-width: 1180px; margin: 0 auto; padding: 0 28px; position: relative; z-index: 2; }
    .eyebrow {
      font-size: 12.5px;
      letter-spacing: .14em;
      text-transform: uppercase;
      color: var(--rose);
      font-weight: 700;
    }
    .bow { display: inline-block; }
    .bow path { fill: currentColor; }

    .btn {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 15px 28px;
      border-radius: 999px;
      font-weight: 700;
      font-size: 15px;
      transition: transform .2s, box-shadow .2s;
    }
    .btn-primary { background: var(--rose); color: #fff; box-shadow: 0 16px 30px -12px rgba(178,63,102,0.55); }
    .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 20px 36px -12px rgba(178,63,102,0.6); }
    .btn-ghost { background: var(--white); color: var(--plum); box-shadow: var(--shadow-soft); }
    .btn-ghost:hover { transform: translateY(-2px); }

    .icon-btn {
      position: relative;
      width: 42px;
      height: 42px;
      border-radius: 50%;
      background: var(--white);
      display: flex;
      align-items: center;
      justify-content: center;
      box-shadow: var(--shadow-soft);
      color: var(--rose);
      transition: transform .2s, background .3s;
    }
    .icon-btn:hover { transform: translateY(-2px); }
    .icon-moon { display: none; }
    html[data-theme="dark"] .icon-sun { display: none; }
    html[data-theme="dark"] .icon-moon { display: block; }
    .cart-count {
      position: absolute;
      top: -4px;
      right: -4px;
      background: var(--rose);
      color: #fff;
      font-size: 11px;
      font-weight: 700;
      min-width: 18px;
      height: 18px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 0 3px;
    }
    .menu-toggle {
      display: none;
      width: 42px;
      height: 42px;
      border-radius: 50%;
      background: var(--white);
      align-items: center;
      justify-content: center;
      box-shadow: var(--shadow-soft);
    }
    .mobile-panel {
      display: none;
      flex-direction: column;
      gap: 4px;
      padding: 0 28px 18px;
    }
    .mobile-panel a {
      padding: 12px 4px;
      font-weight: 600;
      border-bottom: 1px solid var(--blush-deep);
    }

    /* ----- header ----- */
    header {
      position: sticky;
      top: 0;
      z-index: 60;
      background: color-mix(in srgb, var(--cream) 86%, transparent);
      backdrop-filter: blur(10px);
      border-bottom: 1px solid var(--blush-deep);
    }
    .nav {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 16px 28px;
      max-width: 1180px;
      margin: 0 auto;
    }
    .brand {
      display: flex;
      align-items: center;
      gap: 10px;
      color: var(--rose);
      cursor: pointer;
      user-select: none;
    }
    .brand .bow { width: 26px; height: 26px; }
    .brand span {
      font-family: var(--font-display);
      font-weight: 600;
      font-size: 22px;
      color: var(--plum);
      letter-spacing: .01em;
    }
    .nav-links {
      display: flex;
      gap: 32px;
      font-size: 15px;
      font-weight: 600;
    }
    .nav-links a {
      position: relative;
      padding: 6px 0;
      color: var(--plum-soft);
      transition: color .2s;
    }
    .nav-links a:hover { color: var(--rose); }
    .nav-right {
      display: flex;
      align-items: center;
      gap: 12px;
    }

    /* ----- admin overlay ----- */
    .admin-overlay {
      position: fixed;
      inset: 0;
      z-index: 200;
      background: rgba(0, 0, 0, 0.5);
      backdrop-filter: blur(4px);
      display: none;
      align-items: center;
      justify-content: center;
      padding: 20px;
    }
    .admin-overlay.show { display: flex; }
    .admin-modal {
      background: var(--cream);
      border-radius: var(--radius-lg);
      box-shadow: var(--shadow-lift);
      width: 100%;
      max-width: 720px;
      max-height: 90vh;
      overflow-y: auto;
      padding: 32px 28px;
      position: relative;
      animation: slideUp 0.3s ease;
    }
    @keyframes slideUp {
      from { transform: translateY(30px); opacity: 0; }
      to { transform: translateY(0); opacity: 1; }
    }
    .admin-modal .close-admin {
      position: absolute;
      top: 16px;
      right: 20px;
      font-size: 24px;
      color: var(--plum-soft);
      background: var(--white);
      width: 40px;
      height: 40px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      box-shadow: var(--shadow-soft);
      transition: transform .2s;
    }
    .admin-modal .close-admin:hover { transform: scale(1.1); }
    .admin-modal h2 { font-size: 26px; margin-bottom: 6px; }
    .admin-modal .sub { color: var(--plum-soft); margin-bottom: 24px; }

    .admin-tabs {
      display: flex;
      gap: 10px;
      margin-bottom: 24px;
      border-bottom: 2px solid var(--blush-deep);
      padding-bottom: 12px;
      flex-wrap: wrap;
    }
    .admin-tabs button {
      padding: 8px 20px;
      border-radius: 999px;
      font-weight: 700;
      font-size: 14px;
      background: transparent;
      color: var(--plum-soft);
      transition: all .2s;
    }
    .admin-tabs button.active { background: var(--rose); color: #fff; }
    .admin-tabs button:hover:not(.active) { background: var(--blush-soft); }

    .admin-panel { display: none; }
    .admin-panel.active { display: block; }
    .admin-panel .item-list {
      display: flex;
      flex-direction: column;
      gap: 12px;
      margin: 16px 0;
    }
    .admin-panel .item-row {
      background: var(--white);
      border-radius: var(--radius-sm);
      padding: 12px 16px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      box-shadow: var(--shadow-soft);
      gap: 12px;
      flex-wrap: wrap;
    }
    .admin-panel .item-row .info { flex: 1; min-width: 150px; }
    .admin-panel .item-row .info strong { display: block; font-size: 15px; }
    .admin-panel .item-row .info small { color: var(--plum-soft); font-size: 12px; }
    .admin-panel .item-row .info img {
      width: 50px;
      height: 50px;
      object-fit: cover;
      border-radius: var(--radius-sm);
      margin-right: 10px;
      float: left;
    }
    .admin-panel .item-row .actions { display: flex; gap: 8px; }
    .admin-panel .item-row .actions button {
      padding: 6px 14px;
      border-radius: 999px;
      font-weight: 700;
      font-size: 12px;
      background: var(--blush-soft);
      color: var(--rose);
      transition: all .2s;
    }
    .admin-panel .item-row .actions button:hover { background: var(--rose); color: #fff; }
    .admin-panel .item-row .actions button.danger { background: #f8d7da; color: #b02a37; }
    .admin-panel .item-row .actions button.danger:hover { background: #b02a37; color: #fff; }

    .admin-panel .add-btn {
      background: var(--sage);
      color: #fff;
      padding: 10px 22px;
      border-radius: 999px;
      font-weight: 700;
      font-size: 14px;
      transition: all .2s;
    }
    .admin-panel .add-btn:hover { transform: translateY(-2px); box-shadow: var(--shadow-soft); }

    .admin-form {
      background: var(--white);
      border-radius: var(--radius-md);
      padding: 20px;
      margin: 16px 0;
      box-shadow: var(--shadow-soft);
      display: none;
    }
    .admin-form.show { display: block; }
    .admin-form .co-field { margin-bottom: 14px; }
    .admin-form .co-field label {
      display: block;
      font-size: 12.5px;
      font-weight: 700;
      color: var(--plum-soft);
      margin-bottom: 4px;
    }
    .admin-form .co-field input,
    .admin-form .co-field select,
    .admin-form .co-field textarea {
      width: 100%;
      border: 1.5px solid var(--blush-deep);
      background: var(--cream);
      color: var(--plum);
      border-radius: 14px;
      padding: 10px 14px;
      font-family: inherit;
      font-size: 14px;
    }
    .admin-form .co-field input:focus,
    .admin-form .co-field select:focus,
    .admin-form .co-field textarea:focus {
      outline: 2px solid var(--rose);
      outline-offset: 1px;
    }
    .admin-form .form-actions {
      display: flex;
      gap: 12px;
      margin-top: 8px;
    }
    .admin-form .form-actions button {
      padding: 10px 24px;
      border-radius: 999px;
      font-weight: 700;
      font-size: 14px;
    }
    .admin-form .form-actions .btn-save { background: var(--rose); color: #fff; }
    .admin-form .form-actions .btn-cancel { background: var(--blush-soft); color: var(--plum-soft); }

    /* Image preview */
    .image-preview {
      max-width: 120px;
      max-height: 120px;
      border-radius: var(--radius-sm);
      margin: 8px 0 12px;
      border: 2px dashed var(--blush-deep);
      overflow: hidden;
    }
    .image-preview img {
      width: 100%;
      height: auto;
      display: block;
    }

    .order-item {
      background: var(--white);
      border-radius: var(--radius-sm);
      padding: 14px 16px;
      margin-bottom: 12px;
      box-shadow: var(--shadow-soft);
    }
    .order-item .order-header {
      display: flex;
      justify-content: space-between;
      font-weight: 700;
      margin-bottom: 6px;
      flex-wrap: wrap;
    }
    .order-item .order-details {
      font-size: 13px;
      color: var(--plum-soft);
      line-height: 1.5;
    }
    .order-item .order-details span {
      display: inline-block;
      margin-right: 16px;
    }

    .admin-login {
      text-align: center;
      padding: 20px 0;
    }
    .admin-login .co-field {
      max-width: 300px;
      margin: 0 auto 16px;
      text-align: left;
    }
    .admin-login .co-field label {
      display: block;
      font-size: 12.5px;
      font-weight: 700;
      color: var(--plum-soft);
      margin-bottom: 4px;
    }
    .admin-login .co-field input {
      width: 100%;
      border: 1.5px solid var(--blush-deep);
      background: var(--cream);
      color: var(--plum);
      border-radius: 14px;
      padding: 10px 14px;
      font-family: inherit;
      font-size: 14px;
    }
    .admin-login .co-field input:focus {
      outline: 2px solid var(--rose);
      outline-offset: 1px;
    }
    .admin-login .err {
      color: var(--rose-dark);
      font-size: 13px;
      margin-top: 8px;
      display: none;
    }
    .admin-login .err.show { display: block; }
    .admin-login .btn-primary {
      width: 100%;
      max-width: 200px;
      justify-content: center;
    }

    /* ===== HERO ===== */
    .hero { padding: 70px 0 40px; overflow: hidden; }
    .hero-grid {
      display: grid;
      grid-template-columns: 1.05fr .95fr;
      gap: 40px;
      align-items: center;
    }
    .hero h1 {
      font-size: clamp(38px, 5.4vw, 64px);
      line-height: 1.05;
      font-weight: 600;
      margin: 14px 0 20px;
    }
    .hero h1 em { font-style: normal; color: var(--rose); }
    .hero p.lead {
      font-size: 17.5px;
      line-height: 1.65;
      color: var(--plum-soft);
      max-width: 440px;
      margin-bottom: 30px;
    }
    .btn-row { display: flex; gap: 14px; flex-wrap: wrap; }
    .hero-art {
      position: relative;
      height: 440px;
    }
    .blob1 {
      position: absolute;
      width: 320px;
      height: 320px;
      background: var(--blush);
      top: 10px;
      right: 40px;
      border-radius: 44% 56% 62% 38% / 42% 46% 54% 58%;
    }
    .blob2 {
      position: absolute;
      width: 200px;
      height: 200px;
      background: var(--gold);
      bottom: 10px;
      left: 0px;
      border-radius: 58% 42% 38% 62% / 48% 60% 40% 52%;
      opacity: .6;
    }
    .jar-float {
      position: absolute;
      box-shadow: var(--shadow-lift);
      border-radius: 26px;
      background: var(--white);
      display: flex;
      align-items: center;
      justify-content: center;
      animation: float 7s ease-in-out infinite;
    }
    .jar-float.j1 {
      width: 180px; height: 230px;
      top: 60px; left: 70px;
      animation-delay: 0s;
    }
    .jar-float.j2 {
      width: 130px; height: 130px;
      bottom: 40px; right: 60px;
      border-radius: 50%;
      animation-delay: 1.4s;
    }
    .jar-float.j3 {
      width: 96px; height: 120px;
      top: 230px; left: 220px;
      animation-delay: .7s;
    }
    @keyframes float {
      0%, 100% { transform: translateY(0px); }
      50% { transform: translateY(-14px); }
    }

    /* ===== TRUST ===== */
    .trust { padding: 26px 0 10px; }
    .trust-grid {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 18px;
    }
    .trust-item {
      background: var(--white);
      border-radius: var(--radius-md);
      padding: 18px 16px;
      display: flex;
      align-items: center;
      gap: 12px;
      box-shadow: var(--shadow-soft);
    }
    .trust-item .ic {
      width: 38px; height: 38px;
      border-radius: 50%;
      background: var(--blush-soft);
      color: var(--rose);
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
    }
    .trust-item span {
      font-size: 13.5px;
      font-weight: 700;
      color: var(--plum-soft);
      line-height: 1.3;
    }

    /* ===== SECTION HEADERS ===== */
    .sec-head {
      text-align: center;
      max-width: 580px;
      margin: 0 auto 40px;
    }
    .sec-head h2 {
      font-size: clamp(28px, 3.6vw, 40px);
      font-weight: 600;
      margin-top: 10px;
    }
    .sec-head p {
      color: var(--plum-soft);
      margin-top: 12px;
      font-size: 15.5px;
      line-height: 1.6;
    }

    /* ===== PACKAGES ===== */
    .packages { padding: 90px 0 30px; background: var(--cream-deep); }
    .pkg-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 28px;
    }
    .pkg-card {
      background: var(--white);
      border-radius: var(--radius-lg);
      overflow: hidden;
      box-shadow: var(--shadow-soft);
      display: flex;
      flex-direction: column;
      transition: transform .3s, box-shadow .3s;
      cursor: pointer;
    }
    .pkg-card:hover { transform: translateY(-6px); box-shadow: var(--shadow-lift); }
    .pkg-photo {
      height: 220px;
      background: linear-gradient(135deg, var(--blush) 0%, var(--cream-deep) 100%);
      display: flex;
      align-items: center;
      justify-content: center;
      position: relative;
      overflow: hidden;
    }
    .pkg-photo img {
      width: 100%;
      height: 100%;
      object-fit: cover;
    }
    .pkg-photo .cam { width: 44px; height: 44px; color: var(--rose); opacity: .55; }
    .pkg-body { padding: 26px 26px 28px; }
    .pkg-tag {
      font-size: 11.5px;
      font-weight: 800;
      letter-spacing: .06em;
      text-transform: uppercase;
      color: var(--rose);
      margin-bottom: 8px;
    }
    .pkg-body h3 { font-size: 23px; font-weight: 600; margin-bottom: 8px; }
    .pkg-body .pkg-desc {
      font-size: 14px;
      color: var(--plum-soft);
      line-height: 1.6;
      margin-bottom: 16px;
      display: -webkit-box;
      -webkit-line-clamp: 3;
      -webkit-box-orient: vertical;
      overflow: hidden;
    }
    .pkg-list {
      list-style: none;
      padding: 0;
      margin: 0 0 20px;
      display: flex;
      flex-direction: column;
      gap: 8px;
    }
    .pkg-list li {
      font-size: 13.5px;
      color: var(--plum-soft);
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .pkg-list li svg { width: 14px; height: 14px; color: var(--sage); flex-shrink: 0; }
    .pkg-foot {
      display: flex;
      align-items: center;
      justify-content: space-between;
    }
    .pkg-price {
      font-family: var(--font-display);
      font-size: 21px;
      font-weight: 600;
    }

    /* ===== SHOP ===== */
    .shop { padding: 70px 0 40px; }
    .tabs {
      display: flex;
      justify-content: center;
      gap: 10px;
      margin-bottom: 36px;
      flex-wrap: wrap;
    }
    .tab {
      padding: 11px 22px;
      border-radius: 999px;
      background: var(--white);
      font-weight: 700;
      font-size: 14px;
      color: var(--plum-soft);
      box-shadow: var(--shadow-soft);
      transition: all .2s;
    }
    .tab.active, .tab:hover { background: var(--rose); color: #fff; }
    .grid {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 26px;
    }
    .card {
      background: var(--white);
      border-radius: var(--radius-lg);
      padding: 20px;
      box-shadow: var(--shadow-soft);
      display: flex;
      flex-direction: column;
      transition: transform .25s, box-shadow .25s;
      position: relative;
      cursor: pointer;
    }
    .card:hover { transform: translateY(-6px); box-shadow: var(--shadow-lift); }
    .badge {
      position: absolute;
      top: 16px;
      left: 16px;
      background: var(--gold);
      color: #3A2432;
      font-size: 11px;
      font-weight: 800;
      letter-spacing: .04em;
      text-transform: uppercase;
      padding: 6px 12px;
      border-radius: 999px;
      z-index: 3;
    }
    .badge.new { background: var(--sage); }
    .art {
      border-radius: var(--radius-md);
      height: 190px;
      display: flex;
      align-items: center;
      justify-content: center;
      margin-bottom: 16px;
      position: relative;
      overflow: hidden;
    }
    .art img {
      width: 100%;
      height: 100%;
      object-fit: cover;
    }
    .art svg { width: 88px; height: 120px; }
    .card .cat {
      font-size: 11.5px;
      font-weight: 700;
      letter-spacing: .06em;
      text-transform: uppercase;
      color: var(--rose);
      margin-bottom: 6px;
    }
    .card h3 { font-size: 19px; font-weight: 600; margin-bottom: 6px; }
    .card .size { font-size: 12.5px; color: var(--plum-soft); margin-bottom: 8px; }
    .card .desc {
      font-size: 13.5px;
      color: var(--plum-soft);
      line-height: 1.5;
      margin-bottom: 16px;
      min-height: 40px;
      display: -webkit-box;
      -webkit-line-clamp: 2;
      -webkit-box-orient: vertical;
      overflow: hidden;
    }
    .card-foot {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-top: auto;
    }
    .price {
      font-family: var(--font-display);
      font-size: 19px;
      font-weight: 600;
      color: var(--plum);
    }
    .add-btn {
      background: var(--blush-soft);
      color: var(--rose);
      font-weight: 800;
      font-size: 13px;
      padding: 10px 16px;
      border-radius: 999px;
      transition: all .2s;
      position: relative;
      z-index: 2;
    }
    .add-btn:hover { background: var(--rose); color: #fff; }

    /* ===== PRODUCT DETAIL MODAL ===== */
    .product-modal-overlay {
      position: fixed;
      inset: 0;
      z-index: 300;
      background: rgba(0,0,0,0.6);
      backdrop-filter: blur(6px);
      display: none;
      align-items: center;
      justify-content: center;
      padding: 20px;
    }
    .product-modal-overlay.show { display: flex; }
    .product-modal {
      background: var(--cream);
      border-radius: var(--radius-lg);
      box-shadow: var(--shadow-lift);
      max-width: 680px;
      width: 100%;
      max-height: 90vh;
      overflow-y: auto;
      padding: 32px 28px;
      position: relative;
      animation: slideUp 0.3s ease;
    }
    .product-modal .close-modal {
      position: absolute;
      top: 16px;
      right: 20px;
      font-size: 24px;
      color: var(--plum-soft);
      background: var(--white);
      width: 40px;
      height: 40px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      box-shadow: var(--shadow-soft);
      transition: transform .2s;
    }
    .product-modal .close-modal:hover { transform: scale(1.1); }
    .product-modal .modal-image {
      width: 100%;
      max-height: 300px;
      object-fit: cover;
      border-radius: var(--radius-md);
      margin-bottom: 20px;
      background: var(--blush-soft);
    }
    .product-modal .modal-badge {
      display: inline-block;
      background: var(--gold);
      color: #3A2432;
      font-size: 11px;
      font-weight: 800;
      letter-spacing: .04em;
      text-transform: uppercase;
      padding: 4px 12px;
      border-radius: 999px;
      margin-bottom: 10px;
    }
    .product-modal .modal-badge.new { background: var(--sage); }
    .product-modal h2 {
      font-size: 28px;
      font-weight: 600;
      margin-bottom: 4px;
    }
    .product-modal .modal-category {
      font-size: 13px;
      color: var(--rose);
      text-transform: uppercase;
      letter-spacing: .06em;
      font-weight: 700;
      margin-bottom: 8px;
    }
    .product-modal .modal-size {
      font-size: 14px;
      color: var(--plum-soft);
      margin-bottom: 12px;
    }
    .product-modal .modal-desc {
      font-size: 15px;
      line-height: 1.7;
      color: var(--plum-soft);
      margin-bottom: 20px;
    }
    .product-modal .modal-price {
      font-family: var(--font-display);
      font-size: 26px;
      font-weight: 600;
      color: var(--plum);
      margin-bottom: 20px;
    }
    .product-modal .modal-actions {
      display: flex;
      gap: 12px;
      flex-wrap: wrap;
    }

    /* ===== ABOUT ===== */
    .about { padding: 90px 0; background: var(--cream-deep); }
    .about-grid {
      display: grid;
      grid-template-columns: .9fr 1.1fr;
      gap: 56px;
      align-items: center;
    }
    .about-art {
      position: relative;
      height: 360px;
      border-radius: var(--radius-lg);
      overflow: hidden;
    }
    .about-art .blobA {
      position: absolute;
      width: 280px;
      height: 280px;
      background: var(--lilac);
      border-radius: 48% 52% 62% 38% / 50% 42% 58% 50%;
      top: 20px;
      left: 20px;
      opacity: .7;
    }
    .about-art .blobB {
      position: absolute;
      width: 190px;
      height: 190px;
      background: var(--blush);
      border-radius: 50%;
      bottom: 0;
      right: 10px;
    }
    .about-art .ring {
      position: absolute;
      inset: 0;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .about-art .photo-badge {
      position: absolute;
      bottom: 10px;
      right: 10px;
      background: rgba(58,36,50,.62);
      color: #fff;
      font-size: 10.5px;
      font-weight: 700;
      padding: 5px 10px;
      border-radius: 999px;
      display: flex;
      align-items: center;
      gap: 5px;
      letter-spacing: .02em;
      z-index: 2;
    }
    .about-copy p {
      color: var(--plum-soft);
      line-height: 1.75;
      font-size: 15.5px;
      margin-bottom: 16px;
    }
    .values {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 18px;
      margin-top: 28px;
    }
    .value {
      background: var(--cream-deep);
      border-radius: var(--radius-md);
      padding: 18px;
    }
    .value .ic { color: var(--rose); margin-bottom: 10px; }
    .value h4 {
      font-family: var(--font-body);
      font-size: 14px;
      font-weight: 800;
      margin-bottom: 4px;
    }
    .value p {
      font-size: 12.5px;
      color: var(--plum-soft);
      line-height: 1.4;
    }

    /* ===== TESTIMONIALS ===== */
    .testi { padding: 80px 0; }
    .testi-grid {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 24px;
    }
    .t-card {
      background: var(--white);
      border-radius: var(--radius-lg);
      padding: 26px;
      box-shadow: var(--shadow-soft);
    }
    .stars { color: var(--gold); font-size: 14px; margin-bottom: 12px; letter-spacing: 2px; }
    .t-card p.quote {
      font-size: 14.5px;
      line-height: 1.6;
      color: var(--plum-soft);
      margin-bottom: 16px;
    }
    .t-who {
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .t-avatar {
      width: 36px;
      height: 36px;
      border-radius: 50%;
      background: var(--blush);
    }
    .t-who b { font-size: 13.5px; }
    .t-who span {
      font-size: 12px;
      color: var(--plum-soft);
      display: block;
    }
    .sample-tag {
      font-size: 10.5px;
      color: var(--plum-soft);
      opacity: .6;
      margin-top: 10px;
    }

    /* ===== INSTAGRAM BAND ===== */
    .ig-band { padding: 70px 0; }
    .ig-inner {
      background: linear-gradient(135deg, var(--blush) 0%, var(--gold) 100%);
      border-radius: 40px;
      padding: 52px 40px;
      text-align: center;
    }
    .ig-inner h2 {
      font-size: clamp(26px, 3.4vw, 34px);
      margin-bottom: 10px;
      color: var(--plum);
    }
    .ig-inner p { color: var(--plum-soft); margin-bottom: 26px; }
    .ig-grid {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 14px;
      max-width: 640px;
      margin: 0 auto 26px;
    }
    .ig-tile {
      aspect-ratio: 1;
      border-radius: 18px;
      background: rgba(255,255,255,0.4);
      display: flex;
      align-items: center;
      justify-content: center;
      color: var(--rose);
      overflow: hidden;
    }
    .ig-tile img {
      width: 100%;
      height: 100%;
      object-fit: cover;
    }
    .ig-tile svg { width: 22px; height: 22px; opacity: .6; }

    /* ===== FOOTER ===== */
    footer { padding: 60px 0 30px; }
    .foot-grid {
      display: grid;
      grid-template-columns: 1.4fr 1fr 1fr 1.2fr;
      gap: 30px;
      margin-bottom: 40px;
    }
    .foot-brand p {
      color: var(--plum-soft);
      font-size: 13.5px;
      line-height: 1.6;
      margin-top: 12px;
      max-width: 240px;
    }
    .foot-col h5 {
      font-size: 13px;
      text-transform: uppercase;
      letter-spacing: .08em;
      color: var(--rose);
      font-weight: 800;
      margin-bottom: 14px;
    }
    .foot-col a, .foot-col p {
      display: block;
      font-size: 14px;
      color: var(--plum-soft);
      margin-bottom: 10px;
    }
    .newsletter {
      display: flex;
      gap: 8px;
      margin-top: 6px;
    }
    .newsletter input {
      flex: 1;
      border: none;
      background: var(--white);
      color: var(--plum);
      border-radius: 999px;
      padding: 12px 16px;
      font-family: inherit;
      font-size: 13.5px;
      box-shadow: var(--shadow-soft);
    }
    .newsletter button {
      background: var(--rose);
      color: #fff;
      border-radius: 999px;
      padding: 12px 18px;
      font-weight: 700;
      font-size: 13.5px;
    }
    .foot-bottom {
      border-top: 1px solid var(--blush-deep);
      padding-top: 22px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      font-size: 12.5px;
      color: var(--plum-soft);
      flex-wrap: wrap;
      gap: 10px;
    }
    .socials {
      display: flex;
      gap: 10px;
    }
    .socials a {
      width: 34px;
      height: 34px;
      border-radius: 50%;
      background: var(--white);
      display: flex;
      align-items: center;
      justify-content: center;
      color: var(--rose);
      box-shadow: var(--shadow-soft);
    }

    /* ===== CART DRAWER ===== */
    .overlay {
      position: fixed;
      inset: 0;
      background: rgba(20,12,17,0.45);
      backdrop-filter: blur(2px);
      opacity: 0;
      pointer-events: none;
      transition: opacity .3s;
      z-index: 90;
    }
    .overlay.show { opacity: 1; pointer-events: auto; }
    .drawer {
      position: fixed;
      top: 0;
      right: -440px;
      width: 410px;
      max-width: 92vw;
      height: 100%;
      background: var(--cream);
      z-index: 95;
      box-shadow: -20px 0 50px rgba(20,12,17,0.35);
      transition: right .35s cubic-bezier(.4,0,.2,1);
      display: flex;
      flex-direction: column;
    }
    .drawer.show { right: 0; }
    .drawer-head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 24px 24px 16px;
      border-bottom: 1px solid var(--blush-deep);
    }
    .drawer-head h3 { font-size: 22px; }
    .close-btn {
      width: 36px;
      height: 36px;
      border-radius: 50%;
      background: var(--white);
      display: flex;
      align-items: center;
      justify-content: center;
      box-shadow: var(--shadow-soft);
      color: var(--plum);
    }
    .drawer-body {
      flex: 1;
      overflow-y: auto;
      padding: 20px 24px;
    }
    .empty-cart {
      text-align: center;
      padding: 60px 10px;
      color: var(--plum-soft);
    }
    .empty-cart .bow {
      color: var(--blush-deep);
      width: 60px;
      height: 60px;
      margin: 0 auto 18px;
    }
    .line-item {
      display: flex;
      gap: 14px;
      padding: 16px 0;
      border-bottom: 1px solid var(--blush-soft);
    }
    .li-art {
      width: 64px;
      height: 64px;
      border-radius: 14px;
      background: var(--blush-soft);
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
    }
    .li-art svg { width: 32px; height: 44px; }
    .li-info { flex: 1; }
    .li-info h4 { font-size: 14.5px; font-weight: 700; margin-bottom: 2px; }
    .li-info .li-size { font-size: 12px; color: var(--plum-soft); margin-bottom: 8px; }
    .qty-stepper {
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .qty-stepper button {
      width: 26px; height: 26px;
      border-radius: 50%;
      background: var(--blush-soft);
      color: var(--rose);
      font-weight: 800;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .qty-stepper span {
      font-size: 13.5px;
      font-weight: 700;
      min-width: 16px;
      text-align: center;
    }
    .li-right {
      display: flex;
      flex-direction: column;
      align-items: flex-end;
      justify-content: space-between;
    }
    .li-price { font-weight: 700; font-size: 14.5px; }
    .remove-x {
      color: var(--plum-soft);
      font-size: 12px;
      text-decoration: underline;
    }
    .drawer-foot {
      padding: 20px 24px 26px;
      border-top: 1px solid var(--blush-deep);
    }
    .sum-row {
      display: flex;
      justify-content: space-between;
      font-size: 14px;
      color: var(--plum-soft);
      margin-bottom: 8px;
    }
    .sum-row.total {
      font-size: 18px;
      font-weight: 800;
      color: var(--plum);
      margin: 14px 0;
    }
    .checkout-btn {
      width: 100%;
      background: var(--rose);
      color: #fff;
      padding: 16px;
      border-radius: 999px;
      font-weight: 800;
      font-size: 15px;
      text-align: center;
    }
    .checkout-btn:hover { background: var(--rose-dark); }

    .co-field { margin-bottom: 16px; }
    .co-field label {
      display: block;
      font-size: 12.5px;
      font-weight: 700;
      color: var(--plum-soft);
      margin-bottom: 6px;
    }
    .co-field input,
    .co-field select,
    .co-field textarea {
      width: 100%;
      border: 1.5px solid var(--blush-deep);
      background: var(--white);
      color: var(--plum);
      border-radius: 14px;
      padding: 12px 14px;
      font-family: inherit;
      font-size: 14px;
      resize: none;
    }
    .co-field input:focus,
    .co-field select:focus,
    .co-field textarea:focus {
      outline: 2px solid var(--rose);
      outline-offset: 1px;
    }
    .pay-options {
      display: flex;
      flex-direction: column;
      gap: 10px;
    }
    .pay-opt {
      display: flex;
      align-items: center;
      gap: 10px;
      background: var(--blush-soft);
      padding: 12px 14px;
      border-radius: 14px;
      font-size: 13.5px;
      font-weight: 600;
    }
    .pay-opt input {
      width: 16px;
      height: 16px;
      accent-color: var(--rose);
    }
    .back-link {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      font-size: 13px;
      font-weight: 700;
      color: var(--rose);
      margin-bottom: 16px;
    }
    .co-summary {
      background: var(--blush-soft);
      border-radius: 16px;
      padding: 14px 16px;
      margin-bottom: 18px;
      font-size: 13px;
      color: var(--plum-soft);
    }
    .co-summary b { color: var(--plum); }
    .err {
      color: var(--rose-dark);
      font-size: 12px;
      margin-top: -8px;
      margin-bottom: 12px;
      display: none;
    }
    .confirm-wrap {
      text-align: center;
      padding: 40px 6px;
    }
    .confirm-wrap .bow {
      color: var(--rose);
      width: 64px;
      height: 64px;
      margin: 0 auto 20px;
    }
    .confirm-wrap h3 { font-size: 24px; margin-bottom: 10px; }
    .confirm-wrap p {
      color: var(--plum-soft);
      font-size: 14px;
      line-height: 1.6;
      margin-bottom: 18px;
    }
    .order-id {
      display: inline-block;
      background: var(--blush-soft);
      color: var(--rose);
      font-weight: 800;
      padding: 10px 20px;
      border-radius: 999px;
      margin-bottom: 22px;
      letter-spacing: .03em;
    }

    /* ===== RESPONSIVE ===== */
    @media (max-width: 920px) {
      .hero-grid { grid-template-columns: 1fr; }
      .hero-art { height: 340px; order: -1; }
      .trust-grid { grid-template-columns: repeat(2, 1fr); }
      .pkg-grid { grid-template-columns: 1fr; }
      .grid { grid-template-columns: repeat(2, 1fr); }
      .about-grid { grid-template-columns: 1fr; }
      .testi-grid { grid-template-columns: 1fr; }
      .foot-grid { grid-template-columns: 1fr 1fr; }
      .nav-links { display: none; }
      .menu-toggle { display: flex; }
    }
    @media (max-width: 560px) {
      .grid { grid-template-columns: 1fr; }
      .foot-grid { grid-template-columns: 1fr; }
      .values { grid-template-columns: 1fr; }
      .ig-grid { grid-template-columns: repeat(2, 1fr); }
      .product-modal { padding: 20px 16px; }
    }
  </style>
</head>
<body>

<!-- ===== HEADER ===== -->
<header>
  <div class="nav">
    <div class="brand" id="brandTrigger" title="Double-click for admin">
      <svg class="bow" viewBox="0 0 48 48"><path d="M24 24c-2-6-9-11-16-9-4 1-6 5-4 9 2 3 8 4 12 3-4 3-8 7-8 11 0 3 3 5 6 4 5-2 8-9 10-18zm0 0c2-6 9-11 16-9 4 1 6 5 4 9-2 3-8 4-12 3 4 3 8 7 8 11 0 3-3 5-6 4-5-2-8-9-10-18zm-3 0a3 3 0 1 0 6 0 3 3 0 1 0-6 0z"/></svg>
      <span>Skinbliss</span>
    </div>
    <nav class="nav-links">
      <a href="#packages">Packages</a>
      <a href="#shop">Shop</a>
      <a href="#about">Our Story</a>
      <a href="#footer">Contact</a>
    </nav>
    <div class="nav-right">
      <button class="icon-btn" onclick="toggleTheme()" aria-label="Toggle dark mode">
        <svg class="icon-sun" width="19" height="19" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="4.5" stroke="currentColor" stroke-width="1.8"/><path d="M12 2v2.4M12 19.6V22M4.2 4.2l1.7 1.7M18.1 18.1l1.7 1.7M2 12h2.4M19.6 12H22M4.2 19.8l1.7-1.7M18.1 5.9l1.7-1.7" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
        <svg class="icon-moon" width="19" height="19" viewBox="0 0 24 24" fill="none"><path d="M20 14.5A8.5 8.5 0 1 1 9.5 4a7 7 0 0 0 10.5 10.5Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
      </button>
      <a class="icon-btn" href="https://www.instagram.com/skin_bliss_28/" target="_blank" rel="noopener" aria-label="Instagram">
        <svg width="19" height="19" viewBox="0 0 24 24" fill="none"><rect x="2" y="2" width="20" height="20" rx="6" stroke="currentColor" stroke-width="1.8"/><circle cx="12" cy="12" r="4.2" stroke="currentColor" stroke-width="1.8"/><circle cx="17.3" cy="6.7" r="1.1" fill="currentColor"/></svg>
      </a>
      <button class="icon-btn" onclick="openCart()" aria-label="Open cart">
        <svg width="19" height="19" viewBox="0 0 24 24" fill="none"><path d="M6 8h12l-1.2 11.2a2 2 0 0 1-2 1.8H9.2a2 2 0 0 1-2-1.8L6 8Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M9 8V6a3 3 0 0 1 6 0v2" stroke="currentColor" stroke-width="1.8"/></svg>
        <span class="cart-count" id="cartCount" style="display:none;">0</span>
      </button>
      <button class="menu-toggle" onclick="toggleMobile()" aria-label="Menu">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M4 7h16M4 12h16M4 17h16" stroke="var(--rose)" stroke-width="2" stroke-linecap="round"/></svg>
      </button>
    </div>
  </div>
  <div class="mobile-panel" id="mobilePanel">
    <a href="#packages" onclick="toggleMobile()">Packages</a>
    <a href="#shop" onclick="toggleMobile()">Shop</a>
    <a href="#about" onclick="toggleMobile()">Our Story</a>
    <a href="#footer" onclick="toggleMobile()">Contact</a>
  </div>
</header>

<!-- ===== HERO ===== -->
<section class="hero" id="top">
  <div class="wrap hero-grid">
    <div>
      <p class="eyebrow">Handcrafted skincare · Tanzania 🇹🇿</p>
      <h1>Skin that feels<br>like <em>bliss.</em></h1>
      <p class="lead">Gentle, glow-giving skincare and body care made with natural ingredients  created for skin that lives, works, and shines under the East African sun.</p>
      <div class="btn-row">
        <a class="btn btn-primary" href="#packages">Shop our packages</a>
        <a class="btn btn-ghost" href="#about">Our story</a>
      </div>
    </div>
    <div class="hero-art">
      <div class="blob1"></div>
      <div class="blob2"></div>
      <div class="jar-float j1">
        <svg width="70" height="100" viewBox="0 0 70 100" fill="none"><rect x="14" y="24" width="42" height="66" rx="14" fill="#F6D6DE"/><rect x="20" y="8" width="30" height="20" rx="8" fill="#EFB4C8"/><rect x="24" y="0" width="22" height="10" rx="5" fill="#B23F66"/></svg>
      </div>
      <div class="jar-float j2">
        <svg width="60" height="60" viewBox="0 0 60 60" fill="none"><circle cx="30" cy="34" r="24" fill="#F3D998"/><rect x="20" y="6" width="20" height="12" rx="6" fill="#C9A467"/></svg>
      </div>
      <div class="jar-float j3">
        <svg width="50" height="70" viewBox="0 0 50 70" fill="none"><rect x="8" y="18" width="34" height="48" rx="12" fill="#D9C7E8"/><rect x="14" y="4" width="22" height="16" rx="7" fill="#c9a9de"/></svg>
      </div>
    </div>
  </div>
</section>

<!-- ===== TRUST ===== -->
<section class="trust">
  <div class="wrap trust-grid">
    <div class="trust-item"><div class="ic"><svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M12 21s-7-4.4-9.5-9C.5 8 2 4 6 3.6c2.3-.2 4.3 1 6 3 1.7-2 3.7-3.2 6-3C22 4 23.5 8 21.5 12 19 16.6 12 21 12 21Z" stroke="currentColor" stroke-width="1.6"/></svg></div><span>Cruelty-free, always</span></div>
    <div class="trust-item"><div class="ic"><svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M12 3c4 3 6 6 6 10a6 6 0 1 1-12 0c0-4 2-7 6-10Z" stroke="currentColor" stroke-width="1.6"/></svg></div><span>Natural, skin-first ingredients</span></div>
    <div class="trust-item"><div class="ic"><svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M4 20V10l8-6 8 6v10" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg></div><span>Made with care in Tanzania</span></div>
    <div class="trust-item"><div class="ic"><svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M12 4v16M4 12h16" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg></div><span>Fast delivery, nationwide</span></div>
  </div>
</section>

<!-- ===== SIGNATURE PACKAGES (Dynamic) ===== -->
<section class="packages" id="packages">
  <div class="wrap">
    <div class="sec-head">
      <p class="eyebrow">Signature Packages</p>
      <h2>Shade-matched rituals, made for you</h2>
      <p>Curated collections, each formulated for different skin tones – now managed from your admin panel.</p>
    </div>
    <div class="pkg-grid" id="packageGrid"></div>
  </div>
</section>

<div class="wrap"><div class="bow-divider" style="display:flex;align-items:center;gap:16px;margin:0 auto 50px;max-width:280px;"><span class="line" style="flex:1;height:1px;background:var(--blush-deep);"></span><svg viewBox="0 0 48 48" style="width:20px;height:20px;color:var(--rose);flex-shrink:0;"><path d="M24 24c-2-6-9-11-16-9-4 1-6 5-4 9 2 3 8 4 12 3-4 3-8 7-8 11 0 3 3 5 6 4 5-2 8-9 10-18zm0 0c2-6 9-11 16-9 4 1 6 5 4 9-2 3-8 4-12 3 4 3 8 7 8 11 0 3-3 5-6 4-5-2-8-9-10-18zm-3 0a3 3 0 1 0 6 0 3 3 0 1 0-6 0z"/></svg><span class="line" style="flex:1;height:1px;background:var(--blush-deep);"></span></div></div>

<!-- ===== SHOP ===== -->
<section class="shop" id="shop">
  <div class="wrap">
    <div class="sec-head">
      <p class="eyebrow">The Collection</p>
      <h2>Find your glow ritual</h2>
      <p>Click any product to see full details and add to your bag.</p>
    </div>
    <div class="tabs">
      <button class="tab active" data-f="all" onclick="setFilter('all')">All</button>
      <button class="tab" data-f="skincare" onclick="setFilter('skincare')">Skincare</button>
      <button class="tab" data-f="body" onclick="setFilter('body')">Body Care</button>
      <button class="tab" data-f="packages" onclick="setFilter('packages')">Packages</button>
    </div>
    <div class="grid" id="productGrid"></div>
  </div>
</section>

<!-- ===== ABOUT (dynamic founder image) ===== -->
<section class="about" id="about">
  <div class="wrap about-grid">
    <div class="about-art" id="founderImageContainer">
      <!-- Populated by JavaScript -->
    </div>
    <div class="about-copy">
      <p class="eyebrow">Our Story</p>
      <h2 style="margin:12px 0 18px;font-size:clamp(26px,3.2vw,36px);">Small-batch skincare, made for real Tanzanian skin</h2>
      <p>Skinbliss started as a simple idea: skincare shouldn't need a chemistry degree to understand, and it shouldn't cost a fortune to feel good. We formulate every product to work with the heat, the humidity, and the pace of daily life here — and our signature packages are shade-matched so every skin tone gets a ritual built for it.</p>
      <p>This section is placeholder copy — tell us your real founding story, ingredients, and mission, and we'll drop it in.</p>
      <div class="values">
        <div class="value"><div class="ic"><svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M12 3c4 3 6 6 6 10a6 6 0 1 1-12 0c0-4 2-7 6-10Z" stroke="currentColor" stroke-width="1.6"/></svg></div><h4>Natural first</h4><p>Plant-powered actives, no harsh fillers.</p></div>
        <div class="value"><div class="ic"><svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M12 21s-7-4.4-9.5-9C.5 8 2 4 6 3.6c2.3-.2 4.3 1 6 3 1.7-2 3.7-3.2 6-3C22 4 23.5 8 21.5 12 19 16.6 12 21 12 21Z" stroke="currentColor" stroke-width="1.6"/></svg></div><h4>Cruelty-free</h4><p>Never tested on animals, ever.</p></div>
        <div class="value"><div class="ic"><svg width="20" height="20" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.6"/><path d="M12 7v5l3 3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg></div><h4>Made fresh</h4><p>Small batches, made to order weekly.</p></div>
      </div>
    </div>
  </div>
</section>

<!-- ===== TESTIMONIALS ===== -->
<section class="testi">
  <div class="wrap">
    <div class="sec-head"><p class="eyebrow">Loved locally</p><h2>What customers are saying</h2></div>
    <div class="testi-grid">
      <div class="t-card">
        <div class="stars">★★★★★</div>
        <p class="quote">My skin has never felt this soft. The body butter smells incredible and lasts all day without feeling greasy.</p>
        <div class="t-who"><div class="t-avatar"></div><div><b>Amina R.</b><span>Dar es Salaam</span></div></div>
        <p class="sample-tag">Sample review — replace with a real customer quote</p>
      </div>
      <div class="t-card">
        <div class="stars">★★★★★</div>
        <p class="quote">I used the Caramel Package before my sister's wedding and my skin looked amazing in every photo.</p>
        <div class="t-who"><div class="t-avatar"></div><div><b>Grace M.</b><span>Arusha</span></div></div>
        <p class="sample-tag">Sample review — replace with a real customer quote</p>
      </div>
      <div class="t-card">
        <div class="stars">★★★★★</div>
        <p class="quote">The Bridal Collection was perfect prep for my big day. Delivery was quick and packaging felt so premium.</p>
        <div class="t-who"><div class="t-avatar"></div><div><b>Fatuma S.</b><span>Mwanza</span></div></div>
        <p class="sample-tag">Sample review — replace with a real customer quote</p>
      </div>
    </div>
  </div>
</section>

<!-- ===== INSTAGRAM BAND (dynamic tiles) ===== -->
<section class="ig-band">
  <div class="wrap">
    <div class="ig-inner">
      <p class="eyebrow">@skin_bliss_28</p>
      <h2>Follow the glow</h2>
      <p>Behind-the-scenes, new drops, and skin tips on Instagram.</p>
      <div class="ig-grid" id="instagramGrid">
        <!-- Populated by JavaScript -->
      </div>
      <a class="btn btn-primary" href="https://www.instagram.com/skin_bliss_28/" target="_blank" rel="noopener">Visit Instagram</a>
    </div>
  </div>
</section>

<!-- ===== FOOTER ===== -->
<footer id="footer">
  <div class="wrap">
    <div class="foot-grid">
      <div class="foot-brand">
        <div class="brand" style="color:var(--rose);">
          <svg class="bow" width="24" height="24" viewBox="0 0 48 48"><path d="M24 24c-2-6-9-11-16-9-4 1-6 5-4 9 2 3 8 4 12 3-4 3-8 7-8 11 0 3 3 5 6 4 5-2 8-9 10-18zm0 0c2-6 9-11 16-9 4 1 6 5 4 9-2 3-8 4-12 3 4 3 8 7 8 11 0 3-3 5-6 4-5-2-8-9-10-18zm-3 0a3 3 0 1 0 6 0 3 3 0 1 0-6 0z"/></svg>
          <span style="font-family:var(--font-display);font-weight:600;font-size:20px;color:var(--plum);">Skinbliss</span>
        </div>
        <p>Gentle, glow-giving skincare made in Tanzania. Placeholder tagline — update anytime.</p>
        <div class="socials" style="margin-top:14px;">
          <a href="https://www.instagram.com/skin_bliss_28/" target="_blank" rel="noopener" aria-label="Instagram"><svg width="16" height="16" viewBox="0 0 24 24" fill="none"><rect x="2" y="2" width="20" height="20" rx="6" stroke="currentColor" stroke-width="1.8"/><circle cx="12" cy="12" r="4.2" stroke="currentColor" stroke-width="1.8"/></svg></a>
        </div>
      </div>
      <div class="foot-col">
        <h5>Shop</h5>
        <a href="#shop" onclick="setFilter('skincare')">Skincare</a>
        <a href="#shop" onclick="setFilter('body')">Body Care</a>
        <a href="#packages">Packages</a>
      </div>
      <div class="foot-col">
        <h5>Help</h5>
        <a href="#" onclick="return false;">Shipping Info</a>
        <a href="#" onclick="return false;">Returns</a>
        <a href="#" onclick="return false;">Track Order</a>
      </div>
      <div class="foot-col">
        <h5>Stay glowing</h5>
        <p style="margin-bottom:10px;">Get skincare tips and new drops in your inbox.</p>
        <div class="newsletter"><input type="email" placeholder="Your email"><button onclick="return false;">Join</button></div>
      </div>
    </div>
    <div class="foot-bottom">
      <span>© 2026 Skinbliss Tanzania. All rights reserved.</span>
      <span>WhatsApp / phone: +255 XXX XXX XXX (placeholder)</span>
    </div>
  </div>
</footer>

<!-- ===== CART DRAWER ===== -->
<div class="overlay" id="overlay" onclick="closeCart()"></div>
<div class="drawer" id="drawer">
  <div class="drawer-head"><h3 id="drawerTitle">Your Bag</h3><button class="close-btn" onclick="closeCart()">✕</button></div>
  <div class="drawer-body" id="drawerBody"></div>
  <div class="drawer-foot" id="drawerFoot"></div>
</div>

<!-- ===== ADMIN OVERLAY ===== -->
<div class="admin-overlay" id="adminOverlay">
  <div class="admin-modal">
    <button class="close-admin" onclick="closeAdmin()">✕</button>
    <div id="adminContent"></div>
  </div>
</div>

<!-- ===== PRODUCT DETAIL MODAL ===== -->
<div class="product-modal-overlay" id="productModal">
  <div class="product-modal">
    <button class="close-modal" onclick="closeProductModal()">✕</button>
    <div id="productModalContent"></div>
  </div>
</div>

<script>
// ============================================================
//  JAVASCRIPT – Full application logic
// ============================================================

// ---------- API helper ----------
function api(action, options = {}) {
  const url = window.location.pathname + '?action=' + action;
  return fetch(url, {
    ...options,
    headers: { 'Content-Type': 'application/json', ...(options.headers || {}) }
  }).then(res => res.json());
}

// ---------- Globals ----------
let cart = {};
let currentFilter = 'all';
let panelView = 'cart';
let lastOrder = null;
let siteSettings = {};

// ---------- Helpers ----------
function formatTZS(n) { return 'TSh ' + n.toLocaleString('en-US'); }
function catLabel(cat) {
  const map = { skincare: 'Skincare', body: 'Body Care', packages: 'Package' };
  return map[cat] || cat;
}
function artSVG(category, tint) {
  if (category === 'body') {
    return `<svg viewBox="0 0 70 100" fill="none"><ellipse cx="35" cy="60" rx="28" ry="34" fill="${tint}"/><rect x="21" y="8" width="28" height="20" rx="8" fill="${tint}" opacity="0.7"/><path d="M35 40c4 4 4 10 0 14-4-4-4-10 0-14Z" fill="#B23F66" opacity="0.8"/></svg>`;
  }
  if (category === 'packages') {
    return `<svg viewBox="0 0 70 100" fill="none"><rect x="10" y="26" width="50" height="60" rx="14" fill="${tint}"/><rect x="10" y="26" width="50" height="14" fill="#B23F66" opacity="0.85"/><path d="M35 10c-2-6-9-9-14-6-3 2-3 6 0 8 2 2 7 2 10 1-3 2-6 5-6 8 0 2 2 4 5 3 4-1 6-8 5-14zm0 0c2-6 9-9 14-6 3 2 3 6 0 8-2 2-7 2-10 1 3 2 6 5 6 8 0 2-2 4-5 3-4-1-6-8-5-14z" fill="#B23F66"/></svg>`;
  }
  return `<svg viewBox="0 0 70 100" fill="none"><rect x="14" y="24" width="42" height="66" rx="14" fill="${tint}"/><rect x="20" y="8" width="30" height="20" rx="8" fill="${tint}" opacity="0.75"/><rect x="24" y="0" width="22" height="10" rx="5" fill="#B23F66"/></svg>`;
}

// ---------- Fetch settings ----------
async function fetchSettings() {
  try {
    const data = await api('get_settings');
    siteSettings = data || {};
    return siteSettings;
  } catch (e) {
    console.error('Failed to fetch settings:', e);
    return {};
  }
}

// ---------- Render founder image ----------
function renderFounderImage() {
  const container = document.getElementById('founderImageContainer');
  const img = siteSettings.founder_image;
  if (img) {
    container.innerHTML = `<img src="uploads/${img}" alt="Founder" style="width:100%;height:100%;object-fit:cover;border-radius:var(--radius-lg);" />`;
  } else {
    container.innerHTML = `
      <div class="blobA"></div>
      <div class="blobB"></div>
      <div class="ring">
        <svg width="150" height="150" viewBox="0 0 48 48"><path d="M24 24c-2-6-9-11-16-9-4 1-6 5-4 9 2 3 8 4 12 3-4 3-8 7-8 11 0 3 3 5 6 4 5-2 8-9 10-18zm0 0c2-6 9-11 16-9 4 1 6 5 4 9-2 3-8 4-12 3 4 3 8 7 8 11 0 3-3 5-6 4-5-2-8-9-10-18zm-3 0a3 3 0 1 0 6 0 3 3 0 1 0-6 0z" fill="var(--rose)" opacity="0.85"/></svg>
      </div>
      <div class="photo-badge"><svg viewBox="0 0 24 24" fill="none" style="width:12px;height:12px;"><path d="M4 8h3l2-3h6l2 3h3v11H4Z" stroke="white" stroke-width="1.8"/><circle cx="12" cy="13" r="3.2" stroke="white" stroke-width="1.8"/></svg>Add founder photo</div>
    `;
  }
}

// ---------- Render Instagram tiles ----------
function renderInstagramTiles() {
  const grid = document.getElementById('instagramGrid');
  const keys = ['instagram_image_1', 'instagram_image_2', 'instagram_image_3', 'instagram_image_4'];
  grid.innerHTML = keys.map(key => {
    const img = siteSettings[key];
    if (img) {
      return `<div class="ig-tile" style="background:transparent;padding:0;"><img src="uploads/${img}" alt="Instagram" style="width:100%;height:100%;object-fit:cover;border-radius:18px;" /></div>`;
    } else {
      return `<div class="ig-tile"><svg viewBox="0 0 24 24" fill="none"><path d="M4 8h3l2-3h6l2 3h3v11H4Z" stroke="currentColor" stroke-width="1.6"/><circle cx="12" cy="13" r="3" stroke="currentColor" stroke-width="1.6"/></svg></div>`;
    }
  }).join('');
}

// ---------- Fetch products ----------
async function fetchProducts() {
  try {
    const data = await api('get_products');
    return Array.isArray(data) ? data : [];
  } catch (e) {
    console.error('Failed to fetch products:', e);
    return [];
  }
}

// ---------- Render Packages (dynamic from DB) ----------
async function renderPackages() {
  const grid = document.getElementById('packageGrid');
  const allProducts = await fetchProducts();
  const packages = allProducts.filter(p => p.category === 'packages');
  if (packages.length === 0) {
    grid.innerHTML = `<p style="grid-column:1/-1;text-align:center;color:var(--plum-soft);padding:40px 0;">No packages added yet. Go to admin to create one.</p>`;
    return;
  }
  grid.innerHTML = packages.map(pkg => {
    let imgHtml = pkg.image ? `<img src="uploads/${pkg.image}" alt="${pkg.name}" />` : `<svg class="cam" viewBox="0 0 24 24" fill="none"><path d="M4 8h3l2-3h6l2 3h3v11H4Z" stroke="currentColor" stroke-width="1.6"/><circle cx="12" cy="13" r="3.2" stroke="currentColor" stroke-width="1.6"/></svg>`;
    const items = pkg.desc ? pkg.desc.split(',').map(item => item.trim()) : ['Nourishing formula', 'Glow-boosting ingredients'];
    return `
      <div class="pkg-card" onclick="openProductDetail('${pkg.id}')">
        <div class="pkg-photo">${imgHtml}</div>
        <div class="pkg-body">
          <div class="pkg-tag">${pkg.badge || 'Signature Package'}</div>
          <h3>${pkg.name}</h3>
          <p class="pkg-desc">${pkg.desc}</p>
          <ul class="pkg-list">${items.map(i => `<li><svg viewBox="0 0 24 24" fill="none"><path d="M4 12l5 5L20 6" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>${i}</li>`).join('')}</ul>
          <div class="pkg-foot">
            <span class="pkg-price">${formatTZS(pkg.price)}</span>
            <button class="add-btn" onclick="event.stopPropagation();addToCart('${pkg.id}')">Add to bag</button>
          </div>
        </div>
      </div>
    `;
  }).join('');
}

// ---------- Render products (store) with clickable cards ----------
async function renderProducts() {
  const grid = document.getElementById('productGrid');
  const products = await fetchProducts();
  const list = products.filter(p => p.category !== 'packages' && (currentFilter === 'all' || p.category === currentFilter));
  grid.innerHTML = list.map(p => {
    let artHtml = p.image ? `<img src="uploads/${p.image}" alt="${p.name}" />` : artSVG(p.category, p.tint);
    return `
      <div class="card" onclick="openProductDetail('${p.id}')">
        ${p.badge ? `<span class="badge ${p.badge === 'New' ? 'new' : ''}">${p.badge}</span>` : ''}
        <div class="art" style="background:${p.tint}22;">${artHtml}</div>
        <div class="cat">${catLabel(p.category)}</div>
        <h3>${p.name}</h3>
        <div class="size">${p.size}</div>
        <p class="desc">${p.desc}</p>
        <div class="card-foot">
          <span class="price">${formatTZS(p.price)}</span>
          <button class="add-btn" onclick="event.stopPropagation();addToCart('${p.id}')">Add to bag</button>
        </div>
      </div>
    `;
  }).join('');
}

// ---------- Product Detail Modal ----------
function openProductDetail(productId) {
  fetchProducts().then(products => {
    const p = products.find(prod => prod.id === productId);
    if (!p) return;
    const modal = document.getElementById('productModal');
    const content = document.getElementById('productModalContent');
    let imgHtml = p.image ? `<img src="uploads/${p.image}" alt="${p.name}" class="modal-image" />` : `<div class="modal-image" style="display:flex;align-items:center;justify-content:center;background:${p.tint}22;">${artSVG(p.category, p.tint)}</div>`;
    const badgeHtml = p.badge ? `<span class="modal-badge ${p.badge === 'New' ? 'new' : ''}">${p.badge}</span>` : '';
    content.innerHTML = `
      ${imgHtml}
      ${badgeHtml}
      <div class="modal-category">${catLabel(p.category)}</div>
      <h2>${p.name}</h2>
      <div class="modal-size">${p.size}</div>
      <p class="modal-desc">${p.desc}</p>
      <div class="modal-price">${formatTZS(p.price)}</div>
      <div class="modal-actions">
        <button class="btn btn-primary" onclick="addToCart('${p.id}');closeProductModal();">Add to bag</button>
        <button class="btn btn-ghost" onclick="closeProductModal()">Continue browsing</button>
      </div>
    `;
    modal.classList.add('show');
  });
}

function closeProductModal() {
  document.getElementById('productModal').classList.remove('show');
}

// ---------- Filter ----------
function setFilter(f) {
  currentFilter = f;
  document.querySelectorAll('.tab').forEach(t => t.classList.toggle('active', t.dataset.f === f));
  renderProducts();
}

// ---------- Cart Functions ----------
function addToCart(id) {
  cart[id] = (cart[id] || 0) + 1;
  renderCartPanel();
  openCart();
}
function changeQty(id, delta) {
  if (!cart[id]) return;
  cart[id] += delta;
  if (cart[id] <= 0) delete cart[id];
  renderCartPanel();
}
function removeItem(id) { delete cart[id]; renderCartPanel(); }
function cartCount() { return Object.values(cart).reduce((a, b) => a + b, 0); }
async function cartTotal() {
  const products = await fetchProducts();
  return Object.entries(cart).reduce((sum, [id, qty]) => {
    const p = products.find(x => x.id === id);
    return sum + (p ? p.price * qty : 0);
  }, 0);
}
function openCart() {
  panelView = 'cart';
  renderCartPanel();
  document.getElementById('overlay').classList.add('show');
  document.getElementById('drawer').classList.add('show');
}
function closeCart() {
  document.getElementById('overlay').classList.remove('show');
  document.getElementById('drawer').classList.remove('show');
}

async function renderCartPanel() {
  const count = cartCount();
  const badge = document.getElementById('cartCount');
  badge.style.display = count > 0 ? 'flex' : 'none';
  badge.textContent = count;

  const title = document.getElementById('drawerTitle');
  const body = document.getElementById('drawerBody');
  const foot = document.getElementById('drawerFoot');

  if (panelView === 'confirmation' && lastOrder) {
    title.textContent = 'Order placed!';
    body.innerHTML = `
      <div class="confirm-wrap">
        <svg class="bow" viewBox="0 0 48 48"><path d="M24 24c-2-6-9-11-16-9-4 1-6 5-4 9 2 3 8 4 12 3-4 3-8 7-8 11 0 3 3 5 6 4 5-2 8-9 10-18zm0 0c2-6 9-11 16-9 4 1 6 5 4 9-2 3-8 4-12 3 4 3 8 7 8 11 0 3-3 5-6 4-5-2-8-9-10-18zm-3 0a3 3 0 1 0 6 0 3 3 0 1 0-6 0z"/></svg>
        <h3>Thank you, ${lastOrder.name}!</h3>
        <p>We've received your order. Our team will confirm it by WhatsApp or SMS within a few hours to arrange ${lastOrder.payment.toLowerCase()} and delivery.</p>
        <div class="order-id">Order #${lastOrder.id}</div>
        <p>Total: <b>${formatTZS(lastOrder.total)}</b></p>
      </div>`;
    foot.innerHTML = `<button class="checkout-btn" onclick="continueShopping()">Continue shopping</button>`;
    return;
  }

  if (panelView === 'checkout') {
    title.textContent = 'Checkout';
    body.innerHTML = `
      <a class="back-link" href="#" onclick="panelView='cart';renderCartPanel();return false;">&larr; Back to bag</a>
      <div class="co-summary">${cartCount()} item(s) · <b>${formatTZS(await cartTotal())}</b></div>
      <form id="checkoutForm" onsubmit="submitOrder(event)">
        <div class="co-field"><label>Full name</label><input type="text" id="coName" required placeholder="e.g. Amina Rashid"></div>
        <div class="co-field"><label>Phone number</label><input type="tel" id="coPhone" required placeholder="e.g. 0712 345 678"></div>
        <div class="co-field"><label>Region</label>
          <select id="coRegion" required>
            <option value="">Select region</option>
            <option>Dar es Salaam</option><option>Arusha</option><option>Mwanza</option>
            <option>Dodoma</option><option>Mbeya</option><option>Zanzibar</option><option>Other</option>
          </select>
        </div>
        <div class="co-field"><label>Delivery address</label><textarea id="coAddress" required rows="3" placeholder="Street, area, landmark"></textarea></div>
        <div class="co-field"><label>Payment method</label>
          <div class="pay-options">
            <label class="pay-opt"><input type="radio" name="pay" value="M-Pesa" checked> M-Pesa</label>
            <label class="pay-opt"><input type="radio" name="pay" value="Tigo Pesa"> Tigo Pesa</label>
            <label class="pay-opt"><input type="radio" name="pay" value="Airtel Money"> Airtel Money</label>
            <label class="pay-opt"><input type="radio" name="pay" value="Cash on Delivery"> Cash on Delivery</label>
          </div>
        </div>
        <div class="co-field"><label>Order notes (optional)</label><textarea id="coNotes" rows="2" placeholder="Anything we should know?"></textarea></div>
        <p class="err" id="coError">Please fill in your name, phone, region and address.</p>
      </form>`;
    foot.innerHTML = `<button class="checkout-btn" onclick="document.getElementById('checkoutForm').requestSubmit()">Place order · ${formatTZS(await cartTotal())}</button>`;
    return;
  }

  title.textContent = 'Your Bag';
  if (count === 0) {
    body.innerHTML = `
      <div class="empty-cart">
        <svg class="bow" viewBox="0 0 48 48"><path d="M24 24c-2-6-9-11-16-9-4 1-6 5-4 9 2 3 8 4 12 3-4 3-8 7-8 11 0 3 3 5 6 4 5-2 8-9 10-18zm0 0c2-6 9-11 16-9 4 1 6 5 4 9-2 3-8 4-12 3 4 3 8 7 8 11 0 3-3 5-6 4-5-2-8-9-10-18zm-3 0a3 3 0 1 0 6 0 3 3 0 1 0-6 0z"/></svg>
        <p>Your bag is feeling a little empty.<br>Go find something glowy.</p>
      </div>`;
    foot.innerHTML = '';
    return;
  }

  const products = await fetchProducts();
  body.innerHTML = Object.entries(cart).map(([id, qty]) => {
    const p = products.find(x => x.id === id);
    if (!p) return '';
    const cat = p.category || 'packages';
    const tint = p.tint || '#F6D6DE';
    const size = p.size || '';
    return `
      <div class="line-item">
        <div class="li-art" style="background:${tint}33;">${artSVG(cat, tint)}</div>
        <div class="li-info">
          <h4>${p.name}</h4>
          <div class="li-size">${size}</div>
          <div class="qty-stepper"><button onclick="changeQty('${id}',-1)">−</button><span>${qty}</span><button onclick="changeQty('${id}',1)">+</button></div>
        </div>
        <div class="li-right"><span class="li-price">${formatTZS(p.price * qty)}</span><button class="remove-x" onclick="removeItem('${id}')">Remove</button></div>
      </div>`;
  }).join('');
  foot.innerHTML = `
    <div class="sum-row"><span>Subtotal</span><span>${formatTZS(await cartTotal())}</span></div>
    <div class="sum-row"><span>Delivery</span><span>Calculated at confirmation</span></div>
    <div class="sum-row total"><span>Total</span><span>${formatTZS(await cartTotal())}</span></div>
    <button class="checkout-btn" onclick="goCheckout()">Checkout</button>
  `;
}

function goCheckout() { panelView = 'checkout'; renderCartPanel(); }

// ---------- Submit order (FIXED with console logs) ----------
async function submitOrder(e) {
  e.preventDefault();
  const name = document.getElementById('coName').value.trim();
  const phone = document.getElementById('coPhone').value.trim();
  const region = document.getElementById('coRegion').value;
  const address = document.getElementById('coAddress').value.trim();
  const payment = document.querySelector('input[name="pay"]:checked').value;
  const errEl = document.getElementById('coError');

  if (!name || !phone || !region || !address) { errEl.style.display = 'block'; return; }
  errEl.style.display = 'none';

  const products = await fetchProducts();
  const orderItems = Object.entries(cart).map(([id, qty]) => {
    const p = products.find(x => x.id === id);
    return { id, qty, price: p ? p.price : 0 };
  });
  const total = orderItems.reduce((sum, item) => sum + item.price * item.qty, 0);

  const payload = {
    customer: { name, phone, region, address, payment },
    items: orderItems,
    total
  };

  console.log('Sending order:', payload); // Debug

  try {
    const data = await api('place_order', {
      method: 'POST',
      body: JSON.stringify(payload)
    });
    console.log('Order response:', data); // Debug
    if (data.success) {
      lastOrder = { id: data.order_id, name, total, payment };
      cart = {};
      panelView = 'confirmation';
      renderCartPanel();
    } else {
      alert('Order failed: ' + (data.error || 'Unknown error'));
    }
  } catch (error) {
    console.error('Network error:', error);
    alert('Network error. Please try again.');
  }
}
function continueShopping() { panelView = 'cart'; closeCart(); }

// ---------- Theme ----------
function toggleTheme() {
  const html = document.documentElement;
  const next = html.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
  html.setAttribute('data-theme', next);
}
function initTheme() {
  const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
  document.documentElement.setAttribute('data-theme', prefersDark ? 'dark' : 'light');
}

// ---------- Mobile menu ----------
function toggleMobile() {
  const m = document.getElementById('mobilePanel');
  m.style.display = m.style.display === 'flex' ? 'none' : 'flex';
}

// ============================================================
//  ADMIN LOGIC (including Settings tab)
// ============================================================
let adminLoggedIn = false;

async function checkAdminSession() {
  try {
    const data = await api('admin_check');
    adminLoggedIn = data.logged_in || false;
    return adminLoggedIn;
  } catch (e) {
    return false;
  }
}

function openAdmin() {
  document.getElementById('adminOverlay').classList.add('show');
  renderAdminContent();
}
function closeAdmin() {
  document.getElementById('adminOverlay').classList.remove('show');
}

async function renderAdminContent() {
  const container = document.getElementById('adminContent');
  const loggedIn = await checkAdminSession();

  if (loggedIn) {
    container.innerHTML = `
      <h2>Admin Dashboard</h2>
      <p class="sub">Manage products, orders, and site settings</p>
      <div class="admin-tabs">
        <button class="active" data-tab="products" onclick="switchAdminTab('products')">Products</button>
        <button data-tab="orders" onclick="switchAdminTab('orders')">Orders</button>
        <button data-tab="settings" onclick="switchAdminTab('settings')">Settings</button>
        <button onclick="logoutAdmin()" style="color:var(--rose);margin-left:auto;">Logout</button>
      </div>
      <div id="adminPanelProducts" class="admin-panel active">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
          <h3 style="font-size:18px;">All Products</h3>
          <button class="add-btn" onclick="showProductForm()">+ Add Product</button>
        </div>
        <div id="productList" class="item-list"></div>
        <div id="productForm" class="admin-form">
          <h4 id="formTitle" style="margin-bottom:12px;">Add Product</h4>
          <input type="hidden" id="pfId" />
          <div class="co-field"><label>Name</label><input type="text" id="pfName" placeholder="Product name" /></div>
          <div class="co-field"><label>Category</label>
            <select id="pfCategory">
              <option value="skincare">Skincare</option>
              <option value="body">Body Care</option>
              <option value="packages">Packages</option>
            </select>
          </div>
          <div class="co-field"><label>Size</label><input type="text" id="pfSize" placeholder="e.g. 30ml" /></div>
          <div class="co-field"><label>Price (TSh)</label><input type="number" id="pfPrice" placeholder="35000" /></div>
          <div class="co-field"><label>Description</label><textarea id="pfDesc" rows="2" placeholder="Product description"></textarea></div>
          <div class="co-field"><label>Badge (optional)</label><input type="text" id="pfBadge" placeholder="e.g. Bestseller, New" /></div>
          <div class="co-field"><label>Tint colour (hex)</label><input type="text" id="pfTint" placeholder="#F6D6DE" value="#F6D6DE" /></div>
          <div class="co-field">
            <label>Product Image</label>
            <input type="file" id="pfImage" accept="image/*" onchange="previewImage(event)" />
            <div class="image-preview" id="imagePreview"></div>
          </div>
          <div class="form-actions">
            <button class="btn-save" id="pfSaveBtn" onclick="saveProduct()">Save</button>
            <button class="btn-cancel" onclick="hideProductForm()">Cancel</button>
          </div>
        </div>
      </div>
      <div id="adminPanelOrders" class="admin-panel">
        <h3 style="font-size:18px;margin-bottom:12px;">Orders</h3>
        <div id="orderList" class="item-list"></div>
      </div>
      <div id="adminPanelSettings" class="admin-panel">
        <div id="adminSettingsContent"></div>
      </div>
    `;
    renderProductList();
    renderOrderList();
    renderAdminSettings();
  } else {
    container.innerHTML = `
      <div class="admin-login">
        <h2>Admin Access</h2>
        <p class="sub">Enter your credentials</p>
        <form id="adminLoginForm" onsubmit="handleAdminLogin(event)">
          <div class="co-field"><label>Username</label><input type="text" id="adminUser" value="admin" required /></div>
          <div class="co-field"><label>Password</label><input type="password" id="adminPass" required /></div>
          <button type="submit" class="btn btn-primary">Sign in</button>
          <div class="err" id="adminLoginErr">Invalid credentials</div>
        </form>
      </div>
    `;
  }
}

async function handleAdminLogin(e) {
  e.preventDefault();
  const username = document.getElementById('adminUser').value.trim();
  const password = document.getElementById('adminPass').value.trim();
  const err = document.getElementById('adminLoginErr');

  try {
    const data = await api('admin_login', {
      method: 'POST',
      body: JSON.stringify({ username, password })
    });
    if (data.success) {
      adminLoggedIn = true;
      renderAdminContent();
    } else {
      err.classList.add('show');
      setTimeout(() => err.classList.remove('show'), 3000);
    }
  } catch (error) {
    alert('Login error. Check server.');
  }
}

async function logoutAdmin() {
  await api('admin_logout', { method: 'DELETE' });
  adminLoggedIn = false;
  renderAdminContent();
}

async function renderProductList() {
  const products = await fetchProducts();
  const list = document.getElementById('productList');
  if (!list) return;
  list.innerHTML = products.map(p => `
    <div class="item-row">
      <div class="info">
        ${p.image ? `<img src="uploads/${p.image}" alt="${p.name}" />` : ''}
        <strong>${p.name}</strong>
        <small>${catLabel(p.category)} · ${p.size || 'N/A'} · ${formatTZS(p.price)}</small>
      </div>
      <div class="actions">
        <button onclick="editProduct('${p.id}')">Edit</button>
        <button class="danger" onclick="deleteProduct('${p.id}')">Delete</button>
      </div>
    </div>
  `).join('');
}

async function renderOrderList() {
  const list = document.getElementById('orderList');
  if (!list) return;
  try {
    const orders = await api('get_orders');
    if (!Array.isArray(orders) || orders.length === 0) {
      list.innerHTML = '<p style="color:var(--plum-soft);padding:20px 0;">No orders yet.</p>';
      return;
    }
    list.innerHTML = orders.map(o => `
      <div class="order-item">
        <div class="order-header"><span>Order #${o.id}</span><span>${formatTZS(o.total)}</span></div>
        <div class="order-details">
          <span>${o.customer_name}</span><span>${o.phone}</span><span>${o.region}</span><span>${o.payment_method}</span>
          <span style="display:block;font-size:12px;color:var(--plum-soft);">${new Date(o.placed_at).toLocaleString()}</span>
        </div>
        <div style="font-size:12px;color:var(--plum-soft);margin-top:6px;">
          Items: ${(o.items || []).map(item => `${item.product_name || item.product_id} (${item.quantity}x)`).join(', ')}
        </div>
      </div>
    `).join('');
  } catch (e) {
    list.innerHTML = '<p>Error loading orders.</p>';
  }
}

function switchAdminTab(tab) {
  document.querySelectorAll('.admin-tabs button').forEach(b => b.classList.toggle('active', b.dataset.tab === tab));
  document.getElementById('adminPanelProducts').classList.toggle('active', tab === 'products');
  document.getElementById('adminPanelOrders').classList.toggle('active', tab === 'orders');
  document.getElementById('adminPanelSettings').classList.toggle('active', tab === 'settings');
  if (tab === 'orders') renderOrderList();
  if (tab === 'settings') renderAdminSettings();
}

// ---------- Admin Settings ----------
async function renderAdminSettings() {
  const container = document.getElementById('adminSettingsContent');
  const settings = await fetchSettings();
  container.innerHTML = `
    <h3 style="font-size:18px;margin-bottom:16px;">Site Settings</h3>
    <form id="settingsForm" enctype="multipart/form-data">
      <div class="co-field">
        <label>Founder Photo</label>
        <input type="file" name="founder_image" accept="image/*" />
        ${settings.founder_image ? `<div style="margin-top:6px;"><img src="uploads/${settings.founder_image}" alt="Founder" style="max-width:120px;border-radius:var(--radius-sm);" /></div>` : ''}
      </div>
      <div class="co-field">
        <label>Instagram Tile 1</label>
        <input type="file" name="instagram_image_1" accept="image/*" />
        ${settings.instagram_image_1 ? `<div style="margin-top:6px;"><img src="uploads/${settings.instagram_image_1}" alt="IG1" style="max-width:120px;border-radius:var(--radius-sm);" /></div>` : ''}
      </div>
      <div class="co-field">
        <label>Instagram Tile 2</label>
        <input type="file" name="instagram_image_2" accept="image/*" />
        ${settings.instagram_image_2 ? `<div style="margin-top:6px;"><img src="uploads/${settings.instagram_image_2}" alt="IG2" style="max-width:120px;border-radius:var(--radius-sm);" /></div>` : ''}
      </div>
      <div class="co-field">
        <label>Instagram Tile 3</label>
        <input type="file" name="instagram_image_3" accept="image/*" />
        ${settings.instagram_image_3 ? `<div style="margin-top:6px;"><img src="uploads/${settings.instagram_image_3}" alt="IG3" style="max-width:120px;border-radius:var(--radius-sm);" /></div>` : ''}
      </div>
      <div class="co-field">
        <label>Instagram Tile 4</label>
        <input type="file" name="instagram_image_4" accept="image/*" />
        ${settings.instagram_image_4 ? `<div style="margin-top:6px;"><img src="uploads/${settings.instagram_image_4}" alt="IG4" style="max-width:120px;border-radius:var(--radius-sm);" /></div>` : ''}
      </div>
      <div class="form-actions">
        <button type="button" class="btn-save" onclick="saveSettings()">Save Settings</button>
        <button type="button" class="btn-cancel" onclick="closeAdmin()">Close</button>
      </div>
    </form>
  `;
}

async function saveSettings() {
  const form = document.getElementById('settingsForm');
  const formData = new FormData(form);

  try {
    const res = await fetch(window.location.pathname + '?action=save_settings', {
      method: 'POST',
      body: formData
    });
    const data = await res.json();
    if (data.success) {
      alert('Settings saved!');
      await fetchSettings();
      renderFounderImage();
      renderInstagramTiles();
      renderAdminSettings();
    } else {
      alert('Error: ' + (data.error || 'Unknown error'));
    }
  } catch (error) {
    alert('Network error.');
  }
}

// ---------- Product form ----------
function showProductForm(product) {
  const form = document.getElementById('productForm');
  form.classList.add('show');
  const preview = document.getElementById('imagePreview');
  if (product) {
    document.getElementById('pfId').value = product.id;
    document.getElementById('pfName').value = product.name;
    document.getElementById('pfCategory').value = product.category;
    document.getElementById('pfSize').value = product.size || '';
    document.getElementById('pfPrice').value = product.price;
    document.getElementById('pfDesc').value = product.desc;
    document.getElementById('pfBadge').value = product.badge || '';
    document.getElementById('pfTint').value = product.tint || '#F6D6DE';
    document.getElementById('formTitle').textContent = 'Edit Product';
    document.getElementById('pfSaveBtn').textContent = 'Update';
    if (product.image) {
      preview.innerHTML = `<img src="uploads/${product.image}" alt="Product" />`;
    } else {
      preview.innerHTML = '';
    }
  } else {
    document.getElementById('pfId').value = '';
    document.getElementById('pfName').value = '';
    document.getElementById('pfCategory').value = 'skincare';
    document.getElementById('pfSize').value = '';
    document.getElementById('pfPrice').value = '';
    document.getElementById('pfDesc').value = '';
    document.getElementById('pfBadge').value = '';
    document.getElementById('pfTint').value = '#F6D6DE';
    document.getElementById('formTitle').textContent = 'Add Product';
    document.getElementById('pfSaveBtn').textContent = 'Save';
    document.getElementById('imagePreview').innerHTML = '';
  }
  document.getElementById('pfImage').value = '';
}

function hideProductForm() {
  document.getElementById('productForm').classList.remove('show');
}

function previewImage(e) {
  const file = e.target.files[0];
  if (!file) return;
  const reader = new FileReader();
  reader.onload = function(ev) {
    document.getElementById('imagePreview').innerHTML = `<img src="${ev.target.result}" alt="Preview" />`;
  };
  reader.readAsDataURL(file);
}

async function saveProduct() {
  const id = document.getElementById('pfId').value;
  const name = document.getElementById('pfName').value.trim();
  const category = document.getElementById('pfCategory').value;
  const size = document.getElementById('pfSize').value.trim();
  const price = parseInt(document.getElementById('pfPrice').value);
  const desc = document.getElementById('pfDesc').value.trim();
  const badge = document.getElementById('pfBadge').value.trim();
  const tint = document.getElementById('pfTint').value.trim() || '#F6D6DE';
  const imageFile = document.getElementById('pfImage').files[0];

  if (!name || isNaN(price) || !desc) {
    alert('Please fill all required fields (Name, Price, Description).');
    return;
  }

  const formData = new FormData();
  formData.append('id', id);
  formData.append('name', name);
  formData.append('category', category);
  formData.append('size', size);
  formData.append('price', price);
  formData.append('desc', desc);
  formData.append('badge', badge);
  formData.append('tint', tint);
  if (imageFile) formData.append('image', imageFile);

  try {
    const res = await fetch(window.location.pathname + '?action=save_product', {
      method: 'POST',
      body: formData
    });
    const data = await res.json();
    if (data.success) {
      hideProductForm();
      renderProductList();
      renderProducts();
      renderPackages();
      alert('Product saved successfully!');
    } else {
      alert('Error: ' + (data.error || 'Unknown error'));
    }
  } catch (error) {
    alert('Network error. Check your server.');
  }
}

async function editProduct(id) {
  const products = await fetchProducts();
  const p = products.find(x => x.id === id);
  if (p) showProductForm(p);
}

async function deleteProduct(id) {
  if (!confirm('Delete this product?')) return;
  try {
    const data = await api('delete_product&id=' + id, { method: 'DELETE' });
    if (data.success) {
      renderProductList();
      renderProducts();
      renderPackages();
    } else {
      alert('Delete failed.');
    }
  } catch (error) {
    alert('Network error.');
  }
}

// ---------- Init ----------
document.addEventListener('DOMContentLoaded', async function() {
  initTheme();
  await fetchSettings();
  renderFounderImage();
  renderInstagramTiles();
  renderPackages();
  renderProducts();
  renderCartPanel();

  // Double‑click brand to open admin
  document.getElementById('brandTrigger').addEventListener('dblclick', function(e) {
    e.preventDefault();
    openAdmin();
  });

  // Close admin on overlay click
  document.getElementById('adminOverlay').addEventListener('click', function(e) {
    if (e.target === this) closeAdmin();
  });

  // Escape key closes overlays
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
      closeAdmin();
      closeCart();
      closeProductModal();
    }
  });
});
</script>
</body>
</html>