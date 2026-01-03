<?php
declare(strict_types=1);

require __DIR__ . '/../src/db.php';

/**
 * Массовый импорт дворовых территорий (dataset 64036) из data.mos.ru в PostGIS.
 * Использует cURL. Для вашей среды SSL-проверка временно отключена (см. ниже).
 *
 * Запуск:
 *   php backend/scripts/import_yards.php YOUR_API_KEY
 */

$apiKey = $argv[1] ?? '';
if ($apiKey === '') {
    fwrite(STDERR, "Usage: php backend/scripts/import_yards.php YOUR_API_KEY\n");
    exit(1);
}

$datasetId = 64036;
$version = 1;
$release = 288;

$baseUrl = "https://apidata.mos.ru/v1/datasets/{$datasetId}/features";

$top = 200;      // размер страницы
$skip = 0;       // смещение
$maxPages = 1000000; // защита от бесконечного цикла

function httpGetJson(string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_USERAGENT => 'walk-routes-importer/1.0',

        // ВАЖНО: в вашей среде был SSL error (self-signed chain).
        // Для импорта учебного проекта отключаем проверку.
        // Позже можно настроить CA bundle и вернуть true/2.
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);

    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        $code = curl_errno($ch);
        throw new RuntimeException("HTTP request failed (curl): {$err} (code {$code})");
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($httpCode >= 400) {
        throw new RuntimeException("HTTP error {$httpCode}: " . substr($raw, 0, 300));
    }

    $json = json_decode($raw, true);
    if (!is_array($json)) {
        throw new RuntimeException("Invalid JSON: " . substr($raw, 0, 300));
    }
    return $json;
}

// Рекурсивный поиск полей в properties/attributes
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

$upsert = $pdo->prepare("
  INSERT INTO public.yard_territories (global_id, adm_area, district, address, geom, updated_at)
  VALUES (
    :global_id, :adm_area, :district, :address,
    ST_Multi(
      ST_CollectionExtract(
        ST_MakeValid(ST_SetSRID(ST_GeomFromGeoJSON(:g), 4326)),
        3
      )
    )::geography,
    NOW()
  )
  ON CONFLICT (global_id) DO UPDATE
  SET adm_area = EXCLUDED.adm_area,
      district = EXCLUDED.district,
      address  = EXCLUDED.address,
      geom     = EXCLUDED.geom,
      updated_at = NOW();
");


$totalUpserts = 0;
$page = 0;

while ($page < $maxPages) {
    $url = $baseUrl
        . "?api_key=" . urlencode($apiKey)
        . "&version={$version}&release={$release}"
        . "&\$top={$top}&\$skip={$skip}";

    $data = httpGetJson($url);
    $features = $data['features'] ?? null;

    if (!is_array($features) || count($features) === 0) {
        echo "No more features. stop.\n";
        break;
    }

    $pdo->beginTransaction();

    foreach ($features as $f) {
        $props = $f['properties'] ?? [];
        $geom  = $f['geometry'] ?? null;
        if (!is_array($geom) || !isset($geom['type'], $geom['coordinates'])) continue;

        $globalId = findKeyRecursive($props, 'global_id');
        if ($globalId === null) continue;

        /// Нормализация геометрии к MultiPolygon
        $type = $geom['type'] ?? '';

        if ($type === 'Polygon') {
            $geom = [
                'type' => 'MultiPolygon',
                'coordinates' => [ $geom['coordinates'] ],
            ];
            $type = 'MultiPolygon';
        }

        if ($type === 'GeometryCollection') {
            // Берём из коллекции только Polygon/MultiPolygon
            $polys = [];
            $geomsIn = $geom['geometries'] ?? [];
            if (is_array($geomsIn)) {
                foreach ($geomsIn as $g) {
                    if (!is_array($g) || !isset($g['type'], $g['coordinates'])) continue;

                    if ($g['type'] === 'Polygon') {
                        $polys[] = [ $g['coordinates'] ];
                    } elseif ($g['type'] === 'MultiPolygon') {
                        foreach ($g['coordinates'] as $mp) {
                            $polys[] = $mp;
                        }
                    }
                }
            }

            if (count($polys) === 0) {
                // в коллекции нет полигонов — пропускаем
                continue;
            }

            $geom = [
                'type' => 'MultiPolygon',
                'coordinates' => $polys,
            ];
            $type = 'MultiPolygon';
        }

        if ($type !== 'MultiPolygon') {
            // Другие типы геометрии (Point/LineString и т.п.) нам не подходят
            continue;
        }


        $admArea  = findKeyRecursive($props, 'AdmArea');
        $district = findKeyRecursive($props, 'District');

        $address = null;
        $addresses = findKeyRecursive($props, 'Addresses');
        if (is_array($addresses) && isset($addresses[0]['Address'])) {
            $address = $addresses[0]['Address'];
        }

        $geomGeojson = json_encode($geom, JSON_UNESCAPED_UNICODE);

        $upsert->execute([
            ':global_id' => (int)$globalId,
            ':adm_area'  => $admArea ? (string)$admArea : null,
            ':district'  => $district ? (string)$district : null,
            ':address'   => $address ? (string)$address : null,
            ':g'         => $geomGeojson,
        ]);

        $totalUpserts++;
    }

    $pdo->commit();

    echo "Imported page: skip={$skip}, got=" . count($features) . ", totalUpserts={$totalUpserts}\n";

    $skip += $top;
    $page++;
}

echo "DONE. Total upserts: {$totalUpserts}\n";
