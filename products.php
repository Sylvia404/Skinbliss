<?php
require_once 'config.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // List all products
    $stmt = $pdo->query("SELECT * FROM products ORDER BY id");
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($products);
    exit;
}

// Admin-only actions: POST (add/update) and DELETE
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid data']);
        exit;
    }

    // If 'id' is present, update; else insert
    if (isset($data['id']) && $data['id'] !== '') {
        // Update
        $sql = "UPDATE products SET name=?, category=?, size=?, price=?, `desc`=?, badge=?, tint=? WHERE id=?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $data['name'],
            $data['category'],
            $data['size'],
            $data['price'],
            $data['desc'],
            $data['badge'] ?? '',
            $data['tint'] ?? '#F6D6DE',
            $data['id']
        ]);
        echo json_encode(['success' => true, 'message' => 'Product updated']);
    } else {
        // Insert new – generate ID
        $id = 'p' . substr(md5(uniqid()), 0, 6);
        $sql = "INSERT INTO products (id, name, category, size, price, `desc`, badge, tint) VALUES (?,?,?,?,?,?,?,?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $id,
            $data['name'],
            $data['category'],
            $data['size'],
            $data['price'],
            $data['desc'],
            $data['badge'] ?? '',
            $data['tint'] ?? '#F6D6DE'
        ]);
        echo json_encode(['success' => true, 'message' => 'Product added', 'id' => $id]);
    }
    exit;
}

if ($method === 'DELETE') {
    $id = $_GET['id'] ?? null;
    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing product ID']);
        exit;
    }
    $stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
    $stmt->execute([$id]);
    echo json_encode(['success' => true, 'message' => 'Product deleted']);
    exit;
}
?>