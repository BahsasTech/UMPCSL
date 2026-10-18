<?php
declare(strict_types=1);

/**
 * Inventory and e-store: products, categories, stock movements, sales/orders,
 * a shopping cart and reporting.
 *
 * Sales post to the general ledger when they are paid/completed:
 *   revenue  -> Debit cash/bank, Credit Store Sales (4200)
 *   cost     -> Debit Cost of Goods Sold (5200), Credit Inventory (1200)
 */

const PRODUCT_UNITS = [
    'unit'    => 'Unit',
    'piece'   => 'Piece',
    'pack'    => 'Pack',
    'carton'  => 'Carton',
    'bag'     => 'Bag',
    'kg'      => 'Kilogram',
    'litre'   => 'Litre',
    'dozen'   => 'Dozen',
    'set'     => 'Set',
];

const SALE_STATUSES = [
    'pending'   => 'Pending payment',
    'paid'      => 'Paid',
    'fulfilled' => 'Fulfilled',
    'completed' => 'Completed',
    'cancelled' => 'Cancelled',
];

const MOVEMENT_TYPES = [
    'opening'    => 'Opening stock',
    'purchase'   => 'Purchase / restock',
    'sale'       => 'Sale',
    'adjustment' => 'Adjustment',
    'return'     => 'Return',
];

/* =====================================================================
 * Categories
 * ===================================================================*/

function list_categories(): array
{
    return fetch_all(
        'SELECT c.*, (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id) AS products
         FROM product_categories c ORDER BY c.name'
    );
}

function get_category(int $id): ?array
{
    return fetch_one('SELECT * FROM product_categories WHERE id = ?', [$id]);
}

function save_category(?int $id, string $name, ?string $description): int
{
    $name = trim($name);
    if ($name === '') {
        throw new RuntimeException('Category name is required.');
    }
    $exists = fetch_one('SELECT id FROM product_categories WHERE name = ? AND id <> ?', [$name, $id ?? 0]);
    if ($exists) {
        throw new RuntimeException('A category with that name already exists.');
    }
    if ($id) {
        db_update('product_categories', $id, ['name' => $name, 'description' => $description]);
        return $id;
    }
    return db_insert('product_categories', ['name' => $name, 'description' => $description]);
}

function delete_category(int $id): void
{
    q('DELETE FROM product_categories WHERE id = ?', [$id]);
}

/* =====================================================================
 * Products
 * ===================================================================*/

function list_products(array $filters = []): array
{
    $sql = 'SELECT p.*, c.name AS category_name
            FROM products p LEFT JOIN product_categories c ON c.id = p.category_id
            WHERE 1 = 1';
    $params = [];
    if (!empty($filters['search'])) {
        $like = '%' . $filters['search'] . '%';
        $sql .= ' AND (p.name LIKE ? OR p.sku LIKE ?)';
        array_push($params, $like, $like);
    }
    if (!empty($filters['category'])) {
        $sql .= ' AND p.category_id = ?';
        $params[] = (int) $filters['category'];
    }
    if (!empty($filters['online'])) {
        $sql .= ' AND p.is_online = 1 AND p.is_active = 1';
    }
    if (!empty($filters['active'])) {
        $sql .= ' AND p.is_active = 1';
    }
    if (!empty($filters['low_stock'])) {
        $sql .= ' AND p.stock_qty <= p.reorder_level';
    }
    $sql .= ' ORDER BY p.name';
    return fetch_all($sql, $params);
}

function store_products(int $categoryId = 0): array
{
    $sql = 'SELECT p.*, c.name AS category_name
            FROM products p LEFT JOIN product_categories c ON c.id = p.category_id
            WHERE p.is_active = 1 AND p.is_online = 1';
    $params = [];
    if ($categoryId > 0) {
        $sql .= ' AND p.category_id = ?';
        $params[] = $categoryId;
    }
    $sql .= ' ORDER BY c.name, p.name';
    return fetch_all($sql, $params);
}

function get_product(int $id): ?array
{
    return fetch_one(
        'SELECT p.*, c.name AS category_name FROM products p
         LEFT JOIN product_categories c ON c.id = p.category_id WHERE p.id = ?',
        [$id]
    );
}

function save_product(?int $id, array $data): int
{
    $name = trim((string) ($data['name'] ?? ''));
    if ($name === '') {
        throw new RuntimeException('Product name is required.');
    }
    $sku = trim((string) ($data['sku'] ?? ''));
    if ($sku === '') {
        $sku = 'SKU-' . strtoupper(bin2hex(random_bytes(3)));
    }
    $dup = fetch_one('SELECT id FROM products WHERE sku = ? AND id <> ?', [$sku, $id ?? 0]);
    if ($dup) {
        throw new RuntimeException('A product with that SKU already exists.');
    }

    $record = [
        'sku'           => $sku,
        'name'          => $name,
        'category_id'   => !empty($data['category_id']) ? (int) $data['category_id'] : null,
        'unit'          => (string) ($data['unit'] ?? 'unit'),
        'description'   => $data['description'] ?? null,
        'cost_price'    => to_amount($data['cost_price'] ?? 0),
        'price'         => to_amount($data['price'] ?? 0),
        'reorder_level' => (int) ($data['reorder_level'] ?? 0),
        'is_active'     => !empty($data['is_active']) ? 1 : 0,
        'is_online'     => !empty($data['is_online']) ? 1 : 0,
    ];

    if ($id) {
        db_update('products', $id, $record);
        return $id;
    }

    $record['stock_qty'] = 0;
    $productId = db_insert('products', $record);

    $opening = (int) ($data['opening_stock'] ?? 0);
    if ($opening > 0) {
        record_stock_movement($productId, $opening, 'opening', null, 'Opening stock', (float) $record['cost_price']);
    }
    return $productId;
}

/** Store an uploaded product image. Returns the stored filename. */
function save_product_image(array $file, int $productId): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Image upload failed. Please try again.');
    }
    if ($file['size'] > 2 * 1024 * 1024) {
        throw new RuntimeException('Image must be smaller than 2MB.');
    }
    $info = @getimagesize($file['tmp_name']);
    if ($info === false) {
        throw new RuntimeException('The uploaded file is not a valid image.');
    }
    $ext = image_type_to_extension($info[2], false) ?: 'jpg';
    $name = 'product-' . $productId . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dir = (string) config('uploads.products_dir');
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    if (!move_uploaded_file($file['tmp_name'], $dir . DIRECTORY_SEPARATOR . $name)) {
        throw new RuntimeException('Could not store the uploaded image.');
    }
    return $name;
}

/** URL for a product image or a placeholder. */
function product_image_url(?string $filename): string
{
    if ($filename && file_exists((string) config('uploads.products_dir') . DIRECTORY_SEPARATOR . $filename)) {
        return '/uploads/products/' . rawurlencode($filename);
    }
    return '/assets/img/product.svg';
}

/* =====================================================================
 * Stock movements
 * ===================================================================*/

/**
 * Record a change in stock. Positive change increases stock, negative
 * decreases it. Updates the product's balance and writes a movement row.
 */
function record_stock_movement(int $productId, int $change, string $type, ?string $reference = null, ?string $notes = null, float $unitCost = 0.0): int
{
    if (!array_key_exists($type, MOVEMENT_TYPES)) {
        throw new RuntimeException('Unknown stock movement type.');
    }
    $product = fetch_one('SELECT id, stock_qty FROM products WHERE id = ? FOR UPDATE', [$productId]);
    if (!$product) {
        throw new RuntimeException('Product not found.');
    }
    $newBalance = (int) $product['stock_qty'] + $change;
    if ($newBalance < 0) {
        throw new RuntimeException('This movement would take stock below zero.');
    }
    db_update('products', $productId, ['stock_qty' => $newBalance]);
    return db_insert('stock_movements', [
        'product_id'    => $productId,
        'type'          => $type,
        'change_qty'    => $change,
        'balance_after' => $newBalance,
        'unit_cost'     => $unitCost,
        'reference'     => $reference,
        'notes'         => $notes,
        'created_by'    => $_SESSION['user_id'] ?? null,
    ]);
}

/** Restock or adjust a product to an absolute quantity. */
function adjust_stock(int $productId, int $newQty, ?string $notes = null): void
{
    $product = fetch_one('SELECT stock_qty FROM products WHERE id = ?', [$productId]);
    if (!$product) {
        throw new RuntimeException('Product not found.');
    }
    $change = $newQty - (int) $product['stock_qty'];
    if ($change === 0) {
        return;
    }
    record_stock_movement($productId, $change, 'adjustment', null, $notes);
}

/** Add stock (restock) at a unit cost. */
function restock_product(int $productId, int $qty, float $unitCost, ?string $reference = null, ?string $notes = null): void
{
    if ($qty <= 0) {
        throw new RuntimeException('Restock quantity must be greater than zero.');
    }
    record_stock_movement($productId, $qty, 'purchase', $reference, $notes, $unitCost);
}

function list_movements(array $filters = []): array
{
    $sql = 'SELECT sm.*, p.name AS product_name, p.sku, u.full_name AS user_name
            FROM stock_movements sm
            JOIN products p ON p.id = sm.product_id
            LEFT JOIN users u ON u.id = sm.created_by
            WHERE 1 = 1';
    $params = [];
    if (!empty($filters['product'])) {
        $sql .= ' AND sm.product_id = ?';
        $params[] = (int) $filters['product'];
    }
    if (!empty($filters['type'])) {
        $sql .= ' AND sm.type = ?';
        $params[] = $filters['type'];
    }
    if (!empty($filters['from'])) {
        $sql .= ' AND sm.created_at >= ?';
        $params[] = $filters['from'] . ' 00:00:00';
    }
    if (!empty($filters['to'])) {
        $sql .= ' AND sm.created_at <= ?';
        $params[] = $filters['to'] . ' 23:59:59';
    }
    $sql .= ' ORDER BY sm.id DESC LIMIT 500';
    return fetch_all($sql, $params);
}

/** Products at or below their reorder level. */
function low_stock_products(): array
{
    return fetch_all(
        'SELECT p.*, c.name AS category_name FROM products p
         LEFT JOIN product_categories c ON c.id = p.category_id
         WHERE p.is_active = 1 AND p.stock_qty <= p.reorder_level
         ORDER BY (p.stock_qty - p.reorder_level), p.name'
    );
}

/** Total value of stock at cost and at retail. */
function inventory_value(): array
{
    $row = fetch_one(
        'SELECT COALESCE(SUM(stock_qty * cost_price),0) AS cost,
                COALESCE(SUM(stock_qty * price),0) AS retail,
                COALESCE(SUM(stock_qty),0) AS units,
                COUNT(*) AS products
         FROM products WHERE is_active = 1'
    );
    return [
        'cost'     => (float) $row['cost'],
        'retail'   => (float) $row['retail'],
        'units'    => (int) $row['units'],
        'products' => (int) $row['products'],
    ];
}

/* =====================================================================
 * Sales / orders
 * ===================================================================*/

/**
 * Create a sale from a list of items.
 * Each item: ['product_id' => int, 'quantity' => int, 'unit_price' => optional].
 * Decrements stock, records movements, and posts accounting when the sale is
 * paid/completed. Returns the new sale id.
 */
function create_sale(array $data, array $items): int
{
    $lines = [];
    foreach ($items as $item) {
        $qty = (int) ($item['quantity'] ?? 0);
        if ($qty > 0) {
            $lines[] = $item;
        }
    }
    if ($lines === []) {
        throw new RuntimeException('Add at least one product to the sale.');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $subtotal = 0.0;
        $costTotal = 0.0;
        $prepared = [];
        foreach ($lines as $item) {
            $product = fetch_one('SELECT * FROM products WHERE id = ? FOR UPDATE', [(int) $item['product_id']]);
            if (!$product) {
                throw new RuntimeException('A selected product no longer exists.');
            }
            if (!$product['is_active']) {
                throw new RuntimeException($product['name'] . ' is not available for sale.');
            }
            $qty = (int) $item['quantity'];
            if ((int) $product['stock_qty'] < $qty) {
                throw new RuntimeException('Insufficient stock for ' . $product['name'] . ' (' . (int) $product['stock_qty'] . ' left).');
            }
            $price = (isset($item['unit_price']) && $item['unit_price'] !== '') ? to_amount($item['unit_price']) : (float) $product['price'];
            $lineTotal = round($price * $qty, 2);
            $subtotal += $lineTotal;
            $costTotal += (float) $product['cost_price'] * $qty;
            $prepared[] = ['product' => $product, 'qty' => $qty, 'price' => $price, 'line' => $lineTotal];
        }

        $discount = to_amount($data['discount'] ?? 0);
        $total = round($subtotal - $discount, 2);
        if ($total < 0) {
            $total = 0.0;
        }
        $status = (string) ($data['status'] ?? 'completed');
        if (!in_array($status, array_keys(SALE_STATUSES), true)) {
            $status = 'completed';
        }

        $saleNo = next_reference('sales', 'sale_no', 'ST-' . date('y') . '-', 5);
        $saleId = db_insert('sales', [
            'sale_no'         => $saleNo,
            'member_id'       => !empty($data['member_id']) ? (int) $data['member_id'] : null,
            'customer_name'   => (string) ($data['customer_name'] ?? 'Walk-in customer'),
            'customer_phone'  => $data['customer_phone'] ?? null,
            'customer_email'  => $data['customer_email'] ?? null,
            'channel'         => (string) ($data['channel'] ?? 'store'),
            'status'          => $status,
            'subtotal'        => round($subtotal, 2),
            'discount'        => $discount,
            'total'           => $total,
            'cost_total'      => round($costTotal, 2),
            'payment_method'  => $data['payment_method'] ?? null,
            'payment_reference' => $data['payment_reference'] ?? null,
            'notes'           => $data['notes'] ?? null,
            'sold_by'         => $data['sold_by'] ?? ($_SESSION['user_id'] ?? null),
            'paid_at'         => in_array($status, ['paid', 'fulfilled', 'completed'], true) ? date('Y-m-d H:i:s') : null,
            'fulfilled_at'    => in_array($status, ['fulfilled', 'completed'], true) ? date('Y-m-d H:i:s') : null,
        ]);

        foreach ($prepared as $line) {
            db_insert('sale_items', [
                'sale_id'      => $saleId,
                'product_id'   => (int) $line['product']['id'],
                'product_name' => $line['product']['name'],
                'unit_price'   => $line['price'],
                'cost_price'   => (float) $line['product']['cost_price'],
                'quantity'     => $line['qty'],
                'line_total'   => $line['line'],
            ]);
            record_stock_movement((int) $line['product']['id'], -$line['qty'], 'sale', $saleNo, 'Sold');
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    audit('sale_created', 'sales', $saleId, $saleNo . ' for ' . money((float) $total));

    if (in_array($status, ['paid', 'fulfilled', 'completed'], true)) {
        post_sale_accounting($saleId);
    }
    $sale = get_sale($saleId);
    if ($sale && !empty($sale['member_id'])) {
        safe_notify_member((int) $sale['member_id'], 'sale_receipt', [
            'amount'     => money((float) $sale['total']),
            'receipt_no' => $sale['sale_no'],
        ]);
    }
    return $saleId;
}

/** Post revenue and COGS for a sale once. */
function post_sale_accounting(int $saleId): void
{
    $sale = fetch_one('SELECT * FROM sales WHERE id = ?', [$saleId]);
    if (!$sale || (int) $sale['accounts_posted'] === 1) {
        return;
    }
    if ($sale['status'] === 'cancelled') {
        return;
    }
    $date = substr((string) $sale['created_at'], 0, 10);
    post_store_sale_entry((string) ($sale['payment_method'] ?: 'cash'), (float) $sale['total'], (string) $sale['sale_no'], $saleId, $date);
    post_cogs_entry((float) $sale['cost_total'], (string) $sale['sale_no'], $saleId, $date);
    db_update('sales', $saleId, ['accounts_posted' => 1]);
}

function get_sale(int $id): ?array
{
    $sale = fetch_one(
        'SELECT s.*, m.membership_no, m.first_name, m.last_name, u.full_name AS seller
         FROM sales s
         LEFT JOIN members m ON m.id = s.member_id
         LEFT JOIN users u ON u.id = s.sold_by
         WHERE s.id = ?',
        [$id]
    );
    if (!$sale) {
        return null;
    }
    $sale['items'] = fetch_all('SELECT * FROM sale_items WHERE sale_id = ? ORDER BY id', [$id]);
    return $sale;
}

function list_sales(array $filters = []): array
{
    $sql = 'SELECT s.*, m.membership_no, m.first_name, m.last_name, u.full_name AS seller
            FROM sales s
            LEFT JOIN members m ON m.id = s.member_id
            LEFT JOIN users u ON u.id = s.sold_by
            WHERE 1 = 1';
    $params = [];
    if (!empty($filters['status'])) {
        $sql .= ' AND s.status = ?';
        $params[] = $filters['status'];
    }
    if (!empty($filters['channel'])) {
        $sql .= ' AND s.channel = ?';
        $params[] = $filters['channel'];
    }
    if (!empty($filters['search'])) {
        $like = '%' . $filters['search'] . '%';
        $sql .= ' AND (s.sale_no LIKE ? OR s.customer_name LIKE ? OR m.membership_no LIKE ?)';
        array_push($params, $like, $like, $like);
    }
    if (!empty($filters['from'])) {
        $sql .= ' AND s.created_at >= ?';
        $params[] = $filters['from'] . ' 00:00:00';
    }
    if (!empty($filters['to'])) {
        $sql .= ' AND s.created_at <= ?';
        $params[] = $filters['to'] . ' 23:59:59';
    }
    $sql .= ' ORDER BY s.id DESC LIMIT 300';
    return fetch_all($sql, $params);
}

function member_orders(int $memberId): array
{
    return fetch_all('SELECT * FROM sales WHERE member_id = ? ORDER BY id DESC LIMIT 100', [$memberId]);
}

/** Update a sale's status, handling accounting and restocking. */
function set_sale_status(int $saleId, string $status): void
{
    $sale = get_sale($saleId);
    if (!$sale) {
        throw new RuntimeException('Sale not found.');
    }
    if (!array_key_exists($status, SALE_STATUSES)) {
        throw new RuntimeException('Unknown sale status.');
    }
    if ($sale['status'] === 'cancelled') {
        throw new RuntimeException('A cancelled sale cannot be changed.');
    }

    if ($status === 'cancelled') {
        cancel_sale($saleId);
        return;
    }

    $update = ['status' => $status];
    if (in_array($status, ['paid', 'fulfilled', 'completed'], true) && empty($sale['paid_at'])) {
        $update['paid_at'] = date('Y-m-d H:i:s');
    }
    if (in_array($status, ['fulfilled', 'completed'], true) && empty($sale['fulfilled_at'])) {
        $update['fulfilled_at'] = date('Y-m-d H:i:s');
    }
    db_update('sales', $saleId, $update);

    if (in_array($status, ['paid', 'fulfilled', 'completed'], true)) {
        post_sale_accounting($saleId);
    }
    if (!empty($sale['member_id']) && in_array($status, ['paid', 'fulfilled'], true)) {
        safe_notify_member((int) $sale['member_id'], 'order_ready', [
            'receipt_no' => $sale['sale_no'],
            'amount'     => money((float) $sale['total']),
        ]);
    }
    audit('sale_status', 'sales', $saleId, $sale['sale_no'] . ' -> ' . $status);
}

/** Confirm payment for an order and post accounting. */
function mark_sale_paid(int $saleId, string $method, ?string $reference): void
{
    $sale = get_sale($saleId);
    if (!$sale) {
        throw new RuntimeException('Sale not found.');
    }
    if ($sale['status'] === 'cancelled') {
        throw new RuntimeException('A cancelled sale cannot be paid.');
    }
    $update = [
        'payment_method'    => $method,
        'payment_reference' => $reference,
    ];
    if (empty($sale['paid_at'])) {
        $update['paid_at'] = date('Y-m-d H:i:s');
    }
    if ($sale['status'] === 'pending') {
        $update['status'] = 'paid';
    }
    db_update('sales', $saleId, $update);
    post_sale_accounting($saleId);
    audit('sale_paid', 'sales', $saleId, $sale['sale_no'] . ' marked paid');
}

/** Cancel a sale and return its stock to inventory. */
function cancel_sale(int $saleId): void
{
    $sale = get_sale($saleId);
    if (!$sale) {
        throw new RuntimeException('Sale not found.');
    }
    if ($sale['status'] === 'cancelled') {
        return;
    }
    if ((int) $sale['stock_returned'] === 0) {
        foreach ($sale['items'] as $item) {
            if (!empty($item['product_id'])) {
                record_stock_movement((int) $item['product_id'], (int) $item['quantity'], 'return', (string) $sale['sale_no'], 'Sale cancelled');
            }
        }
    }
    db_update('sales', $saleId, ['status' => 'cancelled', 'stock_returned' => 1]);
    audit('sale_cancelled', 'sales', $saleId, $sale['sale_no'] . ' cancelled');
}

/** Sales summary (count, revenue, cost) for a period. */
function sales_summary(?string $from = null, ?string $to = null): array
{
    $sql = 'SELECT COUNT(*) AS count,
                   COALESCE(SUM(total),0) AS revenue,
                   COALESCE(SUM(cost_total),0) AS cost
            FROM sales WHERE status IN ("paid","fulfilled","completed")';
    $params = [];
    if ($from) {
        $sql .= ' AND created_at >= ?';
        $params[] = $from . ' 00:00:00';
    }
    if ($to) {
        $sql .= ' AND created_at <= ?';
        $params[] = $to . ' 23:59:59';
    }
    $row = fetch_one($sql, $params);
    return [
        'count'   => (int) $row['count'],
        'revenue' => (float) $row['revenue'],
        'cost'    => (float) $row['cost'],
        'profit'  => (float) $row['revenue'] - (float) $row['cost'],
    ];
}

/** Sales attributed to each staff member (employee performance). */
function sales_by_staff(?string $from = null, ?string $to = null): array
{
    $sql = 'SELECT u.id, u.full_name, COUNT(s.id) AS sales, COALESCE(SUM(s.total),0) AS revenue,
                   COALESCE(SUM(s.total - s.cost_total),0) AS profit
            FROM sales s JOIN users u ON u.id = s.sold_by
            WHERE s.status IN ("paid","fulfilled","completed")';
    $params = [];
    if ($from) {
        $sql .= ' AND s.created_at >= ?';
        $params[] = $from . ' 00:00:00';
    }
    if ($to) {
        $sql .= ' AND s.created_at <= ?';
        $params[] = $to . ' 23:59:59';
    }
    $sql .= ' GROUP BY u.id, u.full_name ORDER BY revenue DESC';
    return fetch_all($sql, $params);
}

/** Best-selling products for a period. */
function top_products(?string $from = null, ?string $to = null, int $limit = 10): array
{
    $sql = 'SELECT si.product_name, SUM(si.quantity) AS units, COALESCE(SUM(si.line_total),0) AS revenue
            FROM sale_items si JOIN sales s ON s.id = si.sale_id
            WHERE s.status IN ("paid","fulfilled","completed")';
    $params = [];
    if ($from) {
        $sql .= ' AND s.created_at >= ?';
        $params[] = $from . ' 00:00:00';
    }
    if ($to) {
        $sql .= ' AND s.created_at <= ?';
        $params[] = $to . ' 23:59:59';
    }
    $sql .= ' GROUP BY si.product_name ORDER BY units DESC LIMIT ' . (int) $limit;
    return fetch_all($sql, $params);
}

/* =====================================================================
 * Shopping cart (session)
 * ===================================================================*/

function cart(): array
{
    if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
        $_SESSION['cart'] = [];
    }
    return $_SESSION['cart'];
}

function cart_add(int $productId, int $quantity = 1): void
{
    $cart = cart();
    $cart[$productId] = (int) ($cart[$productId] ?? 0) + max(1, $quantity);
    $_SESSION['cart'] = $cart;
}

function cart_set(int $productId, int $quantity): void
{
    $cart = cart();
    if ($quantity <= 0) {
        unset($cart[$productId]);
    } else {
        $cart[$productId] = (int) $quantity;
    }
    $_SESSION['cart'] = $cart;
}

function cart_remove(int $productId): void
{
    $cart = cart();
    unset($cart[$productId]);
    $_SESSION['cart'] = $cart;
}

function cart_clear(): void
{
    $_SESSION['cart'] = [];
}

function cart_count(): int
{
    return array_sum(cart());
}

/** Cart contents joined with live product data, with per-line totals. */
function cart_items(): array
{
    $items = [];
    foreach (cart() as $productId => $qty) {
        $product = get_product((int) $productId);
        if (!$product || !$product['is_active']) {
            continue;
        }
        $items[] = [
            'product'   => $product,
            'quantity'  => (int) $qty,
            'line_total'=> round((float) $product['price'] * (int) $qty, 2),
        ];
    }
    return $items;
}

function cart_subtotal(): float
{
    $total = 0.0;
    foreach (cart_items() as $item) {
        $total += $item['line_total'];
    }
    return round($total, 2);
}
