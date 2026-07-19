# BotWriter 架构分析报告

> 2026-06-09

---

## CSS 架构

| 文件 | 大小 | 用途 |
|------|------|------|
| style.css | 30KB | 主样式 |
| settings.css | 18KB | 设置页 |
| woo-ai.css | 19KB | WooCommerce AI |
| seo.css | 37KB | SEO 模块 |
| jquery-ui.css | 37KB | jQuery UI |
| editor-ai-assistant.css | 16KB | 编辑器 |
| 其他 | ~8KB | loader/banner |

总量约 150KB CSS。无预处理器，原生 CSS。

## 技术栈对比

| 技术 | BotWriter | WooAI Desc |
|------|-----------|-----------|
| 前端框架 | jQuery + 原生 JS | React + @wordpress/components |
| 构建工具 | 无 | Webpack |
| CSS 方案 | 原生 CSS | SCSS |
| 插件架构 | 传统 WP | REST API + React SPA |

