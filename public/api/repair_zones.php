<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: application/json; charset=utf-8');

session_start();

$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

try {
    $pdo = new PDO(
        'pgsql:host=localhost;port=5432;dbname=walk_routes',
        'postgres',
        'qwerty12345@',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    function updateZoneStatuses($pdo) {
        try {
            $pdo->exec("
                UPDATE repair_zones 
                SET status = 'deleted', 
                    is_active = false 
                WHERE status = 'pending'
                AND confirm_count = 0 
                AND created_at < NOW() - INTERVAL '24 hours'
            ");
            
            $pdo->exec("
                UPDATE repair_zones 
                SET status = 'warning' 
                WHERE status = 'active'
                AND created_at < NOW() - INTERVAL '7 days'
                AND created_at >= NOW() - INTERVAL '14 days'
            ");
            
            $pdo->exec("
                UPDATE repair_zones 
                SET status = 'expired' 
                WHERE status = 'warning'
                AND created_at < NOW() - INTERVAL '14 days'
            ");
            
            $pdo->exec("
                UPDATE repair_zones 
                SET status = 'expired' 
                WHERE status = 'active'
                AND created_at < NOW() - INTERVAL '14 days'
            ");
            
            $pdo->exec("
                UPDATE repair_zones 
                SET status = 'deleted', 
                    is_active = false 
                WHERE status = 'expired'
                AND (
                    last_confirmed_at IS NULL 
                    OR last_confirmed_at < NOW() - INTERVAL '24 hours'
                )
            ");
            
            $pdo->exec("
                UPDATE repair_zones 
                SET status = 'active' 
                WHERE status = 'pending'
                AND confirm_count > 0
            ");
            
        } catch (Exception $e) {
            error_log("Ошибка в updateZoneStatuses: " . $e->getMessage());
        }
    }
    updateZoneStatuses($pdo);
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && !isset($_GET['id'])) {
        try {
            $stmt = $pdo->prepare("
                SELECT
                  rz.id,
                  rz.description,
                  ST_AsGeoJSON(rz.geometry) AS geometry,
                  rz.confirm_count,
                  rz.created_at,
                  rz.last_confirmed_at,
                  rz.creator_id,
                  rz.status,
                  CASE 
                    WHEN rz.confirm_count = 0 AND rz.created_at >= NOW() - INTERVAL '24 hours' 
                         THEN '#9E9E9E'
                    WHEN rz.confirm_count > 0 AND rz.created_at >= NOW() - INTERVAL '7 days' 
                         THEN '#E53935'
                    WHEN rz.confirm_count > 0 AND rz.created_at >= NOW() - INTERVAL '14 days' 
                         THEN '#FBC02D'
                    WHEN rz.confirm_count > 0 AND rz.created_at < NOW() - INTERVAL '14 days'
                         AND rz.last_confirmed_at >= NOW() - INTERVAL '24 hours'
                         THEN '#43A047'
                    ELSE NULL
                  END AS color
                FROM repair_zones rz
                WHERE rz.is_active = true
                  AND rz.status != 'deleted'
                  AND (
                    (rz.confirm_count = 0 AND rz.created_at >= NOW() - INTERVAL '24 hours')
                    OR
                    (rz.confirm_count > 0 AND rz.created_at >= NOW() - INTERVAL '7 days')
                    OR
                    (rz.confirm_count > 0 AND rz.created_at >= NOW() - INTERVAL '14 days' 
                     AND rz.created_at < NOW() - INTERVAL '7 days')
                    OR
                    (rz.confirm_count > 0 AND rz.created_at < NOW() - INTERVAL '14 days'
                     AND rz.last_confirmed_at >= NOW() - INTERVAL '24 hours')
                  )
                ORDER BY 
                  CASE 
                    WHEN rz.confirm_count = 0 THEN 0
                    ELSE 1
                  END,
                  rz.last_confirmed_at DESC
            ");
            
            $stmt->execute();
            $zones = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($zones as &$zone) {
                $zone['is_creator'] = ($zone['creator_id'] == $userId);
                if ($zone['confirm_count'] == 0) {
                    $zone['status_display'] = 'pending';
                } elseif (strtotime($zone['created_at']) >= strtotime('-7 days')) {
                    $zone['status_display'] = 'active_red';
                } elseif (strtotime($zone['created_at']) >= strtotime('-14 days')) {
                    $zone['status_display'] = 'active_yellow';
                } else {
                    $zone['status_display'] = 'expired_green';
                }
            }
            
            echo json_encode($zones);
            exit;
            
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to load zones: ' . $e->getMessage()]);
            exit;
        }
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_GET['id'])) {
        try {
            $data = json_decode(file_get_contents('php://input'), true);

            if (!$data || !isset($data['geometry'])) {
                throw new Exception('Нет данных geometry');
            }

            $geometryJson = json_encode($data['geometry']);
            $checkIntersectSql = "
                SELECT COUNT(*) 
                FROM repair_zones 
                WHERE is_active = true 
                AND status != 'deleted'
                AND ST_Intersects(
                        geometry, 
                        ST_SetSRID(ST_GeomFromGeoJSON(:geometry), 4326)
                    )
            ";

            $checkIntersectStmt = $pdo->prepare($checkIntersectSql);
            $checkIntersectStmt->execute([':geometry' => $geometryJson]);
            $intersectCount = $checkIntersectStmt->fetchColumn();

            if ($intersectCount > 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Новая зона пересекается с уже существующей активной зоной']);
                exit;
            }        

            $sql = "
                INSERT INTO repair_zones (
                    description, 
                    geometry, 
                    is_active, 
                    creator_id, 
                    created_at, 
                    last_confirmed_at,
                    confirm_count,
                    status
                )
                VALUES (
                    :description,
                    ST_SetSRID(ST_GeomFromGeoJSON(:geometry), 4326),
                    true,
                    :creator_id,
                    NOW(),
                    NULL,
                    0,
                    'pending'
                )
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':description' => $data['description'] ?? 'Ремонт',
                ':geometry'    => json_encode($data['geometry']),
                ':creator_id'  => $userId
            ]);

            $zoneId = $pdo->lastInsertId();
            $creatorId = $userId;

            $yardsStmt = $pdo->prepare("
                SELECT id
                FROM yards
                WHERE ST_Intersects(
                    geometry,
                    (SELECT geometry FROM repair_zones WHERE id = :zone_id)
                )
            ");
            $yardsStmt->execute(['zone_id' => $zoneId]);
            $yardIds = $yardsStmt->fetchAll(PDO::FETCH_COLUMN);

            foreach ($yardIds as $yardId) {
                $usersStmt = $pdo->prepare("
                    SELECT id
                    FROM users
                    WHERE yard_id = :yard_id
                    AND id != :creator_id
                ");
                $usersStmt->execute([
                    'yard_id'     => $yardId,
                    'creator_id'  => $creatorId
                ]);

                $userIds = $usersStmt->fetchAll(PDO::FETCH_COLUMN);

                foreach ($userIds as $notifyUserId) {
                    $notifyStmt = $pdo->prepare("
                        INSERT INTO notifications (user_id, type, title, message, link)
                        VALUES (:user_id, 'yard_repair', :title, :message, :link)
                    ");

                    $notifyStmt->execute([
                        'user_id' => $notifyUserId,
                        'title'   => 'На вашей территории ведутся работы',
                        'message' => 'В вашем дворе отмечена ремонтная зона',
                        'link'    => '/index.html?zone=' . $zoneId
                    ]);
                }
            }

            echo json_encode(['status' => 'ok', 'zone_id' => $zoneId]);
            exit;

        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
            exit;
        }
    }
    if ($_SERVER['REQUEST_METHOD'] === 'PUT' && isset($_GET['id'])) {
        try {
            $zoneId = (int)$_GET['id'];
            if ($userId === 0) {
                http_response_code(401);
                echo json_encode(['error' => 'Требуется авторизация']);
                exit;
            }
            
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (!$data) {
                throw new Exception('Нет данных для обновления');
            }
            $checkStmt = $pdo->prepare("
                SELECT creator_id FROM repair_zones WHERE id = ? AND is_active = true
            ");
            $checkStmt->execute([$zoneId]);
            $zone = $checkStmt->fetch();
            
            if (!$zone || $zone['creator_id'] != $userId) {
                throw new Exception('Вы не являетесь создателем этой зоны или зона неактивна');
            }
            if (isset($data['geometry'])) {
                $checkIntersectSql = "
                    SELECT COUNT(*) 
                    FROM repair_zones 
                    WHERE is_active = true 
                    AND status != 'deleted'
                    AND id != ?
                    AND ST_Intersects(
                        geometry, 
                        ST_SetSRID(ST_GeomFromGeoJSON(?), 4326)
                    )
                ";
                
                $checkIntersectStmt = $pdo->prepare($checkIntersectSql);
                $checkIntersectStmt->execute([$zoneId, json_encode($data['geometry'])]);
                $intersectCount = $checkIntersectStmt->fetchColumn();
                
                if ($intersectCount > 0) {
                    throw new Exception('Обновленная зона пересекается с другой существующей зоной');
                }
            }
            $updateFields = [];
            $updateParams = [];
            
            if (isset($data['description'])) {
                $updateFields[] = "description = ?";
                $updateParams[] = $data['description'];
            }
            
            if (isset($data['geometry'])) {
                $updateFields[] = "geometry = ST_SetSRID(ST_GeomFromGeoJSON(?), 4326)";
                $updateParams[] = json_encode($data['geometry']);
            }
            
            if (empty($updateFields)) {
                throw new Exception('Нет данных для обновления');
            }
            
            $updateFields[] = "updated_at = NOW()";
            
            $sql = "UPDATE repair_zones SET " . implode(', ', $updateFields) . " WHERE id = ?";
            $updateParams[] = $zoneId;
            
            $updateStmt = $pdo->prepare($sql);
            $updateStmt->execute($updateParams);
            
            echo json_encode([
                'status' => 'ok', 
                'message' => 'Зона успешно обновлена',
                'zone_id' => $zoneId
            ]);
            
        } catch (Exception $e) {
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'DELETE' && isset($_GET['id'])) {
        try {
            $zoneId = (int)$_GET['id'];
            if ($userId === 0) {
                http_response_code(401);
                echo json_encode(['error' => 'Требуется авторизация']);
                exit;
            }
            $checkStmt = $pdo->prepare("
                SELECT creator_id FROM repair_zones WHERE id = ? AND is_active = true
            ");
            $checkStmt->execute([$zoneId]);
            $zone = $checkStmt->fetch();
            
            if (!$zone || $zone['creator_id'] != $userId) {
                throw new Exception('Вы не являетесь создателем этой зоны или зона неактивна');
            }
            
            $deleteStmt = $pdo->prepare("
                UPDATE repair_zones 
                SET is_active = false, updated_at = NOW() 
                WHERE id = ?
            ");
            $deleteStmt->execute([$zoneId]);
            
            echo json_encode([
                'status' => 'ok', 
                'message' => 'Зона успешно удалена'
            ]);
            
        } catch (Exception $e) {
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }
    
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['id'])) {
        try {
            $zoneId = (int)$_GET['id'];
            $stmt = $pdo->prepare("
                SELECT
                  rz.id,
                  rz.description,
                  ST_AsGeoJSON(rz.geometry) AS geometry,
                  rz.confirm_count,
                  rz.created_at,
                  rz.last_confirmed_at,
                  rz.creator_id,
                  rz.status,
                  rz.is_active,
                  CASE 
                    WHEN rz.confirm_count = 0 AND rz.created_at >= NOW() - INTERVAL '24 hours' 
                         THEN '#9E9E9E'
                    WHEN rz.confirm_count > 0 AND rz.created_at >= NOW() - INTERVAL '7 days' 
                         THEN '#E53935'
                    WHEN rz.confirm_count > 0 AND rz.created_at >= NOW() - INTERVAL '14 days' 
                         THEN '#FBC02D'
                    WHEN rz.confirm_count > 0 AND rz.created_at < NOW() - INTERVAL '14 days'
                         AND rz.last_confirmed_at >= NOW() - INTERVAL '24 hours'
                         THEN '#43A047'
                    ELSE NULL
                  END AS color
                FROM repair_zones rz
                WHERE rz.id = :zone_id
            ");
            
            $stmt->execute([':zone_id' => $zoneId]);
            $zone = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$zone) {
                http_response_code(404);
                echo json_encode(['error' => 'Зона не найдена']);
                exit;
            }
            
            $zone['is_creator'] = ($zone['creator_id'] == $userId);
            
            echo json_encode($zone);
            
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to load zone: ' . $e->getMessage()]);
        }
        exit;
    }
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}
?>