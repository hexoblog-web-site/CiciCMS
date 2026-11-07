import { defineConfig } from 'vitepress'

export default defineConfig({
  lang: 'zh-CN',
  title: 'CiciCMS',
  description: 'PHP + MySQL 静态化内容管理系统 · 使用手册',

  head: [
    ['link', { rel: 'icon', href: '/images/favicon.svg' }],
    ['meta', { name: 'theme-color', content: '#2563eb' }],
    ['meta', { name: 'author', content: 'hexoblog-web-site' }],
    ['meta', { property: 'og:type', content: 'website' }],
    ['meta', { property: 'og:title', content: 'CiciCMS 使用手册' }],
    ['meta', { property: 'og:description', content: '不用命令行、不用 Composer，装完就能写文章的静态化 CMS。' }]
  ],

  markdown: {
    lineNumbers: false,
    image: { lazyLoading: true }
  },

  themeConfig: {
    logo: '/images/logo.svg',

    nav: [
      { text: '开始使用', link: '/guide/what-is', activeMatch: '^/guide/' },
      { text: '为什么选它', link: '/why/', activeMatch: '^/why/' },
      { text: '后台使用', link: '/use/', activeMatch: '^/use/' },
      { text: '模板开发', link: '/theme/', activeMatch: '^/theme/' },
      { text: '代码手册', link: '/dev/', activeMatch: '^/dev/' },
      { text: '部署运维', link: '/ops/', activeMatch: '^/ops/' },
      { text: '常见问题', link: '/faq' }
    ],

    sidebar: {
      '/guide/': [
        {
          text: '开始使用',
          items: [
            { text: '这是什么程序', link: '/guide/what-is' },
            { text: '环境要求', link: '/guide/requirement' },
            { text: '安装（图文）', link: '/guide/install' },
            { text: '发布第一篇', link: '/guide/first-post' },
            { text: '升级', link: '/guide/upgrade' },
            { text: '搬迁到新服务器', link: '/guide/migrate' }
          ]
        }
      ],
      '/why/': [
        {
          text: '为什么选它',
          items: [
            { text: '优点与解决的痛点', link: '/why/' },
            { text: '和别的方案比', link: '/why/compare' }
          ]
        }
      ],
      '/use/': [
        {
          text: '后台使用',
          items: [
            { text: '后台地图', link: '/use/' },
            { text: '写文章', link: '/use/article' },
            { text: '栏目管理', link: '/use/category' },
            { text: '单页', link: '/use/page' },
            { text: '媒体信息', link: '/use/media' },
            { text: '友情链接', link: '/use/flink' },
            { text: '生成静态', link: '/use/build' },
            { text: '系统清理', link: '/use/clean' },
            { text: '数据备份与恢复', link: '/use/data' },
            { text: '模板管理', link: '/use/theme' },
            { text: '系统设置', link: '/use/setting' },
            { text: '插件', link: '/use/plugin' },
            { text: '系统更新', link: '/use/update' }
          ]
        }
      ],
      '/theme/': [
        {
          text: '模板开发',
          items: [
            { text: '五分钟改出一个主题', link: '/theme/' },
            { text: '页面与模板对照表', link: '/theme/pages' },
            { text: '变量与字段', link: '/theme/variables' },
            { text: '标签速查', link: '/theme/tags' },
            { text: '修饰器', link: '/theme/modifiers' },
            { text: 'PHP 模板（高级）', link: '/theme/php' }
          ]
        }
      ],
      '/dev/': [
        {
          text: '代码手册',
          items: [
            { text: '代码地图', link: '/dev/' },
            { text: '文件说明', link: '/dev/files' },
            { text: '函数手册', link: '/dev/functions' },
            { text: '钩子一览', link: '/dev/hooks' },
            { text: '数据表字典', link: '/dev/database' },
            { text: '请求是怎么走完一圈的', link: '/dev/flow' }
          ]
        }
      ],
      '/ops/': [
        {
          text: '部署运维',
          items: [
            { text: '部署配置', link: '/ops/deploy' },
            { text: '权限模型（重点）', link: '/ops/permission' },
            { text: '安全加固清单', link: '/ops/security' },
            { text: 'URL 与 SEO', link: '/ops/url' }
          ]
        }
      ]
    },

    socialLinks: [{ icon: 'github', link: 'https://github.com/hexoblog-web-site/CiciCMS' }],

    search: {
      provider: 'local',
      options: {
        translations: {
          button: { buttonText: '搜索文档', buttonAriaLabel: '搜索文档' },
          modal: {
            displayDetails: '显示详情',
            resetButtonTitle: '清除查询条件',
            backButtonTitle: '返回',
            noResultsText: '没有找到结果',
            footer: {
              selectText: '选择',
              navigateText: '切换',
              closeText: '关闭'
            }
          }
        }
      }
    },

    outline: { level: [2, 3], label: '本页目录' },
    docFooter: { prev: '上一篇', next: '下一篇' },
    returnToTopLabel: '回到顶部',
    sidebarMenuLabel: '菜单',
    darkModeSwitchLabel: '主题',
    lightModeSwitchTitle: '切换到浅色',
    darkModeSwitchTitle: '切换到深色',

    footer: {
      message: '以 MIT 许可证发布 · 软件按「现状」提供',
      copyright: 'Copyright © 2026 hexoblog-web-site'
    },

    editLink: {
      pattern: 'https://github.com/hexoblog-web-site/CiciCMS/edit/main/docs/:path',
      text: '在 GitHub 上修改这一页'
    }
  }
})
