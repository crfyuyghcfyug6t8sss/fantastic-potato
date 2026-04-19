<?php
// Load site name for <title> tag
$siteName = 'FB Manager';
try {
    $db = getDB();
    $row = $db->query("SELECT value FROM site_settings WHERE `key`='site_name' LIMIT 1")->fetch();
    if ($row && $row['value']) $siteName = htmlspecialchars($row['value']);
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
  <title><?php echo $siteName; ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;600;700;900&display=swap">
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
  <link rel="stylesheet" href="/css/app.css">
  <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect width='100' height='100' rx='20' fill='%233b82f6'/><text y='.85em' font-size='70' x='20' fill='white' font-weight='900'>F</text></svg>">
</head>
<body>
  <noscript><div style="text-align:center;padding:60px;font-family:sans-serif;color:#fff;background:#0a0d14;min-height:100vh">يرجى تفعيل JavaScript.</div></noscript>
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
  <script src="/js/icons.js"></script>
  <script src="/js/app.js"></script>
</body>
</html>
