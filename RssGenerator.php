<?php

declare(strict_types=1);

namespace nova\plugin\rss;

/**
 * RSS Feed 生成器
 *
 * 设计哲学（Linus式）：
 * - 数据结构优先：RSS 就是 XML，不要过度抽象
 * - 消除特殊情况：只支持 RSS 2.0，够用了
 * - 简单实用：直接生成 XML 字符串，不依赖第三方库
 *
 * RSS 2.0 规范：https://www.rssboard.org/rss-specification
 */
class RssGenerator
{
    /**
     * 生成 RSS 2.0 Feed
     *
     * @param array $channel 频道信息
     *                       - title: 频道标题（必需）
     *                       - link: 频道链接（必需）
     *                       - description: 频道描述（必需）
     *                       - language: 语言代码，如 zh-CN（可选）
     *                       - copyright: 版权信息（可选）
     *                       - managingEditor: 编辑邮箱（可选）
     *                       - webMaster: 网站管理员邮箱（可选）
     *                       - generator: 生成器名称（可选）
     *                       - image: 频道图片（可选）
     *                       - url: 图片URL
     *                       - title: 图片标题
     *                       - link: 图片链接
     *
     * @param array $items 文章列表
     *                     每个元素包含：
     *                     - title: 标题（必需）
     *                     - link: 链接（必需）
     *                     - description: 摘要描述（可选，如果没有提供会从 content 自动截取）
     *                     - content: 完整HTML内容（可选，输出到 content:encoded 字段用于全文RSS）
     *                     - author: 作者（可选）
     *                     - pubDate: 发布时间，Unix时间戳（可选）
     *                     - guid: 唯一标识符（可选，默认使用link）
     *                     - category: 分类数组（可选）
     *
     * @return string RSS XML 字符串
     */
    public static function generate(array $channel, array $items): string
    {
        // 验证必需字段
        if (empty($channel['title']) || empty($channel['link']) || empty($channel['description'])) {
            throw new \InvalidArgumentException('Channel must have title, link, and description');
        }

        // 开始构建 XML
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:content="http://purl.org/rss/1.0/modules/content/">' . "\n";
        $xml .= '<channel>' . "\n";

        // 必需的频道信息
        $xml .= '  <title>' . self::escape($channel['title']) . '</title>' . "\n";
        $xml .= '  <link>' . self::escape($channel['link']) . '</link>' . "\n";
        $xml .= '  <description>' . self::escape($channel['description']) . '</description>' . "\n";

        // atom:link (RSS 2.0 推荐)
        $xml .= '  <atom:link href="' . self::escape($channel['link']) . '/rss.xml" rel="self" type="application/rss+xml" />' . "\n";

        // 可选的频道信息
        if (!empty($channel['language'])) {
            $xml .= '  <language>' . self::escape($channel['language']) . '</language>' . "\n";
        }

        if (!empty($channel['copyright'])) {
            $xml .= '  <copyright>' . self::escape($channel['copyright']) . '</copyright>' . "\n";
        }

        if (!empty($channel['managingEditor'])) {
            $xml .= '  <managingEditor>' . self::escape($channel['managingEditor']) . '</managingEditor>' . "\n";
        }

        if (!empty($channel['webMaster'])) {
            $xml .= '  <webMaster>' . self::escape($channel['webMaster']) . '</webMaster>' . "\n";
        }

        // 最后构建日期（使用最新文章的时间，或当前时间）
        $latestTime = !empty($items[0]['pubDate']) ? $items[0]['pubDate'] : time();
        $xml .= '  <lastBuildDate>' . self::formatRFC822($latestTime) . '</lastBuildDate>' . "\n";

        // 生成器信息
        $generator = $channel['generator'] ?? 'Nova Wiki RSS Generator';
        $xml .= '  <generator>' . self::escape($generator) . '</generator>' . "\n";

        // 频道图片（可选）
        if (!empty($channel['image']) && !empty($channel['image']['url'])) {
            $xml .= '  <image>' . "\n";
            $xml .= '    <url>' . self::escape($channel['image']['url']) . '</url>' . "\n";
            $xml .= '    <title>' . self::escape($channel['image']['title'] ?? $channel['title']) . '</title>' . "\n";
            $xml .= '    <link>' . self::escape($channel['image']['link'] ?? $channel['link']) . '</link>' . "\n";
            $xml .= '  </image>' . "\n";
        }

        // 添加文章
        foreach ($items as $item) {
            $xml .= self::generateItem($item);
        }

        $xml .= '</channel>' . "\n";
        $xml .= '</rss>';

        return $xml;
    }

    /**
     * 生成单个文章项
     */
    private static function generateItem(array $item): string
    {
        if (empty($item['title']) || empty($item['link'])) {
            return ''; // 跳过不完整的项
        }

        $xml = '  <item>' . "\n";
        $xml .= '    <title>' . self::escape($item['title']) . '</title>' . "\n";
        $xml .= '    <link>' . self::escape($item['link']) . '</link>' . "\n";

        // description - 摘要（必需字段）
        $description = $item['description'] ?? '';

        if (!empty($description)) {
            // 如果包含HTML标签，使用CDATA包裹
            if (strip_tags($description) !== $description) {
                $xml .= '    <description><![CDATA[' . $description . ']]></description>' . "\n";
            } else {
                $xml .= '    <description>' . self::escape($description) . '</description>' . "\n";
            }
        }

        // content:encoded - 全文内容（扩展字段）
        if (!empty($item['content'])) {
            $xml .= '    <content:encoded><![CDATA[' . $item['content'] . ']]></content:encoded>' . "\n";
        }

        // GUID（唯一标识符）
        $guid = $item['guid'] ?? $item['link'];
        $isPermaLink = empty($item['guid']) ? 'true' : 'false';
        $xml .= '    <guid isPermaLink="' . $isPermaLink . '">' . self::escape($guid) . '</guid>' . "\n";

        // 作者
        if (!empty($item['author'])) {
            $xml .= '    <author>' . self::escape($item['author']) . '</author>' . "\n";
        }

        // 发布日期
        if (!empty($item['pubDate'])) {
            $xml .= '    <pubDate>' . self::formatRFC822($item['pubDate']) . '</pubDate>' . "\n";
        }

        // 分类
        if (!empty($item['category'])) {
            $categories = is_array($item['category']) ? $item['category'] : [$item['category']];
            foreach ($categories as $category) {
                $xml .= '    <category>' . self::escape($category) . '</category>' . "\n";
            }
        }

        $xml .= '  </item>' . "\n";

        return $xml;
    }

    /**
     * XML 转义
     *
     * "如果你发现自己在转义，说明你的抽象层错了。"
     * 但这里是 XML，转义是必需的，不是抽象问题。
     */
    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /**
     * 格式化 RFC 822 日期
     * RSS 2.0 要求使用 RFC 822 日期格式
     *
     * @param  int    $timestamp Unix 时间戳
     * @return string RFC 822 格式的日期
     */
    private static function formatRFC822(int $timestamp): string
    {
        return date('r', $timestamp);
    }

    /**
     * 截取描述文本（移除 HTML 标签，限制长度）
     *
     * @param  string $text      原始文本
     * @param  int    $maxLength 最大长度
     * @return string 截取后的文本
     */
    public static function truncateDescription(string $text, int $maxLength = 200): string
    {
        // 移除 HTML 标签
        $text = strip_tags($text);

        // 移除多余空白
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);

        // 截取长度
        if (mb_strlen($text) > $maxLength) {
            $text = mb_substr($text, 0, $maxLength) . '...';
        }

        return $text;
    }
}
