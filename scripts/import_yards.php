<?php

require_once __DIR__ . '/../public/api/db.php';

$DATASET_ID = 64036;
$API_TOKEN  = ''; // если используешь токен — вставь сюда
$SOURCE     = 'data.mos.ru';

$limit  = 500;
$offset = 0;
$totalProcessed = 0;

while (true) {
    $url = "https://apidata.mos.ru/v1/datasets/$DATASET_ID/rows"
         . "?$top=$limit&$skip=$offset";

    if (!empty($API_TOKEN)) {
        $url .= "&api_key=" . urlencode($API_TOKEN);
    }

    $json = file_get_contents($url);
    if ($json === false) {
        echo "Ошибка при запросе API\n";
        break;
    }

    $rows = json_decode($json, true);
    if (empty($rows)) {
        break;
    }

    foreach ($rows as $row) {
        $cells = $row['Cells'] ?? [];

        $external_id = $row['Id'] ?? null;
        $geometry    = $cells['geoData']['geometry'] ?? null;
        $area        = $cells['Area'] ?? null;

        if (!$external_id || !$geometry) {
            continue;
        }

        $stmt = $pdo->prepare("
            INSERT INTO yards (
                external_id,
                area_sq_m,
                geometry,
                source,
                imported_at
            )
            VALUES (
                :external_id,
                :area_sq_m,
                ST_SetSRID(ST_GeomFromGeoJSON(:geometry), 4326),
                :source,
                NOW()
            )
            ON CONFLICT (external_id) DO NOTHING
        ");

        $stmt->execute([
            'external_id' => $external_id,
            'area_sq_m'   => $area,
            'geometry'    => json_encode($geometry),
            'source'      => $SOURCE
        ]);

        $totalProcessed++;
    }

    $offset += $limit;
    echo "Импортировано: $totalProcessed\n";
}

echo "Импорт завершён. Всего записей: $totalProcessed\n";
