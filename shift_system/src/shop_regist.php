<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

$coursesFile = 'courses.json';
$shopsFile = 'shops.json';
$uploadDir = 'uploads/';

$courses = array();
$shops = array();
$message = '';
$currentDay = isset($_GET['day']) ? $_GET['day'] : 'monday';
$editCourseId = isset($_GET['edit']) ? $_GET['edit'] : '';

$dayMap = array(
    'monday' => '月曜日', 'tuesday' => '火曜日', 'wednesday' => '水曜日',
    'thursday' => '木曜日', 'friday' => '金曜日', 'saturday' => '土曜日', 'sunday' => '日曜日',
);

// コースデータ読み込み
if (file_exists($coursesFile)) {
    $courses = json_decode(@file_get_contents($coursesFile), true);
}
if (!is_array($courses)) $courses = array();
foreach ($dayMap as $eng => $jp) {
    if (!isset($courses[$eng])) $courses[$eng] = array();
}

// 店舗データ読み込み
if (file_exists($shopsFile)) {
    $shops = json_decode(@file_get_contents($shopsFile), true);
}
if (!is_array($shops)) $shops = array();
foreach ($dayMap as $eng => $jp) {
    if (!isset($shops[$eng])) $shops[$eng] = array();
}

// コース削除処理
if (isset($_GET['action']) && $_GET['action'] === 'delete_course' && isset($_GET['course_id'])) {
    $courseId = $_GET['course_id'];
    $shops[$currentDay] = array_filter($shops[$currentDay], function($shop) use ($courseId) {
        return $shop['course_id'] !== $courseId;
    });
    $shops[$currentDay] = array_values($shops[$currentDay]);
    file_put_contents($shopsFile, json_encode($shops, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    header("Location: shop_regist.php?day=$currentDay&message=" . urlencode("コースと関連店舗を削除しました"));
    exit;
}

// 店舗追加・更新処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_shop'])) {
    $p_course_id = $_POST['course_id'];
    $p_shop_id = !empty($_POST['shop_id']) ? $_POST['shop_id'] : uniqid('shop_');
    $p_shop_name = trim($_POST['shop_name']);
    $p_address = trim($_POST['address']);
    $p_parking_coords = trim($_POST['parking_coords']);
    $p_contact = trim($_POST['contact']);
    $p_details = trim($_POST['details']);
    
    if ($p_shop_name !== '' && $p_parking_coords !== '') {
        $images = array();
        
        if (!empty($_POST['existing_images'])) {
            $images = json_decode($_POST['existing_images'], true);
        }
        
        for ($i = 1; $i <= 3; $i++) {
            if (isset($_FILES["image$i"]) && $_FILES["image$i"]['error'] === UPLOAD_ERR_OK) {
                $ext = pathinfo($_FILES["image$i"]['name'], PATHINFO_EXTENSION);
                $filename = $p_shop_id . '_' . time() . '_' . $i . '.' . $ext;
                $filepath = $uploadDir . $filename;
                if (move_uploaded_file($_FILES["image$i"]['tmp_name'], $filepath)) {
                    $images[] = $filepath;
                }
            }
        }
        
        $images = array_slice($images, 0, 3);
        
        $newShop = array(
            'id' => $p_shop_id,
            'course_id' => $p_course_id,
            'shop_name' => $p_shop_name,
            'address' => $p_address,
            'parking_coords' => $p_parking_coords,
            'contact' => $p_contact,
            'details' => $p_details,
            'images' => $images
        );
        
        $found = false;
        foreach ($shops[$currentDay] as $idx => $shop) {
            if ($shop['id'] === $p_shop_id) {
                $shops[$currentDay][$idx] = $newShop;
                $found = true;
                break;
            }
        }
        if (!$found) {
            $shops[$currentDay][] = $newShop;
        }
        
        file_put_contents($shopsFile, json_encode($shops, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        header("Location: shop_regist.php?day=$currentDay&edit=$p_course_id&message=" . urlencode("店舗を保存しました"));
        exit;
    }
}

// 既存店舗をコースに追加（ドラッグ&ドロップ）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_existing_shop'])) {
    $sourceDay = $_POST['source_day'];
    $sourceShopId = $_POST['source_shop_id'];
    $targetCourseId = $_POST['target_course_id'];
    
    // 元の店舗データを取得
    $sourceShop = null;
    foreach ($shops[$sourceDay] as $shop) {
        if ($shop['id'] === $sourceShopId) {
            $sourceShop = $shop;
            break;
        }
    }
    
    if ($sourceShop) {
        // 新しいIDで店舗を複製
        $newShop = $sourceShop;
        $newShop['id'] = uniqid('shop_');
        $newShop['course_id'] = $targetCourseId;
        
        $shops[$currentDay][] = $newShop;
        file_put_contents($shopsFile, json_encode($shops, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        
        echo json_encode(['success' => true, 'message' => '店舗を追加しました']);
        exit;
    }
    
    echo json_encode(['success' => false, 'message' => '店舗が見つかりません']);
    exit;
}

// 店舗削除処理
if (isset($_GET['action']) && $_GET['action'] === 'delete_shop' && isset($_GET['shop_id'])) {
    $shopId = $_GET['shop_id'];
    $shops[$currentDay] = array_filter($shops[$currentDay], function($shop) use ($shopId) {
        return $shop['id'] !== $shopId;
    });
    $shops[$currentDay] = array_values($shops[$currentDay]);
    file_put_contents($shopsFile, json_encode($shops, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    header("Location: shop_regist.php?day=$currentDay&edit=$editCourseId&message=" . urlencode("店舗を削除しました"));
    exit;
}

// 画像削除処理
if (isset($_GET['action']) && $_GET['action'] === 'delete_image' && isset($_GET['shop_id']) && isset($_GET['image_index'])) {
    $shopId = $_GET['shop_id'];
    $imageIndex = intval($_GET['image_index']);
    
    foreach ($shops[$currentDay] as $idx => $shop) {
        if ($shop['id'] === $shopId) {
            if (isset($shop['images'][$imageIndex])) {
                if (file_exists($shop['images'][$imageIndex])) {
                    unlink($shop['images'][$imageIndex]);
                }
                array_splice($shops[$currentDay][$idx]['images'], $imageIndex, 1);
                file_put_contents($shopsFile, json_encode($shops, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            }
            break;
        }
    }
    header("Location: shop_regist.php?day=$currentDay&edit=$editCourseId&message=" . urlencode("画像を削除しました"));
    exit;
}

// 順序保存処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_order'])) {
    $orderData = json_decode($_POST['order_data'], true);
    $p_course_id = $_POST['course_id'];
    
    if (is_array($orderData)) {
        $reordered = array();
        foreach ($orderData as $shopId) {
            foreach ($shops[$currentDay] as $shop) {
                if ($shop['id'] === $shopId && $shop['course_id'] === $p_course_id) {
                    $reordered[] = $shop;
                    break;
                }
            }
        }
        
        $otherShops = array_filter($shops[$currentDay], function($shop) use ($p_course_id) {
            return $shop['course_id'] !== $p_course_id;
        });
        
        $shops[$currentDay] = array_merge($reordered, array_values($otherShops));
        file_put_contents($shopsFile, json_encode($shops, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        echo json_encode(['success' => true]);
        exit;
    }
}

$message = isset($_GET['message']) ? htmlspecialchars($_GET['message']) : '';

// 編集中のコース情報取得
$editingCourse = null;
if ($editCourseId !== '') {
    foreach ($courses[$currentDay] as $course) {
        if ($course['id'] === $editCourseId) {
            $editingCourse = $course;
            break;
        }
    }
}

// 編集中のコースに紐づく店舗取得
$editingShops = array();
if ($editingCourse) {
    foreach ($shops[$currentDay] as $shop) {
        if ($shop['course_id'] === $editCourseId) {
            $editingShops[] = $shop;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>店舗カルテ登録・管理</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'メイリオ', sans-serif; background-color: #f0f4f8; margin: 0; padding: 20px; }
        .container { max-width: 1200px; margin: 20px auto; background-color: #ffffff; padding: 30px; border-radius: 10px; box-shadow: 0 4px 8px rgba(0,0,0,0.1); }
        h2 { text-align: center; color: #0056b3; margin-bottom: 20px; }
        .message { padding: 10px; background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; border-radius: 4px; margin-bottom: 15px; text-align: center; }
        .tab-nav { display: flex; justify-content: center; border-bottom: 2px solid #ccc; margin-bottom: 20px; overflow-x: auto; flex-wrap: wrap; }
        .tab-nav a { text-decoration: none; color: #333; padding: 10px 15px; border: 1px solid transparent; border-bottom: none; margin-bottom: -2px; white-space: nowrap; }
        .tab-nav a.active { color: #007bff; border: 1px solid #ccc; border-bottom: 2px solid #fff; background-color: #fff; font-weight: bold; }
        
        .course-list { margin-bottom: 30px; }
        .course-item { border: 1px solid #ddd; padding: 15px; margin-bottom: 10px; border-radius: 8px; background-color: #f9f9f9; display: flex; justify-content: space-between; align-items: center; }
        .course-name { font-weight: bold; font-size: 1.1em; color: #333; }
        .course-actions { display: flex; gap: 10px; }
        .btn { padding: 8px 15px; text-decoration: none; border-radius: 5px; font-size: 0.9em; cursor: pointer; border: none; display: inline-block; }
        .btn-edit { background-color: #ffc107; color: #333; }
        .btn-delete { background-color: #dc3545; color: white; }
        .btn-back { background-color: #6c757d; color: white; }
        .btn-save { background-color: #28a745; color: white; }
        .btn-add { background-color: #007bff; color: white; margin-bottom: 15px; margin-right: 10px; }
        .btn-add-existing { background-color: #17a2b8; color: white; margin-bottom: 15px; }
        
        .edit-section { border: 2px solid #007bff; padding: 20px; border-radius: 10px; background-color: #f0f8ff; }
        .section-title { font-size: 1.3em; font-weight: bold; color: #0056b3; margin-bottom: 20px; border-bottom: 2px solid #007bff; padding-bottom: 10px; }
        
        .shop-form { border: 1px solid #ccc; padding: 20px; margin-bottom: 20px; border-radius: 8px; background-color: #fff; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; font-weight: bold; margin-bottom: 5px; color: #333; }
        .form-group input[type="text"], .form-group textarea { width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; }
        .form-group textarea { min-height: 80px; resize: vertical; }
        .required { color: red; }
        
        .image-upload-section { margin-top: 15px; }
        .image-preview { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 10px; }
        .image-preview-item { position: relative; width: 150px; }
        .image-preview-item img { width: 100%; height: 100px; object-fit: cover; border-radius: 5px; border: 1px solid #ddd; }
        .image-delete-btn { position: absolute; top: -5px; right: -5px; background-color: #dc3545; color: white; border: none; border-radius: 50%; width: 25px; height: 25px; cursor: pointer; font-size: 0.8em; }
        
        .shop-list { margin-top: 20px; }
        .shop-item { border: 1px solid #ddd; padding: 15px; margin-bottom: 10px; border-radius: 8px; background-color: #fff; cursor: move; }
        .shop-item.dragging { opacity: 0.5; }
        .shop-item.drag-over { border: 2px dashed #007bff; background-color: #e7f3ff; }
        .shop-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
        .shop-title { font-weight: bold; font-size: 1.1em; color: #0056b3; }
        .shop-details { font-size: 0.9em; color: #666; }
        .shop-details div { margin-bottom: 5px; }
        .shop-images { display: flex; gap: 10px; margin-top: 10px; flex-wrap: wrap; }
        .shop-images img { width: 100px; height: 100px; object-fit: cover; border-radius: 5px; border: 1px solid #ddd; }
        
        .save-order-section { text-align: center; margin-top: 20px; padding: 20px; background-color: #f9f9f9; border-radius: 8px; }
        
        .navigation-links { text-align: center; margin-top: 20px; padding-top: 20px; border-top: 1px solid #ddd; }
        .navigation-links a { margin: 0 10px; text-decoration: none; color: #007bff; }
        
        .drop-zone { min-height: 100px; border: 2px dashed #ccc; border-radius: 8px; padding: 20px; text-align: center; color: #999; margin-bottom: 20px; background-color: #fafafa; }
        .drop-zone.drag-over { border-color: #007bff; background-color: #e7f3ff; color: #007bff; }
        
        @media (max-width: 768px) {
            .course-item { flex-direction: column; align-items: flex-start; }
            .course-actions { margin-top: 10px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <h2>🏪 店舗カルテ登録・管理</h2>
        
        <?php if ($message): ?>
            <div class="message"><?php echo $message; ?></div>
        <?php endif; ?>
        
        <div class="tab-nav">
            <?php foreach ($dayMap as $eng => $jp): ?>
                <a href="?day=<?php echo $eng; ?>" class="<?php echo ($eng === $currentDay) ? 'active' : ''; ?>"><?php echo $jp; ?></a>
            <?php endforeach; ?>
        </div>
        
        <?php if (!$editingCourse): ?>
            <!-- コース一覧表示 -->
            <div class="course-list">
                <h3>コース一覧(<?php echo $dayMap[$currentDay]; ?>)</h3>
                <?php if (empty($courses[$currentDay])): ?>
                    <p style="color: #999; text-align: center; padding: 20px;">この曜日にはコースが登録されていません。</p>
                <?php else: ?>
                    <?php foreach ($courses[$currentDay] as $course): ?>
                        <div class="course-item">
                            <div class="course-name">🗺️ <?php echo htmlspecialchars($course['name']); ?></div>
                            <div class="course-actions">
                                <a href="?day=<?php echo $currentDay; ?>&edit=<?php echo $course['id']; ?>" class="btn btn-edit">編集</a>
                                <a href="?day=<?php echo $currentDay; ?>&action=delete_course&course_id=<?php echo $course['id']; ?>" class="btn btn-delete" onclick="return confirm('このコースと関連する店舗を削除しますか?')">削除</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <!-- 店舗編集セクション -->
            <div class="edit-section">
                <div class="section-title">
                    🗺️ <?php echo htmlspecialchars($editingCourse['name']); ?> の店舗管理
                    <a href="?day=<?php echo $currentDay; ?>" class="btn btn-back" style="float: right; font-size: 0.8em;">← 一覧に戻る</a>
                </div>
                
                <!-- 店舗追加フォーム -->
                <button class="btn btn-add" onclick="toggleShopForm()">➕ 新しい店舗を追加</button>
                <button class="btn btn-add-existing" onclick="openShopSelector()">📋 既存店舗から追加</button>
                
                <div id="shopFormContainer" style="display: none;">
                    <form method="POST" enctype="multipart/form-data" class="shop-form">
                        <input type="hidden" name="save_shop" value="1">
                        <input type="hidden" name="course_id" value="<?php echo htmlspecialchars($editCourseId); ?>">
                        <input type="hidden" name="shop_id" id="edit_shop_id" value="">
                        <input type="hidden" name="existing_images" id="existing_images" value="">
                        
                        <div class="form-group">
                            <label>店舗名 <span class="required">*</span></label>
                            <input type="text" name="shop_name" id="shop_name" required>
                        </div>
                        
                        <div class="form-group">
                            <label>住所</label>
                            <input type="text" name="address" id="address">
                        </div>
                        
                        <div class="form-group">
                            <label>駐車位置マップ座標 <span class="required">*</span> (例: 35.6812,139.7671 または Google Maps URL)</label>
                            <div style="display: flex; gap: 10px;">
                                <input type="text" name="parking_coords" id="parking_coords" placeholder="緯度,経度 または Google Maps URL" required style="flex-grow: 1;">
                                <button type="button" class="btn" id="trimUrlBtn" style="flex-shrink: 0; padding: 5px 10px; font-size: 0.9em; background-color: #007bff; color: white;">URL整形</button>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label>連絡先</label>
                            <input type="text" name="contact" id="contact">
                        </div>
                        
                        <div class="form-group">
                            <label>納品方法などの詳細</label>
                            <textarea name="details" id="details"></textarea>
                        </div>
                        
                        <div class="image-upload-section">
                            <label><strong>画像登録(最大3枚)</strong></label>
                            <div id="existingImagesPreview" class="image-preview"></div>
                            <div class="form-group">
                                <label>画像1</label>
                                <input type="file" name="image1" accept="image/*">
                            </div>
                            <div class="form-group">
                                <label>画像2</label>
                                <input type="file" name="image2" accept="image/*">
                            </div>
                            <div class="form-group">
                                <label>画像3</label>
                                <input type="file" name="image3" accept="image/*">
                            </div>
                        </div>
                        
                        <div style="text-align: center; margin-top: 20px;">
                            <button type="submit" class="btn btn-save">💾 店舗を保存</button>
                            <button type="button" class="btn btn-back" onclick="cancelShopForm()">キャンセル</button>
                        </div>
                    </form>
                </div>
                
                <!-- ドラッグ&ドロップエリア -->
                <div id="dropZone" class="drop-zone" style="display: none;">
                    <p>ここに店舗をドラッグ&ドロップしてください</p>
                </div>
                
                <!-- 店舗一覧(ドラッグ&ドロップ対応) -->
                <div class="shop-list">
                    <h4>登録済み店舗(ドラッグで順序変更可能)</h4>
                    <?php if (empty($editingShops)): ?>
                        <p style="color: #999; text-align: center; padding: 20px;">まだ店舗が登録されていません。</p>
                    <?php else: ?>
                        <div id="shopListContainer">
                            <?php foreach ($editingShops as $shop): ?>
                                <div class="shop-item" draggable="true" data-shop-id="<?php echo $shop['id']; ?>">
                                    <div class="shop-header">
                                        <div class="shop-title">🏪 <?php echo htmlspecialchars($shop['shop_name']); ?></div>
                                        <div>
                                            <button class="btn btn-edit" style="padding: 5px 10px; font-size: 0.85em;" onclick="editShop('<?php echo $shop['id']; ?>')">編集</button>
                                            <a href="?day=<?php echo $currentDay; ?>&edit=<?php echo $editCourseId; ?>&action=delete_shop&shop_id=<?php echo $shop['id']; ?>" class="btn btn-delete" style="padding: 5px 10px; font-size: 0.85em;" onclick="return confirm('この店舗を削除しますか?')">削除</a>
                                        </div>
                                    </div>
                                    <div class="shop-details">
                                        <?php if ($shop['address']): ?>
                                            <div>📍 住所: <?php echo htmlspecialchars($shop['address']); ?></div>
                                        <?php endif; ?>
                                        <div>🅿️ 座標: <?php echo htmlspecialchars($shop['parking_coords']); ?></div>
                                        <?php if ($shop['contact']): ?>
                                            <div>📞 連絡先: <?php echo htmlspecialchars($shop['contact']); ?></div>
                                        <?php endif; ?>
                                        <?php if ($shop['details']): ?>
                                            <div>📝 詳細: <?php echo nl2br(htmlspecialchars($shop['details'])); ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (!empty($shop['images'])): ?>
                                        <div class="shop-images">
                                            <?php foreach ($shop['images'] as $img): ?>
                                                <img src="<?php echo htmlspecialchars($img); ?>" alt="店舗画像">
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <div class="save-order-section">
                            <button class="btn btn-save" style="font-size: 1.1em; padding: 12px 30px;" onclick="saveOrder()">💾 順序を保存</button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
        
        <div class="navigation-links">
            <a href="course_regist.php">🗺️ コースマスター管理</a>
            <a href="shoplist.php">📱 店舗一覧(閲覧用)</a>
        </div>
    </div>
    
    <script>
        const shopsData = <?php echo json_encode($editingShops); ?>;
        const currentDay = '<?php echo $currentDay; ?>';
        const editCourseId = '<?php echo $editCourseId; ?>';
        
        function toggleShopForm() {
            const container = document.getElementById('shopFormContainer');
            container.style.display = container.style.display === 'none' ? 'block' : 'none';
            if (container.style.display === 'block') {
                document.getElementById('shopFormContainer').scrollIntoView({ behavior: 'smooth' });
            }
        }
        
        function cancelShopForm() {
            document.getElementById('shopFormContainer').style.display = 'none';
            resetForm();
        }
        
        function resetForm() {
            document.getElementById('edit_shop_id').value = '';
            document.getElementById('shop_name').value = '';
            document.getElementById('address').value = '';
            document.getElementById('parking_coords').value = '';
            document.getElementById('contact').value = '';
            document.getElementById('details').value = '';
            document.getElementById('existing_images').value = '';
            document.getElementById('existingImagesPreview').innerHTML = '';
        }
        
        function editShop(shopId) {
            const shop = shopsData.find(s => s.id === shopId);
            if (!shop) return;
            
            document.getElementById('edit_shop_id').value = shop.id;
            document.getElementById('shop_name').value = shop.shop_name;
            document.getElementById('address').value = shop.address || '';
            document.getElementById('parking_coords').value = shop.parking_coords;
            document.getElementById('contact').value = shop.contact || '';
            document.getElementById('details').value = shop.details || '';
            
            if (shop.images && shop.images.length > 0) {
                document.getElementById('existing_images').value = JSON.stringify(shop.images);
                let previewHtml = '';
                shop.images.forEach((img, index) => {
                    previewHtml += `
                        <div class="image-preview-item">
                            <img src="${img}" alt="画像${index + 1}">
                            <button type="button" class="image-delete-btn" onclick="deleteExistingImage('${shop.id}', ${index})">×</button>
                        </div>
                    `;
                });
                document.getElementById('existingImagesPreview').innerHTML = previewHtml;
            }
            
            toggleShopForm();
        }
        
        function deleteExistingImage(shopId, imageIndex) {
            if (confirm('この画像を削除しますか?')) {
                window.location.href = `?day=${currentDay}&edit=${editCourseId}&action=delete_image&shop_id=${shopId}&image_index=${imageIndex}`;
            }
        }
        
        // 店舗セレクターウィンドウを開く
        function openShopSelector() {
            const width = 800;
            const height = 600;
            const left = (screen.width - width) / 2;
            const top = (screen.height - height) / 2;
            
            window.open(
                'shop_selector.php', 
                'shopSelector',
                `width=${width},height=${height},left=${left},top=${top},scrollbars=yes,resizable=yes`
            );
            
            // ドロップゾーンを表示
            document.getElementById('dropZone').style.display = 'block';
        }
        
        // ドロップゾーンの設定
        const dropZone = document.getElementById('dropZone');
        
        if (dropZone) {
            dropZone.addEventListener('dragover', function(e) {
                e.preventDefault();
                e.dataTransfer.dropEffect = 'copy';
                this.classList.add('drag-over');
            });
            
            dropZone.addEventListener('dragleave', function(e) {
                this.classList.remove('drag-over');
            });
            
            dropZone.addEventListener('drop', function(e) {
                e.preventDefault();
                this.classList.remove('drag-over');
                
                try {
                    const shopData = JSON.parse(e.dataTransfer.getData('application/json'));
                    addExistingShop(shopData.sourceDay, shopData.shopId);
                } catch (error) {
                    console.error('Error parsing shop data:', error);
                }
            });
        }
        
        // 既存店舗を追加
        function addExistingShop(sourceDay, sourceShopId) {
            const formData = new FormData();
            formData.append('add_existing_shop', '1');
            formData.append('source_day', sourceDay);
            formData.append('source_shop_id', sourceShopId);
            formData.append('target_course_id', editCourseId);
            
            fetch(`shop_regist.php?day=${currentDay}`, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    location.reload();
                } else {
                    alert('エラー: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('店舗の追加に失敗しました');
            });
        }
        
        // URL整形ボタンのロジック
        document.getElementById('trimUrlBtn').addEventListener('click', function() {
            const input = document.getElementById('parking_coords');
            let url = input.value.trim();
            
            if (!url) {
                alert('座標またはURLを入力してください。');
                return;
            }
            
            let data_pos = url.indexOf('/data=');
            if (data_pos !== -1) {
                url = url.substring(0, data_pos);
            }
            
            let at_pos = url.indexOf('@');
            if (at_pos !== -1) {
                url = url.substring(0, at_pos);
            }
            
            let e_slash_pos = url.lastIndexOf('E/');
            if (e_slash_pos !== -1) {
                url = url.substring(0, e_slash_pos + 2);
            }
            
            input.value = url;
            alert('URLを整形しました。保存前に内容を確認してください。');
        });
        
        // ドラッグ&ドロップ機能（順序変更用）
        let draggedElement = null;
        
        const shopItems = document.querySelectorAll('.shop-item');
        shopItems.forEach(item => {
            item.addEventListener('dragstart', function(e) {
                draggedElement = this;
                this.classList.add('dragging');
                e.dataTransfer.effectAllowed = 'move';
            });
            
            item.addEventListener('dragend', function(e) {
                this.classList.remove('dragging');
            });
            
            item.addEventListener('dragover', function(e) {
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
                
                const afterElement = getDragAfterElement(this.parentElement, e.clientY);
                if (afterElement == null) {
                    this.parentElement.appendChild(draggedElement);
                } else {
                    this.parentElement.insertBefore(draggedElement, afterElement);
                }
            });
        });
        
        function getDragAfterElement(container, y) {
            const draggableElements = [...container.querySelectorAll('.shop-item:not(.dragging)')];
            
            return draggableElements.reduce((closest, child) => {
                const box = child.getBoundingClientRect();
                const offset = y - box.top - box.height / 2;
                
                if (offset < 0 && offset > closest.offset) {
                    return { offset: offset, element: child };
                } else {
                    return closest;
                }
            }, { offset: Number.NEGATIVE_INFINITY }).element;
        }
        
        // 順序保存
        function saveOrder() {
            const shopItems = document.querySelectorAll('.shop-item');
            const orderData = [];
            
            shopItems.forEach(item => {
                orderData.push(item.getAttribute('data-shop-id'));
            });
            
            const formData = new FormData();
            formData.append('save_order', '1');
            formData.append('course_id', editCourseId);
            formData.append('order_data', JSON.stringify(orderData));
            
            fetch(`shop_regist.php?day=${currentDay}`, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('保存完了しました');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('保存に失敗しました');
            });
        }
    </script>
</body>
</html>