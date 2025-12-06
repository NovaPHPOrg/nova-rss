# RSS 订阅模块

## 功能说明

为 Nova Wiki 系统提供标准的 RSS 2.0 订阅功能，用户可以通过 RSS 阅读器订阅文档更新。

## 设计哲学（Linus式）

> "Bad programmers worry about the code. Good programmers worry about data structures."

- **数据结构优先**：RSS 本质是 XML 格式的文档列表，直接从现有的 DocDao 读取数据
- **消除特殊情况**：只支持 RSS 2.0 标准，零配置，开箱即用
- **简单实用**：一个生成器类 + 一个控制器 = 完整功能

## 使用方法

### 访问 RSS Feed

每个域名都有独立的 RSS 订阅地址：

```
https://your-domain.com/rss.xml
```

**默认行为：全文模式**
- 输出完整的 HTML 内容
- 支持离线阅读
- 在 RSS 阅读器中完整展示格式化文档

### Feed 内容

- **最新文档**：自动展示最近更新的 20 篇文档
- **过滤规则**：只展示已发布的文档（deleted_at = 0）
- **排序方式**：按更新时间倒序
- **更新频率**：实时生成，缓存 1 小时

### Feed 信息

每个文档项包含：

- **标题**：文档标题
- **链接**：文档完整 URL
- **内容**：输出完整的 HTML 内容（由前端 Markdown 编辑器渲染生成）
  - 优先使用 `html` 字段（完整 HTML）
  - 降级使用 `description` 字段（自定义描述）
- **作者**：文档作者（如果设置）
- **发布时间**：使用 meta_publish_time 或 updated_at
- **分类**：标签（meta_label）、父级分类（parent_title）、自定义标签（meta_tags）

## 技术细节

### 文件结构

```
rss/
├── RssGenerator.php    # RSS XML 生成器
├── package.php         # 插件配置（零依赖）
└── README.md          # 说明文档
```

### 核心类

#### RssGenerator

生成符合 RSS 2.0 规范的 XML 字符串。

**主要方法：**

- `generate(array $channel, array $items): string` - 生成完整的 RSS feed
- `truncateDescription(string $text, int $maxLength): string` - 截取描述文本

**特点：**
- 零依赖，不使用第三方 XML 库
- 符合 RSS 2.0 规范
- 支持中文和特殊字符（自动转义）

#### Rss Controller

处理 `/rss.xml` 路由请求。

**功能：**
- 从 DomainModel 读取站点信息
- 从 DocDao 读取最近文档
- 调用 RssGenerator 生成 XML
- 返回带正确 Content-Type 的响应

### 数据来源

```php
// 站点信息
$channel = [
    'title'       => $domainModel->name,
    'link'        => $siteUrl,
    'description' => $domainModel->description,
    'language'    => 'zh-CN',
];

// 文档列表（包含 html 字段）
$docs = DocDao::getInstance($domainId)->getRecentDocs();
```

### HTML 生成机制

**数据流：**
```
用户编辑 Markdown 
  ↓
前端 Cherry 编辑器实时渲染
  ↓
保存时调用 getHtml() 获取 HTML
  ↓
同时保存 content (Markdown) 和 html (HTML) 到数据库
  ↓
RSS 直接读取 html 字段（零服务端渲染）
```

**为什么这样设计？**
> "Bad programmers worry about the code. Good programmers worry about data structures."

- ✅ 前端已有 Cherry 编辑器，不需要服务端重复实现 Markdown 渲染
- ✅ 预渲染比实时渲染更高效（RSS 请求时零计算）
- ✅ 保证 RSS 输出的 HTML 与前端显示完全一致
- ✅ 简化服务端逻辑（DRY - Don't Repeat Yourself）

### 缓存策略

响应头包含：

```
Content-Type: application/rss+xml; charset=UTF-8
Cache-Control: public, max-age=3600
```

- 浏览器和 CDN 可缓存 1 小时
- RSS 阅读器通常也会自己控制刷新频率

## 集成方式

### 1. 路由注册

在 `Application.php` 中添加路由：

```php
->get("/rss.xml", route("index", "rss", "index"))
```

### 2. 自动发现

在网站 `<head>` 中添加（可选）：

```html
<link rel="alternate" type="application/rss+xml" 
      title="订阅更新" 
      href="/rss.xml" />
```

## 扩展说明

### 自定义 Feed 内容

可以修改 `Rss.php` 控制器：

```php
// 自定义文档数量
$docs = $docDao->getRecentDocs(50); // 默认 20

// 自定义描述生成逻辑（降级使用）
private function generateDescription(array $doc): string {
    // 当 html 字段为空时使用
}

// 自定义分类解析
private function parseCategories(array $doc): array {
    // 你的逻辑
}

// 如果想切换回摘要模式
$isFullContent = false; // 只输出 description，不输出完整 HTML
```

### 为什么默认全文模式？

**优势：**
- ✅ 在 RSS 阅读器中阅读完整内容，无需跳转
- ✅ 支持离线阅读
- ✅ 完整保留格式：代码高亮、图片、表格等
- ✅ 适合技术文档和博客

**考虑：**
- ⚠️ Feed 文件较大（可能几 MB）
- ⚠️ 对服务器带宽有一定要求
- ✅ 可以通过 CDN 和浏览器缓存优化（已设置 1 小时缓存）

## 测试

访问 `/rss.xml`，应该看到类似输出（默认全文模式）：

```xml
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">
<channel>
  <title>站点名称</title>
  <link>https://your-domain.com</link>
  <description>站点描述</description>
  <language>zh-CN</language>
  <lastBuildDate>Sat, 06 Dec 2025 12:00:00 +0800</lastBuildDate>
  <generator>Nova Wiki RSS Generator</generator>
  
  <item>
    <title>文档标题</title>
    <link>https://your-domain.com/docs/example.md</link>
    <description><![CDATA[
      <h1>文档标题</h1>
      <p>这是完整的HTML内容，保留所有格式...</p>
      <pre><code class="language-javascript">
        console.log('hello world');
      </code></pre>
      <blockquote>
        <p>引用内容也完整保留</p>
      </blockquote>
      <!-- 完整的渲染后HTML -->
    ]]></description>
    <guid isPermaLink="true">https://your-domain.com/docs/example.md</guid>
    <pubDate>Fri, 05 Dec 2025 10:00:00 +0800</pubDate>
    <category>分类1</category>
  </item>
  
  <!-- 更多文章... -->
</channel>
</rss>
```

**说明：**
- 使用 `<![CDATA[...]]>` 包裹 HTML 内容，确保特殊字符不被转义
- 完整保留 Markdown 渲染后的所有格式
- 代码高亮、图片、表格等元素都正常显示

## 兼容性

- 符合 RSS 2.0 规范
- 支持所有主流 RSS 阅读器（Feedly、Inoreader、NetNewsWire 等）
- 支持浏览器内置 RSS 订阅功能

## 性能优化

当前实现已经足够高效：

- 使用现有的 `getRecentDocs()` 方法（已优化的 SQL）
- 响应缓存 1 小时
- 无额外数据库查询
- 无外部依赖

> "过早优化是万恶之源。" —— Donald Knuth

如果真的遇到性能问题（日访问量 > 10万），可以考虑：

1. 增加服务器端缓存（Redis/Memcached）
2. 使用 CDN 缓存 RSS 文件
3. 使用静态文件生成（定时任务）

但在那之前，不要做任何优化。

## 许可证

与主项目保持一致。
