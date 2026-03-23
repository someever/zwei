// 聊天模式逻辑
document.addEventListener('DOMContentLoaded', function() {
    const modeBtns = document.querySelectorAll('.mode-btn');
    const modeForms = document.querySelectorAll('.mode-form');
    const chatInput = document.getElementById('chatInput');
    const sendBtn = document.getElementById('sendBtn');
    const chatMessages = document.getElementById('chatMessages');

    // 切换模式
    modeBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            const mode = this.dataset.mode;
            
            modeBtns.forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            
            modeForms.forEach(form => {
                form.classList.remove('active');
                if (form.id === 'birthForm' && mode === 'form') {
                    form.classList.add('active');
                } else if (form.id === 'chatMode' && mode === 'chat') {
                    form.classList.add('active');
                }
            });
        });
    });

    // 发送消息
    function sendMessage() {
        const message = chatInput.value.trim();
        if (!message) return;

        // 添加用户消息
        addMessage(message, 'user');
        chatInput.value = '';

        // 显示加载状态
        const loadingMsg = addMessage('正在解析...', 'bot', true);

        // 调用AI解析
        fetch('/api/parse.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({message})
        })
        .then(r => r.json())
        .then(data => {
            loadingMsg.remove();
            
            if (data.success) {
                // 解析成功，填充表单并切换
                fillForm(data.data);
                addMessage('已识别到你的信息：', 'bot');
                addMessage(formatParsedData(data.data), 'bot');
                
                setTimeout(() => {
                    // 切换到表单模式
                    modeBtns[0].click();
                    // 自动提交
                    document.getElementById('birthForm').dispatchEvent(new Event('submit'));
                }, 1500);
            } else {
                addMessage(data.message || '抱歉，我没有理解你的意思，请换个方式描述试试～', 'bot');
            }
        })
        .catch(err => {
            loadingMsg.remove();
            addMessage('解析失败，请使用表单模式输入详细信息', 'bot');
        });
    }

    // 添加消息到界面
    function addMessage(text, type, isLoading = false) {
        const msgDiv = document.createElement('div');
        msgDiv.className = `chat-message ${type}`;
        
        if (isLoading) {
            msgDiv.innerHTML = `
                <div class="chat-avatar">🔮</div>
                <div class="chat-content chat-loading">${text}</div>
            `;
        } else {
            const avatar = type === 'user' ? '👤' : '🔮';
            msgDiv.innerHTML = `
                <div class="chat-avatar">${avatar}</div>
                <div class="chat-content">${text.replace(/\n/g, '<br>')}</div>
            `;
        }
        
        chatMessages.appendChild(msgDiv);
        chatMessages.scrollTop = chatMessages.scrollHeight;
        
        return msgDiv;
    }

    // 填充表单
    function fillForm(data) {
        if (data.name) document.getElementById('name').value = data.name;
        if (data.gender) {
            const radios = document.querySelectorAll('input[name="gender"]');
            radios.forEach(r => {
                if (r.value === data.gender) r.checked = true;
            });
        }
        if (data.birthDate) {
            document.getElementById('birthDate').value = data.birthDate;
        }
        if (data.birthHour !== undefined) {
            document.getElementById('birthHour').value = data.birthHour;
        }
        if (data.birthMinute !== undefined) {
            document.getElementById('birthMinute').value = data.birthMinute;
        }
        if (data.birthLocation) {
            document.getElementById('birthLocation').value = data.birthLocation;
        }
    }

    // 格式化解析结果
    function formatParsedData(data) {
        let text = '';
        if (data.name) text += `姓名：${data.name}\n`;
        text += `性别：${data.gender === 'male' ? '男' : '女'}\n`;
        if (data.birthDate) text += `出生日期：${data.birthDate}\n`;
        if (data.birthHour !== undefined) {
            text += `出生时间：${data.birthHour}时${data.birthMinute || 0}分\n`;
        }
        if (data.birthLocation) text += `出生地：${data.birthLocation}\n`;
        text += '\n正在跳转到算命页面...';
        return text;
    }

    // 绑定事件
    sendBtn.addEventListener('click', sendMessage);
    chatInput.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') sendMessage();
    });
});
