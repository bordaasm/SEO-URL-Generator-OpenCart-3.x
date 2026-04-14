<?php
/**
 * SEO URL Generator for OpenCart 3.x
 * Generates slugs for categories and products from Russian names
 *
 * Usage:
 *   ?mode=dry   — show what will be inserted (no changes)
 *   ?mode=run   — actually insert into DB
 *   ?mode=clear — delete all auto-generated SEO URLs (for categories and products)
 */

// ── Load OpenCart config ────────────────────────────────────────────────────
define('DIR_ROOT', __DIR__ . '/');
require_once DIR_ROOT . 'config.php';

// ── DB connection ───────────────────────────────────────────────────────────
$pdo = new PDO(
    'mysql:host=' . DB_HOSTNAME . ';port=' . DB_PORT . ';dbname=' . DB_DATABASE . ';charset=utf8',
    DB_USERNAME,
    DB_PASSWORD,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$prefix = DB_PREFIX; // oc_
$mode   = $_GET['mode'] ?? 'dry';

// ── Transliteration table (Russian → Latin) ─────────────────────────────────
function transliterate(string $text): string {
    $map = [
        'а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'yo',
        'ж'=>'zh','з'=>'z','и'=>'i','й'=>'j','к'=>'k','л'=>'l','м'=>'m',
        'н'=>'n','о'=>'o','п'=>'p','р'=>'r','с'=>'s','т'=>'t','у'=>'u',
        'ф'=>'f','х'=>'kh','ц'=>'ts','ч'=>'ch','ш'=>'sh','щ'=>'shch',
        'ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya',
        'А'=>'a','Б'=>'b','В'=>'v','Г'=>'g','Д'=>'d','Е'=>'e','Ё'=>'yo',
        'Ж'=>'zh','З'=>'z','И'=>'i','Й'=>'j','К'=>'k','Л'=>'l','М'=>'m',
        'Н'=>'n','О'=>'o','П'=>'p','Р'=>'r','С'=>'s','Т'=>'t','У'=>'u',
        'Ф'=>'f','Х'=>'kh','Ц'=>'ts','Ч'=>'ch','Ш'=>'sh','Щ'=>'shch',
        'Ъ'=>'','Ы'=>'y','Ь'=>'','Э'=>'e','Ю'=>'yu','Я'=>'ya',
        // Ukrainian
        'і'=>'i','ї'=>'yi','є'=>'ye','ґ'=>'g',
        'І'=>'i','Ї'=>'yi','Є'=>'ye','Ґ'=>'g',
    ];
    return strtr($text, $map);
}

function makeSlug(string $text): string {
    $text = transliterate($text);
    $text = mb_strtolower($text, 'UTF-8');
    $text = preg_replace('/[^a-z0-9\-]+/', '-', $text);
    $text = preg_replace('/-{2,}/', '-', $text);
    return trim($text, '-');
}

// ── Make slug unique ─────────────────────────────────────────────────────────
function uniqueSlug(PDO $pdo, string $prefix, string $base, int $storeId, int $langId, ?string $existingQuery = null): string {
    $slug = $base;
    $i    = 1;
    while (true) {
        $st = $pdo->prepare(
            "SELECT query FROM {$prefix}seo_url
             WHERE store_id = ? AND language_id = ? AND keyword = ?"
        );
        $st->execute([$storeId, $langId, $slug]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        // Free if nothing found, or if found for THIS query (already set)
        if (!$row || ($existingQuery && $row['query'] === $existingQuery)) {
            break;
        }
        $i++;
        $slug = $base . '-' . $i;
    }
    return $slug;
}

// ── Get store_id and language_id ─────────────────────────────────────────────
$storeId = 0; // default store

$langRow = $pdo->query(
    "SELECT language_id FROM {$prefix}language WHERE status = 1 ORDER BY sort_order ASC LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);

if (!$langRow) {
    die('No active language found in DB.');
}
$langId = (int)$langRow['language_id'];

// ── Fetch categories ─────────────────────────────────────────────────────────
$categories = $pdo->query(
    "SELECT c.category_id, cd.name
     FROM {$prefix}category c
     JOIN {$prefix}category_description cd
       ON c.category_id = cd.category_id AND cd.language_id = {$langId}
     ORDER BY c.category_id"
)->fetchAll(PDO::FETCH_ASSOC);

// ── Fetch products ────────────────────────────────────────────────────────────
$products = $pdo->query(
    "SELECT p.product_id, pd.name
     FROM {$prefix}product p
     JOIN {$prefix}product_description pd
       ON p.product_id = pd.product_id AND pd.language_id = {$langId}
     ORDER BY p.product_id"
)->fetchAll(PDO::FETCH_ASSOC);

// ── Build list of entries to insert/update ────────────────────────────────────
$entries = [];

foreach ($categories as $row) {
    $query    = 'category_id=' . $row['category_id'];
    $baseSlug = makeSlug($row['name']);
    if (!$baseSlug) $baseSlug = 'category-' . $row['category_id'];

    // Check if already has a keyword
    $existing = $pdo->prepare(
        "SELECT keyword FROM {$prefix}seo_url
         WHERE store_id = ? AND language_id = ? AND query = ?"
    );
    $existing->execute([$storeId, $langId, $query]);
    $existingKeyword = $existing->fetchColumn();

    $entries[] = [
        'type'     => 'category',
        'id'       => $row['category_id'],
        'name'     => $row['name'],
        'query'    => $query,
        'slug'     => $existingKeyword ?: uniqueSlug($pdo, $prefix, $baseSlug, $storeId, $langId, $query),
        'existing' => (bool)$existingKeyword,
    ];
}

foreach ($products as $row) {
    $query    = 'product_id=' . $row['product_id'];
    $baseSlug = makeSlug($row['name']);
    if (!$baseSlug) $baseSlug = 'product-' . $row['product_id'];

    $existing = $pdo->prepare(
        "SELECT keyword FROM {$prefix}seo_url
         WHERE store_id = ? AND language_id = ? AND query = ?"
    );
    $existing->execute([$storeId, $langId, $query]);
    $existingKeyword = $existing->fetchColumn();

    $entries[] = [
        'type'     => 'product',
        'id'       => $row['product_id'],
        'name'     => $row['name'],
        'query'    => $query,
        'slug'     => $existingKeyword ?: uniqueSlug($pdo, $prefix, $baseSlug, $storeId, $langId, $query),
        'existing' => (bool)$existingKeyword,
    ];
}

// ── Actions ──────────────────────────────────────────────────────────────────
$inserted = 0;
$skipped  = 0;

if ($mode === 'clear') {
    $del = $pdo->prepare(
        "DELETE FROM {$prefix}seo_url
         WHERE store_id = ? AND language_id = ?
           AND (query LIKE 'category_id=%' OR query LIKE 'product_id=%')"
    );
    $del->execute([$storeId, $langId]);
    $count = $del->rowCount();
    echo "Deleted {$count} SEO URL rows. Now reload with ?mode=dry or ?mode=run\n";
    exit;
}

if ($mode === 'run') {
    foreach ($entries as $e) {
        if ($e['existing']) {
            $skipped++;
            continue;
        }
        $st = $pdo->prepare(
            "INSERT IGNORE INTO {$prefix}seo_url (store_id, language_id, query, keyword)
             VALUES (?, ?, ?, ?)"
        );
        $st->execute([$storeId, $langId, $e['query'], $e['slug']]);
        $inserted++;
    }
}

// ── Output ────────────────────────────────────────────────────────────────────
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<title>SEO URL Generator</title>
<style>
  body { font-family: monospace; font-size: 13px; padding: 20px; background: #111; color: #ddd; }
  h2 { color: #7cf; }
  .info { color: #aaa; margin-bottom: 10px; }
  table { border-collapse: collapse; width: 100%; }
  th { background: #222; color: #7cf; padding: 6px 10px; text-align: left; }
  td { padding: 5px 10px; border-bottom: 1px solid #333; }
  .new  { color: #7fc; }
  .skip { color: #888; }
  .cat  { color: #fa0; }
  .prod { color: #adf; }
  .btn  { display: inline-block; margin: 5px; padding: 10px 20px; border-radius: 4px;
          text-decoration: none; font-size: 14px; font-weight: bold; }
  .btn-dry   { background: #444; color: #fff; }
  .btn-run   { background: #2a7; color: #fff; }
  .btn-clear { background: #a33; color: #fff; }
  .stat { margin: 15px 0; font-size: 14px; }
</style>
</head>
<body>
<h2>SEO URL Generator — OpenCart 3.x</h2>

<div class="info">
  store_id: <?= $storeId ?> &nbsp;|&nbsp;
  language_id: <?= $langId ?> &nbsp;|&nbsp;
  mode: <strong><?= htmlspecialchars($mode) ?></strong>
</div>

<a class="btn btn-dry"   href="?mode=dry">Dry Run (preview)</a>
<a class="btn btn-run"   href="?mode=run">Run (insert missing)</a>
<a class="btn btn-clear" href="?mode=clear" onclick="return confirm('Delete all category/product SEO URLs?')">Clear all</a>

<?php if ($mode === 'run'): ?>
<div class="stat">
  Inserted: <strong class="new"><?= $inserted ?></strong> &nbsp;|&nbsp;
  Skipped (already had slug): <strong class="skip"><?= $skipped ?></strong>
</div>
<?php endif; ?>

<table>
  <tr>
    <th>Type</th>
    <th>ID</th>
    <th>Name</th>
    <th>Query</th>
    <th>Slug</th>
    <th>Status</th>
  </tr>
  <?php foreach ($entries as $e): ?>
  <tr>
    <td class="<?= $e['type'] === 'category' ? 'cat' : 'prod' ?>"><?= $e['type'] ?></td>
    <td><?= $e['id'] ?></td>
    <td><?= htmlspecialchars($e['name']) ?></td>
    <td><?= htmlspecialchars($e['query']) ?></td>
    <td><?= htmlspecialchars($e['slug']) ?></td>
    <td class="<?= $e['existing'] ? 'skip' : 'new' ?>">
      <?= $e['existing'] ? 'already set' : ($mode === 'run' ? 'inserted' : 'will insert') ?>
    </td>
  </tr>
  <?php endforeach; ?>
</table>
</body>
</html>
