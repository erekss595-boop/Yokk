<?php
require 'db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// ============ КОНФИГУРАЦИЯ ============
const LOYALTY_RATES = ['Classic' => 0.05, 'Silver' => 0.10, 'Gold' => 0.15, 'Platinum' => 0.20];
const MIN_DISCOUNT = 0;
const STATUS_PAID = 'Оплачен';
const STATUS_IN_PROCESS = 'В процессе';
const DISCOUNT_PENDING = 'Ожидает';
const DISCOUNT_NONE = 'Нет';

// ============ 1. ПРОВЕРКА АВТОРИЗАЦИИ ============
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = intval($_SESSION['user_id']);
$invoice_id = intval($_GET['id'] ?? 0);

// ============ 2. ЗАГРУЗКА ДАННЫХ ============
try {
    // Счет и отправитель
    $invoice = getInvoice($invoice_id);
    
    if (!$invoice || $invoice['receiver_id1'] != $user_id) {
        showError("Счет не найден или вы не являетесь его получателем.");
    }

    if ($invoice['status'] == STATUS_PAID) {
        header("Location: index.php");
        exit;
    }

    // Текущий пользователь (плательщик)
    $me = getUserData($user_id);
    
} catch (Exception $e) {
    error_log("Ошибка загрузки данных: " . $e->getMessage());
    showError("Не удалось загрузить данные счета.");
}

$msg = "";
$error = "";

// ============ 3. ОБРАБОТКА ЗАПРОСА СКИДКИ ============
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_discount'])) {
    $disc = floatval($_POST['discount_amount'] ?? 0);
    
    if (validateDiscount($disc, $invoice['amount'])) {
        try {
            $pdo->prepare("UPDATE invoices SET requested_discount = ?, discount_status = ? WHERE id = ?")
                ->execute([$disc, DISCOUNT_PENDING, $invoice_id]);
            $msg = "✅ Запрос на скидку отправлен! Ожидайте решения исполнителя.";
            header("Refresh: 2");
        } catch (Exception $e) {
            error_log("Ошибка при запросе скидки: " . $e->getMessage());
            $error = "Ошибка при отправке запроса на скидку.";
        }
    } else {
        $error = "❌ Сумма скидки должна быть между 0 и " . number_format($invoice['amount'], 2) . " BYN.";
    }
}

// ============ 4. ГЕНЕРАЦИЯ 2FA КОДА ============
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_code'])) {
    $code = rand(1000, 9999);
    try {
        $pdo->prepare("UPDATE users1 SET temp_auth_code = ? WHERE id = ?")
            ->execute([$code, $user_id]);
        $msg = "✅ Код подтверждения сгенерирован и отображается на вашем дашборде.";
    } catch (Exception $e) {
        error_log("Ошибка при генерации кода: " . $e->getMessage());
        $error = "Ошибка при генерации кода.";
    }
}

// ============ 5. ПОДТВЕРЖДЕНИЕ ОПЛАТЫ ============
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pay'])) {
    $spent = intval($_POST['bonuses_to_use'] ?? 0); // В копейках
    $code_input = sanitizeInput($_POST['bonus_code'] ?? '');
    
    // Валидация
    $validation = validatePayment($spent, $code_input, $me, $invoice);
    
    if (!$validation['valid']) {
        $error = $validation['error'];
    } else {
        try {
            $pdo->beginTransaction();
            
            // Расчет кэшбэка
            $rate = LOYALTY_RATES[$me['loyalty_tier'] ?? 'Classic'] ?? LOYALTY_RATES['Classic'];
            $paid_real = $invoice['amount'] - ($spent / 100);
            $earned = round($paid_real * $rate * 100); // в копейках

            // Списание бонусов (заморозка)
            if ($spent > 0) {
                $pdo->prepare("UPDATE users1 SET bonus_balance = bonus_balance - ?, temp_auth_code = NULL WHERE id = ?")
                    ->execute([$spent, $user_id]);
            }

            // Обновление статуса счета
            $pdo->prepare(
                "UPDATE invoices SET 
                    status = ?, 
                    pending_status = ?, 
                    bonuses_spent = ?, 
                    bonuses_earned = ?, 
                    payment_method = ? 
                WHERE id = ?"
            )->execute([
                STATUS_IN_PROCESS,
                STATUS_PAID,
                $spent,
                $earned,
                'Карта/Бонусы',
                $invoice_id
            ]);

            // Логирование успешной оплаты
            logPayment($user_id, $invoice_id, $spent, $earned);

            $pdo->commit();
            $msg = "✅ Оплата инициирована! После подтверждения отправителем вам зачислится кэшбэк: " . number_format($earned/100, 2) . " BYN.";
            header("Refresh: 3; url=index.php");
            
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("Ошибка при обработке платежа: " . $e->getMessage());
            $error = "❌ Ошибка при обработке транзакции. Попробуйте позже.";
        }
    }
}

// ============ ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ ============
function getInvoice($id) {
    global $pdo;
    $stmt = $pdo->prepare(
        "SELECT i.*, u.username as sender_name 
         FROM invoices i 
         JOIN users1 u ON i.sender_id1 = u.id 
         WHERE i.id = ?"
    );
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function getUserData($id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM users1 WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch();
}

function validateDiscount($amount, $max) {
    return $amount > 0 && $amount < $max;
}

function validatePayment($spent, $code, $user, $invoice) {
    if ($spent < 0) {
        return ['valid' => false, 'error' => '❌ Размер бонусов не может быть отрицательным.'];
    }

    if ($spent > 0) {
        if (empty($user['temp_auth_code']) || $code != $user['temp_auth_code']) {
            return ['valid' => false, 'error' => '❌ Неверный или отсутствующий код подтверждения.'];
        }
        if ($spent > $user['bonus_balance']) {
            $available = number_format($user['bonus_balance']/100, 2);
            return ['valid' => false, 'error' => "❌ Недостаточно бонусов. Доступно: $available BYN."];
        }
    }

    return ['valid' => true];
}

function sanitizeInput($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

function safeGet($arr, $key, $default = '') {
    return isset($arr[$key]) && !empty($arr[$key]) ? htmlspecialchars($arr[$key], ENT_QUOTES, 'UTF-8') : $default;
}

function showError($message) {
    die("<div style='text-align:center; padding:50px; font-family:sans-serif;'><h2>⚠️ Ошибка доступа</h2><p>$message</p><a href='index.php' style='color:#2563eb;'>← Вернуться на главную</a></div>");
}

function logPayment($user_id, $invoice_id, $spent, $earned) {
    global $pdo;
    try {
        $pdo->prepare(
            "INSERT INTO payment_logs (user_id, invoice_id, bonuses_spent, bonuses_earned, created_at) 
             VALUES (?, ?, ?, ?, NOW())"
        )->execute([$user_id, $invoice_id, $spent, $earned]);
    } catch (Exception $e) {
        error_log("Ошибка логирования платежа: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Оплата счета №<?php echo safeGet($invoice, 'invoice_number'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap');
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body { 
            background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%);
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            color: #1e293b;
            min-height: 100vh;
            padding: 20px;
        }
        
        .pay-card { 
            max-width: 550px; 
            margin: 40px auto; 
            border: none; 
            border-radius: 24px; 
            box-shadow: 0 25px 50px -12px rgba(0,0,0,0.1); 
            background: #fff; 
            overflow: hidden;
            animation: slideUp 0.4s ease-out;
        }
        
        @keyframes slideUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .alert { 
            border-radius: 16px; 
            border: none;
            animation: slideDown 0.3s ease-out;
        }

        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .alert-success { background: #dcfce7; color: #166534; }
        .alert-danger { background: #fee2e2; color: #991b1b; }
        .alert-warning { background: #fef3c7; color: #92400e; }
        
        .bonus-zone { 
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            color: #fff; 
            border-radius: 20px; 
            padding: 25px; 
            margin-bottom: 25px;
            box-shadow: 0 10px 25px -5px rgba(15, 23, 42, 0.2);
        }
        
        .tier-badge { 
            font-size: 0.75rem; 
            padding: 8px 14px; 
            border-radius: 50px; 
            font-weight: 800; 
            text-transform: uppercase; 
            letter-spacing: 0.5px;
            display: inline-block;
        }
        
        .tier-Classic { background: #f1f5f9; color: #64748b; }
        .tier-Silver { background: #dbeafe; color: #0c4a6e; }
        .tier-Gold { background: #fef3c7; color: #b45309; }
        .tier-Platinum { background: #1e293b; color: #f8fafc; border: 2px solid #f59e0b; }
        
        .btn-pay { 
            border-radius: 16px; 
            padding: 18px; 
            font-weight: 800; 
            font-size: 1.1rem; 
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border: none;
        }
        
        .btn-pay:hover { 
            transform: translateY(-3px); 
            box-shadow: 0 20px 25px -5px rgba(37, 99, 235, 0.3);
        }
        
        .btn-pay:active {
            transform: translateY(-1px);
        }

        .form-control-dark { 
            background: rgba(255,255,255,0.1); 
            border: 1px solid rgba(255,255,255,0.2); 
            color: white;
            border-radius: 12px;
            transition: all 0.3s;
        }
        
        .form-control-dark:focus { 
            background: rgba(255,255,255,0.15); 
            border-color: #3b82f6; 
            color: white; 
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .form-control-dark::placeholder {
            color: rgba(255,255,255,0.5);
        }

        .discount-zone {
            background: linear-gradient(135deg, #f0fdf4 0%, #f1fdf4 100%);
            border: 2px dashed #86efac;
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 25px;
        }

        .discount-status {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            border: 2px solid #f59e0b;
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 25px;
        }

        .invoice-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid #e2e8f0;
        }

        .invoice-title {
            font-size: 1.1rem;
            font-weight: 700;
        }

        .invoice-number {
            font-size: 0.85rem;
            color: #64748b;
        }

        .amount-display {
            text-align: center;
            padding: 20px 0;
        }

        .text-muted { color: #94a3b8; }
        .opacity-75 { opacity: 0.75; }

        .btn-sm {
            border-radius: 12px;
            font-weight: 600;
            transition: all 0.2s;
        }

        .btn-sm:hover {
            transform: translateY(-1px);
        }

        .back-link {
            color: #64748b;
            text-decoration: none;
            transition: color 0.3s;
            font-weight: 500;
        }

        .back-link:hover {
            color: #475569;
        }

        .hr-light {
            border-color: rgba(255,255,255,0.1);
            margin: 15px 0;
        }

        @media (max-width: 576px) {
            .pay-card { margin: 20px auto; }
            .btn-pay { font-size: 1rem; padding: 14px; }
            .invoice-header { flex-direction: column; text-align: center; gap: 10px; border-bottom: none; }
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>

<div class="container">
    <div class="pay-card p-4">
        <!-- ЗАГОЛОВОК -->
        <div class="invoice-header">
            <div>
                <div class="invoice-title">Оплата счета</div>
                <div class="invoice-number">№ <?php echo safeGet($invoice, 'invoice_number'); ?></div>
            </div>
            <span class="tier-badge tier-<?php echo safeGet($me, 'loyalty_tier', 'Classic'); ?>">
                <?php echo safeGet($me, 'loyalty_tier', 'Classic'); ?>
            </span>
        </div>

        <!-- УВЕДОМЛЕНИЯ -->
        <?php if($msg): ?>
            <div class="alert alert-success mb-4" role="alert">
                <?php echo htmlspecialchars($msg); ?>
            </div>
        <?php endif; ?>
        <?php if($error): ?>
            <div class="alert alert-danger mb-4" role="alert">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <!-- ИНФОРМАЦИЯ О СЧЕТЕ -->
        <div class="mb-4 p-3 rounded-3 bg-light">
            <div class="d-flex justify-content-between text-muted small mb-2">
                <span>Получатель:</span>
                <span class="fw-bold text-dark"><?php echo safeGet($invoice, 'sender_name'); ?></span>
            </div>
            <div class="amount-display border-top pt-3">
                <span class="text-muted small">Сумма к оплате</span>
                <div class="h1 fw-black text-primary m-0">
                    <?php echo number_format(floatval($invoice['amount']), 2); ?>
                    <small class="fs-6">BYN</small>
                </div>
            </div>
        </div>

        <!-- СТАТУС СКИДКИ (если уже запрошена) -->
        <?php if($invoice['discount_status'] === DISCOUNT_PENDING): ?>
            <div class="discount-status text-center">
                <div class="fw-bold mb-2">⏳ Скидка запрошена</div>
                <div class="small text-muted mb-2">Сумма скидки: <b><?php echo number_format(floatval($invoice['requested_discount']), 2); ?> BYN</b></div>
                <div class="small">Ожидайте решения исполнителя...</div>
            </div>
        <?php elseif($invoice['discount_status'] === 'Одобрено'): ?>
            <div class="alert alert-success mb-4 rounded-4">
                ✅ Скидка одобрена! Сумма уменьшена на <?php echo number_format(floatval($invoice['requested_discount']), 2); ?> BYN
            </div>
        <?php elseif($invoice['discount_status'] === 'Отклонено'): ?>
            <div class="alert alert-danger mb-4 rounded-4">
                ❌ Скидка отклонена. Вы можете запросить новую скидку ниже.
            </div>
        <?php endif; ?>

        <!-- СЕКЦИЯ ЗАПРОСА СКИДКИ (только если статус "Нет" или "Отклонено") -->
        <?php if($invoice['discount_status'] === DISCOUNT_NONE || $invoice['discount_status'] === 'Отклонено'): ?>
            <div class="discount-zone text-center">
                <p class="small fw-bold mb-3">💰 Хотите предложить свою цену?</p>
                <form method="POST" class="d-flex gap-2">
                    <input 
                        type="number" 
                        step="0.01" 
                        name="discount_amount" 
                        class="form-control form-control-sm rounded-3" 
                        placeholder="Размер скидки (BYN)" 
                        min="0.01"
                        max="<?php echo floatval($invoice['amount']); ?>"
                        required>
                    <button type="submit" name="request_discount" class="btn btn-sm btn-success fw-bold px-4">Запросить</button>
                </form>
            </div>
        <?php endif; ?>

        <!-- ФОРМА ОПЛАТЫ -->
        <form method="POST">
            <div class="bonus-zone shadow-lg">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <span class="small opacity-75">💎 Ваш бонусный баланс:</span>
                    <span class="h5 m-0 fw-bold text-warning">
                        <?php echo number_format((floatval($me['bonus_balance'] ?? 0) / 100), 2); ?>
                        <small class="fs-6">BYN</small>
                    </span>
                </div>
                
                <div class="mb-3">
                    <label class="small opacity-75 mb-2 d-block">Списать бонусов (в копейках):</label>
                    <input 
                        type="number" 
                        name="bonuses_to_use" 
                        id="bonus_input" 
                        class="form-control form-control-sm form-control-dark" 
                        placeholder="0" 
                        value="0"
                        min="0"
                        max="<?php echo intval($me['bonus_balance'] ?? 0); ?>">
                    <div class="form-text text-info small mt-1">📌 100 бонусов = 1 BYN</div>
                </div>

                <!-- 2FA БЛОК (скрыт по умолчанию) -->
                <div id="2fa_block" style="display: none;">
                    <hr class="hr-light">
                    <div class="row g-2 align-items-end">
                        <div class="col-8">
                            <label class="small text-warning mb-2 d-block">🔐 Код из личного кабинета:</label>
                            <input 
                                type="text" 
                                name="bonus_code" 
                                class="form-control form-control-sm" 
                                placeholder="0000"
                                maxlength="4"
                                inputmode="numeric">
                        </div>
                        <div class="col-4">
                            <button type="submit" name="generate_code" class="btn btn-sm btn-warning w-100 fw-bold">
                                КОД 🔑
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- КНОПКА ОПЛАТЫ -->
            <button type="submit" name="pay" class="btn btn-primary btn-pay w-100 mb-3 shadow">
                ✓ ПОДТВЕРДИТЬ ОПЛАТУ
            </button>
            
            <!-- ССЫЛКА ОТМЕНЫ -->
            <div class="text-center">
                <a href="index.php" class="back-link small">← Отмена и возврат назад</a>
            </div>
        </form>
    </div>
</div>

<script>
    // Управление блоком 2FA
    document.getElementById('bonus_input').addEventListener('input', function() {
        const val = parseInt(this.value) || 0;
        const block = document.getElementById('2fa_block');
        block.style.display = (val > 0) ? 'block' : 'none';
    });

    // Валидация формы на клиенте
    document.querySelector('form').addEventListener('submit', function(e) {
        const bonuses = parseInt(document.getElementById('bonus_input').value) || 0;
        if (bonuses < 0) {
            e.preventDefault();
            alert('❌ Размер бонусов не может быть отрицательным');
        }
    });
</script>
</body>
</html>
