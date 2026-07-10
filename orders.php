<?php
require_once 'config.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    // Place order (no auth needed)
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data || !isset($data['items']) || !isset($data['customer'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid order data']);
        exit;
    }

    $customer = $data['customer'];
    $items = $data['items'];
    $total = $data['total'];

    $orderId = 'SB' . date('ymd') . substr(md5(uniqid()), 0, 4);

    try {
        $pdo->beginTransaction();

        // Insert order
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

        // Insert items
        $sql = "INSERT INTO order_items (order_id, product_id, quantity, price) VALUES (?,?,?,?)";
        $stmt = $pdo->prepare($sql);
        foreach ($items as $item) {
            $stmt->execute([
                $orderId,
                $item['id'],
                $item['qty'],
                $item['price']
            ]);
        }

        $pdo->commit();
        echo json_encode(['success' => true, 'order_id' => $orderId]);
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Order failed: ' . $e->getMessage()]);
    }
    exit;
}

// GET – list orders (admin only)
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$orders = [];
$stmt = $pdo->query("SELECT * FROM orders ORDER BY placed_at DESC");
while ($order = $stmt->fetch(PDO::FETCH_ASSOC)) {
    // Fetch items
    $itemsStmt = $pdo->prepare("SELECT product_id, quantity, price FROM order_items WHERE order_id = ?");
    $itemsStmt->execute([$order['id']]);
    $order['items'] = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
    $orders[] = $order;
}
echo json_encode($orders);
?>