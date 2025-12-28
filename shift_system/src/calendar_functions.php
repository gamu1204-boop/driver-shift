<?php
/**
 * 会社カレンダー共通関数
 * すべてのファイルから利用可能な営業日判定機能を提供
 */

/**
 * 会社カレンダーデータを読み込む
 */
function loadCompanyCalendar() {
    $calendarFile = 'company_calendar.json';
    
    if (file_exists($calendarFile)) {
        $data = json_decode(@file_get_contents($calendarFile), true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
            return $data;
        }
    }
    
    // デフォルト値（カレンダー未設定の場合）
    return [
        'company_name' => '',
        'weekly_holidays' => [],
        'special_holidays' => [],
        'working_days' => []
    ];
}

/**
 * 指定日が会社の営業日かどうかを判定
 * 
 * @param string $date 日付（YYYY-MM-DD形式）
 * @param array $calendar カレンダーデータ（省略時は自動読み込み）
 * @return bool 営業日ならtrue、休業日ならfalse
 */
function isCompanyWorkingDay($date, $calendar = null) {
    if ($calendar === null) {
        $calendar = loadCompanyCalendar();
    }
    
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

/**
 * 指定日の営業ステータスを取得
 * 
 * @param string $date 日付（YYYY-MM-DD形式）
 * @param array $calendar カレンダーデータ（省略時は自動読み込み）
 * @return array ['is_working' => bool, 'type' => string, 'label' => string]
 */
function getCompanyDayStatus($date, $calendar = null) {
    if ($calendar === null) {
        $calendar = loadCompanyCalendar();
    }
    
    $dayOfWeek = strtolower(date('l', strtotime($date)));
    
    // 特別出勤日
    if (in_array($date, $calendar['working_days'])) {
        return [
            'is_working' => true,
            'type' => 'special_working',
            'label' => '特別出勤'
        ];
    }
    
    // 特別休業日
    if (in_array($date, $calendar['special_holidays'])) {
        return [
            'is_working' => false,
            'type' => 'special_holiday',
            'label' => '特別休業'
        ];
    }
    
    // 定休曜日
    if (in_array($dayOfWeek, $calendar['weekly_holidays'])) {
        return [
            'is_working' => false,
            'type' => 'weekly_holiday',
            'label' => '定休日'
        ];
    }
    
    // 通常営業日
    return [
        'is_working' => true,
        'type' => 'normal_working',
        'label' => '営業日'
    ];
}

/**
 * 1週間分の営業ステータスを取得
 * 
 * @param string $startDate 開始日（月曜日）
 * @return array 曜日ごとの営業ステータス
 */
function getWeekWorkingStatus($startDate) {
    $calendar = loadCompanyCalendar();
    $weekStatus = [];
    
    $englishDays = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
    
    $baseDate = new DateTime($startDate);
    
    for ($i = 0; $i < 7; $i++) {
        $date = clone $baseDate;
        $date->modify("+{$i} days");
        $dateStr = $date->format('Y-m-d');
        
        $weekStatus[$englishDays[$i]] = getCompanyDayStatus($dateStr, $calendar);
    }
    
    return $weekStatus;
}

/**
 * デフォルトコース名を取得（休業日の場合は「公休」を返す）
 * 
 * @param array $driver ドライバー情報
 * @param string $dayKey 曜日キー（monday, tuesday, etc.）
 * @param string $date 日付（YYYY-MM-DD形式）
 * @return string コース名
 */
function getDefaultCourseWithHoliday($driver, $dayKey, $date) {
    $status = getCompanyDayStatus($date);
    
    // 会社が休業日の場合は「公休」
    if (!$status['is_working']) {
        return '公休';
    }
    
    // 通常はドライバーのデフォルトコース
    return $driver['courses'][$dayKey]['course'] ?? '-';
}
?>