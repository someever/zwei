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
            'max_tokens' => 8000
        ];

        error_log('AI API Request: model=' . $this->model . ', url=' . $url);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

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

        error_log('AI API unexpected response structure: ' . substr($response, 0, 500));
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
用一两句话总结，让命主一眼看懂，并且感兴趣
- 身宫所在的宫位代表命主一生最在意的事情，请点明
- 分析时必须重点关注：生年四化（禄权科忌）的落宫位置、身宫所在位置（代表命主最在意的事情）、各宫位凶星和忌星的分布
- 结合命主当前所在的大运和流年运势，给出针对性的分析
- 不要泛泛而谈，要根据命盘中的具体星曜组合给出有洞察力的判断
- 如果某个方面命盘显示平和无事，可以简略带过，不必强行展开
- 用通俗易懂的语言，避免堆砌术语，让普通人也能看懂

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
        $systemPrompt = <<<'PROMPT'
你是紫微斗数命理大师，专精事业运势分析。你的风格是一针见血，直接告诉命主：你适合做什么、什么时候是黄金期、当前卡在哪里。
分析原则：
1. 必须结合生年四化（禄权科忌）在事业相关宫位的分布来判断
2. 必须结合命主当前大运和流年，说清楚"现在"的处境
3. 不要泛泛列举职业，要根据星曜特质给出精准判断
4. 每个论断都要有星曜依据，不要说空话
PROMPT;

        $prompt = <<<PROMPT
请根据以下命盘进行深度事业分析：

{$panData}

请按以下结构分析，直击要害：

## 【事业核心定位】
- 看命宫、事业宫、财帛宫、迁移宫的主星组合，判断命主是"打工型"还是"创业型"还是"技术型"还是"管理型"
- 生年禄和生年权落在哪里？这决定了命主天生的事业资源和竞争力在哪个领域
- 生年忌落在事业相关宫位的话，说明事业上最大的坑是什么

## 【事业时间轴】
逐段分析18岁以后的大运（每10年一段），重点说清楚：
- {$age1}-{$age2}岁这段大运：事业宫飞星如何？是上升期还是蛰伏期？遇到了什么机会或阻碍？
- {$age3}-{$age4}岁这段大运：对比上一个大运，是更好还是更差？转折点在哪一年？
- 哪个大运是事业真正的黄金期？哪个大运要特别小心被裁、破产、合作翻车？

## 【当前事业困境】
结合命主目前所处的大运和今年的流年：
- 今年事业宫、官禄宫有没有忌星或凶星冲击？
- 命主当前最可能面临的事业问题是什么（职场瓶颈？被边缘化？创业困难？行业下行？）
- 这个困境什么时候能过去？转机在哪一年？

## 【破局建议】
- 基于命盘特质，命主最应该往哪个方向发力（具体到行业或岗位类型）
- 当前阶段是应该"守"还是"攻"？要不要跳槽/转行/创业？
- 贵人方位和合作对象特征（什么样的人能帮到命主）
PROMPT;

        return $this->generate($prompt, $systemPrompt);
    }

    // 生成合婚分析
    public function generateMarriageReading($malePan, $femalePan)
    {
        $systemPrompt = <<<'PROMPT'
你是紫微斗数命理大师，专精合婚与感情分析。你的风格是坦诚直接，不粉饰太平也不刻意吓人。
分析原则：
1. 不要只看夫妻宫，要结合命宫、福德宫、迁移宫、子女宫综合判断
2. 要说清楚两个人在一起的"化学反应"——是互相成就还是互相消耗
3. 重点关注双方四化的交叉影响，忌星是否冲克对方的关键宫位
4. 给出实际可操作的相处建议，不要说"多沟通"这种废话
PROMPT;

        $prompt = <<<PROMPT
请进行深度合婚分析：

男方（乾造）命盘：
{$malePan}

女方（坤造）命盘：
{$femalePan}

请按以下结构分析，要有洞察力：

## 【缘分本质】
- 双方命宫主星的互动关系：性格上是互补还是冲突？谁更强势？
- 双方生年四化有没有交叉落入对方的关键宫位？这代表什么样的缘分纠葛？
- 一句话概括：这段关系的核心主题是什么（相互依赖？相爱相杀？细水长流？激情燃烧？）

## 【感情风险点】
- 双方夫妻宫的星曜组合各自暗示什么样的感情模式？有没有"天生不安分"的迹象？
- 有没有第三者介入的风险？在哪个时间段需要特别警惕？
- 双方福德宫对比：价值观和生活态度上有没有根本性的矛盾？
- 最容易因为什么事情吵架或产生裂痕？

## 【婚姻走势】
- 如果已婚：婚后感情是越来越好还是越来越淡？哪几年是婚姻危机期？
- 如果未婚：适合什么时候结婚？太早或太晚各有什么风险？
- 子女缘如何？生育对这段关系的影响是正面还是负面？

## 【相处指南】
- 两个人在一起，谁应该在哪些方面让步？
- 经济上应该怎么安排（AA？一方管钱？共同账户？）
- 最能增进感情的方式是什么？最应该避免的雷区是什么？

## 【综合评分】
给出配对指数（百分制），并用一句犀利的话总结这段关系的真相。
PROMPT;

        return $this->generate($prompt, $systemPrompt);
    }

    // 生成财运分析
    public function generateWealthReading($panData, $age1 = 25, $age2 = 34, $age3 = 35, $age4 = 44)
    {
        $systemPrompt = <<<'PROMPT'
你是紫微斗数命理大师，专精财运分析。你的风格是实战派，不说虚的，直接告诉命主：你的钱从哪来、什么时候来、什么时候要守住。
分析原则：
1. 必须区分"正财"和"偏财"的格局，告诉命主靠什么赚钱最靠谱
2. 生年禄的位置决定了命主天生的财富来源，生年忌的位置决定了最大的破财风险
3. 结合大运和流年，精准定位财运的高峰期和低谷期
4. 给出可操作的理财建议，不要说"量入为出"这种废话
PROMPT;

        $prompt = <<<PROMPT
请根据以下命盘进行深度财运分析：

{$panData}

请按以下结构分析，要精准犀利：

## 【财富基因】
- 看财帛宫、福德宫、田宅宫的主星组合，判断命主是"稳定收入型"还是"暴发暴落型"还是"细水长流型"
- 生年禄落在哪个宫位？这是命主天生的财富密码——钱最容易从这个方向来
- 生年忌落在财务相关宫位的话，最大的破财陷阱是什么？
- 命主更适合"靠自己赚"还是"靠资源赚"还是"靠投资赚"？

## 【财运时间轴】
逐段分析成年后的大运：
- {$age1}-{$age2}岁：这段时间财运如何？是积累期还是收获期？有没有重大的破财事件？
- {$age3}-{$age4}岁：财运是上升还是下降？有没有暴富或破产的极端可能？
- 哪个大运是财运真正的黄金期？哪个大运要捂紧钱包？
- 精确到年份：最近几年有没有特别好或特别差的财运年？

## 【当前财务状况】
结合当前大运和今年流年：
- 今年财帛宫、福德宫的飞星状况如何？
- 命主当前最可能面临的财务问题（收入下降？投资亏损？被骗破财？开支过大？）
- 今年适不适合做重大投资决策？

## 【实战建议】
- 最适合命主的赚钱方式（打工、创业、投资、副业？具体什么类型？）
- 房产运势：什么时候买房最有利？有没有不动产暴涨的机会？
- 需要绝对避开的破财陷阱（借钱给人？合伙投资？赌博？担保？）
- 未来3年的财务策略：现在应该"攻"还是"守"？
PROMPT;

        return $this->generate($prompt, $systemPrompt);
    }

    // 生成健康分析
    public function generateHealthReading($panData, $currentYear = null)
    {
        $currentYear = $currentYear ?? date('Y');
        $systemPrompt = <<<'PROMPT'
你是紫微斗数命理大师，专精健康与身心分析。你的风格是负责任的直言不讳——该提醒的风险一定要说清楚，但也不要制造恐慌。
分析原则：
1. 疾厄宫的主星和煞星决定了先天体质弱项，必须具体到器官和系统
2. 结合大运流年中疾厄宫的飞星变化，判断什么时候容易出健康问题
3. 福德宫关系到精神健康和心理状态，不能忽略
4. 给出针对性的养生建议，不要泛泛说"多运动多喝水"
PROMPT;

        $prompt = <<<PROMPT
请根据以下命盘进行深度健康分析：

{$panData}

请按以下结构分析，要具体实用：

## 【先天体质档案】
- 疾厄宫主星和煞星组合，对应的先天体质弱项是什么？具体到器官和身体系统
- 命宫和身宫的星曜对身体素质有什么影响？命主是偏壮还是偏弱的体质？
- 有没有遗传性疾病的迹象？（结合父母宫判断）

## 【健康风险时间轴】
- 回顾过去的大运，有没有已经发生过的重大健康事件（手术、住院、意外伤害）？大概在什么时期？
- 当前大运中，疾厄宫的飞星状况如何？是平安期还是高危期？
- 未来哪个大运或哪几年需要特别注意健康？有没有手术、重大疾病的征兆？
- 需要重点监测的具体疾病方向（不要说"注意身体"，要说"注意心血管/肝脏/腰椎"等具体部位）

## 【{$currentYear}年健康预警】
- 今年疾厄宫、命宫的流年飞星如何？
- 今年最需要警惕的健康问题是什么？
- 哪几个月份是健康低谷期，需要特别小心？
- 有没有意外伤害（车祸、摔伤、烫伤等）的迹象？

## 【心理与精神状态】
- 福德宫的星曜组合揭示命主的精神状态：容不容易焦虑？会不会抑郁？睡眠质量如何？
- 当前大运和流年中，有没有精神压力特别大的时期？
- 命主的情绪管理弱点在哪里？

## 【养生处方】
- 根据命盘体质特点，最适合命主的运动方式（不要泛泛推荐，要结合体质说）
- 饮食上最需要注意什么？应该多吃什么、避免什么？
- 几个最重要的健康提醒（优先级排序，最多3条）
PROMPT;

        return $this->generate($prompt, $systemPrompt);
    }
}
