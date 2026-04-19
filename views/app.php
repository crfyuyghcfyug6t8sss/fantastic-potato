<?php
// Load site name and asset version
$siteName     = 'FB Manager';
$assetVersion = (string) time();
try {
    $db  = getDB();
    $rs  = $db->query("SELECT `key`,value FROM site_settings WHERE `key` IN ('site_name','asset_version')")->fetchAll();
    foreach ($rs as $r) {
        if ($r['key'] === 'site_name'     && $r['value']) $siteName     = htmlspecialchars($r['value']);
        if ($r['key'] === 'asset_version' && $r['value']) $assetVersion = preg_replace('/[^0-9a-zA-Z_.-]/', '', $r['value']);
    }
} catch (Exception $e) {}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
  <meta name="facebook-domain-verification" content="aj8iysm90smz4di7z8cuoe1tu30caq" />
  <title><?php echo $siteName; ?></title>
  <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
  <meta http-equiv="Pragma" content="no-cache">
  <meta http-equiv="Expires" content="0">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;600;700;900&display=swap">
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
  <link rel="stylesheet" href="/css/app.css?v=<?php echo $assetVersion; ?>">
  <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect width='100' height='100' rx='20' fill='%233b82f6'/><text y='.85em' font-size='70' x='20' fill='white' font-weight='900'>F</text></svg>">

  <!-- Meta Pixel Code -->
  <script>
  !function(f,b,e,v,n,t,s)
  {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
  n.callMethod.apply(n,arguments):n.queue.push(arguments)};
  if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
  n.queue=[];t=b.createElement(e);t.async=!0;
  t.src=v;s=b.getElementsByTagName(e)[0];
  s.parentNode.insertBefore(t,s)}(window, document,'script',
  'https://connect.facebook.net/en_US/fbevents.js');
  fbq('init', '1327602419320059');
  fbq('track', 'PageView');
  </script>
  <noscript><img height="1" width="1" style="display:none"
  src="https://www.facebook.com/tr?id=1327602419320059&ev=PageView&noscript=1"
  /></noscript>
  <!-- End Meta Pixel Code -->
</head>
<body>
  <noscript><div style="text-align:center;padding:60px;font-family:sans-serif;color:#fff;background:#0a0d14;min-height:100vh">يرجى تفعيل JavaScript.</div></noscript>
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
  <script src="/js/icons.js?v=<?php echo $assetVersion; ?>"></script>
  <script src="/js/app.js?v=<?php echo $assetVersion; ?>"></script>
</body>
</html>
