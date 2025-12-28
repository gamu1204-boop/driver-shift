<?php
/**
 * 自動シフト生成システム（会社カレンダー対応版）
 */

date_default_timezone_set('Asia/Tokyo');

// 共通関数を読み込み
require_once 'calendar_functions.php';

// ファイルパス定義
$driversFile  = 'drivers.json';
$coursesFile  = 'courses.json';
$vehiclesFile = 'vehicles.json';
$scheduleFile = 'schedule.json';

// メッセージ格納用
$message = '';
$messageType = '';

// 曜日マッピング
$englishDays = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
$dayMap = [
    'monday'    => '月曜日',
    'tuesday'   => '火曜日',
    'wednesday' => '水曜日',
    'thursday'  => '木曜日',
    'friday'    => '金曜日',
    'saturday'  => '土曜日',
    'sunday'    => '日曜日',
];

// ================================
// ヘルパー関数
// ================================

function loadJsonData($filename) {
    if (file_exists($filename) && filesize($filename) > 0) {
        $content = @file_get_contents($filename);
        if ($content !== false) {
            $data = json_decode($content, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
                return $data;
            }
        }
    }
    return [];
}

function normalizeCourseName($name) {
    $name = trim($name);
    if (in_array($name, ['-', '', '公休', '有給', '同乗', 'その他'])) {
        return $name;
    }
    $temp = str_replace(' ', '', $name);
    return preg_replace('/^([A-Z0-9]+)([^\s0-9].*)$/u', '$1 $2', $temp);
}

/**
 * 自動シフト生成メイン関数（会社カレンダー対応）
 */
function generateWeeklyShift($startDate, $drivers, $courses, $vehicles) {
    global $englishDays;
    
    $generatedSchedule = [];
    $conflicts = [];
    $companyCalendar = loadCompanyCalendar();
    
    // 車両マップの作成
    $vehicleMap = [];
    foreach ($vehicles as $vid => $v) {
        $vehicleMap[$vid] = $v['plate'] ?? '';
    }
    
    // コース→車両マップの作成
    $courseVehicleMap = [];
    foreach ($courses as $dayList) {
        if (is_array($dayList)) {
            foreach ($dayList as $course) {
                $courseName = normalizeCourseName($course['name'] ?? '');
                if ($courseName !== '' && $courseName !== '-') {
                    $courseVehicleMap[$courseName] = [
                        'vehicle_id' => $course['vehicle_id'] ?? null,
                        'plate' => $vehicleMap[$course['vehicle_id'] ?? ''] ?? ''
                    ];
                }
            }
        }
    }
    
    // 日付の生成
    $baseDate = new DateTime($startDate);
    if ($baseDate->format('N') != 1) {
        $baseDate->modify('last monday');
    }
    
    $dates = [];
    for ($i = 0; $i < 7; $i++) {
        $tmpDate = clone $baseDate;
        $tmpDate->modify("+$i days");
        $dates[] = [
            'date' => $tmpDate->format('Y-m-d'),
            'dayKey' => $englishDays[$i]
        ];
    }
    
    // アクティブなドライバーのみをフィルタリング
    $activeDrivers = array_filter($drivers, function($d) {
        return (!($d['is_deleted'] ?? false)) && (($d['is_active'] ?? 1) == 1);
    });
    
    // 各ドライバーに対してシフトを生成
    foreach ($activeDrivers as $driverId => $driver) {
        $generatedSchedule[$driverId] = [];
        
        foreach ($dates as $dateInfo) {
            $date = $dateInfo['date'];
            $dayKey = $dateInfo['dayKey'];
            
            // 会社カレンダーをチェック
            $dayStatus = getCompanyDayStatus($date, $companyCalendar);
            
            if (!$dayStatus['is_working']) {
                // 会社が休業日の場合は「公休」
                $generatedSchedule[$driverId][$date] = [
                    'course' => '公休',
                    'vehicle' => '',
                    'note' => $dayStatus['label']
                ];
            } else {
                // 営業日の場合はデフォルトコースを取得
                $defaultCourse = $driver['courses'][$dayKey]['course'] ?? '-';
                $courseName = normalizeCourseName($defaultCourse);
                
                // 車両番号を取得
                $plateNo = '';
                if (isset($courseVehicleMap[$courseName])) {
                    $plateNo = $courseVehicleMap[$courseName]['plate'];
                }
                
                // スケジュールに追加
                $note = $dayStatus['type'] === 'special_working' ? $dayStatus['label'] : '';
                $generatedSchedule[$driverId][$date] = [
                    'course' => $courseName,
                    'vehicle' => $plateNo,
                    'note' => $note
                ];
                
                // 車両重複チェック（公休・有給以外）
                if (!in_array($courseName, ['公休', '有給', '-', '']) && $plateNo !== '') {
                    $key = $date . '_' . $plateNo;
                    if (!isset($conflicts[$key])) {
                        $conflicts[$key] = [];
                    }
                    $conflicts[$key][] = [
                        'driver_name' => $driver['name'] ?? 'Unknown',
                        'course' => $courseName,
                        'vehicle' => $plateNo
                    ];
                }
            }
        }
    }
    
    // 重複している車両を抽出
    $realConflicts = [];
    foreach ($conflicts as $key => $assignments) {
        if (count($assignments) > 1) {
            $realConflicts[$key] = $assignments;
        }
    }
    
    return [
        'schedule' => $generatedSchedule,
        'conflicts' => $realConflicts
    ];
}

// ================================
// メイン処理
// ================================

$drivers = loadJsonData($driversFile);
$courses = loadJsonData($coursesFile);
$vehicles = loadJsonData($vehiclesFile);
$currentSchedule = loadJsonData($scheduleFile);
$companyCalendar = loadCompanyCalendar();

// 現在の週の月曜日を取得
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['target_date'])) {
    $currentDateStr = $_POST['target_date'];
} else {
    $currentDateStr = $_GET['date'] ?? date('Y-m-d');
}

$currentDate = new DateTime($currentDateStr);
if ($currentDate->format('N') != 1) {
    $currentDate->modify('last monday');
}
$mondayStr = $currentDate->format('Y-m-d');

// プレビューデータの保持
$previewData = null;
$conflicts = [];

// POST処理: シフト生成
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['generate_shift'])) {
        $targetDate = $_POST['target_date'] ?? $mondayStr;
        $result = generateWeeklyShift($targetDate, $drivers, $courses, $vehicles);
        
        $previewData = $result['schedule'];
        $conflicts = $result['conflicts'];
        
        if (empty($conflicts)) {
            $message = '✅ シフトを生成しました。問題ありません。内容を確認して「保存する」ボタンを押してください。';
            $messageType = 'success';
        } else {
            $message = '⚠️ シフトを生成しましたが、車両の重複割り当てがあります。下記の競合を確認してください。';
            $messageType = 'warning';
        }
    } elseif (isset($_POST['save_generated_shift'])) {
        $jsonData = json_decode($_POST['generated_data'], true);
        if ($jsonData !== null) {
            file_put_contents($scheduleFile, json_encode($jsonData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
            header('Location: auto_shift_generator.php?saved=1&date=' . urlencode($_POST['target_date'] ?? $mondayStr));
            exit;
        }
    }
}

// 保存完了メッセージ
if (isset($_GET['saved']) && $_GET['saved'] == '1') {
    $message = '✅ シフトを保存しました！';
    $messageType = 'success';
}

// プレビュー用のデータ
$displaySchedule = $previewData ?? $currentSchedule;

// 表示用の日付リスト
$displayDate = new DateTime($mondayStr);
$displayDates = [];
$weekStatus = getWeekWorkingStatus($mondayStr);

for ($i = 0; $i < 7; $i++) {
    $tmpDate = clone $displayDate;
    $tmpDate->modify("+$i days");
    $dateStr = $tmpDate->format('Y-m-d');
    $dayKey = $englishDays[$i];
    
    $displayDates[] = [
        'date' => $dateStr,
        'display' => $tmpDate->format('n月j日'),
        'dayOfWeek' => ['月','火','水','木','金','土','日'][$i],
        'dayKey' => $dayKey,
        'status' => $weekStatus[$dayKey]
    ];
}

// アクティブなドライバーを取得
$activeDrivers = array_filter($drivers, function($d) {
    return (!($d['is_deleted'] ?? false)) && (($d['is_active'] ?? 1) == 1);
});
uasort($activeDrivers, function($a, $b) {
    return (int)($a['personal_id'] ?? 0) <=> (int)($b['personal_id'] ?? 0);
});

?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>自動シフト生成システム</title>
    <style>
        body {
            font-family: 'メイリオ', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            margin: 0;
            padding: 20px;
            color: #333;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
        }
        
        h1 {
            text-align: center;
            color: #667eea;
            margin-bottom: 10px;
            font-size: 2em;
        }
        
        .subtitle {
            text-align: center;
            color: #666;
            margin-bottom: 30px;
            font-size: 0.9em;
        }
        
        .calendar-notice {
            background-color: #e7f3ff;
            border-left: 4px solid #007bff;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 5px;
        }
        
        .calendar-notice strong {
            color: #0056b3;
        }
        
        .message {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 8px;
            font-weight: bold;
        }
        
        .message.success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .message.warning {
            background-color: #fff3cd;
            color: #856404;
            border: 1px solid #ffeeba;
        }
        
        .control-panel {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 30px;
            border: 2px solid #667eea;
        }
        
        .control-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            flex-wrap: wrap;
        }
        
        .date-selector {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .date-selector input[type="date"] {
            padding: 10px;
            border: 2px solid #ddd;
            border-radius: 5px;
            font-size: 1em;
        }
        
        .btn {
            padding: 12px 30px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 1em;
            font-weight: bold;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-generate {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .btn-generate:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
        
        .btn-save {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            color: white;
        }
        
        .btn-save:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(245, 87, 108, 0.4);
        }
        
        .btn-cancel {
            background: #6c757d;
            color: white;
        }
        
        .btn-calendar {
            background: #17a2b8;
            color: white;
            padding: 8px 15px;
            font-size: 0.9em;
        }
        
        .schedule-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            font-size: 0.9em;
        }
        
        .schedule-table th,
        .schedule-table td {
            border: 1px solid #ddd;
            padding: 12px 8px;
            text-align: center;
        }
        
        .schedule-table th {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            font-weight: bold;
        }
        
        .schedule-table th.company-holiday {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
        }
        
        .schedule-table th.special-working {
            background: linear-gradient(135deg, #ffc107 0%, #e0a800 100%);
            color: #333;
        }
        
        .day-label-small {
            font-size: 0.75em;
            display: block;
            margin-top: 2px;
            opacity: 0.9;
        }
        
        .driver-col {
            background: #f8f9fa;
            font-weight: bold;
            width: 120px;
        }
        
        .course-badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 20px;
            font-weight: bold;
            font-size: 0.9em;
        }
        
        .course-work {
            background: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }
        
        .course-holiday {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .vehicle-plate {
            display: block;
            margin-top: 5px;
            color: #dc3545;
            font-weight: bold;
            font-size: 1.1em;
        }
        
        .conflict-section {
            background: #fff3cd;
            border: 2px solid #ffc107;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        
        .conflict-item {
            background: white;
            padding: 10px;
            margin: 10px 0;
            border-left: 4px solid #dc3545;
            border-radius: 5px;
        }
        
        .navigation-links {
            text-align: center;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 2px solid #ddd;
        }
        
        .navigation-links a {
            margin: 0 15px;
            color: #667eea;
            text-decoration: none;
            font-weight: bold;
        }
        
        .preview-badge {
            display: inline-block;
            background: #ffc107;
            color: #333;
            padding: 5px 15px;
            border-radius: 20px;
            font-weight: bold;
            font-size: 0.9em;
            margin-left: 10px;
        }
        
        .sat { color: #007bff; }
        .sun { color: #dc3545; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🚛 自動シフト生成システム</h1>
        <p class="subtitle">ドライバーのデフォルトコースと会社カレンダーを基に1週間分のシフトを自動生成します</p>
        
        <?php if (!empty($companyCalendar['company_name'])): ?>
            <div class="calendar-notice">
                <strong>📅 会社カレンダー適用中:</strong> <?= htmlspecialchars($companyCalendar['company_name']) ?>
                <?php if (!empty($companyCalendar['weekly_holidays'])): ?>
                    <br>定休曜日: 
                    <?php 
                    $dayNames = ['monday'=>'月', 'tuesday'=>'火', 'wednesday'=>'水', 'thursday'=>'木', 'friday'=>'金', 'saturday'=>'土', 'sunday'=>'日'];
                    echo implode('・', array_map(function($d) use ($dayNames) { return $dayNames[$d]; }, $companyCalendar['weekly_holidays'])); 
                    ?>
                <?php endif; ?>
                <a href="company_calendar.php" class="btn btn-calendar" style="float: right;">カレンダー設定</a>
            </div>
        <?php else: ?>
            <div class="calendar-notice">
                <strong>ℹ️ 会社カレンダー未設定:</strong> 
                <a href="company_calendar.php" class="btn btn-calendar">カレンダーを設定する</a>
            </div>
        <?php endif; ?>
        
        <?php if ($message): ?>
            <div class="message <?= $messageType ?>">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($conflicts)): ?>
            <div class="conflict-section">
                <h3>⚠️ 車両の重複割り当てが検出されました</h3>
                <p>以下の車両が複数のドライバーに割り当てられています。手動で調整が必要です。</p>
                <?php foreach ($conflicts as $key => $assignments): 
                    list($date, $plate) = explode('_', $key);
                ?>
                    <div class="conflict-item">
                        <strong>📅 <?= htmlspecialchars($date) ?> - 車両: <?= htmlspecialchars($plate) ?></strong>
                        <ul>
                            <?php foreach ($assignments as $assign): ?>
                                <li>
                                    ドライバー: <?= htmlspecialchars($assign['driver_name']) ?> 
                                    → コース: <?= htmlspecialchars($assign['course']) ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        
        <div class="control-panel">
            <form method="POST">
                <div class="control-row">
                    <div class="date-selector">
                        <label for="target_date"><strong>対象週の開始日:</strong></label>
                        <input type="date" id="target_date" name="target_date" value="<?= htmlspecialchars($mondayStr) ?>" required>
                    </div>
                    
                    <div style="display: flex; gap: 10px;">
                        <button type="submit" name="generate_shift" class="btn btn-generate">
                            🔄 シフト自動生成
                        </button>
                        
                        <?php if ($previewData !== null): ?>
                            <button type="button" onclick="saveGeneratedShift()" class="btn btn-save">
                                💾 保存する
                            </button>
                        <?php endif; ?>
                        
                        <a href="pc_schedule.php?year=<?= $displayDate->format('Y') ?>&month=<?= $displayDate->format('m') ?>&day=<?= $displayDate->format('d') ?>" 
                           class="btn btn-cancel">キャンセル</a>
                    </div>
                </div>
            </form>
        </div>
        
        <h3>
            📋 シフトプレビュー
            <?php if ($previewData !== null): ?>
                <span class="preview-badge">※未保存</span>
            <?php endif; ?>
        </h3>
        
        <table class="schedule-table">
            <thead>
                <tr>
                    <th class="driver-col">ドライバー</th>
                    <?php foreach ($displayDates as $day): 
                        $headerClass = '';
                        if (!$day['status']['is_working']) {
                            $headerClass = 'company-holiday';
                        } elseif ($day['status']['type'] === 'special_working') {
                            $headerClass = 'special-working';
                        }
                    ?>
                        <th class="<?= $headerClass ?>">
                            <span class="<?= ($day['dayOfWeek'] === '土') ? 'sat' : (($day['dayOfWeek'] === '日') ? 'sun' : '') ?>">
                                <?= $day['display'] ?>(<?= $day['dayOfWeek'] ?>)
                            </span>
                            <?php if (!$day['status']['is_working'] || $day['status']['type'] === 'special_working'): ?>
                                <span class="day-label-small"><?= $day['status']['label'] ?></span>
                            <?php endif; ?>
                        </th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($activeDrivers as $driverId => $driver): ?>
                    <tr>
                        <td class="driver-col">
                            <?= htmlspecialchars($driver['name'] ?? 'Unknown') ?>
                            <div style="font-size: 0.8em; color: #666;">
                                No.<?= htmlspecialchars($driver['personal_id'] ?? '-') ?>
                            </div>
                        </td>
                        <?php foreach ($displayDates as $day): 
                            $saved = $displaySchedule[$driverId][$day['date']] ?? null;
                            $courseName = $saved['course'] ?? '-';
                            $plateNo = $saved['vehicle'] ?? '';
                            $badgeClass = in_array($courseName, ['公休', '有給']) ? 'course-holiday' : 'course-work';
                        ?>
                            <td>
                                <?php if ($courseName !== '-'): ?>
                                    <span class="course-badge <?= $badgeClass ?>">
                                        <?= htmlspecialchars($courseName) ?>
                                    </span>
                                    <?php if ($plateNo && !in_array($courseName, ['公休', '有給', '-'])): ?>
                                        <span class="vehicle-plate"><?= htmlspecialchars($plateNo) ?></span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span style="color: #999;">－</span>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <?php if ($previewData !== null): ?>
            <form id="save-form" method="POST" style="display: none;">
                <input type="hidden" name="save_generated_shift" value="1">
                <input type="hidden" name="target_date" value="<?= htmlspecialchars($mondayStr) ?>">
                <input type="hidden" name="generated_data" id="generated-data" value="<?= htmlspecialchars(json_encode($previewData)) ?>">
            </form>
            
            <script>
                function saveGeneratedShift() {
                    if (confirm('生成したシフトを保存しますか？')) {
                        document.getElementById('save-form').submit();
                    }
                }
            </script>
        <?php endif; ?>
        
        <div class="navigation-links">
            <a href="index.html">🤖 TOPページ</a>	
            <a href="pc_schedule.php">📅 週間スケジュール管理</a>
            <a href="driver_regist.php">👨‍✈️ ドライバー登録</a>
            <a href="course_regist.php">🗺️ コースマスター管理</a>
            <a href="vehicle_regist.php">🚚 車両マスター管理</a>
            <a href="company_calendar.php">📅 会社カレンダー設定</a>
        </div>
    </div>
</body>
</html>