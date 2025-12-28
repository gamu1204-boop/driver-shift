<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

$coursesFile = 'courses.json';
$shopsFile = 'shops.json';
$timeLogsFile = 'time_logs.json';
$usersFile = 'users.json';
$driversFile = 'drivers.json';
$scheduleFile = 'schedule.json';

session_start();

// ユーザーデータ読み込み
$users = [];
if (file_exists($usersFile)) {
    $userData = json_decode(@file_get_contents($usersFile), true);
    $users = $userData['users'] ?? [];
}

// ログイン処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    $input_id = $_POST['user_id'] ?? '';
    $input_pass = $_POST['password'] ?? '';
    
    $authenticated = false;
    foreach ($users as $user) {
        if ($user['id'] === $input_id && $user['password'] === $input_pass) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            $authenticated = true;
            break;
        }
    }
    
    if ($authenticated) {
        header("Location: shoplist.php");
        exit;
    } else {
        $loginError = "IDまたはパスワードが間違っています。";
    }
}

// ログアウト処理
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_unset();
    session_destroy();
    header("Location: shoplist.php");
    exit;
}

// ログインチェック
$loggedIn = isset($_SESSION['user_id']);
$currentUserId = $_SESSION['user_id'] ?? null;
$currentUserName = $_SESSION['user_name'] ?? null;

if (!$loggedIn) {
    include 'shoplist_login.php';
    exit;
}

// ドライバーデータを読み込み
$drivers = [];
if (file_exists($driversFile)) {
    $drivers = json_decode(@file_get_contents($driversFile), true);
}

// スケジュールデータを読み込み
$scheduleData = [];
if (file_exists($scheduleFile)) {
    $scheduleData = json_decode(@file_get_contents($scheduleFile), true);
}

// ログイン中のユーザーIDに対応するドライバーを検索
$currentDriver = null;
$currentDriverId = null;
foreach ($drivers as $driverId => $driver) {
    if (isset($driver['personal_id']) && $driver['personal_id'] === $currentUserId) {
        $currentDriver = $driver;
        $currentDriverId = $driverId;
        break;
    }
}

if (!$currentDriver) {
    echo "<div style='padding: 20px; text-align: center;'>";
    echo "<h2>エラー</h2>";
    echo "<p>ログインIDに対応するドライバー情報が見つかりません。</p>";
    echo "<p>個人番号: {$currentUserId}</p>";
    echo "<p><a href='?action=logout'>ログアウト</a></p>";
    echo "</div>";
    exit;
}

$courses = array();
$shops = array();

// 今日の日付から曜日を取得
$today = date('Y-m-d');
$dayOfWeek = strtolower(date('l')); // 'monday', 'tuesday', etc.

// 手動で曜日を変更する場合（デバッグ用）
$currentDay = isset($_GET['day']) ? $_GET['day'] : $dayOfWeek;

$dayMap = array(
    'monday' => '月曜日', 'tuesday' => '火曜日', 'wednesday' => '水曜日',
    'thursday' => '木曜日', 'friday' => '金曜日', 'saturday' => '土曜日', 'sunday' => '日曜日',
);

// コースデータ読み込み
if (file_exists($coursesFile)) {
    $courses = json_decode(@file_get_contents($coursesFile), true);
}
if (!is_array($courses)) $courses = array();

// 店舗データ読み込み
if (file_exists($shopsFile)) {
    $shops = json_decode(@file_get_contents($shopsFile), true);
}
if (!is_array($shops)) $shops = array();

// 時間計測ログの保存処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'record_time') {
    header('Content-Type: application/json');
    
    $shopId = $_POST['shop_id'] ?? '';
    $type = $_POST['type'] ?? '';
    $timestamp = $_POST['timestamp'] ?? '';
    $driverId = $_POST['driver_id'] ?? 'Unknown ID';
    $driverName = $_POST['driver_name'] ?? 'Unknown Driver';
    $day = $_POST['day'] ?? 'unknown';
    
    if (empty($shopId) || empty($type) || empty($timestamp)) {
        echo json_encode(['success' => false, 'message' => 'Missing required parameters.']);
        exit;
    }
    
    $logs = array();
    if (file_exists($timeLogsFile)) {
        $logs = json_decode(@file_get_contents($timeLogsFile), true);
    }
    if (!is_array($logs)) $logs = array('logs' => []);
    
    $newLog = [
        'id' => uniqid('log_'),
        'shop_id' => $shopId,
        'type' => $type,
        'timestamp' => $timestamp,
        'driver_id' => $driverId,
        'driver_name' => $driverName,
        'day_of_week' => $day,
        'date' => date('Y-m-d', strtotime($timestamp))
    ];
    
    $logs['logs'][] = $newLog;
    
    if (file_put_contents($timeLogsFile, json_encode($logs, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT))) {
        echo json_encode(['success' => true, 'message' => 'Time log saved.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to write to log file.']);
    }
    exit;
}

// このドライバーの今日のコース名を取得
$todayCourseName = null;

// まずスケジュールから確認
if (isset($scheduleData[$currentDriverId][$today]['course'])) {
    $todayCourseName = $scheduleData[$currentDriverId][$today]['course'];
}

// スケジュールになければデフォルトコースを使用
if (!$todayCourseName && isset($currentDriver['courses'][$currentDay]['course'])) {
    $todayCourseName = $currentDriver['courses'][$currentDay]['course'];
}

// コース名を正規化
function normalizeCourseName($name) {
    $name = trim($name);
    if (in_array($name, ['-', '', '公休', '有給', '同乗', 'その他'])) {
        return $name;
    }
    // スペースを除去してから英数字と日本語の間に半角スペースを挿入
    $temp = str_replace(' ', '', $name);
    return preg_replace('/^([A-Z0-9]+)([^\s0-9].*)$/u', '$1 $2', $temp);
}

$todayCourseName = normalizeCourseName($todayCourseName);

// このドライバーのコースに該当する店舗のみを抽出
$driverShops = [];

if ($todayCourseName && !in_array($todayCourseName, ['公休', '有給', '-', ''])) {
    // コースIDを検索
    $targetCourseId = null;
    if (isset($courses[$currentDay]) && is_array($courses[$currentDay])) {
        foreach ($courses[$currentDay] as $course) {
            $courseName = normalizeCourseName($course['name'] ?? '');
            if ($courseName === $todayCourseName) {
                $targetCourseId = $course['id'];
                break;
            }
        }
    }
    
    // コースに紐づく店舗を取得
    if ($targetCourseId && isset($shops[$currentDay]) && is_array($shops[$currentDay])) {
        foreach ($shops[$currentDay] as $shop) {
            if ($shop['course_id'] === $targetCourseId) {
                $driverShops[] = $shop;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>店舗一覧(閲覧用)</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { 
            font-family: 'メイリオ', 'Hiragino Kaku Gothic Pro', sans-serif; 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 10px;
        }
        
        .container { 
            max-width: 600px; 
            margin: 0 auto; 
            background-color: #ffffff; 
            border-radius: 15px; 
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
            overflow: hidden;
        }
        
        .header { 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white; 
            padding: 20px; 
            text-align: center; 
        }
        
        .header h1 { 
            font-size: 1.5em; 
            margin-bottom: 5px;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.2);
        }
        
        .header .subtitle { 
            font-size: 0.9em; 
            opacity: 0.9; 
        }
        
        .driver-info {
            background-color: #f8f9fa;
            padding: 15px;
            border-bottom: 2px solid #dee2e6;
        }
        
        .driver-info-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
        }
        
        .driver-info-label {
            font-weight: bold;
            color: #495057;
        }
        
        .driver-info-value {
            color: #212529;
        }
        
        .course-badge {
            display: inline-block;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 5px 15px;
            border-radius: 20px;
            font-weight: bold;
            font-size: 1.1em;
        }
        
        .content { 
            padding: 15px; 
        }
        
        .shop-item { 
            padding: 15px; 
            border-bottom: 1px solid #e9ecef;
            background-color: #fff;
            transition: background-color 0.2s;
            margin-bottom: 10px;
            border-radius: 8px;
            border: 1px solid #e9ecef;
        }
        
        .shop-item:active { 
            background-color: #f8f9fa; 
        }
        
        .shop-number { 
            display: inline-block;
            background-color: #667eea;
            color: white;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            text-align: center;
            line-height: 24px;
            font-size: 0.85em;
            font-weight: bold;
            margin-right: 8px;
        }
        
        .shop-name-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }
        
        .shop-name { 
            font-weight: bold; 
            font-size: 1.05em; 
            color: #212529;
            display: flex;
            align-items: center;
        }
        
        .detail-toggle-btn {
            background-color: #6c757d;
            color: white;
            border: none;
            padding: 5px 10px;
            border-radius: 5px;
            font-size: 0.8em;
            cursor: pointer;
            transition: background-color 0.2s;
            flex-shrink: 0;
        }
        
        .detail-toggle-btn:active {
            background-color: #5a6268;
        }
        
        .detail-content {
            display: none;
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px solid #e9ecef;
        }
        
        .shop-details { 
            font-size: 0.9em; 
            color: #6c757d;
            line-height: 1.6;
        }
        
        .shop-details-item { 
            margin-bottom: 6px;
            display: flex;
            align-items: flex-start;
            gap: 8px;
        }
        
        .shop-details-item .icon { 
            flex-shrink: 0;
            width: 20px;
        }
        
        .shop-details-item .text { 
            flex: 1;
        }
        
        .shop-images { 
            display: flex; 
            gap: 8px; 
            margin-top: 10px;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        
        .shop-images img { 
            width: 100px; 
            height: 100px; 
            object-fit: cover; 
            border-radius: 8px; 
            border: 2px solid #e9ecef;
            flex-shrink: 0;
            cursor: pointer;
            transition: transform 0.2s;
        }
        
        .shop-images img:active { 
            transform: scale(0.95); 
        }
        
        .time-tracking-section {
            display: flex;
            gap: 10px;
            margin-top: 10px;
            align-items: center;
        }
        
        .time-btn {
            padding: 10px 15px;
            border: none;
            border-radius: 6px;
            color: white;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.3s;
            flex-grow: 1;
            font-size: 0.95em;
        }
        
        .start-btn {
            background-color: #007bff;
        }
        
        .start-btn:active:not(:disabled) {
            background-color: #0056b3;
            transform: scale(0.98);
        }
        
        .start-btn.working {
            background-color: #6c757d;
            animation: pulse 2s infinite;
        }
        
        .end-btn {
            background-color: #dc3545;
        }
        
        .end-btn:active:not(:disabled) {
            background-color: #a71d2a;
            transform: scale(0.98);
        }
        
        .end-btn.completed {
            background-color: #28a745;
        }
        
        .time-btn:disabled {
            background-color: #ccc;
            cursor: not-allowed;
            opacity: 0.6;
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }
        
        .time-status {
            font-size: 0.85em;
            color: #495057;
            margin-top: 5px;
            padding: 5px;
            background-color: #f8f9fa;
            border-radius: 4px;
            text-align: center;
        }
        
        .empty-message { 
            text-align: center; 
            padding: 40px 20px; 
            color: #6c757d;
            font-size: 0.95em;
        }
        
        .footer { 
            text-align: center; 
            padding: 15px; 
            background-color: #f8f9fa;
            border-top: 1px solid #dee2e6;
        }
        
        .footer a { 
            color: #667eea; 
            text-decoration: none; 
            font-weight: 500;
        }
        
        /* 画像モーダル */
        .modal { 
            display: none; 
            position: fixed; 
            z-index: 1000; 
            left: 0; 
            top: 0; 
            width: 100%; 
            height: 100%; 
            background-color: rgba(0,0,0,0.9);
            align-items: center;
            justify-content: center;
        }
        
        .modal-close { 
            position: absolute; 
            top: 20px; 
            right: 35px; 
            color: #f1f1f1; 
            font-size: 40px; 
            font-weight: bold; 
            cursor: pointer;
            z-index: 1001;
        }
        
        .image-viewer-container {
            width: 100%;
            height: 100%;
            overflow: hidden;
            position: relative;
        }
        
        .image-slider {
            display: flex;
            height: 100%;
            transition: transform 0.3s ease-in-out;
        }
        
        .image-slide {
            flex-shrink: 0;
            width: 100vw;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .zoomable-image {
            max-width: 100%;
            max-height: 100%;
            width: auto;
            height: auto;
            object-fit: contain;
            transition: transform 0.3s ease-out;
            cursor: grab;
            max-width: 1000px; 
            max-height: 600px;
        }
        
        .slider-controls {
            position: absolute;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%);
            display: flex;
            gap: 10px;
            z-index: 1001;
        }
        
        .slider-dot {
            height: 15px;
            width: 15px;
            background-color: #bbb;
            border-radius: 50%;
            display: inline-block;
            transition: background-color 0.6s ease;
            cursor: pointer;
        }
        
        .slider-dot.active {
            background-color: #717171;
        }
        
        @media (max-width: 480px) {
            .header h1 { font-size: 1.3em; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header"> 
            <h1>🏪 店舗一覧</h1>
            <div class="subtitle">ようこそ、<?php echo htmlspecialchars($currentUserName); ?>さん! (<a href="?action=logout" style="color: white;">ログアウト</a>)</div>
        </div>
        
        <div class="driver-info">
            <div class="driver-info-row">
                <span class="driver-info-label">ドライバー名:</span>
                <span class="driver-info-value"><?php echo htmlspecialchars($currentDriver['name']); ?></span>
            </div>
            <div class="driver-info-row">
                <span class="driver-info-label">個人番号:</span>
                <span class="driver-info-value"><?php echo htmlspecialchars($currentDriver['personal_id']); ?></span>
            </div>
            <div class="driver-info-row">
                <span class="driver-info-label">本日の日付:</span>
                <span class="driver-info-value"><?php echo date('Y年m月d日'); ?> (<?php echo $dayMap[$currentDay]; ?>)</span>
            </div>
            <div class="driver-info-row">
                <span class="driver-info-label">本日のコース:</span>
                <span class="driver-info-value">
                    <?php if ($todayCourseName && !in_array($todayCourseName, ['公休', '有給', '-', ''])): ?>
                        <span class="course-badge"><?php echo htmlspecialchars($todayCourseName); ?></span>
                    <?php else: ?>
                        <span style="color: #dc3545; font-weight: bold;"><?php echo htmlspecialchars($todayCourseName ?: '未設定'); ?></span>
                    <?php endif; ?>
                </span>
            </div>
        </div>
        
        <div class="content">
            <?php if (empty($driverShops)): ?>
                <div class="empty-message">
                    🔭<br><br>
                    <?php if (in_array($todayCourseName, ['公休', '有給'])): ?>
                        本日は<?php echo htmlspecialchars($todayCourseName); ?>です。<br>ゆっくりお休みください。
                    <?php elseif (!$todayCourseName || $todayCourseName === '-'): ?>
                        本日のコースが設定されていません。<br>管理者にお問い合わせください。
                    <?php else: ?>
                        このコースには<br>登録されている店舗がありません
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <?php foreach ($driverShops as $index => $shop): ?>
                    <div class="shop-item">
                        <div class="shop-name-row">
                            <div class="shop-name">
                                <span class="shop-number"><?php echo $index + 1; ?></span>
                                <?php 
                                    $map_link = $shop['parking_coords'];
                                    if (strpos($map_link, 'http') === false) {
                                        $map_link = 'https://www.google.com/maps?q=' . $map_link;
                                    }
                                ?>
                                <a href="<?php echo htmlspecialchars($map_link); ?>" target="_blank" style="color: inherit; text-decoration: none;">
                                    <?php echo htmlspecialchars($shop['shop_name']); ?>
                                </a>
                            </div>
                            <button class="detail-toggle-btn" data-shop-id="<?php echo $shop['id']; ?>">詳細</button>
                        </div>
                        
                        <div class="detail-content" id="detail-<?php echo $shop['id']; ?>">
                            <?php if (!empty($shop['address'])): ?>
                                <div class="shop-details-item">
                                    <span class="icon">📍</span>
                                    <span class="text">
                                        **住所:**<br>
                                        <?php echo htmlspecialchars($shop['address']); ?>
                                    </span>
                                </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($shop['contact'])): ?>
                                <div class="shop-details-item">
                                    <span class="icon">📞</span>
                                    <span class="text">
                                        **連絡先:**<br>
                                        <?php echo htmlspecialchars($shop['contact']); ?>
                                    </span>
                                </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($shop['details'])): ?>
                                <div class="shop-details-item">
                                    <span class="icon">📝</span>
                                    <span class="text">
                                        **納品方法などの詳細:**<br>
                                        <?php echo nl2br(htmlspecialchars($shop['details'])); ?>
                                    </span>
                                </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($shop['images'])): ?>
                                <div class="shop-details-item">
                                    <span class="icon">🖼️</span>
                                    <span class="text">**登録画像:**</span>
                                </div>
                                <div class="shop-images" id="images-<?php echo $shop['id']; ?>">
                                    <?php 
                                        $image_list = array_map('htmlspecialchars', $shop['images']);
                                        $image_json = json_encode($image_list);
                                    ?>
                                    <?php foreach ($shop['images'] as $img): ?>
                                        <img src="<?php echo htmlspecialchars($img); ?>" 
                                             alt="店舗画像" 
                                             data-full-src="<?php echo htmlspecialchars($img); ?>"
                                             onclick="openViewer(<?php echo $image_json; ?>, '<?php echo htmlspecialchars($img); ?>')">
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="time-tracking-section">
                            <button class="time-btn start-btn" data-shop-id="<?php echo $shop['id']; ?>" data-type="start" data-shop-name="<?php echo htmlspecialchars($shop['shop_name']); ?>">
                                ⏱️ 作業開始
                            </button>
                            <button class="time-btn end-btn" data-shop-id="<?php echo $shop['id']; ?>" data-type="end" data-shop-name="<?php echo htmlspecialchars($shop['shop_name']); ?>" disabled>
                                ✅ 作業終了
                            </button>
                        </div>
                        <div class="time-status" id="status-<?php echo $shop['id']; ?>"></div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <div class="footer"> 
            <a href="shop_regist.php">🔧 管理画面へ</a>
            <a href="report.php" style="margin-left: 15px;">📊 帳票出力</a>
        </div>
    </div>
    
    <!-- 画像ビューアモーダル -->
    <div id="imageModal" class="modal">
        <span class="modal-close" onclick="closeViewer()">&times;</span>
        <div class="image-viewer-container">
            <div class="image-slider" id="imageSlider"></div>
        </div>
        <div class="slider-controls" id="sliderControls"></div>
    </div>
    
    <script>
        const currentUserId = '<?php echo $currentUserId; ?>';
        const currentUserName = '<?php echo $currentUserName; ?>';
        const currentDay = '<?php echo $currentDay; ?>';
        
        // ボタンの状態をローカルストレージから復元
        document.addEventListener('DOMContentLoaded', () => {
            document.querySelectorAll('.shop-item').forEach(item => {
                const startBtn = item.querySelector('.start-btn');
                if (!startBtn) return;
                
                const shopId = startBtn.dataset.shopId;
                const startTime = localStorage.getItem(`start-${currentUserId}-${shopId}`);
                const endTime = localStorage.getItem(`end-${currentUserId}-${shopId}`);
                const statusElement = document.getElementById(`status-${shopId}`);
                const endBtn = item.querySelector('.end-btn');
                
                if (startTime && !endTime) {
                    startBtn.disabled = true;
                    startBtn.classList.add('working');
                    startBtn.textContent = '⏳ 作業中...';
                    endBtn.disabled = false;
                    statusElement.textContent = `開始: ${new Date(startTime).toLocaleTimeString('ja-JP')}`;
                } else if (startTime && endTime) {
                    startBtn.disabled = true;
                    endBtn.disabled = true;
                    endBtn.classList.add('completed');
                    endBtn.textContent = '✅ 完了';
                    const start = new Date(startTime);
                    const end = new Date(endTime);
                    const duration = Math.floor((end - start) / 1000 / 60);
                    statusElement.textContent = `完了 (作業時間: ${duration}分)`;
                } else {
                    startBtn.disabled = false;
                    endBtn.disabled = true;
                    statusElement.textContent = '';
                }
            });
        });
        
        // 時間計測ボタンのイベントリスナー
        document.querySelectorAll('.time-btn').forEach(button => {
            button.addEventListener('click', function() {
                const shopId = this.dataset.shopId;
                const shopName = this.dataset.shopName;
                const type = this.dataset.type;
                recordTime(shopId, type, shopName, this);
            });
        });
        
        function recordTime(shopId, type, shopName, buttonElement) {
            if (!currentUserId) {
                alert("ログインしてください。");
                return;
            }
            
            const startBtn = document.querySelector(`.start-btn[data-shop-id="${shopId}"]`);
            const endBtn = document.querySelector(`.end-btn[data-shop-id="${shopId}"]`);
            const statusElement = document.getElementById(`status-${shopId}`);
            const currentTime = new Date().toISOString();
            
            if (type === 'start') {
                if (startBtn.disabled) return;
                
                if (!confirm(`【${shopName}】\n\n作業を開始してください。\n\n作業開始時間を記録しますか?`)) {
                    return;
                }
                
                startBtn.disabled = true;
                startBtn.classList.add('working');
                startBtn.textContent = '⏳ 作業中...';
                endBtn.disabled = false;
                
                localStorage.setItem(`start-${currentUserId}-${shopId}`, currentTime);
                localStorage.removeItem(`end-${currentUserId}-${shopId}`);
                statusElement.textContent = `開始: ${new Date(currentTime).toLocaleTimeString('ja-JP')}`;
                
                sendTimeLog(shopId, type, currentTime, currentUserId, currentUserName)
                    .then(() => {
                        buttonElement.style.backgroundColor = '#28a745';
                        setTimeout(() => {
                            buttonElement.style.backgroundColor = '';
                        }, 500);
                    });
                
            } else if (type === 'end') {
                if (endBtn.disabled) return;
                
                const startTime = localStorage.getItem(`start-${currentUserId}-${shopId}`);
                if (!startTime) {
                    alert("先に作業開始時間を記録してください。");
                    return;
                }
                
                const start = new Date(startTime);
                const end = new Date(currentTime);
                const duration = Math.floor((end - start) / 1000 / 60);
                
                if (!confirm(`【${shopName}】\n\n作業時間: ${duration}分\n\n作業終了時間を記録しますか?`)) {
                    return;
                }
                
                endBtn.disabled = true;
                endBtn.classList.add('completed');
                endBtn.textContent = '✅ 完了';
                
                localStorage.setItem(`end-${currentUserId}-${shopId}`, currentTime);
                statusElement.textContent = `完了 (作業時間: ${duration}分)`;
                
                sendTimeLog(shopId, type, currentTime, currentUserId, currentUserName)
                    .then(() => {
                        alert(`【${shopName}】\n\n✅ 作業終了しました。\n作業時間: ${duration}分\n\n次の店舗に向かってください。`);
                        
                        buttonElement.style.backgroundColor = '#28a745';
                        setTimeout(() => {
                            buttonElement.style.backgroundColor = '';
                        }, 500);
                    });
            }
        }
        
        function sendTimeLog(shopId, type, timestamp, driverId, driverName) {
            const formData = new FormData();
            formData.append('action', 'record_time');
            formData.append('shop_id', shopId);
            formData.append('type', type);
            formData.append('timestamp', timestamp);
            formData.append('driver_id', driverId);
            formData.append('driver_name', driverName);
            formData.append('day', currentDay);
            
            return fetch('shoplist.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    console.log('Time log recorded successfully:', data.message);
                    return data;
                } else {
                    console.error('Failed to record time log:', data.message);
                    alert('⚠️ 時間記録の保存に失敗しました: ' + data.message);
                    throw new Error(data.message);
                }
            })
            .catch(error => {
                console.error('Error sending time log:', error);
                alert('⚠️ 時間記録の送信中にエラーが発生しました。');
                throw error;
            });
        }
        
        // 詳細ボタンのクリックイベント
        document.querySelectorAll('.detail-toggle-btn').forEach(button => {
            button.addEventListener('click', function() {
                const shopId = this.getAttribute('data-shop-id');
                const detailContent = document.getElementById(`detail-${shopId}`);
                
                if (detailContent.style.display === 'block') {
                    detailContent.style.display = 'none';
                    this.textContent = '詳細';
                } else {
                    detailContent.style.display = 'block';
                    this.textContent = '閉じる';
                }
            });
        });
        
        // 画像ビューア機能
        let currentImages = [];
        let currentIndex = 0;
        let isZoomed = false;
        let initialDistance = 0;
        let currentScale = 1;
        
        function openViewer(images, initialImageSrc) {
            currentImages = images;
            currentIndex = images.indexOf(initialImageSrc);
            
            const modal = document.getElementById('imageModal');
            const slider = document.getElementById('imageSlider');
            const controls = document.getElementById('sliderControls');
            
            slider.innerHTML = '';
            controls.innerHTML = '';
            
            currentImages.forEach((src, index) => {
                const imgWrapper = document.createElement('div');
                imgWrapper.className = 'image-slide';
                
                const img = document.createElement('img');
                img.src = src;
                img.alt = '店舗画像 ' + (index + 1);
                img.className = 'zoomable-image';
                img.style.transform = 'scale(1)';
                img.addEventListener('dblclick', toggleZoom);
                
                imgWrapper.appendChild(img);
                slider.appendChild(imgWrapper);
                
                const dot = document.createElement('span');
                dot.className = 'slider-dot';
                dot.setAttribute('data-index', index);
                dot.onclick = () => goToSlide(index);
                controls.appendChild(dot);
            });
            
            modal.style.display = 'flex';
            updateViewer(false);
            
            slider.addEventListener('touchstart', handleTouchStart, false);
            slider.addEventListener('touchmove', handleTouchMove, false);
            slider.addEventListener('touchend', handleTouchEnd, false);
        }
        
        function closeViewer() {
            document.getElementById('imageModal').style.display = 'none';
            const slider = document.getElementById('imageSlider');
            slider.removeEventListener('touchstart', handleTouchStart, false);
            slider.removeEventListener('touchmove', handleTouchMove, false);
            slider.removeEventListener('touchend', handleTouchEnd, false);
            
            const currentImage = document.querySelector('.image-slide .zoomable-image');
            if (currentImage) {
                currentImage.style.transform = 'scale(1)';
                isZoomed = false;
                currentScale = 1;
            }
        }
        
        function updateViewer(animate = true) {
            const slider = document.getElementById('imageSlider');
            const dots = document.querySelectorAll('.slider-dot');
            const slideWidth = slider.querySelector('.image-slide').offsetWidth;
            
            slider.style.transition = animate ? 'transform 0.3s ease-in-out' : 'none';
            slider.style.transform = `translateX(-${currentIndex * slideWidth}px)`;
            
            dots.forEach((dot, index) => {
                dot.classList.toggle('active', index === currentIndex);
            });
            
            document.querySelectorAll('.zoomable-image').forEach(img => {
                img.style.transform = 'scale(1)';
            });
            isZoomed = false;
            currentScale = 1;
        }
        
        function goToSlide(index) {
            currentIndex = index;
            updateViewer();
        }
        
        function toggleZoom(event) {
            const img = event.target;
            isZoomed = !isZoomed;
            currentScale = isZoomed ? 2 : 1;
            img.style.transform = `scale(${currentScale})`;
        }
        
        let startX = 0;
        let isSwiping = false;
        
        function handleTouchStart(event) {
            if (event.touches.length === 1) {
                startX = event.touches[0].clientX;
                isSwiping = true;
            } else if (event.touches.length === 2) {
                initialDistance = getDistance(event.touches[0], event.touches[1]);
                isSwiping = false;
            }
        }
        
        function handleTouchMove(event) {
            if (event.touches.length === 2) {
                const currentDistance = getDistance(event.touches[0], event.touches[1]);
                const scaleFactor = currentDistance / initialDistance;
                const newScale = Math.max(1, Math.min(3, currentScale * scaleFactor));
                const currentImage = document.querySelector('.image-slide:nth-child(' + (currentIndex + 1) + ') .zoomable-image');
                if (currentImage) {
                    currentImage.style.transform = `scale(${newScale})`;
                }
                initialDistance = currentDistance;
                currentScale = newScale;
                isZoomed = newScale > 1.1;
            } else if (event.touches.length === 1 && isSwiping && !isZoomed) {
                const diffX = event.touches[0].clientX - startX;
                const slider = document.getElementById('imageSlider');
                const slideWidth = slider.querySelector('.image-slide').offsetWidth;
                slider.style.transition = 'none';
                slider.style.transform = `translateX(${-currentIndex * slideWidth + diffX}px)`;
            }
        }
        
        function handleTouchEnd(event) {
            if (isSwiping && !isZoomed) {
                const diffX = event.changedTouches[0].clientX - startX;
                const threshold = 50;
                
                if (diffX > threshold && currentIndex > 0) {
                    currentIndex--;
                } else if (diffX < -threshold && currentIndex < currentImages.length - 1) {
                    currentIndex++;
                }
                
                updateViewer();
                isSwiping = false;
            }
            
            if (event.touches.length === 0) {
                const currentImage = document.querySelector('.image-slide:nth-child(' + (currentIndex + 1) + ') .zoomable-image');
                if (currentImage) {
                    const currentTransform = currentImage.style.transform;
                    const match = currentTransform.match(/scale\(([^)]+)\)/);
                    if (match) {
                        currentScale = parseFloat(match[1]);
                    }
                }
            }
        }
        
        function getDistance(touch1, touch2) {
            const dx = touch1.clientX - touch2.clientX;
            const dy = touch1.clientY - touch2.clientY;
            return Math.sqrt(dx * dx + dy * dy);
        }
        
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeViewer();
            }
        });
    </script>
</body>
</html>