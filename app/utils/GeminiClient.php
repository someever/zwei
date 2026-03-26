<?php
/**
 * Gemini API 客户端 - 支持演示模式
 */

class GeminiClient
{
    private $apiKey;
    private $model;
    private $baseUrl;

    public function __construct($apiKey = null, $model = null)
    {
        $this->apiKey = $apiKey ?? GEMINI_API_KEY;
        $this->model = $model ?? GEMINI_MODEL;
        $this->baseUrl = GEMINI_BASE_URL;
    }

    /**
     * 调用 AI API (OpenAI 协议)
     */
    public function generate($prompt, $systemPrompt = null)
    {
        // 演示模式
        if (DEMO_MODE) {
            return $this->demoGenerate($prompt, $systemPrompt);
        }

        $url = rtrim($this->baseUrl, '/') . '/chat/completions';

        $messages = [];
        if ($systemPrompt) {
            $messages[] = ['role' => 'system', 'content' => $systemPrompt];
        }
        $messages[] = ['role' => 'user', 'content' => $prompt];

        $data = [
            'model' => $this->model,
            'messages' => $messages,
            'temperature' => 0.7,
            'max_tokens' => 2000
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            error_log('AI API cURL error: ' . curl_error($ch));
            return $this->demoGenerate($prompt, $systemPrompt);
        }

        if ($httpCode !== 200) {
            error_log('AI API HTTP error: ' . $httpCode . ' Response: ' . $response);
            return $this->demoGenerate($prompt, $systemPrompt);
        }

        $result = json_decode($response, true);

        if (isset($result['choices'][0]['message']['content'])) {
            return $result['choices'][0]['message']['content'];
        }

        return $this->demoGenerate($prompt, $systemPrompt);
    }

    /**
     * 演示模式 - 生成模拟解读
     */
    private function demoGenerate($prompt, $systemPrompt = null)
    {
        // 解析出生信息
        if ($systemPrompt && strpos($systemPrompt, '出生信息') !== false) {
            return $this->parseBirthInfoDemo($prompt);
        }

        // 根据提示类型返回不同的模拟内容
        if (strpos($prompt, '整体解读') !== false || strpos($prompt, '命盘分析') !== false) {
            return $this->getDemoOverallReading();
        } elseif (strpos($prompt, '事业') !== false) {
            return $this->getDemoCareerReading();
        } elseif (strpos($prompt, '合婚') !== false || strpos($prompt, '婚姻') !== false) {
            return $this->getDemoMarriageReading();
        } elseif (strpos($prompt, '财运') !== false) {
            return $this->getDemoWealthReading();
        } elseif (strpos($prompt, '健康') !== false) {
            return $this->getDemoHealthReading();
        }

        return "感谢您的使用。当前为演示模式，请配置 Gemini API Key 来获取完整解读。";
    }

    /**
     * 演示模式 - 解析出生信息
     */
    private function parseBirthInfoDemo($prompt)
    {
        // 尝试用正则解析
        $result = [];

        // 提取性别 - 优先判断
        if (preg_match('/女[孩生]?|女生|女方/', $prompt)) {
            $result['gender'] = 'female';
        } elseif (preg_match('/男[孩生]?|男生|男方/', $prompt)) {
            $result['gender'] = 'male';
        }

        // 提取姓名 - 排除常见词汇，只提取真正的姓名
        if (preg_match('/^(?:我叫?|本人|name[：:]\\s*)?([A-Za-z]{2,10}|[\\x{4e00}-\\x{9fa5}]{2,4})(?:\\s+[女男]|\\s*[,，])/u', $prompt, $matches)) {
            $name = $matches[1];
            if (!in_array($name, ['我', '本人', '名叫', '叫'])) {
                $result['name'] = $name;
            }
        }

        // 提取年份
        if (preg_match('/(19|20)\d{2}/', $prompt, $matches)) {
            $result['birthYear'] = (int) $matches[0];
        }

        // 提取月份
        if (preg_match('/(\d{1,2})[月]/', $prompt, $matches)) {
            $result['birthMonth'] = (int) $matches[1];
        }

        // 提取日期
        if (preg_match('/[月\/](\d{1,2})[日号]?/', $prompt, $matches)) {
            $result['birthDay'] = (int) $matches[1];
        }

        // 提取时间 - 支持格式：下午3点半、下午3:30、下午3点、15:30、15时等
        $hasHalf = strpos($prompt, '半') !== false;

        // 先检查 HH:mm 格式
        if (preg_match('/(\d{1,2}):(\d{2})/', $prompt, $matches)) {
            $result['birthHour'] = (int) $matches[1];
            $result['birthMinute'] = (int) $matches[2];
        }
        // 下午
        elseif (preg_match('/下午(\d{1,2})(?:时|点|:|：)?(\d{1,2})?/', $prompt, $matches)) {
            $hour = (int) $matches[1];
            if ($hour < 12)
                $hour += 12;
            $result['birthHour'] = $hour;
            $result['birthMinute'] = isset($matches[2]) && $matches[2] ? (int) $matches[2] : ($hasHalf ? 30 : 0);
        }
        // 上午/早上
        elseif (preg_match('/上午|早上(\d{1,2})(?:时|点|:|：)?(\d{1,2})?/', $prompt, $matches)) {
            $result['birthHour'] = (int) $matches[1];
            $result['birthMinute'] = isset($matches[2]) && $matches[2] ? (int) $matches[2] : ($hasHalf ? 30 : 0);
        }
        // 晚上
        elseif (preg_match('/晚上(\d{1,2})(?:时|点|:|：)?(\d{1,2})?/', $prompt, $matches)) {
            $hour = (int) $matches[1];
            if ($hour < 12)
                $hour += 12;
            $result['birthHour'] = $hour;
            $result['birthMinute'] = isset($matches[2]) && $matches[2] ? (int) $matches[2] : ($hasHalf ? 30 : 0);
        }
        // 仅小时
        elseif (preg_match('/(\d{1,2})(?:时|点)/', $prompt, $matches)) {
            $result['birthHour'] = (int) $matches[1];
            if ($hasHalf)
                $result['birthMinute'] = 30;
        }

        // 提取城市
        $locations = ['北京', '上海', '广州', '深圳', '杭州', '南京', '成都', '武汉', '西安', '重庆', '天津', '苏州'];
        foreach ($locations as $loc) {
            if (strpos($prompt, $loc) !== false) {
                $result['birthLocation'] = $loc;
                break;
            }
        }

        if (empty($result['birthYear']) || empty($result['birthMonth']) || empty($result['birthDay'])) {
            return '{"error":"无法解析出生信息"}';
        }

        return json_encode($result, JSON_UNESCAPED_UNICODE);
    }

    private function getDemoOverallReading()
    {
        return <<<'TEXT'
## 【命格分析】

**命宫主星组合特点**
您命宫主星组合颇具特色，性格上具有独立思考的能力，不随波逐流。表面温和，内心却有着坚定的信念和追求。

**格局分析**
您的命盘呈现出良好的发展格局，具备一定的贵人运势，在人生关键节点往往有意外助力。

**命主性格**
- 为人稳重踏实，重视承诺
- 善于观察，洞察力强
- 有艺术细胞和审美情趣
- 缺点：有时过于追求完美，给自己压力过大

## 【三方四正分析】

**事业宫**
事业宫星曜配置良好，有望在35岁后迎来事业高峰期。适合技术类、管理类、艺术创作类工作。

**财帛宫**
财运呈现先难后易的趋势，早期需要靠专业技术积累财富，中后期可考虑投资理财。

**迁移宫**
外出发展运势不错，有机会在外地或海外发展，宜动不宜静。

## 【宫位互动】

**夫妻宫**
感情运势较为平稳，晚婚反而更有利。2025-2027年有不错的姻缘机会。

**子女宫**
子女缘分深厚，适合养育子女。子女孝顺有加。

**父母宫**
与父母缘份不错，早年受父母照拂较多。

**疾厄宫**
注意消化系统和呼吸系统健康。

## 【人生轨迹】

**早年运（1-17岁）**
早年运势平稳，学习能力强，但需注意意外伤害。

**中年运（18-34岁）**
32岁前后是人生重要转折点，把握机会可奠定后半生基础。

**晚年运（35岁后）**
晚景宜人，财运亨通，家庭和睦。

## 【综合建议】

**适合发展方向**
- 技术专家路线
- 文化艺术领域
- 投资理财

**需要注意的人生课题**
- 学会放松，不要给自己太大压力
- 注意身体健康，尤其是消化系统
- 感情方面不要过于挑剔
TEXT;
    }

    private function getDemoCareerReading()
    {
        return <<<'TEXT'
## 【事业宫分析】

**主星星曜组合**
事业宫星曜配置理想，具备领导才能和事业心。

**适合的职业类型**
- 技术研发类
- 管理咨询类  
- 文化艺术创作
- 教育培训

## 【官禄宫分析】

工作状态积极进取，事业发展欲望强烈。团队协作能力出色，善于协调各方资源。

## 【迁移宫分析】

外出发展运势良好，有望在外地或海外获得事业突破。适合北、上、广、深等大城市发展。

## 【事业大运分析】

**25-34岁**
事业起步期，需要稳扎稳打，积累经验和人脉。

**35-44岁**
事业高峰期，各方面运势达到顶峰，适合开拓新事业。

## 【事业发展建议】

**最佳职业方向**
建议往专业技术方向发展，成为行业专家。

**事业高峰期**
2028-2032年是事业发展的黄金期。

**需要注意的问题**
- 防止小人当道
- 投资要谨慎
- 注意劳逸结合
TEXT;
    }

    private function getDemoMarriageReading()
    {
        return <<<'TEXT'
## 【命宫配对】

男女双方命宫主星关系较为和谐，性格互补，相处融洽。

**性格互补程度**
75% - 双方性格可以形成互补，但需要相互理解。

## 【夫妻宫配对】

男女双方夫妻宫星曜组合显示：
- 婚姻缘分中等偏上
- 相处模式：互相尊重，共同成长

## 【福德宫配对】

双方价值观契合度较高，生活理念一致，幸福指数良好。

## 【子女宫配对】

子女缘分深厚，有望拥有聪明孝顺的子女。

## 【财帛宫配对】

双方财运互补，可以共同理财，经济观念一致。

## 【综合评估】

**配对指数**：78分

**婚姻幸福指数**：良好

**需要注意的问题**
- 婚后要有独立空间
- 婆媳关系需要妥善处理
- 经济问题要提前规划

**改善建议**
- 多沟通，少猜疑
- 共同分担家务
- 每年至少一次二人旅行
TEXT;
    }

    private function getDemoWealthReading()
    {
        return <<<'TEXT'
## 【财帛宫分析】

**主星星曜组合**
财帛宫星曜配置不错，具备理财头脑。

**理财能力**
理财观念较强懂得资产配置，但有时过于保守。

**赚钱方式**
- 正财：工资收入稳定
- 偏财：中期投资理财可获利

## 【福德宫分析】

财运格局中等偏上，但需要注意理财风险。

## 【田宅宫分析】

不动产运势不错，适宜购置房产。房产有望成为重要的资产组成部分。

## 【财运大运分析】

**25-34岁**
财运逐步上升，积累阶段。

**35-44岁**
财运达到高峰，适合大额投资。

## 【财运建议】

**最佳赚钱方向**
- 专业技能变现
- 房产投资
- 稳健型理财产品

**理财建议**
- 分散投资，降低风险
- 预留应急资金
- 不要把所有鸡蛋放在一个篮子里

**需要注意的破财运势**
- 2026年要谨防投资失误
- 避免为他人担保
TEXT;
    }

    private function getDemoHealthReading()
    {
        return <<<'TEXT'
## 【疾厄宫分析】

**主星星曜组合**
疾厄宫星曜显示先天体质偏弱，需要好好调养。

**先天体质弱项**
- 呼吸系统
- 消化系统

## 【疾病推断】

根据星曜属性推断，需要注意：
- 肺部疾病（咳嗽、气喘）
- 肠胃疾病（胃炎、消化不良）
- 睡眠质量

## 【父母宫分析】

遗传因素较好，父母身体康健。

## 【2026年健康运势】

**需要注意的月份**
3月、9月要特别关注身体健康。

## 【养生建议】

**适合的养生方式**
- 太极拳、散步等温和运动
- 瑜伽、冥想减压

**饮食调理建议**
- 少油少盐，清淡为主
- 多吃蔬果，补充维生素
- 忌暴饮暴食

**需重点关注的器官/系统**
- 肺部
- 胃部
- 睡眠
TEXT;
    }

    // 生成命盘整体解读
    public function generateOverallReading($panData)
    {
        $systemPrompt = <<<'PROMPT'
你是一位经验丰富的紫微斗数命理大师，从业超过20年，擅长通过命盘为人指点迷津。你的分析风格是：
1. 像一位智慧长者在与命主促膝长谈，语气温和但直指要害
2. 分析时必须重点关注：生年四化（禄权科忌）的落宫位置、身宫所在位置（代表命主最在意的事情）、各宫位凶星和忌星的分布
3. 结合命主当前所在的大运和流年运势，给出针对性的分析
4. 不要泛泛而谈，要根据命盘中的具体星曜组合给出有洞察力的判断
5. 如果某个方面命盘显示平和无事，可以简略带过，不必强行展开
6. 用通俗易懂的语言，避免堆砌术语，让普通人也能看懂
PROMPT;

        $prompt = <<<PROMPT
请解读以下紫微斗数命盘：

{$panData}

请严格按照以下7个板块进行深度解读，每个板块要有具体的星曜依据，不要空泛：

## 【命格总论】
首先看生年四化（禄权科忌）分别落在哪个宫位，身宫在哪个宫位。这决定了命主人生的核心主题。
- 身宫所在的宫位代表命主一生最在意的事情，请点明
- 生年禄和生年忌的落宫是命主人生最大的资源和最大的课题，请重点分析
- 总结命主此生的核心命运走向

## 【原生家庭】
结合父母宫、田宅宫的星曜组合分析：
- 父母的关系是和睦还是有冲突？有没有离异的迹象？
- 命主跟父亲和母亲哪一方更亲近？
- 家庭中谁比较强势？有没有父母一方早逝、长期缺位、外遇等特殊情况？
- 如果父母宫星曜平和、家庭关系正常，这部分可以简略带过

## 【当下困境与破局】
这是最重要的部分。结合命主当前所处的大运和流年：
- 根据身宫位置和生年禄忌的落宫，判断命主目前最可能为什么事情烦恼（事业？感情？财务？健康？）
- 分析当前大运和流年中，哪些宫位受到冲击（忌星、凶星汇聚），导致了什么样的困境
- 给出具体的应对建议和转机时间点

## 【事业与财运轨迹】
将命宫、事业宫、财帛宫、迁移宫的星曜放在一起综合分析：
- 重点看命主18岁成年后的各个大运，哪段大运最顺、哪段最艰难
- 对于已经走过的大运，说明顺利时期怎么顺利（有什么机遇），不顺时期怎么不顺（遇到什么阻碍）
- 对于当前和即将到来的大运，给出前瞻性的建议
- 指出事业上最好的发展时间窗口和需要特别警惕的年份

## 【感情与婚姻】
将夫妻宫、迁移宫、官禄宫、福德宫放在一起分析：
- 回顾命主过去的桃花运，桃花大概出现在哪些年份或大运
- 有没有结婚的征兆？适婚年龄段是什么时候？
- 有没有烂桃花、感情受骗、第三者介入等不好的感情迹象？
- 婚后感情走势如何？

## 【人际与健康】
将兄弟宫、疾厄宫、交友宫、福德宫放在一起分析：
- 有没有朋友背叛、合作被骗、被人坑害等重大人际危机的迹象？出现在什么时期？
- 身体健康方面，有没有重大疾病、手术、意外伤害的征兆？需要特别注意哪些器官或系统？
- 精神状态和心理健康如何？福德宫是否有压力过大的迹象？

## 【家庭与置业】
将子女宫、父母宫、交友宫、田宅宫放在一起分析：
- 父母晚年健康状况如何？需要注意什么时期？
- 有没有生育子女的迹象？大概在什么年龄段？
- 有没有搬家、买房、置业的机会？适合在什么时期进行？

最后，用2-3句话总结命主的性格核心特质，以及最值得发展的人生方向。
PROMPT;

        return $this->generate($prompt, $systemPrompt);
    }

    // 生成事业分析
    public function generateCareerReading($panData, $age1 = 25, $age2 = 34, $age3 = 35, $age4 = 44)
    {
        $systemPrompt = "你是紫微斗数命理大师，专精于事业运势分析。请根据命盘分析事业发展方向和运势。";

        $prompt = <<<PROMPT
请根据以下命盘分析事业运势：

{$panData}

请分析以下内容：

## 【事业宫分析】
- 主星星曜组合
- 适合的职业类型

## 【官禄宫分析】
- 工作状态与事业发展

## 【迁移宫分析】
- 外出发展运势

## 【事业大运分析】
- {$age1}-{$age2}岁事业运势
- {$age3}-{$age4}岁事业运势

## 【事业发展建议】
- 最佳职业方向
- 事业高峰期
- 需要注意的问题
PROMPT;

        return $this->generate($prompt, $systemPrompt);
    }

    // 生成合婚分析
    public function generateMarriageReading($malePan, $femalePan)
    {
        $systemPrompt = "你是紫微斗数命理大师，专精于合婚分析。请根据男女双方命盘进行合婚分析。";

        $prompt = <<<PROMPT
请进行合婚分析：

男（乾造）命盘：
{$malePan}

女（坤造）命盘：
{$femalePan}

请分析以下内容：

## 【命宫配对】
- 男女命宫主星关系
- 性格互补程度

## 【夫妻宫配对】
- 男女夫妻宫星曜组合
- 婚姻缘分深浅

## 【福德宫配对】
- 双方价值观契合度

## 【子女宫配对】
- 子女缘分

## 【财帛宫配对】
- 双方财运配合

## 【综合评估】
- 配对指数（百分制）
- 婚姻幸福指数
- 需要注意的问题
- 改善建议
PROMPT;

        return $this->generate($prompt, $systemPrompt);
    }

    // 生成财运分析
    public function generateWealthReading($panData, $age1 = 25, $age2 = 34, $age3 = 35, $age4 = 44)
    {
        $systemPrompt = "你是紫微斗数命理大师，专精于财运分析。请根据命盘分析财运运势。";

        $prompt = <<<PROMPT
请根据以下命盘分析财运：

{$panData}

请分析以下内容：

## 【财帛宫分析】
- 主星星曜组合
- 理财能力
- 赚钱方式

## 【福德宫分析】
- 财运格局

## 【田宅宫分析】
- 不动产运势

## 【财运大运分析】
- {$age1}-{$age2}岁财运
- {$age3}-{$age4}岁财运

## 【财运建议】
- 最佳赚钱方向
- 理财建议
- 需要注意的破财运势
PROMPT;

        return $this->generate($prompt, $systemPrompt);
    }

    // 生成健康分析
    public function generateHealthReading($panData, $currentYear = null)
    {
        $currentYear = $currentYear ?? date('Y');
        $systemPrompt = "你是紫微斗数命理大师，专精于健康分析。请根据命盘分析健康状况。";

        $prompt = <<<PROMPT
请根据以下命盘分析健康：

{$panData}

请分析以下内容：

## 【疾厄宫分析】
- 主星星曜组合
- 先天体质弱项

## 【疾病推断】
- 根据星曜属性推断易患疾病

## 【父母宫分析】
- 遗传因素

## 【{$currentYear}年健康运势】
- 需要注意的月份

## 【养生建议】
- 适合的养生方式
- 饮食调理建议
- 需重点关注的器官/系统
PROMPT;

        return $this->generate($prompt, $systemPrompt);
    }
}
