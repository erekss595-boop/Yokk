<?php
require 'db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }

$u_id = intval($_SESSION['user_id']);

// Get invoice id from POST/GET
$inv_id = isset($_REQUEST['id']) ? intval($_REQUEST['id']) : 0;
if ($inv_id <= 0) { header('Location: index.php'); exit; }

// Load invoice and related info
$st = $pdo->prepare("SELECT i.*, s.username as sender_name, r.username as receiver_name,
    (SELECT GROUP_CONCAT(CONCAT(description, ' (', (original_amount + 0), ' ', currency, ')') SEPARATOR '\n') FROM invoice_items WHERE invoice_id = i.id) as items_summary
    FROM invoices i
    LEFT JOIN users1 s ON s.id = i.sender_id1
    LEFT JOIN users1 r ON r.id = i.receiver_id1
    WHERE i.id = ? LIMIT 1");
$st->execute([$inv_id]);
$invoice = $st->fetch(PDO::FETCH_ASSOC);
if (!$invoice) { header('Location: index.php'); exit; }

// Only sender can confirm payment (they confirm receiving payment from receiver)
if (intval($invoice['sender_id1']) !== $u_id) {
    // unauthorized
    header('HTTP/1.1 403 Forbidden');
    echo "Доступ запрещён.";
    exit;
}

// Helper to calculate final amount (same logic as in index.php)
function calculateFinalAmountLocal($invoice) {
    $amount = floatval($invoice['amount']);
    if ($invoice['discount_status'] === 'Одобрено' && !empty($invoice['requested_discount'])) {
        $amount -= floatval($invoice['requested_discount']);
    }
    $bonus_spent = (floatval($invoice['bonuses_spent'] ?? 0) / 100);
    $amount -= $bonus_spent;
    return max(0, $amount);
}

$final = calculateFinalAmountLocal($invoice);

// If form submitted with payment details -> finalize
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'approve' && isset($_POST['payment_method'])) {
    $payment_method = in_array($_POST['payment_method'], ['card','cash','other']) ? $_POST['payment_method'] : 'other';
    $card_info = isset($_POST['card_info']) ? trim($_POST['card_info']) : '';
    $paid_amount = isset($_POST['paid_amount']) ? floatval($_POST['paid_amount']) : $final;
    $paid_amount = max(0, $paid_amount);

    try {
        $pdo->beginTransaction();

        // Update invoice: mark as paid
        $upd = $pdo->prepare("UPDATE invoices SET status = 'Оплачен', paid_at = NOW(), pending_status = NULL WHERE id = ?");
        $upd->execute([$inv_id]);

        // Ensure receipts table exists
        $pdo->exec("CREATE TABLE IF NOT EXISTS receipts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            invoice_id INT NOT NULL,
            created_at DATETIME NOT NULL,
            receipt_data JSON NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Build receipt data
        $items = [];
        $itSt = $pdo->prepare("SELECT description, original_amount, currency FROM invoice_items WHERE invoice_id = ?");
        $itSt->execute([$inv_id]);
        while ($it = $itSt->fetch(PDO::FETCH_ASSOC)) {
            $items[] = [
                'description' => $it['description'],
                'amount' => (float)$it['original_amount'],
                'currency' => $it['currency']
            ];
        }

        $receipt = [
            'date' => date('Y-m-d H:i:s'),
            'invoice_number' => $invoice['invoice_number'],
            'sender' => $invoice['sender_name'],
            'receiver' => $invoice['receiver_name'],
            'items' => $items,
            'discount' => isset($invoice['requested_discount']) ? (float)$invoice['requested_discount'] : 0.0,
            'bonuses_spent' => (floatval($invoice['bonuses_spent'] ?? 0) / 100),
            'total_due' => $final,
            'paid_amount' => $paid_amount,
            'payment_method' => $payment_method,
            'card_info' => $card_info
        ];

        // Insert receipt
        $ins = $pdo->prepare("INSERT INTO receipts (invoice_id, created_at, receipt_data) VALUES (?, NOW(), ?)");
        $ins->execute([$inv_id, json_encode($receipt, JSON_UNESCAPED_UNICODE)]);
        $receipId = $pdo->lastInsertId();

        $pdo->commit();

        // Generate printable HTML receipt file in receipts/ directory
        $receiptsDir = __DIR__ . '/receipts';
        if (!is_dir($receiptsDir)) mkdir($receiptsDir, 0755, true);

        $html = "<!doctype html><html><head><meta charset=\"utf-8\"><title>Чек #" . htmlspecialchars($receipId) . "</title>
            <style>body{font-family:Arial,Helvetica,sans-serif;padding:20px}table{width:100%;border-collapse:collapse}td,th{padding:8px;border:1px solid #ddd}</style>
            </head><body>";
        $html .= "<h2>Чек оплаты — №" . htmlspecialchars($receipId) . "</h2>";
        $html .= "<p><strong>Дата:</strong> " . htmlspecialchars($receipt['date']) . "</p>";
        $html .= "<p><strong>Счет №:</strong> " . htmlspecialchars($receipt['invoice_number']) . "</p>";
        $html .= "<p><strong>За что:</strong></p>";
        $html .= "<table><thead><tr><th>Описание</th><th>Сумма</th><th>Валюта</th></tr></thead><tbody>";
        foreach ($receipt['items'] as $it) {
            $html .= "<tr><td>" . htmlspecialchars($it['description']) . "</td><td>" . number_format($it['amount'],2) . "</td><td>" . htmlspecialchars($it['currency']) . "</td></tr>";
        }
        $html .= "</tbody></table>";
        $html .= "<p><strong>Скидка:</strong> " . number_format($receipt['discount'],2) . " BYN</p>";
        $html .= "<p><strong>Бонусы списаны:</strong> " . number_format($receipt['bonuses_spent'],2) . " BYN</p>";
        $html .= "<p><strong>Итого к оплате:</strong> " . number_format($receipt['total_due'],2) . " BYN</p>";
        $html .= "<p><strong>Оплачено:</strong> " . number_format($receipt['paid_amount'],2) . " BYN</p>";
        $html .= "<p><strong>Способ оплаты:</strong> " . htmlspecialchars(ucfirst($receipt['payment_method'])) . "";
        if (!empty($receipt['card_info'])) $html .= " — " . htmlspecialchars($receipt['card_info']);
        $html .= "</p>";
        $html .= "</body></html>";

        $filePath = $receiptsDir . "/receipt_" . $receipId . ".html";
        file_put_contents($filePath, $html);

        // Redirect to receipt view page so sender sees the generated receipt
        header('Location: receipt_view.php?id=' . intval($receipId));
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('confirm_status error: ' . $e->getMessage());
        header('Location: index.php?err=confirm');
        exit;
    }
}

// If we got here and POST action approve but no payment_method provided, show a small form to collect payment details
// Or if visited via GET, show the form.
$default_paid = $final;
?>

<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Подтверждение оплаты — Счет #<?php echo htmlspecialchars($invoice['invoice_number']); ?></title>
    <link rel="stylesheet" href="/assets/bootstrap.min.css">
</head>
<body class="p-4">
<div class="container" style="max-width:720px">
    <h3>Подтвердить получение оплаты — Счет №<?php echo htmlspecialchars($invoice['invoice_number']); ?></h3>
    <p><strong>От:</strong> <?php echo htmlspecialchars($invoice['receiver_name']); ?> &nbsp; <strong>Сумма к оплате:</strong> <?php echo number_format($final,2); ?> BYN</p>

    <form method="POST">
        <input type="hidden" name="id" value="<?php echo intval($inv_id); ?>">
        <input type="hidden" name="action" value="approve">

        <div class="mb-3">
            <label class="form-label">Способ оплаты</label>
            <select name="payment_method" class="form-select">
                <option value="card">Карта</option>
                <option value="cash">Наличные</option>
                <option value="other">Другое</option>
            </select>
        </div>
        <div class="mb-3">
            <label class="form-label">Детали карты (опционально, например последние 4)</label>
            <input type="text" name="card_info" class="form-control" placeholder="0000">
        </div>
        <div class="mb-3">
            <label class="form-label">Оплаченная сумма (BYN)</label>
            <input type="number" step="0.01" name="paid_amount" class="form-control" value="<?php echo number_format($default_paid,2,'.',''); ?>">
            <div class="form-text">Введите сумму, которую реально получили. Оставшаяся часть, если есть, должен доплатить плательщик позже.</div>
        </div>
        <button class="btn btn-success">Подтвердить и создать чек</button>
        <a href="index.php" class="btn btn-secondary">Отмена</a>
    </form>
</div>
</body>
</html>
