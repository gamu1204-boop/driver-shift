<?php
// PHP設定: タイムゾーンを日本に設定
date_default_timezone_set('Asia/Tokyo');

// 共通関数を読み込み
require_once 'calendar_functions.php';

// ファイルパス
$driversFile = 'drivers.json';
$coursesFile = 'courses.json';

// エラー/メッセージ格納用
$message = '';
$drivers = [];
$courses = [];

// 会社カレンダーを読み込み
$companyCalendar = loadCompanyCalendar();

// ===========================================
// 設定オプション
// ===========================================
$jobTypeOptions = ['driver' => 'ドライバー', 'office' => '事務', 'other' => 'その他'];
$statusOptions = ['fulltime' => '正社員', 'contract' => '契約社員', 'entrusted' => '委託社員', 'part-time' => 'パート・アルバイト'];
$licenseOptions = ['large' => '大型', 'medium' => '中型', 'normal' => '普通'];

// 配送区分オプション
$deliveryTypeOptions = ['shop' => '店舗専任', 'large' => '大型専任', 'both' => '兼任'];

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
$holidayOptions = ['公休', '有給', '同乗', '大型配送', 'その他'];

// ===========================================
// ファイルからの安全なデータ読み込み関数（ID自動修復機能搭載）
// ===========================================
function loadDrivers(string $filename): array {
    $data = [];
    if (file_exists($filename) && filesize($filename) > 0) {
        $jsonContent = @file_get_contents($filename);
        if ($jsonContent !== false && trim($jsonContent) !== '') {
            $decodedData = json_decode($jsonContent, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decodedData)) {
                $data = $decodedData;
            }
        }
    }
    
    $repairedData = [];
    $needsRepair = false;

    foreach ($data as $key => $driver) {
        $driverId = $driver['id'] ?? $key;
        if (empty($key) || empty($driverId)) {
            $newId = uniqid('driver_repair_');
            $driver['id'] = $newId;
            $repairedData[$newId] = $driver;
            $needsRepair = true;
        } else {
            $repairedData[$key] = $driver;
        }
    }

    if ($needsRepair) {
        @file_put_contents($filename, json_encode($repairedData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }
    
    return $repairedData;
}

// ===========================================
// 1. データ読み込みとソート
// ===========================================
$allDrivers = loadDrivers($driversFile);

$activeDrivers = [];
foreach ($allDrivers as $driverId => $driver) {
    if (!($driver['is_deleted'] ?? false)) {
        $activeDrivers[$driverId] = $driver;
    }
}

// ソート順：表示(1)を上に、非表示(0)を下に。その中で個人番号昇順。
uasort($activeDrivers, function($a, $b) {
    $statA = $a['is_active'] ?? 1;
    $statB = $b['is_active'] ?? 1;
    if ($statA !== $statB) {
        return ($statA > $statB) ? -1 : 1;
    }
    $idA = floatval($a['personal_id'] ?? 0);
    $idB = floatval($b['personal_id'] ?? 0);
    if ($idA === $idB) return 0;
    return ($idA < $idB) ? -1 : 1;
});

$drivers = $activeDrivers;

// 曜日別のコースリストを作成（コース→休日オプションの順）
$coursesByDay = [];
foreach ($englishDays as $day) {
    $coursesByDay[$day] = [];
}

if (file_exists($coursesFile)) {
    $rawCourses = json_decode(@file_get_contents($coursesFile), true) ?? [];
    if (is_array($rawCourses)) {
        foreach ($rawCourses as $day => $dayCourses) {
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
}

// コースの後に休日オプションを追加
foreach ($englishDays as $day) {
    foreach ($holidayOptions as $h) {
        if (!in_array($h, $coursesByDay[$day])) {
            $coursesByDay[$day][] = $h;
        }
    }
}

// ===========================================
// 2. POST処理 (登録/更新/削除)
// ===========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $allDriversToSave = loadDrivers($driversFile);

    if (isset($_POST['delete_id'])) {
        $deleteId = $_POST['delete_id'];
        if (isset($allDriversToSave[$deleteId])) {
            $allDriversToSave[$deleteId]['is_deleted'] = true;
            file_put_contents($driversFile, json_encode($allDriversToSave, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        }
        header("Location: driver_regist.php");
        exit;
    }

    $driverId = trim($_POST['driver_id'] ?? '') ?: uniqid('driver_');
    $name = trim($_POST['name'] ?? '');
    $personalId = trim($_POST['personal_id'] ?? '');
    $isActive = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;
    $deliveryType = $_POST['delivery_type'] ?? 'shop';

    if (!empty($name) && !empty($personalId)) {
        $newCourses = [];
        foreach ($englishDays as $day) {
            $newCourses[$day] = ['course' => trim($_POST['course_day'][$day] ?? '-')];
        }
        
        $allDriversToSave[$driverId] = [
            'id' => $driverId,
            'name' => $name,
            'personal_id' => $personalId,
            'job_type' => $_POST['job_type'] ?? '',
            'status' => $_POST['status'] ?? '',
            'license' => $_POST['license'] ?? '',
            'delivery_type' => $deliveryType,
            'courses' => $newCourses,
            'is_deleted' => false,
            'is_active' => $isActive
        ];
        
        file_put_contents($driversFile, json_encode($allDriversToSave, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        header("Location: driver_regist.php");
        exit;
    }
}

$editDriver = (isset($_GET['edit_id']) && isset($allDrivers[$_GET['edit_id']])) ? $allDrivers[$_GET['edit_id']] : null;
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ドライバー情報登録・管理</title>
    <style>
        body { font-family: 'メイリオ', Meiryo, sans-serif; background-color: #f4f4f4; margin: 0; padding: 10px; }
        .container { max-width: 100%; width: 100%; margin: 0 auto; background: white; padding: 15px; border-radius: 8px; box-shadow: 0 0 10px rgba(0, 0, 0, 0.1); box-sizing: border-box; }
        h2 { color: #333; border-bottom: 2px solid #ccc; padding-bottom: 10px; margin-bottom: 20px; }
        
        .calendar-notice {
            background-color: #e7f3ff;
            border-left: 4px solid #007bff;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 5px;
        }
        
        .calendar-notice strong { color: #0056b3; }
        .calendar-notice a { color: #007bff; text-decoration: underline; }
        
        .form-area, .driver-list { margin-bottom: 30px; padding: 15px; border: 1px solid #ddd; border-radius: 6px; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; }
        input[type="text"], select { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; max-width: 500px; }
        .course-settings { display: grid; grid-template-columns: repeat(auto-fit, minmax(100px, 1fr)); gap: 10px; padding: 10px 0; border-top: 1px dashed #ccc; }
        .course-settings select { width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-size: 0.9em; }
        .btn-submit { background-color: #007bff; color: white; padding: 10px 15px; border: none; border-radius: 4px; cursor: pointer; font-weight: bold; }
        .btn-cancel { background-color: #f8f9fa; color: #333; border: 1px solid #ccc; text-decoration: none; padding: 10px 15px; border-radius: 4px; display: inline-block; }

        .driver-list { overflow-x: hidden; }
        .driver-list table { width: 100%; border-collapse: collapse; font-size: 0.75em; table-layout: fixed; }
        .driver-list th, .driver-list td { border: 1px solid #ddd; padding: 4px 2px; }
        .driver-list th { background-color: #e9ecef; text-align: center; }

        .name-col { width: 8%; text-align: center; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .id-col { width: 7%; text-align: center; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .status-col { width: 5%; text-align: center; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .actions { width: 7%; text-align: center; white-space: nowrap; }
        .course-col { width: 10.4%; text-align: center; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .course-display { text-align: center; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        
        .btn-edit { padding: 3px 6px; font-size: 0.9em; }
        .btn-delete { padding: 3px 6px; font-size: 0.9em; }

        .row-inactive { background-color: #e9e9e9; color: #777; }

        .btn-edit { background-color: #ffc107; color: #333; padding: 5px 8px; text-decoration: none; border-radius: 4px; font-weight: bold; }
        .btn-delete { background-color: #dc3545; color: white; padding: 5px 8px; border: none; border-radius: 4px; cursor: pointer; }
        .status-toggle-area { display: inline-block; margin-right: 20px; padding: 8px 15px; background: #fff3cd; border-radius: 4px; vertical-align: middle; }
        
        .large-delivery { background-color: #e3f2fd !important; color: #1565c0; font-weight: bold; }
    </style>
</head>
<body>
<div class="container">
    <h2>ドライバー情報登録・管理</h2>
    
    <?php if (!empty($companyCalendar['company_name'])): ?>
        <div class="calendar-notice">
            <strong>📅 会社カレンダー設定済み:</strong> <?= htmlspecialchars($companyCalendar['company_name']) ?>
            <br>
            <?php if (!empty($companyCalendar['weekly_holidays'])): ?>
                定休曜日が設定されています。シフト自動生成時に自動的に「公休」が設定されます。
            <?php else: ?>
                365日営業として設定されています。
            <?php endif; ?>
            <br>
            <a href="company_calendar.php">📅 会社カレンダーを編集</a>
        </div>
    <?php else: ?>
        <div class="calendar-notice" style="background-color: #fff3cd; border-left-color: #ffc107;">
            <strong>ℹ️ 会社カレンダー未設定:</strong> 
            会社全体の休業日を設定すると、シフト作成時に自動的に反映されます。
            <br>
            <a href="company_calendar.php">📅 会社カレンダーを設定する</a>
        </div>
    <?php endif; ?>
    
    <div class="form-area">
        <h3><?= $editDriver ? 'ドライバー情報編集' : '新規ドライバー登録' ?></h3>
        <form method="POST">
            <input type="hidden" name="driver_id" value="<?= htmlspecialchars($editDriver['id'] ?? '') ?>">
            
            <div class="form-group">
                <label for="name">ドライバー名 *必須</label>
                <input type="text" id="name" name="name" value="<?= htmlspecialchars($editDriver['name'] ?? '') ?>" required>
            </div>
            
            <div class="form-group">
                <label for="personal_id">個人番号 *必須</label>
                <input type="text" id="personal_id" name="personal_id" value="<?= htmlspecialchars($editDriver['personal_id'] ?? '') ?>" required>
            </div>

            <div class="form-group">
                <label for="job_type">職種</label>
                <select id="job_type" name="job_type">
                    <option value="">選択してください</option>
                    <?php foreach ($jobTypeOptions as $key => $value): ?>
                        <option value="<?php echo htmlspecialchars($key); ?>" 
                                <?php echo ($editDriver['job_type'] ?? '') === $key ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($value); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="status">雇用形態</label>
                <select id="status" name="status">
                    <option value="">選択してください</option>
                    <?php foreach ($statusOptions as $key => $value): ?>
                        <option value="<?php echo htmlspecialchars($key); ?>" 
                                <?php echo ($editDriver['status'] ?? '') === $key ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($value); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="license">保有免許</label>
                <select id="license" name="license">
                    <option value="">選択してください</option>
                    <?php foreach ($licenseOptions as $key => $value): ?>
                        <option value="<?php echo htmlspecialchars($key); ?>" 
                                <?php echo ($editDriver['license'] ?? '') === $key ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($value); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label for="delivery_type">配送区分 *</label>
                <select id="delivery_type" name="delivery_type" onchange="updateCourseOptions()">
                    <?php foreach ($deliveryTypeOptions as $key => $value): ?>
                        <option value="<?php echo htmlspecialchars($key); ?>" 
                                <?php echo ($editDriver['delivery_type'] ?? 'shop') === $key ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($value); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p style="font-size: 0.85em; color: #666; margin-top: 5px;">
                    店舗専任: 店舗配送コースのみ / 大型専任: 大型配送画面で設定 / 兼任: 曜日ごとに「大型配送」選択可
                </p>
            </div>
            
            <div class="form-group">
                <label style="margin-top: 15px;">デフォルトコース設定</label>
                <p style="font-size: 0.9em; color: #666;">※会社カレンダーで休業日が設定されている場合、シフト生成時に自動的に「公休」となります</p>
                <p id="large-only-notice" style="font-size: 0.9em; color: #dc3545; display: none;">※大型専任のため、コース設定は大型配送画面で行ってください</p>
                <div class="course-settings" id="course-settings">
                    <?php foreach ($englishDays as $day): $current = $editDriver['courses'][$day]['course'] ?? ''; ?>
                        <div>
                            <label style="font-weight: normal;"><?= $dayMap[$day] ?></label>
                            <select name="course_day[<?= $day ?>]" class="course-select" data-day="<?= $day ?>">
                                <option value="-" <?= ($current === '-' || $current === '') ? 'selected' : '' ?>>-</option>
                                <?php foreach ($coursesByDay[$day] as $c): ?>
                                    <option value="<?= htmlspecialchars($c) ?>" <?= ($current === $c) ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div style="margin-top: 20px;">
                <?php if ($editDriver): ?>
                    <div class="status-toggle-area">
                        <label style="display:inline; cursor:pointer;"><input type="radio" name="is_active" value="1" <?= ($editDriver['is_active'] ?? 1) == 1 ? 'checked' : '' ?>> 表示</label>
                        <label style="display:inline; cursor:pointer; margin-left:15px;"><input type="radio" name="is_active" value="0" <?= ($editDriver['is_active'] ?? 1) == 0 ? 'checked' : '' ?>> 非表示</label>
                    </div>
                <?php endif; ?>
                <button type="submit" class="btn-submit"><?= $editDriver ? '更新' : '登録' ?></button>
                <?php if ($editDriver): ?><a href="driver_regist.php" class="btn-cancel">キャンセル</a><?php endif; ?>
            </div>
        </form>

        <br>
        <div class="navigation-links" style="text-align: center;">
            <a href="index.html" target="_blank">🤖 TOPページ</a>	
            <a href="pc_schedule.php" target="_blank">📅 週間スケジュール管理</a>
            <a href="driver_regist.php" target="_blank">👨‍✈️ ドライバー登録</a>
            <a href="course_regist.php" target="_blank">🗺️ コースマスター管理</a>
            <a href="vehicle_regist.php" target="_blank">🚚 車両マスター管理</a>
            <a href="company_calendar.php" target="_blank">📅 会社カレンダー設定</a>
        </div>
    </div>

    <div class="driver-list">
        <h3>登録済みのドライバー一覧</h3>
        <div style="overflow-x: auto;">
            <table>
                <thead>
                    <tr>
                        <th class="name-col">名前</th>
                        <th class="id-col">個人番号</th>
                        <th class="status-col">区分</th>
                        <th class="status-col">状態</th>
                        <?php foreach ($dayMap as $v): ?><th class="course-col"><?= mb_substr($v, 0, 1) ?></th><?php endforeach; ?>
                        <th class="actions">操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($drivers as $id => $d): 
                        $isActive = ($d['is_active'] ?? 1) == 1;
                        $rowClass = $isActive ? '' : 'row-inactive';
                        $deliveryType = $d['delivery_type'] ?? 'shop';
                        $deliveryLabel = ['shop' => '店舗', 'large' => '大型', 'both' => '兼任'][$deliveryType] ?? '店舗';
                    ?>
                        <tr class="<?= $rowClass ?>">
                            <td class="name-col" title="<?= htmlspecialchars($d['name'] ?? '') ?>"><?= htmlspecialchars($d['name'] ?? '') ?></td>
                            <td class="id-col" title="<?= htmlspecialchars($d['personal_id'] ?? '') ?>"><?= htmlspecialchars($d['personal_id'] ?? '') ?></td>
                            <td class="status-col"><?= $deliveryLabel ?></td>
                            <td class="status-col"><?= $isActive ? '表示' : '<span style="color:red;">非表示</span>' ?></td>
                            <?php foreach ($englishDays as $key): $c = $d['courses'][$key]['course'] ?? '-'; ?>
                                <td class="course-col <?= $c === '大型配送' ? 'large-delivery' : '' ?>" title="<?= htmlspecialchars($c) ?>"><?= ($c === '-' || empty($c)) ? '－' : htmlspecialchars($c) ?></td>
                            <?php endforeach; ?>
                            <td class="actions">
                                <a href="?edit_id=<?= htmlspecialchars($id) ?>" class="btn-edit">編集</a>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('削除しますか？');">
                                    <input type="hidden" name="delete_id" value="<?= htmlspecialchars($id) ?>">
                                    <button type="submit" class="btn-delete">削除</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
function updateCourseOptions() {
    const deliveryType = document.getElementById('delivery_type').value;
    const courseSettings = document.getElementById('course-settings');
    const largeOnlyNotice = document.getElementById('large-only-notice');
    const selects = document.querySelectorAll('.course-select');
    
    if (deliveryType === 'large') {
        // 大型専任: コース設定を無効化
        courseSettings.style.opacity = '0.5';
        largeOnlyNotice.style.display = 'block';
        selects.forEach(select => {
            select.disabled = true;
        });
    } else {
        // 店舗専任 or 兼任: コース設定を有効化
        courseSettings.style.opacity = '1';
        largeOnlyNotice.style.display = 'none';
        selects.forEach(select => {
            select.disabled = false;
            
            // 「大型配送」オプションの表示/非表示
            const largeOption = select.querySelector('option[value="大型配送"]');
            if (deliveryType === 'both') {
                // 兼任: 大型配送オプションを表示（既に存在しなければ追加）
                if (!largeOption) {
                    const option = document.createElement('option');
                    option.value = '大型配送';
                    option.textContent = '大型配送';
                    // 公休の後に追加
                    const publicHolidayOption = select.querySelector('option[value="公休"]');
                    if (publicHolidayOption) {
                        publicHolidayOption.after(option);
                    } else {
                        select.appendChild(option);
                    }
                }
            } else {
                // 店舗専任: 大型配送オプションを非表示
                if (largeOption) {
                    // 現在大型配送が選択されていたら-に戻す
                    if (select.value === '大型配送') {
                        select.value = '-';
                    }
                    largeOption.remove();
                }
            }
        });
    }
}

// ページ読み込み時に実行
document.addEventListener('DOMContentLoaded', updateCourseOptions);
</script>
</body>
</html>