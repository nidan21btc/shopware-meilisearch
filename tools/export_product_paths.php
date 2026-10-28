<?php declare(strict_types=1);

// Read-only mapping from Shopware's own SEO URL history to product UUIDs.
// Provide DATABASE_URL as environment variable and an output filename.
if ($argc !== 2 || !getenv('DATABASE_URL')) {
    fwrite(STDERR, "Usage: DATABASE_URL=... php export_product_paths.php /path/output.json\n");
    exit(1);
}
$url = parse_url(getenv('DATABASE_URL'));
if (!is_array($url) || ($url['scheme'] ?? '') !== 'mysql' || !isset($url['host'], $url['path'])) {
    throw new InvalidArgumentException('Expected a MySQL DATABASE_URL.');
}
$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $url['host'], $url['port'] ?? 3306, ltrim($url['path'], '/')),
    rawurldecode($url['user'] ?? ''), rawurldecode($url['pass'] ?? ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$query = $pdo->query("SELECT locale.code locale, seo.seo_path_info path, LOWER(HEX(seo.foreign_key)) id
    FROM seo_url seo
    INNER JOIN language ON language.id = seo.language_id
    INNER JOIN locale ON locale.id = language.translation_code_id
    INNER JOIN product ON product.id = seo.foreign_key AND product.version_id = UNHEX('0fa91ce3e96a4bc2be4bd9ce752c3425')
    WHERE seo.route_name = 'frontend.detail.page' AND seo.seo_path_info IS NOT NULL");
$paths = [];
foreach ($query as $row) {
    $paths[$row['locale']][] = [$row['path'], $row['id']];
}
$numbers = [];
$families = [];
foreach ($pdo->query("SELECT product_number, LOWER(HEX(id)) id, LOWER(HEX(COALESCE(parent_id,id))) family FROM product WHERE version_id = UNHEX('0fa91ce3e96a4bc2be4bd9ce752c3425')") as $row) {
    $numbers[] = [$row['product_number'], $row['id']];
    $families[$row['id']] = $row['family'];
}
// Never overwrite an existing export, and keep the catalog mapping private.
$mask = umask(0077);
try { $file = @fopen($argv[1], 'x'); } finally { umask($mask); }
if ($file === false) throw new RuntimeException('Cannot create a new catalog export.');
try {
    $json = json_encode(['paths' => $paths, 'numbers' => $numbers, 'families' => (object) $families], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    if (fwrite($file, $json) !== strlen($json)) throw new RuntimeException('Cannot write catalog export.');
} finally { fclose($file); }
echo 'Exported ', array_sum(array_map('count', $paths)), ' historical SEO paths and ', count($numbers), " product numbers.\n";
