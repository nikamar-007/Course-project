<?php
declare(strict_types=1);

require __DIR__ . '/../src/db.php';

/**
 * Импорт 1 записи дворовой территории из data.mos.ru (dataset 64036) в PostGIS.
 * Использует cURL (надёжнее на Windows).
 *
 * Запуск:
 *   php backend/scripts/import_yards_one.php YOUR_API_KEY
 *
 * Или ключ из backend/.env:
 *   MOS_API_KEY=...
 */

function readEnvFile(string $path): array {
    if (!is_file($path)) return [];
    $vars = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        [$k, $v] = array_pad(explode('=', $line, 2), 2, '');
        $vars[trim($k)] = trim($v);
    }
    return $vars;
}

$env = readEnvFile(__DIR__ . '/../.env');

$apiKey = $argv[1] ?? ($env['MOS_API_KEY'] ?? '');
if ($apiKey === '') {
    fwrite(STDERR, "Usage: php backend/scripts/import_yards_one.php YOUR_API_KEY\n");
    fwrite(STDERR, "Or set MOS_API_KEY in backend/.env\n");
    exit(1);
}

$datasetId = 64036;
$version = 1;
$release = 288;

$url =
  "https://apidata.mos.ru/v1/datasets/{$datasetId}/features"
  . "?api_key=" . urlencode($apiKey)
  . "&version={$version}&release={$release}"
  . "&\$top=1&\$skip=0";

function httpGetJson(string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_USERAGENT => 'walk-routes-importer/1.0',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);

    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        $code = curl_errno($ch);
        curl_close($ch);
        throw new RuntimeException("HTTP request failed (curl): {$err} (code {$code})");
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode >= 400) {
        throw new RuntimeException("HTTP error {$httpCode}: " . substr($raw, 0, 300));
    }

    $json = json_decode($raw, true);
    if (!is_array($json)) {
        throw new RuntimeException("Invalid JSON: " . substr($raw, 0, 300));
    }
    return $json;
}

// Рекурсивно ищем ключ в любом месте массива (подходит для properties/attributes)
function findKeyRecursive($data, string $key) {
    if (!is_array($data)) return null;
    if (array_key_exists($key, $data)) return $data[$key];
    foreach ($data as $v) {
        $r = findKeyRecursive($v, $key);
        if ($r !== null) return $r;
    }
    return null;
}

$pdo = db();
echo "DB: " . $pdo->query("select current_database()")->fetchColumn() . PHP_EOL;

// Таблица должна быть уже правильной (с adm_area, district, address, geom)
$pdo->exec("
  CREATE TABLE IF NOT EXISTS public.yard_territories (
    id         BIGSERIAL PRIMARY KEY,
    global_id  BIGINT UNIQUE,
    adm_area   TEXT,
    district   TEXT,
    address    TEXT,
    geom       GEOGRAPHY(MultiPolygon, 4326) NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT NOW()
  );
");
$pdo->exec("CREATE INDEX IF NOT EXISTS yard_territories_geom_idx ON public.yard_territories USING GIST (geom);");

try {
    $data = httpGetJson($url);
} catch (Throwable $e) {
    // Частая проблема на Windows: SSL-сертификаты.
    // Для учебного проекта можно временно отключить проверку и убедиться, что причина в SSL.
    // Если хотите — потом настроим ca-bundle и вернём проверку обратно.
    echo "HTTP ERROR: " . $e->getMessage() . PHP_EOL;
    echo "If this looks like SSL/cert issue, tell me the message and I will give the exact fix.\n";
    exit(1);
}

$features = $data['features'] ?? null;
if (!is_array($features) || count($features) === 0) {
    echo "ERROR: features empty\n";
    exit(1);
}

$f = $features[0];
$props = $f['properties'] ?? [];
$geom  = $f['geometry'] ?? null;

$globalId = findKeyRecursive($props, 'global_id');
$admArea  = findKeyRecursive($props, 'AdmArea');
$district = findKeyRecursive($props, 'District');

// Адрес чаще всего в Addresses[0].Address
$address = null;
$addresses = findKeyRecursive($props, 'Addresses');
if (is_array($addresses) && isset($addresses[0]['Address'])) {
    $address = $addresses[0]['Address'];
}

echo "DEBUG: global_id=" . var_export($globalId, true) . PHP_EOL;
echo "DEBUG: district=" . var_export($district, true) . PHP_EOL;

if ($globalId === null) {
    echo "ERROR: global_id not found in properties\n";
    exit(1);
}

if (!is_array($geom) || !isset($geom['type'], $geom['coordinates'])) {
    echo "ERROR: geometry missing\n";
    exit(1);
}

// Нормализуем Polygon -> MultiPolygon
if ($geom['type'] === 'Polygon') {
    $geom = [
        'type' => 'MultiPolygon',
        'coordinates' => [ $geom['coordinates'] ],
    ];
}
if ($geom['type'] !== 'MultiPolygon') {
    echo "ERROR: unsupported geometry type: " . $geom['type'] . PHP_EOL;
    exit(1);
}

$geomGeojson = json_encode($geom, JSON_UNESCAPED_UNICODE);

$sql = "
  INSERT INTO public.yard_territories (global_id, adm_area, district, address, geom, updated_at)
  VALUES (
    :global_id, :adm_area, :district, :address,
    ST_MakeValid(ST_SetSRID(ST_GeomFromGeoJSON(:g), 4326))::geography,
    NOW()
  )
  ON CONFLICT (global_id) DO UPDATE
  SET adm_area = EXCLUDED.adm_area,
      district = EXCLUDED.district,
      address  = EXCLUDED.address,
      geom     = EXCLUDED.geom,
      updated_at = NOW();
";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':global_id' => (int)$globalId,
        ':adm_area' => $admArea ? (string)$admArea : null,
        ':district' => $district ? (string)$district : null,
        ':address' => $address ? (string)$address : null,
        ':g' => $geomGeojson,
    ]);

    $cnt = $pdo->query("select count(*) from public.yard_territories")->fetchColumn();
    echo "OK: inserted/upserted 1 row. COUNT=" . $cnt . PHP_EOL;

} catch (Throwable $e) {
    echo "SQL ERROR: " . $e->getMessage() . PHP_EOL;
    exit(1);
}
