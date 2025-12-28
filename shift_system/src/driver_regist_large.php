<?php
// PHP設定: タイムゾーンを日本に設定
date_default_timezone_set('Asia/Tokyo');

// 共通関数を読み込み
require_once 'calendar_functions.php';

// ファイルパス
$driversFile = 'drivers.json';
$coursesFile = 'courses_large.json';
$largeCoursesFile = 'driver_large_courses.json'; // 大型配送コース割当用

// 会社カレンダーを読み込み
$companyCalendar = loadCompanyCalendar();

// 曜日マッピング
$dayMap = [
    'monday' => '月曜日',
    'tuesday' => '火曜日',
    'wednesday' => '水曜日',
    'thursday' => '木曜日',
    'friday' => '金曜日',
    'saturday' => '土曜日',
    'sunday' => '日曜日',
];
$englishDays = array_keys($dayMap);

// ===========================================
// ファイル読み込み
// ===========================================
function loadJsonData($filename) {
    if (file_exists($filename) && filesize($filename) > 0) {
        $data = json_decode(file_get_contents($filename), true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $data;
        }
    }
    return [];
}

// ドライバーデータ読み込み
$allDrivers = loadJsonData($driversFile);

// 大型配送対象ドライバーのみ抽出（大型専任 or 兼任で「大型配送」設定あり）
$largeDrivers = [];
foreach ($allDrivers as $driverId => $driver) {
    if ($driver['is_deleted'] ?? false) continue;
    if (($driver['is_active'] ?? 1) != 1) continue;
    
    $deliveryType = $driver['delivery_type'] ?? 'shop';
    
    if ($deliveryType === 'large') {
        // 大型専任: 全曜日対象
        $largeDrivers[$driverId] = $driver;
        $largeDrivers[$driverId]['large_days'] = $englishDays;
    } elseif ($deliveryType === 'both') {
        // 兼任: 「大型配送」が設定された曜日のみ対象
        $largeDays = [];
        foreach ($englishDays as $day) {
            $course = $driver['courses'][$day]['course'] ?? '-';
            if ($course === '大型配送') {
                $largeDays[] = $day;
            }
        }
        if (!empty($largeDays)) {
            $largeDrivers[$driverId] = $driver;
            $largeDrivers[$driverId]['large_days'] = $largeDays;
        }
    }
}

// 個人番号順にソート
uasort($largeDrivers, function($a, $b) {
    return (float)($a['personal_id'] ?? 0) <=> (float)($b['personal_id'] ?? 0);
});

// 大型コースデータ読み込み
$coursesRaw = loadJsonData($coursesFile);
$coursesByDay = [
    'monday' => [], 'tuesday' => [], 'wednesday' => [],
    'thursday' => [], 'friday' => [], 'saturday' => [], 'sunday' => []
];
if (is_array($coursesRaw)) {
    foreach ($coursesRaw as $day => $dayCourses) {
        if (is_array($dayCourses) && isset($coursesByDay[$day])) {
            foreach ($dayCourses as $course) {
                $cName = $course['course'] ?? $course['name'] ?? null;
                if ($cName && !in_array($cName, $coursesByDay[$day])) {
                    $coursesByDay[$day][] = $cName;
                }
            }
        }
    }
}

// 大型コース割当データ読み込み
$largeCourses = loadJsonData($largeCoursesFile);
if (!is_array($largeCourses)) $largeCourses = [];

// ===========================================
// POST処理 (コース割当保存)
// ===========================================
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_courses'])) {
    $driverId = $_POST['driver_id'] ?? '';
    if ($driverId && isset($largeDrivers[$driverId])) {
        $newCourses = [];
        foreach ($englishDays as $day) {
            $newCourses[$day] = trim($_POST['large_course'][$day] ?? '-');
        }
        $largeCourses[$driverId] = $newCourses;
        file_put_contents($largeCoursesFile, json_encode($largeCourses, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        $message = '✅ コース割当を保存しました。';
    }
}

$editDriverId = $_GET['edit_id'] ?? '';
$editDriver = ($editDriverId && isset($largeDrivers[$editDriverId])) ? $largeDrivers[$editDriverId] : null;
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>大型車配送ドライバー設定</title>
    <style>
        body { font-family: 'メイリオ', Meiryo, sans-serif; background-color: #f4f4f4; margin: 0; padding: 20px; }
        .container { max-width: 1400px; width: 98%; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 0 10px rgba(0, 0, 0, 0.1); }
        h2 { color: #1565c0; border-bottom: 3px solid #1565c0; padding-bottom: 10px; margin-bottom: 20px; }
        
        .info-notice {
            background-color: #e3f2fd;
            border-left: 4px solid #1565c0;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 5px;
        }
        
        .form-area { margin-bottom: 30px; padding: 20px; border: 2px solid #1565c0; border-radius: 8px; background: #fafafa; }
        .form-area h3 { color: #1565c0; margin-bottom: 15px; }
        
        .course-settings { display: grid; grid-template-columns: repeat(7, 1fr); gap: 10px; }
        .course-day { text-align: center; }
        .course-day label { display: block; font-weight: bold; margin-bottom: 5px; }
        .course-day select { width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; }
        .course-day.disabled { opacity: 0.4; }
        .course-day.disabled select { background-color: #e9e9e9; }
        .course-day.needs-setting select { border: 2px solid #f44336; background-color: #ffebee; }
        
        .btn-submit { background-color: #1565c0; color: white; padding: 12px 30px; border: none; border-radius: 4px; cursor: pointer; font-weight: bold; font-size: 1em; }
        .btn-cancel { background-color: #f8f9fa; color: #333; border: 1px solid #ccc; text-decoration: none; padding: 12px 30px; border-radius: 4px; display: inline-block; margin-left: 10px; }
        
        .driver-list { margin-top: 20px; }
        .driver-list table { width: 100%; border-collapse: collapse; font-size: 0.85em; table-layout: fixed; }
        .driver-list th, .driver-list td { border: 1px solid #ddd; padding: 8px 4px; text-align: center; }
        .driver-list th { background-color: #1565c0; color: white; }
        .driver-list tr:nth-child(even) { background-color: #f8f9fa; }
        
        .name-col { width: 10%; }
        .id-col { width: 8%; }
        .type-col { width: 7%; }
        .day-col { width: 10%; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .actions-col { width: 7%; }
        
        .btn-edit { background-color: #1565c0; color: white; padding: 4px 10px; text-decoration: none; border-radius: 4px; font-size: 0.85em; }
        
        .cell-na { color: #999; }
        .cell-needs { background-color: #ffebee !important; color: #c62828; font-weight: bold; }
        .cell-set { background-color: #e8f5e9 !important; color: #2e7d32; }
        
        .message { padding: 12px 20px; margin-bottom: 20px; border-radius: 5px; background-color: #d4edda; color: #155724; }
        
        .navigation-links { text-align: center; margin-top: 30px; padding-top: 20px; border-top: 2px solid #ddd; }
        .navigation-links a { margin: 0 15px; color: #1565c0; text-decoration: none; font-weight: bold; }
        
        .legend { display: flex; gap: 20px; margin-bottom: 15px; font-size: 0.9em; }
        .legend-item { display: flex; align-items: center; gap: 5px; }
        .legend-box { width: 20px; height: 20px; border-radius: 3px; }
        .legend-na { background-color: #e9e9e9; }
        .legend-needs { background-color: #ffebee; border: 1px solid #c62828; }
        .legend-set { background-color: #e8f5e9; border: 1px solid #2e7d32; }
    </style>
</head>
<body>
<div class="container">
    <h2>🚛 大型車配送ドライバー設定</h2>
    
    <div class="info-notice">
        <strong>ℹ️ この画面について:</strong><br>
        店舗配送画面で「大型専任」または「兼任（大型配送曜日あり）」と設定されたドライバーのみ表示されます。<br>
        各曜日に大型車配送用のコースを割り当ててください。
    </div>
    
    <?php if ($message): ?>
        <div class="message"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>
    
    <?php if ($editDriver): ?>
        <div class="form-area">
            <h3>📝 コース割当編集: <?= htmlspecialchars($editDriver['name']) ?> (<?= htmlspecialchars($editDriver['personal_id']) ?>)</h3>
            <form method="POST">
                <input type="hidden" name="driver_id" value="<?= htmlspecialchars($editDriverId) ?>">
                <input type="hidden" name="save_courses" value="1">
                
                <div class="course-settings">
                    <?php foreach ($englishDays as $day): 
                        $isLargeDay = in_array($day, $editDriver['large_days']);
                        $currentCourse = $largeCourses[$editDriverId][$day] ?? '-';
                    ?>
                        <div class="course-day <?= $isLargeDay ? '' : 'disabled' ?>">
                            <label><?= $dayMap[$day] ?></label>
                            <select name="large_course[<?= $day ?>]" <?= $isLargeDay ? '' : 'disabled' ?>>
                                <option value="-">-</option>
                                <?php if ($isLargeDay): ?>
                                    <?php foreach ($coursesByDay[$day] as $c): ?>
                                        <option value="<?= htmlspecialchars($c) ?>" <?= ($currentCourse === $c) ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
                                    <?php endforeach; ?>
                                    <?php foreach (['公休', '有給'] as $h): ?>
                                        <option value="<?= $h ?>" <?= ($currentCourse === $h) ? 'selected' : '' ?>><?= $h ?></option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <div style="margin-top: 20px; text-align: center;">
                    <button type="submit" class="btn-submit">💾 保存</button>
                    <a href="driver_regist_large.php" class="btn-cancel">キャンセル</a>
                </div>
            </form>
        </div>
    <?php endif; ?>
    
    <div class="driver-list">
        <h3>大型配送対象ドライバー一覧（<?= count($largeDrivers) ?>名）</h3>
        
        <div class="legend">
            <div class="legend-item"><div class="legend-box legend-na"></div> 対象外</div>
            <div class="legend-item"><div class="legend-box legend-needs"></div> 要設定</div>
            <div class="legend-item"><div class="legend-box legend-set"></div> 設定済み</div>
        </div>
        
        <?php if (empty($largeDrivers)): ?>
            <p style="text-align: center; color: #666; padding: 30px;">
                大型配送対象のドライバーがいません。<br>
                <a href="driver_regist.php">店舗配送ドライバー登録画面</a>で「大型専任」または「兼任」に設定してください。
            </p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th class="name-col">名前</th>
                        <th class="id-col">番号</th>
                        <th class="type-col">区分</th>
                        <?php foreach ($dayMap as $v): ?><th class="day-col"><?= mb_substr($v, 0, 1) ?></th><?php endforeach; ?>
                        <th class="actions-col">操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($largeDrivers as $id => $d): 
                        $deliveryType = $d['delivery_type'] ?? 'shop';
                        $deliveryLabel = ($deliveryType === 'large') ? '大型' : '兼任';
                        $largeDays = $d['large_days'] ?? [];
                    ?>
                        <tr>
                            <td class="name-col"><?= htmlspecialchars($d['name'] ?? '') ?></td>
                            <td class="id-col"><?= htmlspecialchars($d['personal_id'] ?? '') ?></td>
                            <td class="type-col"><?= $deliveryLabel ?></td>
                            <?php foreach ($englishDays as $day): 
                                $isLargeDay = in_array($day, $largeDays);
                                $assignedCourse = $largeCourses[$id][$day] ?? '-';
                                
                                if (!$isLargeDay) {
                                    $cellClass = 'cell-na';
                                    $cellText = '－';
                                } elseif ($assignedCourse === '-' || $assignedCourse === '') {
                                    $cellClass = 'cell-needs';
                                    $cellText = '要設定';
                                } else {
                                    $cellClass = 'cell-set';
                                    $cellText = $assignedCourse;
                                }
                            ?>
                                <td class="day-col <?= $cellClass ?>" title="<?= htmlspecialchars($cellText) ?>"><?= htmlspecialchars($cellText) ?></td>
                            <?php endforeach; ?>
                            <td class="actions-col">
                                <a href="?edit_id=<?= htmlspecialchars($id) ?>" class="btn-edit">編集</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    
    <div class="navigation-links">
        <a href="index.html">🤖 TOPページ</a>
        <a href="driver_regist.php">👨‍✈️ 店舗配送ドライバー登録</a>
        <a href="course_regist_large.php">🗺️ 大型コースマスター</a>
        <a href="pc_schedule_large.php">📅 大型週間スケジュール</a>
    </div>
</div>
</body>
</html>
