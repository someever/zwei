<?php
/**
 * 紫微斗数排盘工具类 - 增强版（支持 缘分居 API）
 */

class PanCalculator
{
    const STARS = [
        '紫微',
        '天机',
        '太阳',
        '武曲',
        '天同',
        '廉贞',
        '天府',
        '太阴',
        '贪狼',
        '巨门',
        '天相',
        '天梁',
        '七杀',
        '破军'
    ];

    const PALACE_NAMES = [
        '命宫',
        '父母宫',
        '福德宫',
        '田宅宫',
        '事业宫',
        '交友宫',
        '迁移宫',
        '疾厄宫',
        '财帛宫',
        '子女宫',
        '夫妻宫',
        '兄弟宫'
    ];

    const DIZHI = ['子', '丑', '寅', '卯', '辰', '巳', '午', '未', '申', '酉', '戌', '亥'];
    const TIANGAN = ['甲', '乙', '丙', '丁', '戊', '己', '庚', '辛', '壬', '癸'];

    // 天干四化表（禄权科忌）- 王亭之体系
    const SIHUA_TABLE = [
        '甲' => ['禄' => '廉贞', '权' => '破军', '科' => '武曲', '忌' => '太阳'],
        '乙' => ['禄' => '天机', '权' => '天梁', '科' => '紫微', '忌' => '太阴'],
        '丙' => ['禄' => '天同', '权' => '天机', '科' => '文昌', '忌' => '廉贞'],
        '丁' => ['禄' => '太阴', '权' => '天同', '科' => '天机', '忌' => '巨门'],
        '戊' => ['禄' => '贪狼', '权' => '太阴', '科' => '右弼', '忌' => '天机'],
        '己' => ['禄' => '武曲', '权' => '贪狼', '科' => '天梁', '忌' => '文曲'],
        '庚' => ['禄' => '太阳', '权' => '武曲', '科' => '太阴', '忌' => '天同'],
        '辛' => ['禄' => '巨门', '权' => '太阳', '科' => '文曲', '忌' => '文昌'],
        '壬' => ['禄' => '天梁', '权' => '紫微', '科' => '左辅', '忌' => '武曲'],
        '癸' => ['禄' => '破军', '权' => '巨门', '科' => '太阴', '忌' => '贪狼'],
    ];

    public function calculate($year, $month, $day, $hour, $minute, $gender, $location = [])
    {
        $locInfo = is_array($location) ? $location : ['location' => $location];

        // 如果配置了缘分居 API Key，优先使用 API 排盘
        if (defined('YUANFENJU_API_KEY') && !empty(YUANFENJU_API_KEY)) {
            $apiResult = $this->calculateViaApi($year, $month, $day, $hour, $minute, $gender, $locInfo);
            if ($apiResult) {
                return $apiResult;
            }
        }

        // 否则使用本地降级算法
        $lunarDate = $this->getLunarDate($year, $month, $day, $hour, $minute);
        $shichen = $this->getShichen($hour, $minute);
        $zhongshu = $this->getZhongshu($year, $month);
        $pan = $this->buildPan($year, $month, $day, $hour, $minute, $gender, $lunarDate, $shichen);

        return [
            'solar_date' => ['year' => $year, 'month' => $month, 'day' => $day, 'hour' => $hour, 'minute' => $minute],
            'lunar_date' => $lunarDate,
            'zhongshu' => $zhongshu,
            'shichen' => $shichen,
            'gender' => $gender,
            'location' => $locInfo['location'] ?? '',
            'pan' => $pan,
            'is_api' => false,
            'generated_at' => date('Y-m-d H:i:s')
        ];
    }

    /**
     * 使用缘分居 API 排盘
     */
    private function calculateViaApi($year, $month, $day, $hour, $minute, $gender, $locInfo = [])
    {
        $url = 'https://api.yuanfenju.com/index.php/v1/Bazi/zwpan';
        $params = [
            'api_key' => YUANFENJU_API_KEY,
            'sex' => ($gender === 'male' ? 1 : 0),
            'type' => 1, // 1表示公历
            'year' => $year,
            'month' => $month,
            'day' => $day,
            'hours' => $hour,
            'minute' => $minute,
            'name' => '访客',
            'zhen' => 1, // 中国真太阳时
            'sect' => 2, // 门派2 (默认)
            'province' => $locInfo['province'] ?? '',
            'city' => $locInfo['city'] ?? ''
        ];

        try {
            $demoFile = __DIR__ . '/../../public/js/data/panDemo.json';
            if (file_exists($demoFile)) {
                $json = file_get_contents($demoFile);
            } else {
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
                curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

                $startTime = microtime(true);
                error_log("PanCalculator: Calling YuanFenJu API...");
                
                $json = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $duration = round(microtime(true) - $startTime, 2);
                
                if (curl_errno($ch)) {
                    error_log("PanCalculator: YuanFenJu API error - " . curl_error($ch));
                } else {
                    error_log("PanCalculator: YuanFenJu API finished in {$duration}s [HTTP {$httpCode}]");
                }
            }

            if (!$json)
                return false;

            $res = json_decode($json, true);
            if (!isset($res['errcode']) || $res['errcode'] !== 0) {
                error_log("PanCalculator: YuanFenJu API returned business error: " . ($res['errmsg'] ?? 'Unknown'));
                return false;
            }

            $data = $res['data'];
            $base = $data['base_info'];

            // 转换为内部格式
            $palaces = [];
            foreach ($data['gong_pan'] as $item) {
                // 合并该宫位的所有星曜
                $stars = [];
                $starFields = ['ziweixing', 'tianfuxing', 'monthxing', 'hourxing', 'yearganxing', 'yearzhixing', 'qitaxing'];
                foreach ($starFields as $f) {
                    if (!empty($item[$f]) && $item[$f] !== '无' && $item[$f] !== '空') {
                        // 有些字段可能是逗号分隔的多个星
                        $parts = explode(',', $item[$f]);
                        foreach ($parts as $p) {
                            $stars[] = trim($p);
                        }
                    }
                }

                $palaceName = $item['minggong'];
                $palaces[$palaceName] = [
                    'stars' => array_unique($stars),
                    'zhi' => mb_substr($item['yinshou'], 1, 1),
                    'gan' => mb_substr($item['yinshou'], 0, 1),
                    'is_ming_gong' => ($palaceName === '命宫'),
                    'daxian' => $item['daxian'] ?? '',
                    'desc' => $item['fuxing_desc'] ?? ''
                ];
            }

            return [
                'solar_date' => ['year' => $year, 'month' => $month, 'day' => $day, 'hour' => $hour, 'minute' => $minute],
                'lunar_date' => [
                    'year' => $base['yeargz'],
                    'month' => $base['monthgz'],
                    'day' => $base['daygz'],
                    'ganzhi_year' => $base['yeargz'],
                    'ganzhi_month' => $base['monthgz'],
                    'ganzhi_day' => $base['daygz'],
                    'ganzhi_hour' => $base['hourgz'],
                    'desc' => $base['nongli']
                ],
                'zhongshu' => ['zhongshu' => $base['mingju'] ?? ''],
                'shichen' => mb_substr($base['nongli'], -2),
                'gender' => $gender,
                'pan' => [
                    'palaces' => $palaces,
                    'ming_gong' => ['name' => '命宫'],
                    'shen_gong' => ['name' => $base['shengong'] . '宫'],
                    'four_transformations' => $this->parseSihua($base['mingsihua']),
                    'patterns' => [], // API 没直接给格局，暂时留空
                    'jieqi' => []
                ],
                'is_api' => true,
                'api_data' => $data, // 保留原始数据以供深入解读
                'generated_at' => date('Y-m-d H:i:s')
            ];

        } catch (Exception $e) {
            error_log("YuanFenJu API Exception: " . $e->getMessage());
            return false;
        }
    }

    private function parseSihua($sihuaStr)
    {
        if (empty($sihuaStr))
            return [];
        // 格式通常是 "机阴贪弼" 对应 禄权科忌
        $stars = mb_str_split($sihuaStr);
        $types = ['化禄', '化权', '化科', '化忌'];
        $res = [];
        for ($i = 0; $i < min(count($stars), 4); $i++) {
            $res[$types[$i]] = $stars[$i];
        }
        return $res;
    }

    // --- 以下为本地降级算法 (与之前一致) ---

    private function getLunarDate($year, $month, $day, $hour, $minute)
    {
        $tiangan = self::TIANGAN;
        $dizhi = self::DIZHI;

        $tgIndex = ($year - 4) % 10;
        $dzIndex = ($year - 4) % 12;
        $ganzhiYear = $tiangan[$tgIndex] . $dizhi[$dzIndex];

        $monthGanIndex = ($tgIndex * 2 + $month) % 10;
        $ganzhiMonth = $tiangan[$monthGanIndex] . $dizhi[($month - 1) % 12];

        $daysSince1900 = (int) ((mktime(0, 0, 0, $month, $day, $year) - mktime(0, 0, 0, 1, 1, 1900)) / 86400);
        $ganzhiDay = $tiangan[($daysSince1900 + 6) % 10] . $dizhi[($daysSince1900 + 8) % 12];

        $hourGanIndex = ($tgIndex * 2 + floor($hour / 2)) % 10;
        $ganzhiHour = $tiangan[$hourGanIndex] . $dizhi[($hour + 1) % 12];

        return ['year' => $year, 'month' => $month, 'day' => $day, 'ganzhi_year' => $ganzhiYear, 'ganzhi_month' => $ganzhiMonth, 'ganzhi_day' => $ganzhiDay, 'ganzhi_hour' => $ganzhiHour];
    }

    private function getShichen($hour, $minute)
    {
        $shichenList = ['子时' => [23, 1], '丑时' => [1, 3], '寅时' => [3, 5], '卯时' => [5, 7], '辰时' => [7, 9], '巳时' => [9, 11], '午时' => [11, 13], '未时' => [13, 15], '申时' => [15, 17], '酉时' => [17, 19], '戌时' => [19, 21], '亥时' => [21, 23]];

        foreach ($shichenList as $name => $range) {
            if ($hour >= $range[0] && $hour < $range[1])
                return $name;
        }
        return '子时';
    }

    private function getZhongshu($year, $month)
    {
        $zhongshuList = [1 => '大寒', 2 => '雨水', 3 => '春分', 4 => '谷雨', 5 => '小满', 6 => '夏至', 7 => '大暑', 8 => '处暑', 9 => '秋分', 10 => '霜降', 11 => '小雪', 12 => '冬至'];
        return ['month' => $month, 'zhongshu' => $zhongshuList[$month] ?? ''];
    }

    private function buildPan($year, $month, $day, $hour, $minute, $gender, $lunarDate, $shichen)
    {
        $shichenMap = ['子时' => 0, '丑时' => 1, '寅时' => 2, '卯时' => 3, '辰时' => 4, '巳时' => 5, '午时' => 6, '未时' => 7, '申时' => 8, '酉时' => 9, '戌时' => 10, '亥时' => 11];
        $shichenIndex = $shichenMap[$shichen] ?? 0;
        $mingGongIndex = (11 - $shichenIndex + 12) % 12;
        $shenGongIndex = ($mingGongIndex + $month - 1) % 12;
        $starDistribution = $this->distributeStars($year, $month, $day, $gender, $mingGongIndex);

        $palaces = [];
        for ($i = 0; $i < 12; $i++) {
            $palaceName = self::PALACE_NAMES[$i];
            $zhiIndex = ($shichenIndex + $i) % 12;
            $ganIndex = ($this->getYearGanIndex($lunarDate['ganzhi_year']) + $i) % 10;
            $palaces[$palaceName] = ['stars' => $starDistribution[$i] ?? [], 'zhi' => self::DIZHI[$zhiIndex], 'gan' => self::TIANGAN[$ganIndex], 'index' => $i];
        }

        $palaces[self::PALACE_NAMES[$mingGongIndex]]['is_ming_gong'] = true;
        $palaces[self::PALACE_NAMES[$shenGongIndex]]['is_shen_gong'] = true;
        $fourTransforms = $this->calculateFourTransformations($lunarDate['ganzhi_year']);
        $patterns = $this->detectPatterns($palaces, $fourTransforms);

        return ['palaces' => $palaces, 'ming_gong' => ['index' => $mingGongIndex, 'name' => self::PALACE_NAMES[$mingGongIndex]], 'shen_gong' => ['index' => $shenGongIndex, 'name' => self::PALACE_NAMES[$shenGongIndex]], 'four_transformations' => $fourTransforms, 'patterns' => $patterns, 'jieqi' => $this->getJieqi($month)];
    }

    private function getYearGanIndex($ganzhiYear)
    {
        $gan = $ganzhiYear[0];
        return array_search($gan, self::TIANGAN) ?? 0;
    }

    private function distributeStars($year, $month, $day, $gender, $mingGongIndex)
    {
        $distribution = array_fill(0, 12, []);
        $ziweiIndex = ($mingGongIndex + ($year % 12)) % 12;
        $starOrder = ['紫微', '天机', '太阳', '武曲', '天同', '廉贞', '天府', '太阴', '贪狼', '巨门', '天相', '天梁', '七杀', '破军'];
        $distribution[$ziweiIndex][] = '紫微';

        $idx = ($ziweiIndex + 1) % 12;
        for ($i = 1; $i < count($starOrder); $i++) {
            if ($starOrder[$i] !== '紫微') {
                if (!in_array($starOrder[$i], $distribution[$idx])) {
                    $distribution[$idx][] = $starOrder[$i];
                }
                $idx = ($idx + 1) % 12;
                if ($i < 7)
                    $idx = ($idx + 1) % 12;
            }
        }

        $this->addSubStars($distribution, $ziweiIndex, $year, $month, $day, $gender);
        return $distribution;
    }

    private function addSubStars(&$distribution, $ziweiIndex, $year, $month, $day, $gender)
    {
        $taohuaIndex = ($ziweiIndex + 5) % 12;
        $distribution[$taohuaIndex][] = '红鸾';
        $distribution[($taohuaIndex + 6) % 12][] = '天喜';
        $lucunIndex = ($ziweiIndex + $year % 12 + $month) % 12;
        $distribution[$lucunIndex][] = '禄存';
        $keIndex = ($ziweiIndex + $day) % 12;
        $distribution[$keIndex][] = '左辅';
        $distribution[($keIndex + 6) % 12][] = '右弼';
        $shaIndex = ($ziweiIndex + $year % 6) % 12;
        $distribution[$shaIndex][] = '火星';
        $distribution[($shaIndex + 4) % 12][] = '铃星';
        $tiankongIndex = ($ziweiIndex + $month) % 12;
        $distribution[$tiankongIndex][] = '天空';
        $tuoluoIndex = ($ziweiIndex + $year % 12 + 2) % 12;
        $distribution[$tuoluoIndex][] = '陀罗';
        $qingtianIndex = ($ziweiIndex + $day % 12) % 12;
        $distribution[$qingtianIndex][] = '擎羊';
    }

    private function calculateFourTransformations($ganzhiYear)
    {
        $yearGan = $ganzhiYear[0];
        $transforms = ['甲' => ['化禄' => '廉贞', '化权' => '破军', '化科' => '紫微', '化忌' => '武曲'], '乙' => ['化禄' => '天机', '化权' => '太阳', '化科' => '太阴', '化忌' => '天梁'], '丙' => ['化禄' => '天同', '化权' => '天机', '化科' => '廉贞', '化忌' => '天梁'], '丁' => ['化禄' => '太阴', '化权' => '紫微', '化科' => '天府', '化忌' => '巨门'], '戊' => ['化禄' => '贪狼', '化权' => '太阴', '化科' => '天机', '化忌' => '武曲'], '己' => ['化禄' => '武曲', '化权' => '贪狼', '化科' => '天梁', '化忌' => '紫微'], '庚' => ['化禄' => '太阳', '化权' => '武曲', '化科' => '天同', '化忌' => '天机'], '辛' => ['化禄' => '巨门', '化权' => '太阳', '化科' => '天府', '化忌' => '太阴'], '壬' => ['化禄' => '天梁', '化权' => '紫微', '化科' => '天府', '化忌' => '贪狼'], '癸' => ['化禄' => '破军', '化权' => '巨门', '化科' => '太阴', '化忌' => '廉贞']];
        return $transforms[$yearGan] ?? [];
    }

    private function detectPatterns($palaces, $fourTransforms)
    {
        $patterns = [];
        foreach ($palaces as $name => $palace) {
            $stars = $palace['stars'];
            if (in_array('紫微', $stars) && in_array('天府', $stars))
                $patterns[] = '紫府同宫';
            if (in_array('太阳', $stars) && in_array('太阴', $stars))
                $patterns[] = '日月并明';
            if (in_array('贪狼', $stars) && (in_array('火星', $stars) || in_array('铃星', $stars)))
                $patterns[] = '火贪暴发';
            if (in_array('七杀', $stars) && in_array('破军', $stars) && in_array('贪狼', $stars))
                $patterns[] = '杀破狼格';
            if (in_array('禄存', $stars) && isset($fourTransforms['化禄']))
                $patterns[] = '禄星拱照';
        }
        return array_unique($patterns);
    }

    private function getJieqi($month)
    {
        $seasonJieqi = [1 => ['小寒', '大寒'], 2 => ['立春', '雨水'], 3 => ['惊蛰', '春分'], 4 => ['清明', '谷雨'], 5 => ['立夏', '小满'], 6 => ['芒种', '夏至'], 7 => ['小暑', '大暑'], 8 => ['立秋', '处暑'], 9 => ['白露', '秋分'], 10 => ['寒露', '霜降'], 11 => ['立冬', '小雪'], 12 => ['大雪', '冬至']];
        return $seasonJieqi[$month] ?? [];
    }

    public function formatForGemini($pan)
    {
        $output = "紫微斗数命盘分析\n====================\n\n";
        $output .= "【基本信息】\n";
        $output .= "农历: " . ($pan['lunar_date']['desc'] ?? "{$pan['lunar_date']['year']}年{$pan['lunar_date']['month']}月{$pan['lunar_date']['day']}日") . "\n";
        $output .= "年干支: {$pan['lunar_date']['ganzhi_year']}\n";
        $output .= "月干支: {$pan['lunar_date']['ganzhi_month']}\n";
        $output .= "日干支: {$pan['lunar_date']['ganzhi_day']}\n";
        $output .= "时干支: {$pan['lunar_date']['ganzhi_hour']}\n";
        $output .= "时辰: {$pan['shichen']}\n";
        $output .= "命局: " . ($pan['zhongshu']['zhongshu'] ?? '') . "\n";
        // 当前时间和命主年龄（必须明确告诉 AI，防止用训练数据中的旧年份）
        $currentYear = (int) date('Y');
        $currentMonth = (int) date('m');
        $currentDay = (int) date('d');
        $output .= "\n【重要：当前真实时间】\n";
        $output .= "今天是 {$currentYear}年{$currentMonth}月{$currentDay}日（这是真实的当前日期，所有分析必须以此为基准）\n";

        // 今年流年天干
        $tiangan = self::TIANGAN;
        $yearGanIndex = ($currentYear - 4) % 10;
        $currentYearGan = $tiangan[$yearGanIndex];
        $output .= "今年流年天干: {$currentYearGan} ({$currentYear}年)\n";

        // 命主年龄
        $birthYear = $pan['solar_date']['year'] ?? null;
        $currentAge = $birthYear ? ($currentYear - $birthYear) : null;
        if ($currentAge) {
            $output .= "命主出生年: {$birthYear}年\n";
            $output .= "命主当前年龄: {$currentAge}岁\n";
        }
        if (!empty($pan['pan']['jieqi'])) {
            $output .= "节气: " . implode('、', $pan['pan']['jieqi']) . "\n";
        }

        // 大运一览表（帮助 AI 准确对应年龄段、宫位和四化）
        if (!empty($pan['api_data']['gong_pan']) && $currentAge) {
            // 先建立宫位→主星的映射表
            $palaceStarMap = [];
            foreach ($pan['api_data']['gong_pan'] as $item) {
                $stars = [];
                if (!empty($item['ziweixing'])) {
                    $s = $item['ziweixing'];
                    $state = (!empty($item['ziweixing_xingyao']) && $item['ziweixing_xingyao'] !== '无') ? "({$item['ziweixing_xingyao']})" : '';
                    $stars[] = $s . $state;
                }
                if (!empty($item['tianfuxing'])) {
                    $s = $item['tianfuxing'];
                    $state = (!empty($item['tianfuxing_xingyao']) && $item['tianfuxing_xingyao'] !== '无') ? "({$item['tianfuxing_xingyao']})" : '';
                    $stars[] = $s . $state;
                }
                $palaceStarMap[$item['minggong']] = $stars;
            }

            // 建立星→所在宫位的映射表（用于标注四化落宫）
            $starPalaceMap = [];
            foreach ($pan['api_data']['gong_pan'] as $item) {
                $pName = $item['minggong'];
                foreach (['ziweixing', 'tianfuxing', 'monthxing', 'hourxing', 'yearganxing', 'qitaxing'] as $field) {
                    if (!empty($item[$field])) {
                        foreach (explode(',', $item[$field]) as $sn) {
                            $sn = trim($sn);
                            if ($sn && $sn !== '无' && $sn !== '空') {
                                $starPalaceMap[$sn] = $pName;
                            }
                        }
                    }
                }
            }

            $daxianList = [];
            $currentDaxian = null;
            foreach ($pan['api_data']['gong_pan'] as $item) {
                if (!empty($item['daxian'])) {
                    $parts = explode('-', $item['daxian']);
                    if (count($parts) === 2) {
                        $startAge = (int) $parts[0];
                        $endAge = (int) $parts[1];
                        if ($endAge <= 100) {
                            $gan = mb_substr($item['yinshou'], 0, 1);
                            $entry = [
                                'range' => $item['daxian'],
                                'palace' => $item['minggong'],
                                'yinshou' => $item['yinshou'],
                                'gan' => $gan,
                                'stars' => $palaceStarMap[$item['minggong']] ?? [],
                            ];
                            // 计算该大运天干的四化
                            if (isset(self::SIHUA_TABLE[$gan])) {
                                $entry['sihua'] = self::SIHUA_TABLE[$gan];
                                // 标注四化落宫
                                $sihuaWithPalace = [];
                                foreach (self::SIHUA_TABLE[$gan] as $type => $star) {
                                    $landPalace = $starPalaceMap[$star] ?? '未知';
                                    $sihuaWithPalace[$type] = "{$star}→{$landPalace}";
                                }
                                $entry['sihua_detail'] = $sihuaWithPalace;
                            }
                            $daxianList[$startAge] = $entry;
                            if ($currentAge >= $startAge && $currentAge <= $endAge) {
                                $currentDaxian = $entry;
                            }
                        }
                    }
                }
            }
            ksort($daxianList);

            $output .= "\n【大运一览表】（每段大运对应的宫位、主星和四化）\n";
            foreach ($daxianList as $start => $info) {
                $marker = '';
                if ($currentDaxian && $info['range'] === $currentDaxian['range']) {
                    $marker = ' ★当前大运★';
                }
                $starsStr = !empty($info['stars']) ? implode('、', $info['stars']) : '无主星';
                $output .= "\n{$info['range']}岁 → {$info['palace']}({$info['yinshou']}) 主星:[{$starsStr}]{$marker}\n";
                if (!empty($info['sihua_detail'])) {
                    $output .= "  大运天干{$info['gan']}的四化: ";
                    $parts = [];
                    foreach ($info['sihua_detail'] as $type => $detail) {
                        $parts[] = "化{$type}:{$detail}";
                    }
                    $output .= implode('、', $parts) . "\n";
                }
            }

            if ($currentDaxian) {
                $output .= "\n【当前大运详情】\n";
                $output .= "命主{$currentAge}岁，正在走 {$currentDaxian['range']}岁 的大运\n";
                $output .= "大运宫位: {$currentDaxian['palace']}({$currentDaxian['yinshou']})\n";
                $output .= "大运天干: {$currentDaxian['gan']}\n";
                if (!empty($currentDaxian['sihua_detail'])) {
                    $output .= "大运四化: ";
                    $parts = [];
                    foreach ($currentDaxian['sihua_detail'] as $type => $detail) {
                        $parts[] = "化{$type}:{$detail}";
                    }
                    $output .= implode('、', $parts) . "\n";
                }
            }

            // 流年四化
            if (isset(self::SIHUA_TABLE[$currentYearGan])) {
                $output .= "\n【今年流年四化】({$currentYear}年 天干{$currentYearGan})\n";
                $parts = [];
                foreach (self::SIHUA_TABLE[$currentYearGan] as $type => $star) {
                    $landPalace = $starPalaceMap[$star] ?? '未知';
                    $parts[] = "化{$type}:{$star}→{$landPalace}";
                }
                $output .= implode('、', $parts) . "\n";
            }
        }
        $output .= "\n【命宫与身宫】\n";
        $output .= "命宫: {$pan['pan']['ming_gong']['name']}\n";
        $output .= "身宫: {$pan['pan']['shen_gong']['name']}\n\n";

        // 生年四化及落宫
        $output .= "【生年四化】\n";
        foreach ($pan['pan']['four_transformations'] as $transform => $star) {
            // 找出此四化星落在哪个宫位
            $landingPalace = '';
            foreach ($pan['pan']['palaces'] as $pName => $p) {
                if (in_array($star, $p['stars'] ?? [])) {
                    $landingPalace = $pName;
                    break;
                }
            }
            $output .= "{$transform}: {$star}" . ($landingPalace ? " → 落{$landingPalace}" : '') . "\n";
        }

        // 计算命宫飞化（命主侧重点辅助判断）
        $mingGongGan = '';
        if (isset($pan['pan']['palaces']['命宫']['gan'])) {
            $mingGongGan = $pan['pan']['palaces']['命宫']['gan'];
        } elseif (!empty($pan['api_data']['gong_pan'])) {
            foreach ($pan['api_data']['gong_pan'] as $item) {
                if ($item['minggong'] === '命宫') {
                    $mingGongGan = mb_substr($item['yinshou'], 0, 1);
                    break;
                }
            }
        }
        
        if ($mingGongGan && isset(self::SIHUA_TABLE[$mingGongGan])) {
            $output .= "\n【命宫飞化】（命宫天干 {$mingGongGan} 的四化落宫，代表命主的执念和行为倾向）\n";
            foreach (self::SIHUA_TABLE[$mingGongGan] as $type => $star) {
                // 找出此飞化星落在哪个宫位
                $landingPalace = '';
                foreach ($pan['pan']['palaces'] as $pName => $p) {
                    if (in_array($star, $p['stars'] ?? [])) {
                        $landingPalace = $pName;
                        break;
                    }
                }
                // 使用之前 api_data 里的 starPalaceMap 作 fallback 保护
                if (!$landingPalace && isset($starPalaceMap) && isset($starPalaceMap[$star])) {
                    $landingPalace = $starPalaceMap[$star];
                }
                $output .= "命宫化{$type}: {$star}" . ($landingPalace ? " → 落{$landingPalace}" : '') . "\n";
            }
        }

        if (!empty($pan['pan']['patterns'])) {
            $output .= "\n【格局】\n";
            $output .= implode('、', $pan['pan']['patterns']) . "\n";
        }

        $output .= "\n【十二宫位详情】\n";
        // 如果有 API 原始数据，使用详细格式（含星曜状态）
        if (!empty($pan['api_data']['gong_pan'])) {
            foreach ($pan['api_data']['gong_pan'] as $item) {
                $palaceName = $item['minggong'];
                $output .= "\n◆ {$palaceName} ({$item['yinshou']})";
                if (!empty($item['daxian'])) {
                    $output .= " [大限:{$item['daxian']}]";
                }
                if (!empty($item['changsheng'])) {
                    $output .= " [长生:{$item['changsheng']}]";
                }
                $output .= "\n";

                // 逐类输出星曜，带状态和四化
                $starFields = [
                    ['ziweixing', 'ziweixing_xingyao', 'ziweixing_sihua', '紫微系'],
                    ['tianfuxing', 'tianfuxing_xingyao', 'tianfuxing_sihua', '天府系'],
                    ['monthxing', 'monthxing_xingyao', 'monthxing_sihua', '月系'],
                    ['hourxing', 'hourxing_xingyao', 'hourxing_sihua', '时系'],
                    ['yearganxing', 'yearganxing_xingyao', 'yearganxing_sihua', '年干系'],
                    ['yearzhixing', 'yearzhixing_xingyao', 'yearzhixing_sihua', '年支系'],
                    ['qitaxing', 'qitaxing_xingyao', 'qitaxing_sihua', '其他']
                ];

                $allStarParts = [];
                foreach ($starFields as [$nameKey, $stateKey, $sihuaKey, $category]) {
                    if (empty($item[$nameKey]) || $item[$nameKey] === '无' || $item[$nameKey] === '空')
                        continue;
                    $names = explode(',', $item[$nameKey]);
                    $states = !empty($item[$stateKey]) ? explode(',', $item[$stateKey]) : [];
                    $sihuas = !empty($item[$sihuaKey]) ? explode(',', $item[$sihuaKey]) : [];
                    for ($i = 0; $i < count($names); $i++) {
                        $s = trim($names[$i]);
                        if (empty($s))
                            continue;
                        $state = isset($states[$i]) ? trim($states[$i]) : '';
                        $sihua = isset($sihuas[$i]) ? trim($sihuas[$i]) : '';
                        $entry = $s;
                        if ($state && $state !== '无')
                            $entry .= "({$state})";
                        if ($sihua)
                            $entry .= "[{$sihua}]";
                        $allStarParts[] = $entry;
                    }
                }
                if (!empty($allStarParts)) {
                    $output .= "  星曜: " . implode('、', $allStarParts) . "\n";
                }
            }
        } else {
            // 降级：本地算法数据（无星曜状态）
            foreach ($pan['pan']['palaces'] as $palaceName => $palace) {
                $stars = implode('、', $palace['stars']);
                $output .= "{$palaceName} ({$palace['gan']}{$palace['zhi']}): {$stars}\n";
                if (!empty($palace['daxian'])) {
                    $output .= "  ┗ 大限: {$palace['daxian']}\n";
                }
            }
        }
        return $output;
    }
}
