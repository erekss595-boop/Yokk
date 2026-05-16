<?php
require 'db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }

$u_id = intval($_SESSION['user_id']);
$today = date('Y-m-d');
$msg = '';
$error = '';

// --- ОДОБРЕНИЕ/ОТКЛОНЕНИЕ СКИДКИ ---
if (isset($_POST['handle_discount'])) {
    $inv_id = intval($_POST['inv_id']);
    $action = $_POST['action'] ?? '';

    if ($action == 'approve') {
        $st = $pdo->prepare("SELECT amount, requested_discount FROM invoices WHERE id = ? AND sender_id1 = ?");
        $st->execute([$inv_id, $u_id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        if ($row && $row['requested_discount'] > 0) {
            $pdo->prepare(
                "UPDATE invoices 
                SET amount = amount - requested_discount,
                    requested_discount = 0,
                    discount_status = 'Одобрено'
                WHERE id = ?"
            )->execute([$inv_id]);
            $msg = 'Скидка одобрена.';
        }

    } elseif ($action == 'reject') {
        $pdo->prepare(
            "UPDATE invoices 
            SET discount_status = 'Отклонено',
                requested_discount = 0
            WHERE id = ?"
        )->execute([$inv_id]);
        $msg = 'Скидка отклонена.';
    }
    header("Location: index.php"); exit;
}

// --- СПИСАТЬ БОНУСЫ СЕЙЧАС (ЧАСТИЧНАЯ ОПЛАТА) ---
if (isset($_POST['use_bonuses'])) {
    $inv_id = intval($_POST['inv_id']);
    // Ожидаем сумму в BYN (с плавающей точкой)
    $use_byn = floatval($_POST['bonuses_byn'] ?? 0);
    $use = intval(round($use_byn * 100)); // в копейках

    // Проверки
    $invStmt = $pdo->prepare("SELECT * FROM invoices WHERE id = ? AND receiver_id1 = ?");
    $invStmt->execute([$inv_id, $u_id]);
    $invoice = $invStmt->fetch(PDO::FETCH_ASSOC);

    if (!$invoice) {
        $error = 'Счет не найден или доступ запрещен.';
    } elseif ($invoice['status'] == 'Оплачен') {
        $error = 'Счет уже оплачен.';
    } elseif ($use <= 0) {
        $error = 'Введите корректную сумму бонусов.';
    } else {
        // Получим актуальный баланс пользователя
        $uStmt = $pdo->prepare("SELECT bonus_balance FROM users1 WHERE id = ?");
        $uStmt->execute([$u_id]);
        $uRow = $uStmt->fetch(PDO::FETCH_ASSOC);
        $balance = intval($uRow['bonus_balance'] ?? 0);

        if ($use > $balance) {
            $error = 'У вас недостаточно бонусов.';
        } else {
            // Рассчитаем оставшуюся к оплате сумму (в копейках)
            $final_amount_byn = calculateFinalAmount($invoice); // BYN
            $final_cop = intval(round($final_amount_byn * 100));

            // Нельзя списать больше, чем остаток
            $use = min($use, $final_cop);

            try {
                $pdo->beginTransaction();

                // Списываем бонусы у пользователя
                $pdo->prepare("UPDATE users1 SET bonus_balance = bonus_balance - ? WHERE id = ?")->execute([$use, $u_id]);

                // Увеличиваем bonuses_spent в счете (в копейках)
                $pdo->prepare("UPDATE invoices SET bonuses_spent = COALESCE(bonuses_spent,0) + ? , pending_status = ?, payment_method = COALESCE(payment_method, '') WHERE id = ?")
                    ->execute([$use, 'Бонусы списаны', $inv_id]);

                $pdo->commit();
                $msg = 'Бонусы списаны. Осталось доплатить: ' . number_format(max(0, ($final_cop - $use)/100), 2) . ' BYN.';
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log('Ошибка списания бонусов: ' . $e->getMessage());
                $error = 'Ошибка при списании бонусов. Попробуйте позже.';
            }
        }
    }
    header('Location: index.php'); exit;
}

// --- пользователь ---
$u_stmt = $pdo->prepare("SELECT bonus_balance, loyalty_level, card_number, temp_auth_code FROM users1 WHERE id = ?");
$u_stmt->execute([$u_id]);
$curr_user = $u_stmt->fetch(PDO::FETCH_ASSOC);

// --- счета (включая содержимое и валюты) ---
$sql_base = "SELECT i.*, u.username as contact_name,
            (SELECT GROUP_CONCAT(CONCAT(description, ' (', (original_amount + 0), ' ', currency, ')') SEPARATOR '\n')
            FROM invoice_items WHERE invoice_id = i.id) as items_summary,
            (SELECT GROUP_CONCAT(CONCAT((original_amount + 0), ' ', currency) SEPARATOR ', ')
            FROM invoice_items WHERE invoice_id = i.id) as orig_cur
            FROM invoices i JOIN users1 u ON ";

$sent_stmt = $pdo->prepare($sql_base . "i.receiver_id1 = u.id WHERE i.sender_id1 = ? ORDER BY i.created_at DESC");
$sent_stmt->execute([$u_id]);
$sent_invoices = $sent_stmt->fetchAll(PDO::FETCH_ASSOC);

$recv_stmt = $pdo->prepare($sql_base . "i.sender_id1 = u.id WHERE i.receiver_id1 = ? ORDER BY i.created_at DESC");
$recv_stmt->execute([$u_id]);
$received_invoices = $recv_stmt->fetchAll(PDO::FETCH_ASSOC);

// --- уведомления ---
$deadline_alerts = [];
$status_requests = [];
$new_invoice_alerts = [];
$discount_requests = []; // Запросы скидок ОТ получателей (к обработке отправителем)

foreach ($received_invoices as $inv) {
    if ($inv['status'] == 'Новый') {
        $new_invoice_alerts[] = "🔔 Новый счет №<b>{$inv['invoice_number']}</b> от {$inv['contact_name']}";
    }

    if ($inv['status'] != 'Оплачен' && !empty($inv['due_date']) && strtotime($inv['due_date']) < strtotime($today)) {
        $deadline_alerts[] = "❌ Срок оплаты счета <b>{$inv['invoice_number']}</b> истек!";
    }
}

foreach ($sent_invoices as $inv) {
    if (!empty($inv['pending_status'])) {
        $status_requests[] = $inv;
    }

    // Запросы скидок от получателей
    if ($inv['discount_status'] == 'Ожидает') {
        $discount_requests[] = $inv;
    }
}

// --- ФУНКЦИЯ: Расчет финальной суммы с учетом скидок и бонусов ---
function calculateFinalAmount($invoice) {
    // Исходная сумма
    $amount = floatval($invoice['amount']);

    // Вычитаем уже одобренные скидки
    if ($invoice['discount_status'] === 'Одобрено' && !empty($invoice['requested_discount'])) {
        $amount -= floatval($invoice['requested_discount']);
    }

    // Вычитаем потраченные бонусы (конвертируем из копеек в BYN)
    $bonus_spent = (floatval($invoice['bonuses_spent'] ?? 0) / 100);
    $amount -= $bonus_spent;

    // Минимум 0
    return max(0, $amount);
}

// --- суммы ---
$total_sent = 0;
foreach($sent_invoices as $i) {
    if($i['status']!='Оплачен' && $i['status']!='Отменен') {
        $total_sent += calculateFinalAmount($i);
    }
}

$total_recv = 0;
foreach($received_invoices as $i) {
    if($i['status']!='Оплачен' && $i['status']!='Отменен') {
        $total_recv += calculateFinalAmount($i);
    }
}

// --- статус ---
function getStatusColor($s) {
    switch ($s) {
        case 'Оплачен': return 'table-success';
        case 'В обработке':
        case 'В процессе': return 'table-warning';
        case 'Отменен': return 'table-danger';
        default: return '';
    }
}

// --- ФУНКЦИЯ: Отображение статуса скидки ---
function getDiscountBadge($invoice) {
    if ($invoice['discount_status'] === 'Нет' || empty($invoice['discount_status'])) {
        return '';
    }

    $discount_amount = floatval($invoice['requested_discount']);
    $status_text = '';
    $status_class = '';

    if ($invoice['discount_status'] === 'Ожидает') {
        $status_text = '⏳ Ожидание';
        $status_class = 'badge-warning';
    } elseif ($invoice['discount_status'] === 'Одобрено') {
        $status_text = '✅ Одобрено';
        $status_class = 'badge-success';
    } elseif ($invoice['discount_status'] === 'Отклонено') {
        $status_text = '❌ Отклонено';
        $status_class = 'badge-danger';
    }

    return "<span class='badge {$status_class} mt-1'>Скидка: -" . number_format($discount_amount,2) . " BYN ({$status_text})</span>";
}

function safeGet($arr, $key, $default = '') {
    return isset($arr[$key]) && $arr[$key] !== null ? htmlspecialchars($arr[$key], ENT_QUOTES, 'UTF-8') : $default;
}

include 'header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-5 mt-2">
    <div>
        <h2 class="fw-bold mb-0">Дашборд</h2>
        <p class="text-muted small mb-0">Управление взаиморасчетами</p>
    </div>
    <a href="create_invoice.php" class="btn btn-primary btn-lg shadow px-4">✨ Выставить счет</a>
</div>

<?php if($msg): ?><div class="alert alert-success"><?php echo htmlspecialchars($msg); ?></div><?php endif; ?>
<?php if($error): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

<!-- УВЕДОМЛЕНИЯ -->
<?php if(!empty($new_invoice_alerts) || !empty($deadline_alerts) || !empty($status_requests) || !empty($discount_requests) || !empty($curr_user['temp_auth_code'])): ?>
<div class="card bg-white shadow-sm p-3 mb-4 border-start border-primary border-4 rounded-4">

    <h6 class="fw-bold text-uppercase small text-primary mb-3">⚡ Уведомления (<?php echo count($new_invoice_alerts) + count($deadline_alerts) + count($status_requests) + count($discount_requests) + (!empty($curr_user['temp_auth_code']) ? 1 : 0); ?>)</h6>

    <?php if($curr_user['temp_auth_code']): ?>
        <div class="alert alert-dark py-2 small mb-2 d-flex justify-content-between align-items-center rounded-3" style="background:#0f172a; color:white; border:0;">
            <span>🛡️ Ваш 2FA код: <b class="text-warning"><?php echo safeGet($curr_user, 'temp_auth_code'); ?></b></span>
        </div>
    <?php endif; ?>

    <?php foreach($new_invoice_alerts as $m): ?>
        <div class="alert alert-info py-2 small mb-2 rounded-3">🔔 <?php echo $m; ?></div>
    <?php endforeach; ?>

    <?php foreach($deadline_alerts as $m): ?>
        <div class="alert alert-danger py-2 small mb-2 rounded-3"><?php echo $m; ?></div>
    <?php endforeach; ?>

    <?php foreach($discount_requests as $d): ?>
        <div class="alert alert-warning py-3 small mb-2 d-flex justify-content-between align-items-center rounded-3">
            <div>
                <span>💰 Запрос скидки на счет <b>№<?php echo safeGet($d, 'invoice_number'); ?></b></span>
                <br/>
                <small class="text-muted">Сумма скидки: <b><?php echo number_format(floatval($d['requested_discount']), 2); ?> BYN</b></small>
                <br/>
                <small class="text-muted">От: <b><?php echo safeGet($d, 'contact_name'); ?></b></small>
            </div>
            <form method="POST" class="d-flex gap-1 ms-2" style="flex-shrink: 0;">
                <input type="hidden" name="inv_id" value="<?php echo intval($d['id']); ?>">
                <button type="submit" name="handle_discount" value="1" class="btn btn-sm btn-success fw-bold px-3" title="Одобрить скидку">✅</button>
                <button type="submit" name="action" value="reject" class="btn btn-sm btn-danger fw-bold px-3" title="Отклонить скидку">❌</button>
            </form>
        </div>
    <?php endforeach; ?>

    <?php foreach($status_requests as $n): ?>
        <div class="alert alert-primary py-3 small mb-2 d-flex justify-content-between align-items-center rounded-3">
            <div>
                <span>💳 Подтвердите получение оплаты</span>
                <br/>
                <small class="text-muted">Счет <b>№<?php echo safeGet($n, 'invoice_number'); ?></b> - <b><?php echo number_format(calculateFinalAmount($n), 2); ?> BYN</b></small>
            </div>
            <form action="confirm_status.php" method="POST" style="flex-shrink: 0;">
                <input type="hidden" name="id" value="<?php echo intval($n['id']); ?>">
                <button type="submit" name="action" value="approve" class="btn btn-sm btn-success fw-bold">✅ Подтвердить</button>
            </form>
        </div>
    <?php endforeach; ?>

</div>
<?php endif; ?>

<!-- СТАТИСТИКА -->
<div class="row mb-5 g-4 text-center">
    <div class="col-md-4">
        <div class="card p-4 shadow-sm border-0 rounded-4" style="background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);">
            <div class="text-muted small fw-bold">💰 Ожидается</div>
            <h3 class="text-success fw-bold m-0"><?php echo number_format($total_sent, 2); ?> BYN</h3>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card p-4 shadow-sm border-0 rounded-4" style="background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);">
            <div class="text-muted small fw-bold">📊 Я должен</div>
            <h3 class="text-danger fw-bold m-0"><?php echo number_format($total_recv, 2); ?> BYN</h3>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card p-4 shadow-sm border-0 rounded-4" style="background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%);">
            <div class="text-muted small fw-bold">🎁 Бонусы</div>
            <h3 class="text-warning fw-bold m-0"><?php echo number_format((floatval($curr_user['bonus_balance'] ?? 0) / 100), 2); ?> BYN</h3>
        </div>
    </div>
</div>

<!-- ВЫСТАВЛЕННЫЕ СЧЕТА -->
<div class="card bg-white shadow-sm border-0 mb-5 overflow-hidden rounded-4">
    <div class="card-header bg-dark text-white p-3 fw-bold">📤 Выставленные мной счета (<?php echo count($sent_invoices); ?>)</div>
    <div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead class="table-light small"><tr><th>№</th><th>ЗАКАЗЧИК</th><th>СОДЕРЖАНИЕ</th><th>ИСХОДНАЯ</th><th>СКИДКА</th><th>К ОПЛАТЕ</th><th>СТАТУС</th><th>ДЕЙСТВИЯ</th></tr></thead>
        <tbody>
            <?php foreach($sent_invoices as $i): 
                $original = floatval($i['amount']);
                $discount_amount = ($i['discount_status'] === 'Одобрено' && !empty($i['requested_discount'])) ? floatval($i['requested_discount']) : 0;
                $final = calculateFinalAmount($i);
                $items = nl2br(htmlspecialchars($i['items_summary'] ?? ''));
                $orig_cur = htmlspecialchars($i['orig_cur'] ?? '');
            ?>
            <tr class="<?php echo getStatusColor($i['status']); ?> bg-opacity-10">
                <td><code class="fw-bold"><?php echo safeGet($i, 'invoice_number'); ?></code></td>
                <td><b><?php echo safeGet($i, 'contact_name'); ?></b></td>
                <td style="max-width:240px;"><?php echo $items; ?><div class="small text-muted mt-1"><?php echo $orig_cur; ?></div></td>
                <td><span class="text-muted"><?php echo number_format($original, 2); ?> BYN</span></td>
                <td>
                    <?php if($discount_amount > 0): ?>
                        <span class="badge bg-success">-<?php echo number_format($discount_amount, 2); ?></span>
                    <?php else: ?>
                        <span class="text-muted small">—</span>
                    <?php endif; ?>
                </td>
                <td><b><?php echo number_format($final, 2); ?> BYN</b></td>
                <td>
                    <form action="update_status.php" method="POST" class="d-inline">
                        <input type="hidden" name="id" value="<?php echo intval($i['id']); ?>">
                        <select name="status" onchange="this.form.submit()" class="form-select form-select-sm fw-bold border-0 shadow-none" style="max-width: 140px;">
                            <?php foreach(['Новый','В обработке','Оплачен','Отменен'] as $s) echo "<option " . ($i['status'] == $s ? 'selected' : '') . ">$s</option>"; ?>
                        </select>
                    </form>
                    <?php echo getDiscountBadge($i); ?>
                </td>
                <td>
                    <div class="btn-group btn-group-sm" role="group">
                        <a href="print_invoice.php?id=<?php echo intval($i['id']); ?>" class="btn btn-outline-primary" target="_blank" title="Печать">🖨️</a>
                        <a href="edit_invoice.php?id=<?php echo intval($i['id']); ?>" class="btn btn-outline-warning" title="Редактировать">✏️</a>
                        <a href="delete_invoice.php?id=<?php echo intval($i['id']); ?>" class="btn btn-outline-danger" onclick="return confirm('Удалить счет?')" title="Удалить">✕</a>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table></div>
</div>

<!-- ПОЛУЧЕННЫЕ СЧЕТА -->
<div class="card bg-white shadow-sm border-0 mb-5 overflow-hidden rounded-4">
    <div class="card-header bg-primary text-white p-3 fw-bold">📥 Мои полученные счета (<?php echo count($received_invoices); ?>)</div>
    <div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead class="table-light small"><tr><th>№</th><th>ИСПОЛНИТЕЛЬ</th><th>СОДЕРЖАНИЕ</th><th>ИСХОДНАЯ</th><th>СКИДКА / БОНУСЫ</th><th>К ОПЛАТЕ</th><th>ДЕЙСТВИЕ</th><th>ПЕЧАТЬ</th></tr></thead>
        <tbody>
            <?php foreach($received_invoices as $i): 
                $original = floatval($i['amount']);
                $discount_amount = ($i['discount_status'] === 'Одобрено' && !empty($i['requested_discount'])) ? floatval($i['requested_discount']) : 0;
                $bonus_spent = (floatval($i['bonuses_spent'] ?? 0) / 100);
                $final = calculateFinalAmount($i);
                $items = nl2br(htmlspecialchars($i['items_summary'] ?? ''));
                $orig_cur = htmlspecialchars($i['orig_cur'] ?? '');
            ?>
            <tr class="<?php echo getStatusColor($i['status']); ?> bg-opacity-10">
                <td><code class="fw-bold"><?php echo safeGet($i, 'invoice_number'); ?></code></td>
                <td><b><?php echo safeGet($i, 'contact_name'); ?></b></td>
                <td style="max-width:240px;"><?php echo $items; ?><div class="small text-muted mt-1"><?php echo $orig_cur; ?></div></td>
                <td><span class="text-muted"><?php echo number_format($original, 2); ?> BYN</span></td>
                <td>
                    <div class="small">
                        <?php if($discount_amount > 0): ?>
                            <span class="badge bg-success d-block mb-1">Скидка: -<?php echo number_format($discount_amount, 2); ?></span>
                        <?php endif; ?>
                        <?php if($bonus_spent > 0): ?>
                            <span class="badge bg-warning">Бонусы: -<?php echo number_format($bonus_spent, 2); ?></span>
                        <?php endif; ?>
                        <?php if($discount_amount === 0 && $bonus_spent === 0): ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </div>
                </td>
                <td><b class="text-primary fs-5"><?php echo number_format($final, 2); ?> BYN</b></td>
                <td>
                    <div class="mb-2">
                        <?php if($i['status'] != 'Оплачен' && empty($i['pending_status'])): ?>
                            <a href="pay_invoice.php?id=<?php echo intval($i['id']); ?>" class="btn btn-sm btn-success fw-bold w-100 shadow-sm mb-2">💳 Оплатить</a>
                        <?php else: ?>
                            <span class="badge bg-light text-dark border w-100 p-2 mb-2"><?php echo safeGet($i, 'pending_status') ?: safeGet($i, 'status'); ?></span>
                        <?php endif; ?>

                        <!-- Форма: списать бонусы сейчас -->
                        <?php if($i['status'] != 'Оплачен' && $final > 0): ?>
                            <form method="POST" class="d-flex gap-2">
                                <input type="hidden" name="inv_id" value="<?php echo intval($i['id']); ?>">
                                <input type="number" name="bonuses_byn" step="0.01" min="0.01" max="<?php echo number_format(min((floatval($curr_user['bonus_balance'] ?? 0)/100), $final), 2); ?>" class="form-control form-control-sm" placeholder="Списать бонусов (BYN)">
                                <button type="submit" name="use_bonuses" class="btn btn-sm btn-warning">🎁 Списать</button>
                            </form>
                        <?php endif; ?>

                    </div>
                    <form action="update_status.php" method="POST" class="d-inline w-100">
                        <input type="hidden" name="id" value="<?php echo intval($i['id']); ?>">
                        <select name="status" onchange="this.form.submit()" class="form-select form-select-sm small border-0 shadow-none">
                            <?php foreach(['Новый','В обработке','Оплачен'] as $s) echo "<option " . ($i['status'] == $s ? 'selected' : '') . ">$s</option>"; ?>
                        </select>
                    </form>
                </td>
                <td><a href="print_invoice.php?id=<?php echo intval($i['id']); ?>" class="btn btn-sm btn-dark px-3" target="_blank" title="Печать">🖨️</a></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table></div>
</div>

<?php include 'footer.php'; ?>
