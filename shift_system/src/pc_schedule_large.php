<?php
ob_start(); 
date_default_timezone_set('Asia/Tokyo');

// ファイルパス定義
$scheduleFile = 'schedule_large.json'; 
$driversFile  = 'drivers.json';
$coursesFile  = 'courses_large.json'; 
$vehiclesFile = 'vehicles.json';
$calendarFile = 'company_calendar.json';

function loadJsonData($filename) {
    if (file_exists($filename) && filesize($filename) > 0) {
        return json_decode(file_get_contents($filename), true) ?: [];
    }
    return [];
}

// 会社カレンダーを読み込む
function loadCompanyCalendar() {
    global $calendarFile;
    if (file_exists($calendarFile)) {
        $data = json_decode(@file_get_contents($calendarFile), true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
            return $data;
        }
    }
    return [
        'company_name' => '',
        'weekly_holidays' => [],
        'special_holidays' => [],
        'working_days' => []
    ];
}

// 指定日が営業日かどうかを判定
function isCompanyWorkingDay($date, $calendar) {
    $dayOfWeek = strtolower(date('l', strtotime($date)));
    
    // 特別出勤日
    if (in_array($date, $calendar['working_days'])) {
        return true;
    }
    
    // 特別休業日
    if (in_array($date, $calendar['special_holidays'])) {
        return false;
    }
    
    // 定休曜日
    if (in_array($dayOfWeek, $calendar['weekly_holidays'])) {
        return false;
    }
    
    return true;
}

/**
 * 【核心】データが「KT1002群馬」でも「KT1002 群馬」でも、
 * 常に「KT1002 群馬」として扱うための正規化関数
 */
function normalizeCourseName($name) {
    $name = trim($name);
    if ($name === '-' || $name === '' || $name === '同乗' || $name === '有給' || $name === 'その他') return $name;
    // 一旦スペースを抜き、英数字と日本語の間に強制的に半角スペースを1つ入れる
    $temp = str_replace(' ', '', $name);
    return preg_replace('/^([A-Z0-9]+)([^\s0-9].*)$/u', '$1 $2', $temp);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_schedule_all'])) {
    $jsonData = json_decode($_POST['schedule_data'], true);
    if ($jsonData !== null) {
        file_put_contents($scheduleFile, json_encode($jsonData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        echo "<script>location.href='pc_schedule_large.php?saved=1';</script>";
        exit;
    }
}

$allDrivers   = loadJsonData($driversFile);
$scheduleData = loadJsonData($scheduleFile);
$vehiclesRaw  = loadJsonData($vehiclesFile);
$coursesRaw   = loadJsonData($coursesFile);
$companyCalendar = loadCompanyCalendar();
$largeCoursesData = loadJsonData('driver_large_courses.json'); // 大型コース割当データ

// 車両ID -> プレート番号マップ
$vMap = [];
foreach ($vehiclesRaw as $vid => $v) { $vMap[$vid] = $v['plate'] ?? ''; }

// コースマスター作成（表示形式の「スペースあり」をキーにして車両番号を紐付け）
$masterCourseMap = [];
if (is_array($coursesRaw)) {
    foreach ($coursesRaw as $dayList) {
        if (is_array($dayList)) {
            foreach ($dayList as $c) {
                $cName = normalizeCourseName($c['name'] ?? '');
                if ($cName !== '' && $cName !== '-') {
                    $masterCourseMap[$cName] = $vMap[$c['vehicle_id'] ?? ''] ?? '';
                }
            }
        }
    }
}

// 大型配送対象ドライバーのみ抽出（大型専任 or 兼任で「大型配送」設定あり）
$englishDays = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
$activeDrivers = [];
foreach ($allDrivers as $driverId => $driver) {
    if ($driver['is_deleted'] ?? false) continue;
    if (($driver['is_active'] ?? 1) != 1) continue;
    
    $deliveryType = $driver['delivery_type'] ?? 'shop';
    
    if ($deliveryType === 'large') {
        // 大型専任: 対象
        $activeDrivers[$driverId] = $driver;
        $activeDrivers[$driverId]['large_days'] = $englishDays;
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
            $activeDrivers[$driverId] = $driver;
            $activeDrivers[$driverId]['large_days'] = $largeDays;
        }
    }
}
uasort($activeDrivers, function($a, $b) { return (int)($a['personal_id'] ?? 0) <=> (int)($b['personal_id'] ?? 0); });

$y = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$m = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$d = isset($_GET['day']) ? (int)$_GET['day'] : (int)date('d');
$baseDate = new DateTime(); $baseDate->setDate($y, $m, $d);
$monday = clone $baseDate; if ($monday->format('N') != 1) $monday->modify('last monday');

$dates = [];
for ($i = 0; $i < 7; $i++) {
    $tmpDate = clone $monday; $tmpDate->modify("+$i days");
    $dateStr = $tmpDate->format('Y-m-d');
    $isWorking = isCompanyWorkingDay($dateStr, $companyCalendar);
    $dates[] = [
        'date' => $dateStr, 
        'display' => $tmpDate->format('n月j日'), 
        'dayOfWeek' => ['月','火','水','木','金','土','日'][$i], 
        'dayKey' => strtolower($tmpDate->format('l')),
        'is_working' => $isWorking
    ];
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>大型車週間スケジュール管理</title>
    <style>
        body { font-family: 'メイリオ', sans-serif; background-color: #f4f4f4; padding: 20px; }
        .container { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 0 10px rgba(0,0,0,0.1); position: relative; }
        .navigation { margin-bottom: 20px; text-align: center; }
        .navigation a { text-decoration: none; color: #007bff; padding: 5px 10px; border: 1px solid #007bff; border-radius: 4px; }
        .schedule-table { width: 100%; border-collapse: collapse; margin-top: 20px; table-layout: fixed; }
        .schedule-table th, .schedule-table td { border: 1px solid #ddd; padding: 8px; text-align: center; }
        .schedule-table th.company-holiday { background-color: #ffcccc; }
        .driver-col { width: 120px; font-weight: bold; background: #f9f9f9; }
        .drop-zone { min-height: 70px; background-color: #fdfdfd; border: 1px dashed #bbb; border-radius: 4px; padding: 5px; }
        .drop-zone.not-target { background-color: #f0f0f0; border: 1px solid #ddd; }
        .drop-zone.needs-setup { background-color: #ffebee; border: 2px solid #f44336; }
        .needs-setup-label { 
            background-color: #f44336; 
            color: white; 
            padding: 5px 10px; 
            border-radius: 3px; 
            font-size: 11px; 
            font-weight: bold;
            display: inline-block;
            animation: pulse 1.5s infinite;
        }
        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.6; }
            100% { opacity: 1; }
        }
        .course-item { background-color: #1565c0; color: white; padding: 4px 8px; margin: 2px; border-radius: 3px; cursor: move; display: inline-block; font-size: 11px; }
        .v-input { width: 90%; font-size: 12px; text-align: center; margin-top: 5px; border: 1px solid #ccc; padding: 2px; border-radius: 3px; }
        .btn-save { background-color: #1565c0; color: white; padding: 12px 50px; border: none; border-radius: 4px; cursor: pointer; font-size: 18px; font-weight: bold; }
        .btn-save:hover { background-color: #0d47a1; }
        .escape-area { position: fixed; top: 10px; right: 20px; width: 200px; background-color: rgba(227, 242, 253, 0.98); border: 2px solid #1565c0; padding: 10px; border-radius: 10px; z-index: 9999; }
        .quick-items { display: flex; flex-wrap: wrap; gap: 4px; }
        .quick-item { background-color: #6c757d; font-size: 10px; padding: 3px 6px; }
        .quick-item#quick-holiday { background-color: #dc3545; }
        .quick-item#quick-paid { background-color: #28a745; }
        .quick-item#quick-ride { background-color: #fd7e14; }
        .quick-item#quick-other { background-color: #6c757d; }
        
        .legend { display: flex; gap: 15px; margin: 15px 0; padding: 10px; background: #f8f9fa; border-radius: 5px; flex-wrap: wrap; font-size: 0.85em; }
        .legend-item { display: flex; align-items: center; gap: 5px; }
        .legend-box { width: 18px; height: 18px; border-radius: 3px; }
    </style>
</head>
<body>
<div class="container">
    <h2>🚛 大型車週間スケジュール管理</h2>
    <div class="navigation">
        <a href="pc_schedule_large.php?year=<?= (clone $monday)->modify('-7 days')->format('Y') ?>&month=<?= (clone $monday)->modify('-7 days')->format('m') ?>&day=<?= (clone $monday)->modify('-7 days')->format('d') ?>">前の週</a>
        <strong style="margin: 0 20px;"><?= $monday->format('Y/m/d') ?> 〜 <?= (clone $monday)->modify('+6 days')->format('Y/m/d') ?></strong>
        <a href="pc_schedule_large.php?year=<?= (clone $monday)->modify('+7 days')->format('Y') ?>&month=<?= (clone $monday)->modify('+7 days')->format('m') ?>&day=<?= (clone $monday)->modify('+7 days')->format('d') ?>">次の週</a>
    </div>

    <?php if (empty($activeDrivers)): ?>
        <div style="text-align: center; padding: 50px; color: #666;">
            <p>大型配送対象のドライバーがいません。</p>
            <p><a href="driver_regist.php">店舗配送ドライバー登録画面</a>で「大型専任」または「兼任」に設定してください。</p>
        </div>
    <?php else: ?>
    
    <div class="legend">
        <div class="legend-item"><div class="legend-box" style="background-color: #1565c0;"></div><span>コース設定済み</span></div>
        <div class="legend-item"><div class="legend-box" style="background-color: #ffebee; border: 2px solid #f44336;"></div><span>要設定（大型配送対象）</span></div>
        <div class="legend-item"><div class="legend-box" style="background-color: #f0f0f0;"></div><span>対象外（店舗配送日）</span></div>
        <div class="legend-item"><div class="legend-box" style="background-color: #ffcccc;"></div><span>会社休業日</span></div>
    </div>

    <div class="escape-area">
        <p style="font-size:11px; margin:0 0 8px 0; font-weight:bold;">📦 一時置き場・よく使う項目</p>
        <div class="quick-items">
            <div class="course-item quick-item" draggable="true" ondragstart="drag(event)" id="quick-holiday">公休</div>
            <div class="course-item quick-item" draggable="true" ondragstart="drag(event)" id="quick-paid">有給</div>
            <div class="course-item quick-item" draggable="true" ondragstart="drag(event)" id="quick-ride">同乗</div>
            <div class="course-item quick-item" draggable="true" ondragstart="drag(event)" id="quick-other">その他</div>
        </div>
        <div class="drop-zone" id="escape-zone" ondrop="drop(event)" ondragover="allowDrop(event)" style="margin-top:8px; min-height:40px;">
            <p style="font-size:10px; margin:0; color:#888;">ドロップエリア</p>
        </div>
    </div>

    <form id="schedule-form" method="POST">
        <input type="hidden" name="schedule_data" id="schedule-data-input">
        <input type="hidden" name="update_schedule_all" value="1">
        <table class="schedule-table">
            <thead>
                <tr>
                    <th class="driver-col">ドライバー</th>
                    <?php foreach ($dates as $day): ?>
                        <th <?= !$day['is_working'] ? 'class="company-holiday"' : '' ?>><?= $day['display'] ?>(<?= $day['dayOfWeek'] ?>)</th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($activeDrivers as $driverId => $driver): 
                    $largeDays = $driver['large_days'] ?? [];
                ?>
                <tr>
                    <td class="driver-col"><?= htmlspecialchars($driver['name'] ?? '') ?></td>
                    <?php foreach ($dates as $day): 
                        $dKey = $day['date'];
                        $saved = $scheduleData[$driverId][$dKey] ?? null;
                        $isLargeDay = in_array($day['dayKey'], $largeDays);
                        
                        // 大型配送対象外の曜日
                        if (!$isLargeDay) {
                            $rawCourse = '-';
                            $cellClass = 'drop-zone not-target';
                            $needsSetup = false;
                        // 会社が休業日の場合は自動的に「公休」
                        } elseif (!$day['is_working'] && !$saved) {
                            $rawCourse = '公休';
                            $cellClass = 'drop-zone';
                            $needsSetup = false;
                        } else {
                            // 保存データ → 大型コース割当データ → デフォルト
                            $rawCourse = $saved['course'] ?? ($largeCoursesData[$driverId][$day['dayKey']] ?? '-');
                            $needsSetup = ($rawCourse === '-' || $rawCourse === '');
                            $cellClass = 'drop-zone' . ($needsSetup ? ' needs-setup' : '');
                        }
                        
                        // データがスペースなし（KT1002群馬）でも、強制的に正規化して表示
                        $courseName = normalizeCourseName($rawCourse);
                        
                        // 正規化した名前でマスターから車両番号を取得
                        $plateNo = $saved['vehicle'] ?? ($masterCourseMap[$courseName] ?? '');
                    ?>
                    <td class="<?= $cellClass ?>" data-driver-id="<?= $driverId ?>" data-date="<?= $dKey ?>" ondrop="drop(event)" ondragover="allowDrop(event)">
                        <?php if ($needsSetup): ?>
                            <div class="needs-setup-label">要設定</div>
                        <?php elseif ($courseName !== '-' && $courseName !== ''): ?>
                            <div class="course-item" draggable="true" ondragstart="drag(event)" id="item-<?= $driverId ?>-<?= str_replace('-','',$dKey) ?>"><?= htmlspecialchars($courseName) ?></div>
                        <?php endif; ?>
                        <input type="text" class="v-input" value="<?= htmlspecialchars($plateNo) ?>">
                    </td>
                    <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <div style="text-align: center; margin-top: 30px;"><button type="button" onclick="saveAll()" class="btn-save">保存する</button></div>
    </form>
    
    <?php endif; ?>
    					
<br>					
        <div class="navigation-links" style="text-align: center;">
            <a href="index.html" target="_blank">🤖 TOPページ</a>	
            <a href="pc_schedule_large.php" target="_blank">📅 大型車週間スケジュール</a>
            <a href="driver_regist_large.php" target="_blank">👨‍✈️ 大型車ドライバー設定</a>
            <a href="course_regist_large.php" target="_blank">🗺️ 大型車コースマスター</a>
            <a href="vehicle_regist.php" target="_blank">🚚 車両マスター管理</a>
        </div>
				
    					
    					
</div>

<script>
const MASTER_MAP = <?= json_encode($masterCourseMap, JSON_UNESCAPED_UNICODE) ?>;
window.onload = function() {
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('saved') === '1') alert("保存完了しました。");
};
function allowDrop(ev) { ev.preventDefault(); }
function drag(ev) { ev.dataTransfer.setData("text", ev.target.id); }
function drop(ev) {
    ev.preventDefault();
    const itemId = ev.dataTransfer.getData("text");
    const draggedElement = document.getElementById(itemId);
    if (!draggedElement) return;
    
    let targetZone = ev.target;
    if (!targetZone.classList.contains('drop-zone')) targetZone = targetZone.closest('.drop-zone');
    if (!targetZone) return;
    
    // 固定アイテム（quick-で始まるID）はコピーする
    const isQuickItem = itemId.startsWith('quick-');
    const sourceZone = draggedElement.closest('.drop-zone');
    const escapeZone = document.getElementById('escape-zone');
    
    // ターゲットが一時置き場の場合はそのまま移動
    if (targetZone.id === 'escape-zone') {
        if (!isQuickItem) {
            targetZone.appendChild(draggedElement);
            // 元のセルの車両番号をクリア
            if (sourceZone && sourceZone.id !== 'escape-zone') {
                const input = sourceZone.querySelector('.v-input');
                if (input) input.value = '';
            }
        }
        return;
    }
    
    // ターゲットセルの既存アイテム（固定アイテム以外）を取得
    const targetExistingItem = targetZone.querySelector('.course-item:not(.quick-item)');
    
    // 要設定ラベルを削除
    const needsSetupLabel = targetZone.querySelector('.needs-setup-label');
    if (needsSetupLabel) {
        needsSetupLabel.remove();
    }
    // セルのクラスを更新
    targetZone.classList.remove('needs-setup');
    
    if (isQuickItem) {
        // 固定アイテムの場合：コピーを作成、元のコースは一時置き場へ
        if (targetExistingItem) {
            escapeZone.appendChild(targetExistingItem);
        }
        
        const newItem = document.createElement('div');
        newItem.className = 'course-item';
        newItem.draggable = true;
        newItem.ondragstart = drag;
        newItem.id = 'item-' + Date.now();
        newItem.innerText = draggedElement.innerText;
        targetZone.insertBefore(newItem, targetZone.querySelector('.v-input'));
    } else {
        // 通常アイテムの場合：移動
        // ソースが一時置き場の場合
        if (sourceZone && sourceZone.id === 'escape-zone') {
            // ターゲットに既存アイテムがあれば一時置き場へ
            if (targetExistingItem) {
                escapeZone.appendChild(targetExistingItem);
            }
            targetZone.insertBefore(draggedElement, targetZone.querySelector('.v-input'));
        } else {
            // ソースもセルの場合：入れ替え
            if (targetExistingItem && sourceZone) {
                sourceZone.insertBefore(targetExistingItem, sourceZone.querySelector('.v-input'));
            }
            targetZone.insertBefore(draggedElement, targetZone.querySelector('.v-input'));
            
            // ソースセルの車両番号を更新
            if (sourceZone) {
                const sourceInput = sourceZone.querySelector('.v-input');
                const sourceItem = sourceZone.querySelector('.course-item:not(.quick-item)');
                if (sourceInput) {
                    const cName = sourceItem ? sourceItem.innerText : '-';
                    sourceInput.value = (cName !== '-') ? (MASTER_MAP[cName] || "") : "";
                }
            }
        }
    }
    
    // ターゲットセルの車両番号を更新
    const targetInput = targetZone.querySelector('.v-input');
    const targetItem = targetZone.querySelector('.course-item:not(.quick-item)');
    if (targetInput) {
        const cName = targetItem ? targetItem.innerText : '-';
        targetInput.value = (cName !== '-') ? (MASTER_MAP[cName] || "") : "";
    }
}
function saveAll() {
    if (!confirm("保存しますか？")) return;
    const res = {};
    document.querySelectorAll('td.drop-zone').forEach(z => {
        const dId = z.dataset.driverId;
        const dt = z.dataset.date;
        if (dId && dt) {
            if (!res[dId]) res[dId] = {};
            const input = z.querySelector('.v-input');
            const courseItem = z.querySelector('.course-item:not(.quick-item)');
            res[dId][dt] = { course: courseItem ? courseItem.innerText : '-', vehicle: input ? input.value : '' };
        }
    });
    document.getElementById('schedule-data-input').value = JSON.stringify(res);
    document.getElementById('schedule-form').submit();
}
</script>



</body>
</html>