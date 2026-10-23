<?php
session_start();

$host = 'db';
$dbname = 'pos_db';
$username = 'pos_user';
$password = getenv('MYSQL_PASSWORD') ?: '';

$pdo = null;
$products = [];
$invoices = [];
$error = '';
$success = '';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function money($value): string
{
    return number_format((float) $value, 0, ',', '.') . ' đ';
}

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    // Xử lý yêu cầu tạo hóa đơn.
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (
            !isset($_POST['csrf_token']) ||
            !is_string($_POST['csrf_token']) ||
            !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
        ) {
            throw new RuntimeException('Phiên làm việc không hợp lệ. Vui lòng tải lại trang.');
        }

        $productIds = $_POST['product_id'] ?? [];
        $quantities = $_POST['quantity'] ?? [];

        if (!is_array($productIds) || !is_array($quantities)) {
            throw new RuntimeException('Dữ liệu sản phẩm không hợp lệ.');
        }

        if (count($productIds) === 0 || count($productIds) > 50 ||
            count($productIds) !== count($quantities)) {
            throw new RuntimeException('Vui lòng chọn ít nhất một sản phẩm hợp lệ.');
        }

        // Gộp các dòng trùng sản phẩm để tránh xử lý lặp.
        $items = [];

        foreach ($productIds as $index => $rawId) {
            $rawQuantity = $quantities[$index] ?? null;

            if (
                !is_scalar($rawId) ||
                !is_scalar($rawQuantity) ||
                !ctype_digit((string) $rawId) ||
                !ctype_digit((string) $rawQuantity)
            ) {
                throw new RuntimeException('Mã sản phẩm hoặc số lượng không hợp lệ.');
            }

            $id = (int) $rawId;
            $quantity = (int) $rawQuantity;

            if ($id <= 0 || $quantity <= 0 || $quantity > 100000) {
                throw new RuntimeException('Số lượng phải lớn hơn 0 và không vượt quá giới hạn.');
            }

            $items[$id] = ($items[$id] ?? 0) + $quantity;

            if ($items[$id] > 100000) {
                throw new RuntimeException('Tổng số lượng sản phẩm vượt giới hạn.');
            }
        }

        ksort($items);

        $pdo->beginTransaction();

        try {
            $lockedProducts = [];
            $total = 0.0;

            // Khóa từng dòng sản phẩm trong lúc kiểm tra và bán.
            $selectProduct = $pdo->prepare(
                'SELECT id, name, price, stock
                 FROM products
                 WHERE id = ?
                 FOR UPDATE'
            );

            foreach ($items as $id => $quantity) {
                $selectProduct->execute([$id]);
                $product = $selectProduct->fetch();

                if (!$product) {
                    throw new RuntimeException('Có sản phẩm không tồn tại.');
                }

                if ((int) $product['stock'] < $quantity) {
                    throw new RuntimeException(
                        'Sản phẩm "' . $product['name'] .
                        '" không đủ hàng. Tồn kho hiện tại: ' .
                        (int) $product['stock'] . '.'
                    );
                }

                $lockedProducts[$id] = $product;
                $total += (float) $product['price'] * $quantity;
            }

            $total = round($total, 2);

            // Tạo hóa đơn.
            $insertInvoice = $pdo->prepare(
                'INSERT INTO invoices (total) VALUES (?)'
            );
            $insertInvoice->execute([$total]);

            $invoiceId = (int) $pdo->lastInsertId();

            $insertItem = $pdo->prepare(
                'INSERT INTO invoice_items
                 (invoice_id, product_id, quantity, unit_price)
                 VALUES (?, ?, ?, ?)'
            );

            $updateStock = $pdo->prepare(
                'UPDATE products
                 SET stock = stock - ?
                 WHERE id = ? AND stock >= ?'
            );

            foreach ($items as $id => $quantity) {
                $product = $lockedProducts[$id];
                $unitPrice = (float) $product['price'];

                $insertItem->execute([
                    $invoiceId,
                    $id,
                    $quantity,
                    $unitPrice,
                ]);

                $updateStock->execute([$quantity, $id, $quantity]);

                if ($updateStock->rowCount() !== 1) {
                    throw new RuntimeException(
                        'Không thể cập nhật tồn kho. Giao dịch đã bị hủy.'
                    );
                }
            }

            $pdo->commit();

            // Làm mới token sau giao dịch thành công.
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

            // Chuyển hướng để tránh tạo hóa đơn lại khi tải trang.
            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') .
                '?invoice=' . $invoiceId);
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }

    if (isset($_GET['invoice']) && ctype_digit((string) $_GET['invoice'])) {
        $success = 'Đã tạo hóa đơn #' . (int) $_GET['invoice'] . ' thành công.';
    }

    $products = $pdo->query(
        'SELECT id, name, price, stock FROM products ORDER BY id DESC'
    )->fetchAll();

    $invoices = $pdo->query(
        'SELECT id, total, created_at FROM invoices ORDER BY id DESC LIMIT 10'
    )->fetchAll();

} catch (Throwable $e) {
    error_log($e->getMessage());

    if ($e instanceof RuntimeException) {
        $error = $e->getMessage();
    } else {
        $error = 'Không thể xử lý yêu cầu. Vui lòng kiểm tra hệ thống và thử lại.';
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Quản lý cửa hàng POS</title>
    <style>
* {
    box-sizing: border-box;
}

:root {
    color-scheme: light;
    --primary: #1d4ed8;
    --primary-dark: #1e40af;
    --border: #e2e8f0;
    --muted: #64748b;
    --surface: #ffffff;
    --background: #f1f5f9;
}

body {
    margin: 0;
    background: var(--background);
    color: #172033;
    font-family: Arial, Helvetica, sans-serif;
    font-size: 15px;
    line-height: 1.6;
}

header {
    padding: 26px 24px;
    color: #ffffff;
    background: linear-gradient(120deg, #1e3a8a, #2563eb);
    box-shadow: 0 4px 14px #0f172a18;
}

header h1 {
    max-width: 1120px;
    margin: 0 auto;
    font-size: clamp(23px, 3vw, 30px);
    line-height: 1.3;
}

header p {
    max-width: 1120px;
    margin: 8px auto 0;
    color: #dbeafe;
}

main {
    width: min(1120px, 100%);
    margin: 28px auto;
    padding: 0 20px;
}

.card {
    min-width: 0;
    margin-bottom: 24px;
    padding: 24px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 14px;
    box-shadow: 0 5px 18px #0f172a08;
}

h2 {
    margin: 0 0 20px;
    color: #172554;
    font-size: 21px;
    line-height: 1.4;
}

.stats {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 18px;
}

.stats > div {
    padding: 20px;
    background: #eff6ff;
    border: 1px solid #dbeafe;
    border-left: 5px solid var(--primary);
    border-radius: 10px;
}

.stats h3 {
    margin: 0 0 8px;
    color: #475569;
    font-size: 14px;
    font-weight: 600;
}

.stats p {
    margin: 0;
    color: #1d4ed8;
    font-size: 30px;
    font-weight: 700;
}

.row {
    display: flex;
    flex-wrap: wrap;
    align-items: end;
    gap: 14px;
    margin-bottom: 16px;
}

.item-row {
    display: grid;
    grid-template-columns: minmax(220px, 1fr) 150px auto;
    align-items: end;
    gap: 14px;
    margin-bottom: 14px;
    padding: 16px;
    background: #f8fafc;
    border: 1px solid var(--border);
    border-radius: 10px;
}

.item-row select {
    width: 100%;
    min-width: 0;
}

.item-row label {
    display: flex;
    flex-direction: column;
    gap: 6px;
    color: #334155;
    font-size: 14px;
    font-weight: 600;
}

.item-row input[type="number"] {
    width: 100%;
    min-width: 0;
}

input, select, button {
    max-width: 100%;
    min-height: 42px;
    padding: 10px 12px;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    font: inherit;
}

select, input {
    background: #ffffff;
    color: #172033;
}

select:focus, input:focus, button:focus-visible {
    outline: 3px solid #93c5fd;
    outline-offset: 2px;
}

button {
    border: 0;
    background: var(--primary);
    color: white;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.15s ease;
}

button:hover {
    background: var(--primary-dark);
}

button:active {
    transform: translateY(1px);
}

.remove {
    background: #dc2626;
}

.remove:hover {
    background: #b91c1c;
}

.secondary {
    background: #475569;
}

.secondary:hover {
    background: #334155;
}

form > .row:last-of-type {
    align-items: center;
    margin-top: 20px;
}

.muted {
    margin: 18px 0 0;
    color: var(--muted);
    font-size: 13px;
}

.table-wrap {
    width: 100%;
    overflow-x: auto;
    border: 1px solid var(--border);
    border-radius: 10px;
}

table {
    width: 100%;
    border-collapse: collapse;
    background: white;
}

th, td {
    padding: 14px 16px;
    text-align: left;
    vertical-align: middle;
    border-bottom: 1px solid var(--border);
}

th {
    background: #eff6ff;
    color: #1e3a8a;
    font-size: 13px;
    font-weight: 700;
    white-space: nowrap;
}

tbody tr:last-child td {
    border-bottom: 0;
}

tbody tr:hover {
    background: #f8fafc;
}

.status {
    color: #15803d;
    font-weight: 700;
}

td.status {
    white-space: nowrap;
}

.error, .success {
    margin: 0 0 20px;
    padding: 14px 16px;
    border: 1px solid transparent;
    border-radius: 10px;
}

.error {
    background: #fef2f2;
    border-color: #fecaca;
    color: #991b1b;
}

.success {
    background: #f0fdf4;
    border-color: #bbf7d0;
    color: #166534;
}

@media (max-width: 700px) {
    header {
        padding: 22px 18px;
    }

    main {
        margin: 18px auto;
        padding: 0 12px;
    }

    .card {
        padding: 18px;
        border-radius: 12px;
    }

    .stats {
        grid-template-columns: 1fr;
        gap: 12px;
    }

    .item-row {
        grid-template-columns: minmax(0, 1fr);
        gap: 12px;
        padding: 14px;
    }

    .item-row .remove {
        width: 100%;
    }

    form > .row:last-of-type {
        align-items: stretch;
        flex-direction: column;
    }

    form > .row:last-of-type button {
        width: 100%;
    }

    th, td {
        padding: 11px 12px;
    }
}
</style>
</head>
<body>
<header>
    <h1>Hệ thống quản lý cửa hàng POS</h1>
    <p>Quản lý sản phẩm, bán hàng và theo dõi tồn kho</p>
</header>

<main>
    <?php if ($error): ?>
        <p class="error"><?= h($error) ?></p>
    <?php endif; ?>

    <?php if ($success): ?>
        <p class="success"><?= h($success) ?></p>
    <?php endif; ?>

    <section class="card stats">
        <div>
            <h3>Tổng số sản phẩm</h3>
            <p><?= count($products) ?></p>
        </div>
        <div>
            <h3>Tổng số lượng tồn</h3>
            <p><?= array_sum(array_column($products, 'stock')) ?></p>
        </div>
    </section>

    <section class="card">
        <h2>Tạo hóa đơn bán hàng</h2>

        <?php if (empty($products)): ?>
            <p>Chưa có sản phẩm để bán.</p>
        <?php else: ?>
            <form method="post">
                <input type="hidden" name="csrf_token"
                       value="<?= h($_SESSION['csrf_token']) ?>">

                <div id="items">
                    <div class="row item-row">
                        <select name="product_id[]" required>
                            <option value="">-- Chọn sản phẩm --</option>
                            <?php foreach ($products as $product): ?>
                                <option
                                    value="<?= (int) $product['id'] ?>"
                                    <?= (int) $product['stock'] <= 0 ? 'disabled' : '' ?>>
                                    <?= h($product['name']) ?>
                                    — <?= money($product['price']) ?>
                                    — Tồn: <?= (int) $product['stock'] ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <label>Số lượng
                            <input type="number" name="quantity[]"
                                   min="1" max="100000" value="1" required>
                        </label>

                        <button type="button" class="remove"
                                onclick="removeRow(this)">Xóa dòng</button>
                    </div>
                </div>

                <div class="row">
                    <button type="button" class="secondary" onclick="addRow()">
                        + Thêm sản phẩm
                    </button>
                    <button type="submit">Tạo hóa đơn và trừ tồn kho</button>
                </div>

                <p class="muted">
                    Giá và tồn kho được kiểm tra lại trên máy chủ khi bán.
                    Không thể bán số lượng vượt tồn kho.
                </p>
            </form>
        <?php endif; ?>
    </section>

    <section class="card">
        <h2>Danh sách sản phẩm</h2>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Mã</th>
                    <th>Tên sản phẩm</th>
                    <th>Giá bán</th>
                    <th>Tồn kho</th>
                    <th>Trạng thái</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($products as $product): ?>
                    <tr>
                        <td><?= (int) $product['id'] ?></td>
                        <td><?= h($product['name']) ?></td>
                        <td><?= money($product['price']) ?></td>
                        <td><?= (int) $product['stock'] ?></td>
                        <td class="status">
                            <?= (int) $product['stock'] > 0
                                ? 'Còn hàng' : 'Hết hàng' ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="card">
        <h2>10 hóa đơn gần nhất</h2>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Mã hóa đơn</th>
                    <th>Tổng tiền</th>
                    <th>Thời gian</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($invoices)): ?>
                    <tr><td colspan="3">Chưa có hóa đơn.</td></tr>
                <?php else: ?>
                    <?php foreach ($invoices as $invoice): ?>
                        <tr>
                            <td>#<?= (int) $invoice['id'] ?></td>
                            <td><?= money($invoice['total']) ?></td>
                            <td><?= h($invoice['created_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>

<script>
function addRow() {
    const container = document.getElementById('items');
    const first = container.querySelector('.item-row');
    const row = first.cloneNode(true);

    row.querySelector('select').selectedIndex = 0;
    row.querySelector('input[type="number"]').value = 1;
    container.appendChild(row);
}

function removeRow(button) {
    const container = document.getElementById('items');
    const rows = container.querySelectorAll('.item-row');

    if (rows.length === 1) {
        rows[0].querySelector('select').selectedIndex = 0;
        rows[0].querySelector('input[type="number"]').value = 1;
        return;
    }

    button.closest('.item-row').remove();
}
</script>
</body>
</html>
