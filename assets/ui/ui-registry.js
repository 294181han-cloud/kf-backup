// ui-registry.js - 多 UI 注册表（唯一需要登记模板/配色的地方）
//
// 结构：分类（pc 电脑端 / mobile 手机端）→ 模板（结构性外观）→ 配色（颜色方案）。
// - 模板：可整体改变界面结构/布局的完整 UI，css 可为 null（沿用基础样式结构）。
// - 配色：模板之下的颜色方案，只换颜色不动结构；data-ui 属性 = 配色 id。
// 新增模板/配色步骤见 UI-DEV.md（根目录）。改完任何内容后把 version +1（缓存破坏）。
window.UI_STYLES = {
  version: '2.4.0',

  categories: {
    // ===== 电脑端（工作台 app.html / 管理台 admin.html）=====
    pc: {
      label: '电脑端',
      pages: ['app.html', 'admin.html'],
      defaultTemplate: 'default',
      base: '/assets/ui/pc/_base.css',   // 所有模板共用的接线（el 主色映射等），最先加载
      templates: [
        {
          id: 'default', name: '默认', css: null,   // 当前唯一模板：经典工作台结构
          colors: [
            { id: 'default',  name: '经典蓝', chip: '#1890ff', css: '/assets/ui/pc/default/theme.css' },
            { id: 'purple',   name: '优雅紫', chip: '#722ed1', css: '/assets/ui/pc/purple/theme.css' },
            { id: 'sky',      name: '天空蓝', chip: '#13c2c2', css: '/assets/ui/pc/sky/theme.css' },
            { id: 'green',    name: '清新绿', chip: '#52c41a', css: '/assets/ui/pc/green/theme.css' },
            { id: 'rose',     name: '玫瑰红', chip: '#eb2f96', css: '/assets/ui/pc/rose/theme.css' },
            { id: 'sunset',   name: '暖阳橙', chip: '#fa8c16', css: '/assets/ui/pc/sunset/theme.css' },
            { id: 'indigo',   name: '靛蓝',   chip: '#4c6ef5', css: '/assets/ui/pc/indigo/theme.css' },
            { id: 'graphite', name: '石墨灰', chip: '#475569', css: '/assets/ui/pc/graphite/theme.css' },
          ],
        },
      ],
    },

    // ===== 手机端（访客页 visitor.html）=====
    mobile: {
      label: '手机端',
      pages: ['visitor.html'],
      defaultTemplate: 'default',
      base: null,
      templates: [
        {
          id: 'default', name: '默认', css: null,
          colors: [
            { id: 'default', name: '默认亮色', chip: '#1677ff', css: '/assets/ui/mobile/default/theme.css' },
            { id: 'dark',    name: '暗色',     chip: '#1c2333', css: '/assets/ui/mobile/dark/theme.css' },
            { id: 'mint',    name: '薄荷',     chip: '#10b981', css: '/assets/ui/mobile/mint/theme.css' },
            { id: 'sakura',  name: '樱花粉',   chip: '#ec4899', css: '/assets/ui/mobile/sakura/theme.css' },
            { id: 'sunset',  name: '暖阳橙',   chip: '#f97316', css: '/assets/ui/mobile/sunset/theme.css' },
            { id: 'taro',    name: '香芋紫',   chip: '#8b5cf6', css: '/assets/ui/mobile/taro/theme.css' },
          ],
        },
      ],
    },
  },
};
