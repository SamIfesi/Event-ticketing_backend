<?php

class SitemapController
{
  public static function generate(): void
  {
    header('Content-Type: application/xml; charset=utf-8');

    $db = Database::connect();
    $appUrl = rtrim(Environment::get('FRONTEND_URL', 'https://ticketer-e.vercel.app'), '/');

    $stmt = $db->prepare("
      SELECT slug, updated_at
      FROM events
      WHERE status = 'published'
        AND deleted_at IS NULL
      ORDER BY updated_at DESC
    ");
    $stmt->execute();
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

    // Static pages worth indexing
    echo "  <url><loc>{$appUrl}/</loc><priority>1.0</priority></url>\n";
    echo "  <url><loc>{$appUrl}/events</loc><priority>0.9</priority></url>\n";

    foreach ($events as $event) {
      $loc = htmlspecialchars("{$appUrl}/events/{$event['slug']}", ENT_QUOTES);
      $lastmod = date('Y-m-d', strtotime($event['updated_at']));
      echo "  <url>\n";
      echo "    <loc>{$loc}</loc>\n";
      echo "    <lastmod>{$lastmod}</lastmod>\n";
      echo "    <priority>0.8</priority>\n";
      echo "  </url>\n";
    }

    echo '</urlset>';
  }
}
