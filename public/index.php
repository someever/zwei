<!DOCTYPE html>
<html lang="zh-CN">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>知运星 - 探索你的命运轨迹</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
</head>

<body>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/zh.js"></script>
    <div class="container">
        <header class="header">
            <div class="logo">
                <img src="images/icon.png" alt="知运星" class="app-icon" onerror="this.style.display='none'">
                <span class="logo-icon">🔮</span>
            </div>
            <h1>知运星</h1>
            <p class="subtitle">探索你的命运轨迹</p>
        </header>

        <main class="main">
            <!-- 切换模式 -->
            <div class="mode-switch">
                <button class="mode-btn active" data-mode="form">✍️ 手工输入</button>
                <button class="mode-btn" data-mode="chat">🤖 AI 解析</button>
            </div>

            <!-- 手工输入模式 -->
            <form id="birthForm" class="form mode-form active">
                <div class="form-group">
                    <label for="name">姓名</label>
                    <input type="text" id="name" name="name" placeholder="请输入姓名" required>
                </div>

                <div class="form-group">
                    <label>性别</label>
                    <div class="radio-group">
                        <label class="radio-label">
                            <input type="radio" name="gender" value="male" checked>
                            <span>男</span>
                        </label>
                        <label class="radio-label">
                            <input type="radio" name="gender" value="female">
                            <span>女</span>
                        </label>
                    </div>
                </div>

                <div class="form-group">
                    <label for="birthDate">出生日期</label>
                    <input type="date" id="birthDate" name="birthDate" required>
                </div>

                <div class="form-group">
                    <label for="birthTime">出生时间</label>
                    <div class="time-inputs">
                        <select id="birthHour" name="birthHour" required>
                            <?php for ($i = 0; $i < 24; $i++): ?>
                                <option value="<?= $i ?>"><?= str_pad($i, 2, '0', STR_PAD_LEFT) ?> 时</option>
                            <?php endfor; ?>
                        </select>
                        <select id="birthMinute" name="birthMinute" required>
                            <?php for ($i = 0; $i < 60; $i++): ?>
                                <option value="<?= $i ?>"><?= str_pad($i, 2, '0', STR_PAD_LEFT) ?> 分</option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>

                <div class="form-group row">
                    <div class="col">
                        <label for="provinceSelect">出生省份</label>
                        <select id="provinceSelect" name="province" required>
                            <option value="">请选择省份</option>
                        </select>
                    </div>
                    <div class="col">
                        <label for="citySelect">出生城市</label>
                        <select id="citySelect" name="city" required>
                            <option value="">请选择城市</option>
                        </select>
                    </div>
                </div>

                <button type="submit" class="btn-primary">开始算命</button>
            </form>

            <!-- 聊天模式 -->
            <div id="chatMode" class="chat-container mode-form">
                <div class="chat-messages" id="chatMessages">
                    <div class="chat-message bot">
                        <div class="chat-avatar">🔮</div>
                        <div class="chat-content">
                            <p>你好！我是知运星助手</p>
                            <p>请告诉我你的出生信息，例如：</p>
                            <p class="example">"我1995年8月15日下午3点半在北京出生，男"</p>
                            <p>或者直接描述你的情况，我帮你解析～</p>
                        </div>
                    </div>
                </div>
                <div class="chat-input-container">
                    <input type="text" id="chatInput" placeholder="输入你的出生信息..." autocomplete="off">
                    <button id="sendBtn" class="btn-send">发送</button>
                </div>
            </div>
        </main>

        <footer class="footer">
            <p>© 2026 知运星 · 紫微斗数传承</p>
            <script src="js/main.js"></script>
            <script src="js/chat.js"></script>
</body>

</html>