<?php
require __DIR__ . '/db.php';

$stmt = $pdo->query("
  SELECT id, ST_AsGeoJSON(geometry) AS geo
  FROM yards
");

$out = [];

while ($row = $stmt->fetch()) {
  $out[] = [
    'id' => $row['id'],
    'geometry' => json_decode($row['geo'], true)
  ];
}

echo json_encode($out);
