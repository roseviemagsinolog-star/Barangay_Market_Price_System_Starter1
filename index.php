<?php
/*
 * Barangay Market Price and Information System for Farmers
 * Single-file starter application for Laragon (PHP + MySQL).
 *
 * Default local database settings:
 *   host: 127.0.0.1
 *   user: root
 *   password: (blank)
 * Change these below if your MySQL settings are different.
 */
session_start();

$dbHost = '127.0.0.1';
$dbUser = 'root';
$dbPass = '';
$dbName = 'barangay_market_price';

mysqli_report(MYSQLI_REPORT_OFF);
$conn = new mysqli($dbHost, $dbUser, $dbPass);
if ($conn->connect_error) {
    http_response_code(500);
    die('Database connection failed. Start MySQL in Laragon and check the database settings at the top of index.php.');
}
$conn->query("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$conn->select_db($dbName);
$conn->set_charset('utf8mb4');

$conn->query("CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(60) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'official',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB");

$conn->query("CREATE TABLE IF NOT EXISTS price_entries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_name VARCHAR(120) NOT NULL,
    category VARCHAR(80) NOT NULL DEFAULT 'Other',
    price_per_kg DECIMAL(10,2) NOT NULL,
    market_name VARCHAR(120) NOT NULL DEFAULT 'Barangay Market',
    barangay VARCHAR(120) NOT NULL DEFAULT 'San Isidro, Liloan',
    price_date DATE NOT NULL,
    notes VARCHAR(255) DEFAULT '',
    updated_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX (product_name),
    INDEX (price_date)
) ENGINE=InnoDB");

/* Create a starter official account once. Change the password after first login. */
$check = $conn->prepare("SELECT id FROM users WHERE username = ?");
$starterUser = 'official';
$check->bind_param('s', $starterUser);
$check->execute();
$exists = $check->get_result()->fetch_assoc();
$check->close();
if (!$exists) {
    $starterHash = password_hash('ChangeMe123!', PASSWORD_DEFAULT);
    $insertUser = $conn->prepare("INSERT INTO users (username, password_hash, role) VALUES (?, ?, 'official')");
    $insertUser->bind_param('ss', $starterUser, $starterHash);
    $insertUser->execute();
    $insertUser->close();
}

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
function isOfficial() {
    return isset($_SESSION['official_id']) && $_SESSION['official_role'] === 'official';
}
function redirectSelf() {
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

$message = '';
$error = '';
$today = date('Y-m-d');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'login') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $stmt = $conn->prepare("SELECT id, username, password_hash, role FROM users WHERE username = ? LIMIT 1");
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($user && password_verify($password, $user['password_hash']) && $user['role'] === 'official') {
            session_regenerate_id(true);
            $_SESSION['official_id'] = (int)$user['id'];
            $_SESSION['official_username'] = $user['username'];
            $_SESSION['official_role'] = $user['role'];
            redirectSelf();
        } else {
            $error = 'Incorrect username or password.';
        }
    } elseif ($action === 'logout') {
        $_SESSION = [];
        session_destroy();
        redirectSelf();
    } elseif (in_array($action, ['add_price', 'edit_price', 'delete_price'], true)) {
        if (!isOfficial()) {
            $error = 'Please log in as a barangay official to manage prices.';
        } else {
            $csrf = $_POST['csrf_token'] ?? '';
            if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
                $error = 'Your session expired. Please refresh the page and try again.';
            } elseif ($action === 'delete_price') {
                $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
                if ($id) {
                    $stmt = $conn->prepare("DELETE FROM price_entries WHERE id = ?");
                    $stmt->bind_param('i', $id);
                    $stmt->execute();
                    $stmt->close();
                    $message = 'Price entry deleted.';
                } else {
                    $error = 'Invalid price entry.';
                }
            } else {
                $id = (int)($_POST['id'] ?? 0);
                $product = trim($_POST['product_name'] ?? '');
                $category = trim($_POST['category'] ?? 'Other');
                $price = filter_var($_POST['price_per_kg'] ?? '', FILTER_VALIDATE_FLOAT);
                $market = trim($_POST['market_name'] ?? 'Barangay Market');
                $barangay = trim($_POST['barangay'] ?? 'San Isidro, Liloan');
                $priceDate = $_POST['price_date'] ?? $today;
                $notes = trim($_POST['notes'] ?? '');
                $dateValid = DateTime::createFromFormat('Y-m-d', $priceDate);
                $validDate = $dateValid && $dateValid->format('Y-m-d') === $priceDate;

                if ($product === '' || mb_strlen($product) > 120 || $category === '' ||
                    $price === false || $price < 0 || $market === '' || $barangay === '' || !$validDate) {
                    $error = 'Please complete the required fields and enter a valid non-negative price and date.';
                } else {
                    $officialId = (int)$_SESSION['official_id'];
                    if ($action === 'add_price') {
                        $stmt = $conn->prepare("INSERT INTO price_entries (product_name, category, price_per_kg, market_name, barangay, price_date, notes, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->bind_param('ssdssssi', $product, $category, $price, $market, $barangay, $priceDate, $notes, $officialId);
                        if ($stmt->execute()) $message = 'Market price added successfully.';
                        else $error = 'Could not save the price. Please try again.';
                    } else {
                        $stmt = $conn->prepare("UPDATE price_entries SET product_name=?, category=?, price_per_kg=?, market_name=?, barangay=?, price_date=?, notes=?, updated_by=? WHERE id=?");
                        $stmt->bind_param('ssdssssii', $product, $category, $price, $market, $barangay, $priceDate, $notes, $officialId, $id);
                        if ($stmt->execute()) $message = 'Market price updated successfully.';
                        else $error = 'Could not update the price. Please try again.';
                    }
                    $stmt->close();
                }
            }
        }
    }
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

$search = trim($_GET['search'] ?? '');
$categoryFilter = trim($_GET['category'] ?? '');
$dateFilter = trim($_GET['date'] ?? '');
$barangayFilter = trim($_GET['barangay'] ?? '');
$editing = null;
if (isOfficial() && isset($_GET['edit']) && ctype_digit((string)$_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    $stmt = $conn->prepare("SELECT * FROM price_entries WHERE id=?");
    $stmt->bind_param('i', $editId);
    $stmt->execute();
    $editing = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$where = [];
$params = [];
$types = '';
if ($search !== '') {
    $where[] = '(product_name LIKE ? OR market_name LIKE ? OR barangay LIKE ? OR notes LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like);
    $types .= 'ssss';
}
if ($categoryFilter !== '') {
    $where[] = 'category = ?';
    $params[] = $categoryFilter;
    $types .= 's';
}
if ($dateFilter !== '') {
    $where[] = 'price_date = ?';
    $params[] = $dateFilter;
    $types .= 's';
}
if ($barangayFilter !== '') {
    $where[] = 'barangay LIKE ?';
    $params[] = '%' . $barangayFilter . '%';
    $types .= 's';
}
$sql = 'SELECT * FROM price_entries';
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' ORDER BY price_date DESC, product_name ASC LIMIT 250';
$stmt = $conn->prepare($sql);
if ($types !== '') $stmt->bind_param($types, ...$params);
$stmt->execute();
$prices = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$categoriesResult = $conn->query("SELECT DISTINCT category FROM price_entries ORDER BY category");
$categories = [];
while ($row = $categoriesResult->fetch_assoc()) $categories[] = $row['category'];

$stats = $conn->query("SELECT COUNT(*) AS total, COUNT(DISTINCT product_name) AS products, MAX(price_date) AS latest FROM price_entries")->fetch_assoc();
$history = [];
if ($search !== '') {
    $stmt = $conn->prepare("SELECT price_date, price_per_kg FROM price_entries WHERE product_name LIKE ? ORDER BY price_date ASC LIMIT 30");
    $likeHistory = '%' . $search . '%';
    $stmt->bind_param('s', $likeHistory);
    $stmt->execute();
    $history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Barangay Market Price System</title>
<style>
:root{--blue:#1769aa;--blue2:#eaf5ff;--ink:#193247;--muted:#637789;--line:#dce7ef;--green:#16805d;--red:#b42318;--bg:#f4f8fb}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font-family:Arial,Helvetica,sans-serif;line-height:1.5}
header{background:linear-gradient(120deg,#145da0,#2587c8);color:white;padding:22px max(18px,calc((100% - 1120px)/2));}
.top{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap}.brand{display:flex;align-items:center;gap:12px}.logo{width:46px;height:46px;display:grid;place-items:center;border-radius:14px;background:#ffffff25;font-size:25px}
h1{font-size:clamp(22px,3vw,31px);line-height:1.15;margin:0}.subtitle{margin:7px 0 0;color:#e5f4ff;font-size:14px}
main{max-width:1120px;margin:24px auto;padding:0 18px 42px}.hero{background:white;border:1px solid var(--line);border-radius:18px;padding:24px;margin-bottom:18px;box-shadow:0 8px 28px #1d4b6b0a}.hero h2{margin:0 0 8px;font-size:23px}.hero p{margin:0;color:var(--muted);max-width:760px}
.stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-top:18px}.stat{background:var(--blue2);border-radius:12px;padding:14px}.stat span{display:block;color:var(--muted);font-size:12px}.stat strong{font-size:22px}
.panel{background:white;border:1px solid var(--line);border-radius:16px;padding:20px;margin-bottom:18px;box-shadow:0 5px 20px #1d4b6b08}.panel-head{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:14px}.panel h2{font-size:19px;margin:0}
form .grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.field{display:flex;flex-direction:column;gap:5px}.field.full{grid-column:1/-1}label{font-size:13px;font-weight:700;color:#3b5367}input,select,textarea{width:100%;padding:11px 12px;border:1px solid #cbd9e4;border-radius:9px;font:inherit;background:white;color:var(--ink)}input:focus,select:focus,textarea:focus{outline:2px solid #8bc9f3;border-color:#2788c9}
.actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:14px}.btn{display:inline-flex;align-items:center;justify-content:center;border:0;border-radius:9px;padding:10px 15px;background:var(--blue);color:white;text-decoration:none;font-weight:700;font-size:14px;cursor:pointer}.btn:hover{filter:brightness(.94)}.btn.light{background:#eaf1f6;color:#28465d}.btn.green{background:var(--green)}.btn.red{background:var(--red)}.btn.small{padding:6px 9px;font-size:12px}
.filters{display:grid;grid-template-columns:2fr 1fr 1fr 1fr auto;gap:10px;align-items:end}.table-wrap{overflow-x:auto}table{border-collapse:collapse;width:100%;min-width:720px}th,td{text-align:left;padding:12px 10px;border-bottom:1px solid #e6edf2;font-size:14px}th{background:#f2f7fb;color:#425a6d;font-size:12px;text-transform:uppercase;letter-spacing:.04em}tbody tr:hover{background:#f9fcfe}.price{font-weight:800;color:#126c4c}.tag{display:inline-block;background:#e8f4ff;color:#155e91;padding:4px 8px;border-radius:99px;font-size:12px}
.notice{padding:12px 14px;border-radius:10px;margin-bottom:14px}.success{background:#e9f8f0;color:#146c43}.error{background:#fff0ef;color:#a32119}.muted{color:var(--muted);font-size:13px}.loginbox{max-width:430px}.inline-form{display:inline}.footer{text-align:center;color:var(--muted);font-size:12px;padding:20px}
.history{display:flex;flex-wrap:wrap;gap:8px}.history-item{background:#f1f7fb;border:1px solid var(--line);border-radius:8px;padding:8px 10px;font-size:13px}
@media(max-width:780px){form .grid{grid-template-columns:repeat(2,minmax(0,1fr))}.filters{grid-template-columns:1fr 1fr}.filters .search{grid-column:1/-1}.stats{grid-template-columns:1fr 1fr}.stats .stat:last-child{grid-column:1/-1}}
@media(max-width:480px){main{margin-top:14px;padding:0 12px 28px}.hero,.panel{padding:16px}.stats{grid-template-columns:1fr 1fr}.stats .stat:last-child{grid-column:1/-1}form .grid{grid-template-columns:1fr}.field.full{grid-column:auto}.filters{grid-template-columns:1fr}.filters .search{grid-column:auto}.top{align-items:flex-start}}
</style>
</head>
<body>
<header>
  <div class="top">
    <div class="brand"><div class="logo" aria-hidden="true">🌾</div><div><h1>Barangay Market Price System</h1><p class="subtitle">Barangay Market Price and Information System for Farmers</p></div></div>
    <?php if (isOfficial()): ?>
      <form method="post" class="inline-form"><input type="hidden" name="action" value="logout"><button class="btn light" type="submit">Log out (<?= h($_SESSION['official_username']) ?>)</button></form>
    <?php else: ?>
      <a class="btn light" href="#official-login">Official login</a>
    <?php endif; ?>
  </div>
</header>
<main>
  <section class="hero">
    <h2>Know the price before you sell.</h2>
    <p>Check current farm-product prices, search previous records, and use the information as a guide when discussing prices with buyers. Prices are for reference and may vary by quality, location, and market conditions.</p>
    <div class="stats">
      <div class="stat"><span>Price records</span><strong><?= (int)$stats['total'] ?></strong></div>
      <div class="stat"><span>Products listed</span><strong><?= (int)$stats['products'] ?></strong></div>
      <div class="stat"><span>Latest price date</span><strong style="font-size:17px"><?= h($stats['latest'] ?: 'No records yet') ?></strong></div>
    </div>
  </section>

  <?php if ($message): ?><div class="notice success"><?= h($message) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="notice error"><?= h($error) ?></div><?php endif; ?>

  <?php if (isOfficial()): ?>
  <section class="panel" id="price-form">
    <div class="panel-head"><h2><?= $editing ? 'Edit market price' : 'Add a market price' ?></h2><span class="tag">Official tools</span></div>
    <form method="post">
      <input type="hidden" name="action" value="<?= $editing ? 'edit_price' : 'add_price' ?>">
      <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
      <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int)$editing['id'] ?>"><?php endif; ?>
      <div class="grid">
        <div class="field"><label for="product_name">Product name *</label><input id="product_name" name="product_name" maxlength="120" required value="<?= h($editing['product_name'] ?? '') ?>" placeholder="e.g. Tomato"></div>
        <div class="field"><label for="category">Category *</label><select id="category" name="category" required>
          <?php $opts = ['Vegetables','Fruits','Rice and grains','Root crops','Livestock','Other']; $selectedCategory = $editing['category'] ?? 'Vegetables'; foreach ($opts as $opt): ?><option value="<?= h($opt) ?>" <?= $selectedCategory === $opt ? 'selected' : '' ?>><?= h($opt) ?></option><?php endforeach; ?>
        </select></div>
        <div class="field"><label for="price_per_kg">Price per kg (₱) *</label><input id="price_per_kg" name="price_per_kg" type="number" min="0" step="0.01" required value="<?= h($editing['price_per_kg'] ?? '') ?>" placeholder="e.g. 45.00"></div>
        <div class="field"><label for="price_date">Price date *</label><input id="price_date" name="price_date" type="date" required value="<?= h($editing['price_date'] ?? $today) ?>"></div>
        <div class="field"><label for="market_name">Market name *</label><input id="market_name" name="market_name" maxlength="120" required value="<?= h($editing['market_name'] ?? 'Barangay Market') ?>"></div>
        <div class="field"><label for="barangay">Barangay / location *</label><input id="barangay" name="barangay" maxlength="120" required value="<?= h($editing['barangay'] ?? 'San Isidro, Liloan') ?>"></div>
        <div class="field full"><label for="notes">Notes (optional)</label><input id="notes" name="notes" maxlength="255" value="<?= h($editing['notes'] ?? '') ?>" placeholder="e.g. Price may vary by quality"></div>
      </div>
      <div class="actions"><button class="btn green" type="submit"><?= $editing ? 'Save changes' : 'Add price' ?></button><?php if ($editing): ?><a class="btn light" href="<?= h(strtok($_SERVER['REQUEST_URI'], '?')) ?>">Cancel edit</a><?php endif; ?></div>
    </form>
  </section>
  <?php endif; ?>

  <section class="panel">
    <div class="panel-head"><h2>Market prices</h2><span class="muted">Prices are for reference only</span></div>
    <form method="get" class="filters">
      <div class="field search"><label for="search">Search product or location</label><input id="search" name="search" value="<?= h($search) ?>" placeholder="e.g. tomato, rice, San Isidro"></div>
      <div class="field"><label for="category_filter">Category</label><select id="category_filter" name="category"><option value="">All categories</option><?php foreach (array_unique(array_merge(['Vegetables','Fruits','Rice and grains','Root crops','Livestock','Other'], $categories)) as $cat): ?><option value="<?= h($cat) ?>" <?= $categoryFilter === $cat ? 'selected' : '' ?>><?= h($cat) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label for="date_filter">Date</label><input id="date_filter" type="date" name="date" value="<?= h($dateFilter) ?>"></div>
      <div class="field"><label for="barangay_filter">Barangay</label><input id="barangay_filter" name="barangay" value="<?= h($barangayFilter) ?>" placeholder="Any location"></div>
      <div class="actions"><button class="btn" type="submit">Search</button><a class="btn light" href="<?= h(strtok($_SERVER['REQUEST_URI'], '?')) ?>">Clear</a></div>
    </form>
    <p class="muted"><?= count($prices) ?> record(s) shown (up to 250). Add real prices through the official account; no sample prices are inserted automatically.</p>
    <div class="table-wrap"><table>
      <thead><tr><th>Product</th><th>Category</th><th>Price / kg</th><th>Market / Barangay</th><th>Date</th><?php if (isOfficial()): ?><th>Manage</th><?php endif; ?></tr></thead>
      <tbody>
      <?php if (!$prices): ?><tr><td colspan="<?= isOfficial() ? 6 : 5 ?>" class="muted">No prices found. Try a different search, or ask a barangay official to add a price record.</td></tr><?php else: foreach ($prices as $row): ?>
        <tr><td><strong><?= h($row['product_name']) ?></strong><?php if ($row['notes']): ?><div class="muted"><?= h($row['notes']) ?></div><?php endif; ?></td><td><?= h($row['category']) ?></td><td class="price">₱<?= number_format((float)$row['price_per_kg'], 2) ?></td><td><?= h($row['market_name']) ?><div class="muted"><?= h($row['barangay']) ?></div></td><td><?= h($row['price_date']) ?></td>
        <?php if (isOfficial()): ?><td><div class="actions" style="margin:0"><a class="btn small" href="?edit=<?= (int)$row['id'] ?>">Edit</a><form method="post" class="inline-form" onsubmit="return confirm('Delete this price record?');"><input type="hidden" name="action" value="delete_price"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>"><button class="btn red small" type="submit">Delete</button></form></div></td><?php endif; ?></tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table></div>
  </section>

  <?php if ($search !== '' && $history): ?>
  <section class="panel"><h2>Price history matching “<?= h($search) ?>”</h2><p class="muted">Records are shown by date. Search one product name for a clearer history.</p><div class="history"><?php foreach ($history as $item): ?><div class="history-item"><?= h($item['price_date']) ?> · <strong>₱<?= number_format((float)$item['price_per_kg'], 2) ?>/kg</strong></div><?php endforeach; ?></div></section>
  <?php endif; ?>

  <?php if (!isOfficial()): ?>
  <section class="panel loginbox" id="official-login">
    <h2>Barangay official login</h2><p class="muted">Only authorized officials can add, edit, or remove price records.</p>
    <form method="post"><input type="hidden" name="action" value="login">
      <div class="field" style="margin-bottom:10px"><label for="username">Username</label><input id="username" name="username" autocomplete="username" required></div>
      <div class="field"><label for="password">Password</label><input id="password" name="password" type="password" autocomplete="current-password" required></div>
      <div class="actions"><button class="btn" type="submit">Log in</button></div>
    </form>
    <p class="muted"><strong>First local test login:</strong> username <code>official</code>, password <code>ChangeMe123!</code>. Change this starter password before using real data.</p>
  </section>
  <?php endif; ?>

  <div class="footer">Barangay Market Price and Information System for Farmers · Built for local demonstration · Prices are reference information, not official legal price limits.</div>
</main>
</body>
</html>
