# BotWriter CSS 样式分析报告
> 生成日期：2026-06-09
> 来源：BotWriter v3.4.1 (WordPress.org)

---

## 一、颜色体系

### 主色调

| 用途 | 色值 | 说明 |
|------|------|------|
| 按钮主色 | `#04AA6D` | 绿色，品牌识别色 |
| Tab/链接激活 | `#2271b1` | WordPress 标准蓝 |
| 渐变 Banner | `#667eea` → `#764ba2` | 紫蓝渐变 |
| 卡片阴影 | `rgba(0,0,0,.06)` ~ `rgba(0,0,0,.1)` | 轻阴影到重阴影 |
| 背景灰 | `#f6f7f7` | Tab 区域背景 |
| 白底 | `#fff` | 卡片/内容区 |

## 二、按钮样式

```css
/* 主按钮 */
.button {
    background-color: #04AA6D;
    border: none;
    color: white;
    padding: 10px 25px;
    font-size: 18px;
}

/* 标准按钮 */
.button-large {
    padding: 8px 30px !important;
    font-size: 14px !important;
}
```

**要点：**
- 绿色主按钮，大号（18px），醒目
- 无边框（border: none）
- 白色文字

## 三、输入框/表单样式

```css
.super-title-input {
    font-size: 20px;
    padding: 5px;
    border-radius: 4px;
}
.super-text-input {
    font-size: 16px;
    padding: 5px;
    border-radius: 4px;
}
```

**要点：** `border-radius: 4px`，标题框号大 20px

## 四、卡片/容器样式

```css
/* 通用卡片 */
.bw-woo-card {
    background: #fff;
    padding: 22px 28px;
    border-radius: 10px;
    box-shadow: 0 2px 8px rgba(0,0,0,.08);
    margin-bottom: 20px;
}
/* WooCommerce 头部渐变 */
.bw-woo-header {
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: #fff;
    padding: 24px 30px;
    border-radius: 12px;
    box-shadow: 0 4px 15px rgba(102,126,234,.3);
}
```

**要点：** 圆角 8px~12px，白底+阴影，hover 加深

## 五、布局系统

```css
.botwriter-main-tabs {
    display: flex;
    border-bottom: 2px solid #2271b1;
}
.provider-config-section {
    display: grid;
    gap: 20px;
}
.bw-fields-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
    gap: 12px;
}
```

**要点：** 大量 CSS Grid，Flex 用于导航/操作行

## 六、与 WooAI Desc 样式对比

| 维度 | WooAI Desc | BotWriter | 建议 |
|------|-----------|-----------|------|
| 按钮主色 | `#2271b1`（蓝） | `#04AA6D`（绿） | 保持蓝色 |
| 圆角 | 8px | 4px~12px | ✔️ 8px 更现代 |
| 卡片阴影 | 轻 0 1px 3px | 中 0 2px 8px | 可加重 |
| Grid布局 | 无 | ✔️ auto-fill | 可借鉴 |
| 渐变 Banner | 紫蓝 | 紫蓝类似 | 基本一致 |
| 动画 | fadeIn | spin+hover | 可补充 |
