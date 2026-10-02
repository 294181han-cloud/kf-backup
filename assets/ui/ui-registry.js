// ui-registry.js - 多 UI 款式注册表（唯一需要登记新款式的地方）
//
// 结构：分类（pc / mobile）→ 款式列表。每个款式一个目录一个 theme.css。
// 新增款式步骤见 UI-DEV.md（根目录）。改完任何 theme.css 后把 version +1（缓存破坏）。
window.UI_STYLES = {
  version: '2.2.0',

  categories: {
    // ===== PC 端（工作台 app.html / 管理台 admin.html）=====
    pc: {
      label: 'PC 端',
      pages: ['app.html', 'admin.html'],
      default: 'default',
      base: '/assets/ui/pc/_base.css',   // 所有 PC 款式共用的接线（el 主色映射等），先于款式加载
      styles: [
        { id: 'default', name: '经典蓝', chip: '#1890ff', css: '/assets/ui/pc/default/theme.css' },
        { id: 'purple',  name: '优雅紫', chip: '#722ed1', css: '/assets/ui/pc/purple/theme.css' },
        { id: 'sky',     name: '天空蓝', chip: '#13c2c2', css: '/assets/ui/pc/sky/theme.css' },
        { id: 'green',   name: '清新绿', chip: '#52c41a', css: '/assets/ui/pc/green/theme.css' },
      ],
    },

    // ===== 手机端（访客页 visitor.html）=====
    mobile: {
      label: '手机端',
      pages: ['visitor.html'],
      default: 'default',
      base: null,
      styles: [
        { id: 'default', name: '默认亮色', chip: '#1677ff', css: '/assets/ui/mobile/default/theme.css' },
        { id: 'dark',    name: '暗色',     chip: '#1c2333', css: '/assets/ui/mobile/dark/theme.css' },
      ],
    },
  },
};
