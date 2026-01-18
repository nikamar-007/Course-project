<?php
$API_KEY = '69cbe98e-7db7-43f9-9f41-bcc6bc83d70f';
$DATASET_ID = 64036;
$API_URL = "https://apidata.mos.ru/v1/datasets/$DATASET_ID/rows";

$LIMIT  = 100;
$OFFSET = 0;

$pdo = new PDO(
    "pgsql:host=127.0.0.1;dbname=walk_routes",
    "postgres",
    "qwerty12345@",
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]
);


$sql = "
INSERT INTO public.yards (
    external_id,
    adm_area,
    district,
    address,
    area_sq_m,
    geometry,
    imported_at
)
VALUES (
    :external_id,
    :adm_area,
    :district,
    :address,
    :area_sq_m,
    ST_SetSRID(ST_GeomFromGeoJSON(:geometry), 4326),
    NOW()
)
ON CONFLICT (external_id) DO NOTHING
";

$stmt = $pdo->prepare($sql);

$totalProcessed = 0;
$totalInserted  = 0;

while (true) {

    $query =
        '$top=' . $LIMIT .
        '&$skip=' . $OFFSET .
        '&api_key=' . urlencode($API_KEY);

    $json = file_get_contents("$API_URL?$query");

    if ($json === false) {
        die("Ошибка запроса к API\n");
    }

    $rows = json_decode($json, true);

    if (empty($rows)) {
        break;
    }

    foreach ($rows as $row) {
        $totalProcessed++;
         if (!isset($row['Cells']['geoData'])) {
            continue;
        }

        $stmt->execute([
            ':external_id' => (string)$row['global_id'],
            ':adm_area'    => $row['Cells']['AdmArea']  ?? null,
            ':district'    => $row['Cells']['District'] ?? null,
            ':address'     => $row['Cells']['Address']  ?? null,
            ':area_sq_m'   => $row['Cells']['Area']     ?? null,
            ':geometry'    => json_encode($row['Cells']['geoData'])
        ]);

        $totalInserted++;
    }


    $OFFSET += $LIMIT;
    echo "Обработано: $totalProcessed\n";
}

echo "\nИмпорт завершён.\n";
echo "Всего обработано: $totalProcessed\n";
echo "Попыток вставки:  $totalInserted\n";
