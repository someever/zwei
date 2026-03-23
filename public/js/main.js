/**
 * 紫微斗数算命系统 - 前端脚本
 */

document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('birthForm');
    
    if (form) {
        form.addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const submitBtn = form.querySelector('button[type="submit"]');
            submitBtn.disabled = true;
            submitBtn.textContent = '排盘中...';
            
            try {
                // 收集表单数据
                const formData = new FormData(form);
                const data = {
                    name: formData.get('name'),
                    gender: formData.get('gender'),
                    birthDate: formData.get('birthDate'),
                    birthHour: parseInt(formData.get('birthHour')),
                    birthMinute: parseInt(formData.get('birthMinute')),
                    birthLocation: formData.get('birthLocation'),
                    package: formData.get('package')
                };
                
                // 解析出生日期
                const dateParts = data.birthDate.split('-');
                data.birthYear = parseInt(dateParts[0]);
                data.birthMonth = parseInt(dateParts[1]);
                data.birthDay = parseInt(dateParts[2]);
                
                // 调用排盘API
                const response = await fetch('/api/pan.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(data)
                });
                
                const result = await response.json();
                
                if (result.success) {
                    // 保存到sessionStorage
                    sessionStorage.setItem('pan_data', JSON.stringify(result.pan_data));
                    sessionStorage.setItem('reading_id', result.reading_id);
                    sessionStorage.setItem('overall_reading', result.overall_reading);
                    
                    // 跳转到结果页
                    window.location.href = '/result.php';
                } else {
                    alert('排盘失败: ' + result.message);
                    submitBtn.disabled = false;
                    submitBtn.textContent = '开始算命';
                }
            } catch (error) {
                console.error('Error:', error);
                alert('发生错误，请重试');
                submitBtn.disabled = false;
                submitBtn.textContent = '开始算命';
            }
        });
    }
    
    // 套餐选择更新
    const packageInputs = document.querySelectorAll('input[name="package"]');
    packageInputs.forEach(input => {
        input.addEventListener('change', function() {
            // 可以添加统计分析代码
            console.log('Selected package:', this.value);
        });
    });
});

/**
 * 格式化日期为中文
 */
function formatDateChinese(date) {
    const year = date.getFullYear();
    const month = date.getMonth() + 1;
    const day = date.getDate();
    return `${year}年${month}月${day}日`;
}

/**
 * 获取星座（如果有需要）
 */
function getZodiac(month, day) {
    const zodiacs = [
        { start: [1, 20], name: '水瓶座' },
        { start: [2, 19], name: '双鱼座' },
        { start: [3, 21], name: '白羊座' },
        { start: [4, 20], name: '金牛座' },
        { start: [5, 21], name: '双子座' },
        { start: [6, 21], name: '巨蟹座' },
        { start: [7, 23], name: '狮子座' },
        { start: [8, 23], name: '处女座' },
        { start: [9, 23], name: '天秤座' },
        { start: [10, 23], name: '天蝎座' },
        { start: [11, 22], name: '射手座' },
        { start: [12, 22], name: '摩羯座' }
    ];
    
    for (let i = zodiacs.length - 1; i >= 0; i--) {
        if (month > zodiacs[i].start[0] || 
            (month === zodiacs[i].start[0] && day >= zodiacs[i].start[1])) {
            return zodiacs[i].name;
        }
    }
    return zodiacs[0].name;
}
