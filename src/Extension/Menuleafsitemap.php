<?php
/**
 * Lightweight menu-based HTML and XML sitemap for Joomla 5 and 6.
 * @license GNU General Public License version 2 or later
 */
namespace MenuLeaf\Plugin\System\Menuleafsitemap\Extension;

defined('_JEXEC') or die;

use Joomla\CMS\Event\Application\AfterRouteEvent;
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\CMS\Event\Content\ContentPrepareEvent;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Router\Route;
use Joomla\Component\Content\Site\Helper\RouteHelper as ContentRouteHelper;
use Joomla\Event\SubscriberInterface;

final class Menuleafsitemap extends CMSPlugin implements SubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            'onAfterRoute' => 'renderXmlIfRequested',
            'onContentPrepare' => 'renderHtmlPlaceholder',
        ];
    }

    /** XML is generated on request. No writable public file and no cron job are needed. */
    public function renderXmlIfRequested(AfterRouteEvent $event): void
    {
        $app = $this->getApplication();
        if (!$app->isClient('site') || strtoupper($app->input->getMethod()) !== 'GET') {
            return;
        }

        $key = (string) $this->params->get('xml_key', 'menuleaf_sitemap');
        if ($key === '' || $app->input->getCmd($key, '') !== 'xml') {
            return;
        }

        // Fixed public endpoint. No arbitrary file paths or template inputs.
        $xml = $this->buildXml();
        $app->setHeader('Content-Type', 'application/xml; charset=utf-8', true);
        $app->setHeader('X-Content-Type-Options', 'nosniff', true);
        $app->setHeader('Cache-Control', 'public, max-age=300', true);
        $app->sendHeaders();
        echo $xml;
        $app->close();
    }

    /** Use {menuleafsitemap} in an ordinary published Joomla article. */
    public function renderHtmlPlaceholder(ContentPrepareEvent $event): void
    {
        $app = $this->getApplication();
        if (!$app->isClient('site')) {
            return;
        }

        if (!in_array($event->getContext(), ['com_content.article', 'com_content.featured'], true)) {
            return;
        }

        $item = $event->getItem();
        $token = (string) $this->params->get('html_token', '{menuleafsitemap}');
        if (!$token || !is_object($item) || !isset($item->text) || !is_string($item->text) || !str_contains($item->text, $token)) {
            return;
        }

        $item->text = str_replace($token, $this->buildHtml(), $item->text);
    }

    /** Returns site menu items, filtered for the current visitor's access levels. */
    private function menuItems(): array
    {
        $app = $this->getApplication();
        $levels = $app->getIdentity()->getAuthorisedViewLevels();
        $items = [];

        foreach ($app->getMenu()->getMenu() as $item) {
            // SiteMenu has already filtered published, in-date, frontend items.
            // Joomla 5/6 SiteMenu objects do not expose "published" or "client_id".
            // Filtering $item->published here would discard every menu item.
            if (!in_array((int) $item->access, $levels, true)) {
                continue;
            }
            $items[] = $item;
        }

        return $items;
    }

    /** The published menu ordering is preserved; structural parents are retained as headings. */
    private function groups(): array
    {
        $all = $this->menuItems();
        $byType = [];
        foreach ($all as $item) {
            $byType[(string) $item->menutype][] = $item;
        }

        // Group names are read from Joomla's menu-type metadata, not hard-coded.
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $query = $db->getQuery(true)
            ->select([$db->quoteName('menutype'), $db->quoteName('title')])
            ->from($db->quoteName('#__menu_types'))
            ->where($db->quoteName('client_id') . ' = 0')
            ->order($db->quoteName('id') . ' ASC');
        $types = $db->setQuery($query)->loadAssocList();
        $groups = [];
        foreach ($types as $type) {
            $key = (string) $type['menutype'];
            if (!empty($byType[$key])) {
                $groups[$key] = ['title' => (string) $type['title'], 'items' => $byType[$key]];
            }
        }
        // Resilience if a menu type is missing its metadata.
        foreach ($byType as $key => $items) {
            $groups[$key] ??= ['title' => $key, 'items' => $items];
        }
        return $groups;
    }

    private function itemUrl(object $item, bool $xml): ?string
    {
        $type = (string) $item->type;
        if (in_array($type, ['heading', 'separator'], true)) {
            return null;
        }
        if ($xml && in_array($type, ['alias', 'url'], true)) {
            return null; // Avoid duplicate aliases and external links in Search Console.
        }

        if ($type === 'url') {
            $link = trim((string) $item->link);
            if (!preg_match('~^https?://~i', $link)) {
                return null;
            }
            return $link; // HTML only, never XML
        }

        $link = Route::_('index.php?Itemid=' . (int) $item->id, false, Route::TLS_IGNORE, true);
        if (!is_string($link) || !preg_match('~^https?://~i', $link)) {
            return null;
        }
        return $link;
    }

    private function buildHtml(): string
    {
        $html = '<div class="menuleaf-sitemap">';
        foreach ($this->groups() as $group) {
            $html .= '<section class="menuleaf-sitemap-group">';
            $html .= '<h2>' . $this->esc($group['title']) . '</h2>';
            $nodes = [];
            foreach ($group['items'] as $item) {
                $nodes[(int) $item->id] = ['item' => $item, 'children' => []];
            }
            $roots = [];
            foreach ($nodes as $id => $node) {
                $parent = (int) $node['item']->parent_id;
                if ($parent !== $id && isset($nodes[$parent])) {
                    $nodes[$parent]['children'][] = $id;
                } else {
                    $roots[] = $id;
                }
            }
            $html .= $this->renderHtmlBranch($nodes, $roots, []);
            $html .= '</section>';
        }
        return $html . '</div>';
    }

    private function renderHtmlBranch(array &$nodes, array $ids, array $ancestors): string
    {
        if (!$ids) {
            return '';
        }
        $html = '<ul>';
        foreach ($ids as $id) {
            if (isset($ancestors[$id])) {
                continue; // Protection against corrupted cycles in menu parent relationships.
            }
            $item = $nodes[$id]['item'];
            $title = $this->esc((string) $item->title);
            $url = $this->itemUrl($item, false);
            $html .= '<li>';
            $html .= $url !== null
                ? '<a href="' . $this->esc($url) . '">' . $title . '</a>'
                : '<span>' . $title . '</span>';
            $next = $ancestors;
            $next[$id] = true;
            $html .= $this->renderHtmlBranch($nodes, $nodes[$id]['children'], $next);
            $html .= '</li>';
        }
        return $html . '</ul>';
    }

    /** Menu item robots metadata; an explicit noindex must never re-enter via articles. */
    private function isNoindex(string $robots): bool
    {
        return preg_match('~(?:^|[,\s])noindex(?:[,\s]|$)~i', $robots) === 1;
    }

    /**
     * Collect publicly eligible Joomla content articles once for both menu routes
     * and stand-alone article routes. XML is always evaluated as a guest request.
     * The archived switch also applies to direct single-article menu entries.
     *
     * @return array<int, array<string, mixed>> Map indexed by content ID.
     */
    private function eligibleArticles(bool $includeArchived): array
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $allowedLevels = array_map('intval', \Joomla\CMS\Access\Access::getAuthorisedViewLevels(0));
        if (!$allowedLevels) {
            return [];
        }

        $now = Factory::getDate()->toSql();
        $categoriesQuery = $db->getQuery(true)
            ->select([$db->quoteName('id'), $db->quoteName('parent_id'), $db->quoteName('published'), $db->quoteName('access')])
            ->from($db->quoteName('#__categories'))
            ->where($db->quoteName('extension') . ' = ' . $db->quote('com_content'));
        $categoryRows = $db->setQuery($categoriesQuery)->loadAssocList('id');
        $categoryAllowed = [];
        $isCategoryPublic = static function (int $catid) use (&$categoryAllowed, $categoryRows, $allowedLevels): bool {
            if (array_key_exists($catid, $categoryAllowed)) {
                return $categoryAllowed[$catid];
            }
            $path = [];
            $current = $catid;
            while ($current > 1) {
                if (isset($path[$current]) || !isset($categoryRows[$current])) {
                    return $categoryAllowed[$catid] = false;
                }
                $path[$current] = true;
                $row = $categoryRows[$current];
                if ((int) $row['published'] !== 1 || !in_array((int) $row['access'], $allowedLevels, true)) {
                    return $categoryAllowed[$catid] = false;
                }
                $current = (int) $row['parent_id'];
            }
            return $categoryAllowed[$catid] = true;
        };

        $query = $db->getQuery(true)
            ->select([
                $db->quoteName('id'), $db->quoteName('alias'), $db->quoteName('catid'),
                $db->quoteName('language'), $db->quoteName('metadata'), $db->quoteName('access'),
            ])
            ->from($db->quoteName('#__content'))
            ->where($db->quoteName('state') . ' IN (' . ($includeArchived ? '1,2' : '1') . ')')
            ->where($db->quoteName('access') . ' IN (' . implode(',', $allowedLevels) . ')')
            ->where('(' . $db->quoteName('publish_up') . ' IS NULL OR ' . $db->quoteName('publish_up') . ' = ' . $db->quote('0000-00-00 00:00:00') . ' OR ' . $db->quoteName('publish_up') . ' <= ' . $db->quote($now) . ')')
            ->where('(' . $db->quoteName('publish_down') . ' IS NULL OR ' . $db->quoteName('publish_down') . ' = ' . $db->quote('0000-00-00 00:00:00') . ' OR ' . $db->quoteName('publish_down') . ' >= ' . $db->quote($now) . ')')
            ->order($db->quoteName('id') . ' ASC');

        $eligible = [];
        foreach ($db->setQuery($query)->loadAssocList() as $article) {
            if (!$isCategoryPublic((int) $article['catid'])) {
                continue;
            }
            $metadata = json_decode((string) ($article['metadata'] ?? ''), true);
            if (is_array($metadata) && $this->isNoindex((string) ($metadata['robots'] ?? ''))) {
                continue;
            }
            $eligible[(int) $article['id']] = $article;
        }
        return $eligible;
    }

    /** The target of a direct com_content article menu item, if any. */
    private function menuArticleId(object $item): ?int
    {
        $query = (array) ($item->query ?? []);
        if (($query['option'] ?? '') !== 'com_content' || ($query['view'] ?? '') !== 'article') {
            return null;
        }
        $id = (int) ($query['id'] ?? 0);
        return $id > 0 ? $id : null;
    }

    /**
     * Build individual article URLs from exactly the same visibility decisions
     * used for direct single-article menu entries.
     *
     * @param array<int, array<string, mixed>> $eligible
     * @param array<int, bool> $excludedArticleIds
     * @return array<string, bool>
     */
    private function articleUrls(array $eligible, array $excludedArticleIds): array
    {
        $urls = [];
        foreach ($eligible as $id => $article) {
            if (isset($excludedArticleIds[$id])) {
                continue;
            }
            $slug = $id . ':' . (string) $article['alias'];
            $route = ContentRouteHelper::getArticleRoute($slug, (int) $article['catid'], (string) $article['language']);
            $url = Route::_($route, false, Route::TLS_IGNORE, true);
            if (is_string($url) && preg_match('~^https?://~i', $url)) {
                $urls[$url] = true;
            }
        }
        return $urls;
    }

    private function buildXml(): string
    {
        $urls = [];
        $excludedArticleIds = [];
        $includeArchived = (bool) $this->params->get('include_archived_articles', 0);
        $eligible = $this->eligibleArticles($includeArchived);
        $anonymousLevels = array_map('intval', \Joomla\CMS\Access\Access::getAuthorisedViewLevels(0));
        $items = $this->menuItems();

        // First pass: collect all noindex exclusions before adding *any* route.
        // This avoids a menu-order dependency if two menu items target one article.
        foreach ($items as $item) {
            if (!in_array((int) $item->access, $anonymousLevels, true)) {
                continue;
            }
            $id = $this->menuArticleId($item);
            if ($id !== null && $this->isNoindex((string) $item->getParams()->get('robots', ''))) {
                $excludedArticleIds[$id] = true;
            }
        }

        // Second pass: direct article menu items must pass the *same* public,
        // date, category, robots and archived-state checks as article discovery.
        foreach ($items as $item) {
            if (!in_array((int) $item->access, $anonymousLevels, true)
                || $this->isNoindex((string) $item->getParams()->get('robots', ''))) {
                continue;
            }
            $id = $this->menuArticleId($item);
            if ($id !== null && (!isset($eligible[$id]) || isset($excludedArticleIds[$id]))) {
                continue;
            }
            $link = (string) ($item->link ?? '');
            // Known functional pages that shouldn't be submitted as indexed content.
            if (preg_match('~(?:^|[?&])option=com_(?:users|finder|search)(?:&|$)~', $link)) {
                continue;
            }
            $url = $this->itemUrl($item, true);
            if ($url !== null) {
                $urls[$url] = true;
            }
        }

        $urls += $this->articleUrls($eligible, $excludedArticleIds);
        ksort($urls, SORT_STRING);
        $out = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $out .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach (array_keys($urls) as $url) {
            $out .= '  <url><loc>' . $this->esc($url) . '</loc></url>' . "\n";
        }
        return $out . '</urlset>' . "\n";
    }

    private function esc(string $text): string
    {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
