<?php
date_default_timezone_set('Asia/Tokyo');

$calendarFile = 'company_calendar.json';
$message = '';
$messageType = '';

// カレンダーデータ読み込み
function loadCalendar($file) {
    if (file_exists($file)) {
        $data = json_decode(@file_get_contents($file), true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
            return $data;
        }
    }
    return [
        'company_name' => '',
        'weekly_holidays' => [], // 定休曜日（例: ['sunday', 'saturday']）
        'special_holidays' => [], // 特別休業日（例: ['2025-01-01', '2025-12-31']）
        'working_days' => [] // 特別出勤日（例: ['2025-01-05']）
    ];
}

$calendar = loadCalendar($calendarFile);

// 保存処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_calendar'])) {
    $calendar['company_name'] = trim($_POST['company_name'] ?? '');
    $calendar['weekly_holidays'] = $_POST['weekly_holidays'] ?? [];
    
    // 特別休業日の処理
    $specialHolidays = explode("\n", $_POST['special_holidays'] ?? '');
    $calendar['special_holidays'] = array_filter(array_map('trim', $specialHolidays));
    
    // 特別出勤日の処理
    $workingDays = explode("\n", $_POST['working_days'] ?? '');
    $calendar['working_days'] = array_filter(array_map('trim', $workingDays));
    
    if (file_put_contents($calendarFile, json_encode($calendar, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))) {
        $message = '✅ 会社カレンダーを保存しました';
        $messageType = 'success';
    } else {
        $message = '❌ 保存に失敗しました';
        $messageType = 'error';
    }
}

// 日本の祝日を取得（簡易版）
function getJapaneseHolidays($year) {
    return [
        "$year-01-01" => "元日",
        "$year-01-08" => "成人の日",
        "$year-02-11" => "建国記念の日",
        "$year-02-23" => "天皇誕生日",
        "$year-03-20" => "春分の日",
        "$year-04-29" => "昭和の日",
        "$year-05-03" => "憲法記念日",
        "$year-05-04" => "みどりの日",
        "$year-05-05" => "こどもの日",
        "$year-07-15" => "海の日",
        "$year-08-11" => "山の日",
        "$year-09-16" => "敬老の日",
        "$year-09-23" => "秋分の日",
        "$year-10-14" => "スポーツの日",
        "$year-11-03" => "文化の日",
        "$year-11-23" => "勤労感謝の日"
    ];
}

// 指定日が営業日かどうかを判定
function isWorkingDay($date, $calendar) {
    $dayOfWeek = strtolower(date('l', strtotime($date)));
    
    // 特別出勤日として登録されている場合は営業日
    if (in_array($date, $calendar['working_days'])) {
        return true;
    }
    
    // 特別休業日として登録されている場合は休業日
    if (in_array($date, $calendar['special_holidays'])) {
        return false;
    }
    
    // 定休曜日の場合は休業日
    if (in_array($dayOfWeek, $calendar['weekly_holidays'])) {
        return false;
    }
    
    return true;
}

// カレンダープレビュー用（今月と来月）
$currentYear = date('Y');
$currentMonth = date('n');
$nextMonth = $currentMonth + 1;
$nextYear = $currentYear;
if ($nextMonth > 12) {
    $nextMonth = 1;
    $nextYear++;
}

$dayMap = [
    'monday' => '月', 'tuesday' => '火', 'wednesday' => '水',
    'thursday' => '木', 'friday' => '金', 'saturday' => '土', 'sunday' => '日'
];

$dayNameMap = [
    'monday' => '月曜日', 'tuesday' => '火曜日', 'wednesday' => '水曜日',
    'thursday' => '木曜日', 'friday' => '金曜日', 'saturday' => '土曜日', 'sunday' => '日曜日'
];
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>会社カレンダー設定</title>
    <style>
        body { font-family: 'メイリオ', sans-serif; background-color: #f4f4f4; margin: 0; padding: 20px; }
        .container { max-width: 1000px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 0 15px rgba(0,0,0,0.1); }
        h2 { color: #333; border-bottom: 3px solid #007bff; padding-bottom: 10px; margin-bottom: 25px; }
        h3 { color: #0056b3; margin-top: 30px; margin-bottom: 15px; border-left: 4px solid #007bff; padding-left: 10px; }
        
        .message { padding: 15px; margin-bottom: 20px; border-radius: 5px; font-weight: bold; }
        .message.success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .message.error { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        
        .form-section { background-color: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 25px; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; font-weight: bold; margin-bottom: 8px; color: #333; }
        .form-group input[type="text"] { width: 100%; max-width: 400px; padding: 10px; border: 1px solid #ccc; border-radius: 4px; }
        .form-group textarea { width: 100%; max-width: 600px; padding: 10px; border: 1px solid #ccc; border-radius: 4px; min-height: 120px; font-family: monospace; }
        
        .checkbox-group { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 10px; margin-top: 10px; }
        .checkbox-item { display: flex; align-items: center; }
        .checkbox-item input[type="checkbox"] { margin-right: 8px; width: 18px; height: 18px; cursor: pointer; }
        .checkbox-item label { cursor: pointer; user-select: none; }
        
        .help-text { font-size: 0.9em; color: #666; margin-top: 5px; line-height: 1.5; }
        
        .btn { padding: 12px 30px; border: none; border-radius: 6px; cursor: pointer; font-size: 1em; font-weight: bold; transition: all 0.3s; }
        .btn-primary { background-color: #007bff; color: white; }
        .btn-primary:hover { background-color: #0056b3; transform: translateY(-2px); box-shadow: 0 4px 8px rgba(0,123,255,0.3); }
        .btn-secondary { background-color: #6c757d; color: white; margin-left: 10px; }
        
        .quick-add { display: flex; gap: 10px; margin-top: 10px; flex-wrap: wrap; }
        .quick-add button { padding: 6px 12px; border: 1px solid #007bff; background: white; color: #007bff; border-radius: 4px; cursor: pointer; font-size: 0.9em; }
        .quick-add button:hover { background-color: #007bff; color: white; }
        
        .preview-calendar { display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px; margin-top: 20px; }
        .calendar-month { border: 1px solid #ddd; border-radius: 8px; overflow: hidden; }
        .calendar-header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 15px; text-align: center; font-weight: bold; font-size: 1.1em; }
        .calendar-grid { display: grid; grid-template-columns: repeat(7, 1fr); }
        .calendar-day-header { background-color: #f8f9fa; padding: 10px 5px; text-align: center; font-weight: bold; font-size: 0.85em; border-bottom: 1px solid #ddd; }
        .calendar-day { padding: 10px 5px; text-align: center; border: 1px solid #eee; min-height: 50px; display: flex; flex-direction: column; justify-content: center; align-items: center; }
        .calendar-day.empty { background-color: #fafafa; }
        .calendar-day.holiday { background-color: #ffebee; color: #c62828; }
        .calendar-day.working { background-color: #e3f2fd; }
        .calendar-day.special-work { background-color: #fff3e0; color: #e65100; }
        .calendar-day.sunday { color: #dc3545; font-weight: bold; }
        .calendar-day.saturday { color: #007bff; font-weight: bold; }
        .day-label { font-size: 0.75em; margin-top: 2px; }
        
        .legend { display: flex; gap: 20px; flex-wrap: wrap; padding: 15px; background-color: #f8f9fa; border-radius: 5px; margin-top: 15px; }
        .legend-item { display: flex; align-items: center; gap: 8px; }
        .legend-box { width: 20px; height: 20px; border: 1px solid #ddd; border-radius: 3px; }
        
        .navigation-links { text-align: center; margin-top: 30px; padding-top: 20px; border-top: 2px solid #ddd; }
        .navigation-links a { margin: 0 15px; color: #007bff; text-decoration: none; font-weight: bold; }
        
        @media (max-width: 768px) {
            .preview-calendar { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="container">
        <h2>🗓️ 会社カレンダー設定</h2>
        
        <?php if ($message): ?>
            <div class="message <?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <input type="hidden" name="save_calendar" value="1">
            
            <div class="form-section">
                <h3>基本情報</h3>
                <div class="form-group">
                    <label>会社名</label>
                    <input type="text" name="company_name" value="<?= htmlspecialchars($calendar['company_name']) ?>" placeholder="例: 株式会社〇〇">
                </div>
            </div>
            
            <div class="form-section">
                <h3>定休曜日の設定</h3>
                <p class="help-text">毎週お休みの曜日を選択してください。365日営業の場合は何も選択しないでください。</p>
                <div class="checkbox-group">
                    <?php foreach ($dayNameMap as $eng => $jp): ?>
                        <div class="checkbox-item">
                            <input type="checkbox" 
                                   id="day_<?= $eng ?>" 
                                   name="weekly_holidays[]" 
                                   value="<?= $eng ?>"
                                   <?= in_array($eng, $calendar['weekly_holidays']) ? 'checked' : '' ?>>
                            <label for="day_<?= $eng ?>"><?= $jp ?></label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <div class="form-section">
                <h3>特別休業日の設定</h3>
                <p class="help-text">年末年始、お盆、ゴールデンウィークなど、特別に休業する日を1行に1日付で入力してください。</p>
                <div class="quick-add">
                    <button type="button" onclick="addHolidays('年末年始')">年末年始を追加</button>
                    <button type="button" onclick="addHolidays('お盆')">お盆を追加</button>
                    <button type="button" onclick="addHolidays('GW')">GWを追加</button>
                    <button type="button" onclick="addHolidays('祝日')">祝日を追加</button>
                </div>
                <div class="form-group">
                    <label>特別休業日（YYYY-MM-DD形式で1行に1つ）</label>
                    <textarea name="special_holidays" id="special_holidays"><?= htmlspecialchars(implode("\n", $calendar['special_holidays'])) ?></textarea>
                    <div class="help-text">
                        例:<br>
                        2025-01-01<br>
                        2025-12-31<br>
                        2025-08-13
                    </div>
                </div>
            </div>
            
            <div class="form-section">
                <h3>特別出勤日の設定</h3>
                <p class="help-text">定休曜日でも営業する特別出勤日を入力してください（例: 日曜日が定休日だが第1日曜は営業など）</p>
                <div class="form-group">
                    <label>特別出勤日（YYYY-MM-DD形式で1行に1つ）</label>
                    <textarea name="working_days" id="working_days"><?= htmlspecialchars(implode("\n", $calendar['working_days'])) ?></textarea>
                    <div class="help-text">
                        例:<br>
                        2025-01-05<br>
                        2025-03-02
                    </div>
                </div>
            </div>
            
            <div style="text-align: center; margin-top: 30px;">
                <button type="submit" class="btn btn-primary">💾 保存する</button>
                <a href="driver_regist.php" class="btn btn-secondary">キャンセル</a>
            </div>
        </form>
        
        <h3 style="margin-top: 40px;">📅 カレンダープレビュー</h3>
        <div class="legend">
            <div class="legend-item">
                <div class="legend-box" style="background-color: #e3f2fd;"></div>
                <span>通常営業日</span>
            </div>
            <div class="legend-item">
                <div class="legend-box" style="background-color: #ffebee;"></div>
                <span>定休日/特別休業日</span>
            </div>
            <div class="legend-item">
                <div class="legend-box" style="background-color: #fff3e0;"></div>
                <span>特別出勤日</span>
            </div>
        </div>
        
        <div class="preview-calendar">
            <?php
            $months = [
                ['year' => $currentYear, 'month' => $currentMonth],
                ['year' => $nextYear, 'month' => $nextMonth]
            ];
            
            foreach ($months as $m):
                $year = $m['year'];
                $month = $m['month'];
                $firstDay = mktime(0, 0, 0, $month, 1, $year);
                $daysInMonth = date('t', $firstDay);
                $startDayOfWeek = date('N', $firstDay); // 1=月曜 7=日曜
            ?>
                <div class="calendar-month">
                    <div class="calendar-header"><?= $year ?>年<?= $month ?>月</div>
                    <div class="calendar-grid">
                        <?php foreach ($dayMap as $d): ?>
                            <div class="calendar-day-header"><?= $d ?></div>
                        <?php endforeach; ?>
                        
                        <?php for ($i = 1; $i < $startDayOfWeek; $i++): ?>
                            <div class="calendar-day empty"></div>
                        <?php endfor; ?>
                        
                        <?php for ($day = 1; $day <= $daysInMonth; $day++):
                            $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
                            $dayOfWeek = strtolower(date('l', strtotime($date)));
                            $isWorking = isWorkingDay($date, $calendar);
                            $isSpecialWork = in_array($date, $calendar['working_days']);
                            
                            $classes = ['calendar-day'];
                            if ($dayOfWeek === 'sunday') $classes[] = 'sunday';
                            if ($dayOfWeek === 'saturday') $classes[] = 'saturday';
                            
                            if ($isSpecialWork) {
                                $classes[] = 'special-work';
                                $label = '出勤';
                            } elseif (!$isWorking) {
                                $classes[] = 'holiday';
                                $label = '休';
                            } else {
                                $classes[] = 'working';
                                $label = '';
                            }
                        ?>
                            <div class="<?= implode(' ', $classes) ?>">
                                <div><?= $day ?></div>
                                <?php if ($label): ?>
                                    <div class="day-label"><?= $label ?></div>
                                <?php endif; ?>
                            </div>
                        <?php endfor; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        
        <div class="navigation-links">
            <a href="index.html">🤖 TOPページ</a>
            <a href="pc_schedule.php">📅 週間スケジュール管理</a>
            <a href="driver_regist.php">👨‍✈️ ドライバー登録</a>
            <a href="course_regist.php">🗺️ コースマスター管理</a>
            <a href="vehicle_regist.php">🚚 車両マスター管理</a>
        </div>
    </div>
    
    <script>
        const currentYear = <?= $currentYear ?>;
        
        function addHolidays(type) {
            const textarea = document.getElementById('special_holidays');
            let holidays = [];
            
            if (type === '年末年始') {
                holidays = [
                    `${currentYear}-12-29`,
                    `${currentYear}-12-30`,
                    `${currentYear}-12-31`,
                    `${currentYear + 1}-01-01`,
                    `${currentYear + 1}-01-02`,
                    `${currentYear + 1}-01-03`
                ];
            } else if (type === 'お盆') {
                holidays = [
                    `${currentYear}-08-13`,
                    `${currentYear}-08-14`,
                    `${currentYear}-08-15`,
                    `${currentYear}-08-16`
                ];
            } else if (type === 'GW') {
                holidays = [
                    `${currentYear}-04-29`,
                    `${currentYear}-04-30`,
                    `${currentYear}-05-01`,
                    `${currentYear}-05-02`,
                    `${currentYear}-05-03`,
                    `${currentYear}-05-04`,
                    `${currentYear}-05-05`,
                    `${currentYear}-05-06`
                ];
            } else if (type === '祝日') {
                holidays = [
                    `${currentYear}-01-01`,
                    `${currentYear}-01-13`,
                    `${currentYear}-02-11`,
                    `${currentYear}-02-23`,
                    `${currentYear}-03-20`,
                    `${currentYear}-04-29`,
                    `${currentYear}-05-03`,
                    `${currentYear}-05-04`,
                    `${currentYear}-05-05`,
                    `${currentYear}-07-21`,
                    `${currentYear}-08-11`,
                    `${currentYear}-09-15`,
                    `${currentYear}-09-23`,
                    `${currentYear}-10-13`,
                    `${currentYear}-11-03`,
                    `${currentYear}-11-23`
                ];
            }
            
            const current = textarea.value.trim();
            const existing = current ? current.split('\n') : [];
            const newHolidays = holidays.filter(h => !existing.includes(h));
            
            if (newHolidays.length > 0) {
                textarea.value = current + (current ? '\n' : '') + newHolidays.join('\n');
                alert(`${newHolidays.length}件の休業日を追加しました`);
            } else {
                alert('すべての日付が既に登録されています');
            }
        }
    </script>
</body>
</html>