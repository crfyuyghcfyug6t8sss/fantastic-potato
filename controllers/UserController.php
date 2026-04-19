<?php
class UserController {

    // ─── Pages ─────────────────────────────────────────

    public function myPages(): void {
        $user = requireAuth();
        $db = getDB();

        if ($user['role'] === 'admin') {
            $pages = $db->query('SELECT id, page_id, page_name, platform FROM pages ORDER BY platform, page_name')->fetchAll();
        } else {
            $stmt = $db->prepare(
                'SELECT p.id, p.page_id, p.page_name, p.platform
                 FROM pages p
                 JOIN user_pages up ON up.page_id = p.id
                 WHERE up.user_id = ?
                 ORDER BY p.platform, p.page_name'
            );
            $stmt->execute([$user['id']]);
            $pages = $stmt->fetchAll();
        }

        jsonSuccess(['pages' => $pages]);
    }

    // ─── Posts ─────────────────────────────────────────

    public function pagePosts(): void {
        $user   = requireAuth();
        $pageId = trim($_GET['page_id'] ?? '');
        $after  = trim($_GET['after']   ?? '');
        $before = trim($_GET['before']  ?? '');

        if (!$pageId) jsonError('page_id is required');

        $db = getDB();

        // Check access
        if ($user['role'] !== 'admin') {
            $stmt = $db->prepare(
                'SELECT p.id FROM pages p 
                 JOIN user_pages up ON up.page_id = p.id
                 WHERE p.page_id = ? AND up.user_id = ? LIMIT 1'
            );
            $stmt->execute([$pageId, $user['id']]);
            if (!$stmt->fetch()) jsonError('Access denied', 403);
        }

        // Get token + name + platform
        $stmt = $db->prepare('SELECT access_token, page_name, platform, instagram_id FROM pages WHERE page_id = ? LIMIT 1');
        $stmt->execute([$pageId]);
        $row = $stmt->fetch();
        if (!$row) jsonError('Page not found', 404);

        $token    = decryptToken($row['access_token']);
        $platform = $row['platform'] ?? 'facebook';

        // ─── Instagram ────────────────────────────────────────────────────────
        if ($platform === 'instagram') {
            $igId = $row['instagram_id'] ?? ltrim($pageId, 'ig_');

            // Profile picture
            $profileRes  = fbGet("/{$igId}", $token, ['fields' => 'profile_picture_url,username']);
            $pagePicture = $profileRes['profile_picture_url'] ?? null;

            $params = [
                'fields' => 'id,caption,media_type,media_url,thumbnail_url,timestamp,permalink',
                'limit'  => 20,
            ];
            if ($after)  $params['after']  = $after;
            if ($before) $params['before'] = $before;

            $result = fbGet("/{$igId}/media", $token, $params);

            if (isset($result['error'])) {
                jsonError('Instagram API error: ' . ($result['error']['message'] ?? 'Unknown'));
            }

            $posts = array_map(function ($m) {
                $image = $m['media_url'] ?? $m['thumbnail_url'] ?? null;
                return [
                    'id'            => $m['id'] ?? '',
                    'message'       => $m['caption'] ?? '',
                    'full_picture'  => $image,
                    'created_time'  => $m['timestamp'] ?? '',
                    'permalink_url' => $m['permalink'] ?? '',
                    'media_type'    => $m['media_type'] ?? 'IMAGE',
                ];
            }, $result['data'] ?? []);

            $posts = array_values(array_filter($posts, fn($p) => !empty($p['message']) || !empty($p['full_picture'])));

            jsonSuccess([
                'posts'        => $posts,
                'paging'       => $result['paging'] ?? null,
                'page_picture' => $pagePicture,
                'page_name'    => $row['page_name'],
                'platform'     => 'instagram',
            ]);
            return;
        }

        // ─── Facebook ─────────────────────────────────────────────────────────
        $picResult   = fbGet("/{$pageId}", $token, ['fields' => 'picture.type(large)']);
        $pagePicture = $picResult['picture']['data']['url'] ?? null;

        $params = [
            'fields' => 'id,message,created_time,full_picture,attachments{media,type,url},permalink_url',
            'limit'  => 20,
        ];
        if ($after)  $params['after']  = $after;
        if ($before) $params['before'] = $before;

        $result = fbGet("/{$pageId}/feed", $token, $params);

        if (isset($result['error'])) {
            jsonError('Facebook API error: ' . ($result['error']['message'] ?? 'Unknown'));
        }

        $posts = array_map(function ($post) {
            $image = $post['full_picture'] ?? null;
            if (!$image && !empty($post['attachments']['data'][0]['media']['image']['src'])) {
                $image = $post['attachments']['data'][0]['media']['image']['src'];
            }
            return [
                'id'           => $post['id'] ?? '',
                'message'      => $post['message'] ?? '',
                'full_picture' => $image,
                'created_time' => $post['created_time'] ?? '',
                'permalink_url'=> $post['permalink_url'] ?? '',
            ];
        }, $result['data'] ?? []);

        $posts = array_values(array_filter($posts, fn($p) => !empty($p['message']) || !empty($p['full_picture'])));

        jsonSuccess([
            'posts'        => $posts,
            'paging'       => $result['paging'] ?? null,
            'page_picture' => $pagePicture,
            'page_name'    => $row['page_name'],
            'platform'     => 'facebook',
        ]);
    }

    // ─── Page Picture ──────────────────────────────────

    public function getPagePicture(): void {
        $user   = requireAuth();
        $pageId = trim($_GET['page_id'] ?? '');
        if (!$pageId) jsonError('page_id is required');

        $db = getDB();

        if ($user['role'] !== 'admin') {
            $stmt = $db->prepare(
                'SELECT p.id FROM pages p 
                 JOIN user_pages up ON up.page_id = p.id
                 WHERE p.page_id = ? AND up.user_id = ? LIMIT 1'
            );
            $stmt->execute([$pageId, $user['id']]);
            if (!$stmt->fetch()) jsonError('Access denied', 403);
        }

        $stmt = $db->prepare('SELECT access_token, platform, instagram_id FROM pages WHERE page_id = ? LIMIT 1');
        $stmt->execute([$pageId]);
        $row = $stmt->fetch();
        if (!$row) jsonError('Page not found', 404);

        $token    = decryptToken($row['access_token']);
        $platform = $row['platform'] ?? 'facebook';
        $picture  = null;

        if ($platform === 'instagram') {
            $igId   = $row['instagram_id'] ?? ltrim($pageId, 'ig_');
            $result = fbGet("/{$igId}", $token, ['fields' => 'profile_picture_url']);
            $picture = $result['profile_picture_url'] ?? null;
        } else {
            $result  = fbGet("/{$pageId}", $token, ['fields' => 'picture.type(large)']);
            $picture = $result['picture']['data']['url'] ?? null;
        }

        jsonSuccess(['picture' => $picture]);
    }

    // ─── Wallet ────────────────────────────────────────

    public function walletInfo(): void {
        $user = requireAuth();
        $db   = getDB();

        $stmt = $db->prepare('SELECT balance FROM users WHERE id = ?');
        $stmt->execute([$user['id']]);
        $row = $stmt->fetch();

        $methods = $db->query('SELECT id,name,description,address FROM payment_methods WHERE is_active=1')->fetchAll();

        $dStmt = $db->prepare(
            'SELECT d.*, pm.name as method_name FROM deposits d
             JOIN payment_methods pm ON pm.id = d.payment_method_id
             WHERE d.user_id = ? ORDER BY d.created_at DESC'
        );
        $dStmt->execute([$user['id']]);
        $deposits = $dStmt->fetchAll();

        jsonSuccess([
            'balance'  => (float)($row['balance'] ?? 0),
            'methods'  => $methods,
            'deposits' => $deposits,
        ]);
    }

    // ─── Campaigns ─────────────────────────────────────

    public function createCampaign(): void {
        $user = requireAuth();
        $data = json_decode(file_get_contents('php://input'), true) ?? [];

        $required = ['page_id','post_id','campaign_name','objective','gender','age_min','age_max','locations','budget'];

        foreach ($required as $f) {
            if (empty($data[$f])) jsonError("Field '$f' is required");
        }

        $budget = (float)$data['budget'];

        if ($budget < 10 || $budget > 1000) {
            jsonError('Budget must be between $10 and $1000');
        }

        $db = getDB();

        $stmt = $db->prepare('SELECT balance FROM users WHERE id = ?');
        $stmt->execute([$user['id']]);
        $balRow = $stmt->fetch();

        if (!$balRow || (float)$balRow['balance'] < $budget) {
            jsonError('Insufficient balance');
        }

        $stmt = $db->prepare('SELECT page_name FROM pages WHERE page_id = ? LIMIT 1');
        $stmt->execute([$data['page_id']]);
        $pageRow = $stmt->fetch();

        $locations = is_array($data['locations'])
            ? json_encode($data['locations'], JSON_UNESCAPED_UNICODE)
            : $data['locations'];

        $stmt = $db->prepare(
            'INSERT INTO campaigns 
             (user_id,page_id,page_name,post_id,post_message,post_picture,campaign_name,
              objective,gender,age_min,age_max,locations,budget,status)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,\'pending\')'
        );

        $stmt->execute([
            $user['id'],
            $data['page_id'],
            $pageRow['page_name'] ?? '',
            $data['post_id'],
            $data['post_message'] ?? '',
            $data['post_picture'] ?? '',
            $data['campaign_name'],
            $data['objective'],
            $data['gender'],
            (int)$data['age_min'],
            (int)$data['age_max'],
            $locations,
            $budget,
        ]);

        $db->prepare('UPDATE users SET balance = balance - ? WHERE id = ?')
           ->execute([$budget, $user['id']]);

        jsonSuccess(['id' => $db->lastInsertId()]);
    }

    // ─── My Campaigns ──────────────────────────────────────────────────────────

    public function myCampaigns(): void {
        $user = requireAuth();
        $db   = getDB();
        $stmt = $db->prepare(
            'SELECT * FROM campaigns WHERE user_id = ? ORDER BY created_at DESC'
        );
        $stmt->execute([$user['id']]);
        $camps = $stmt->fetchAll();
        foreach ($camps as &$c) $c['locations'] = json_decode($c['locations'], true);
        jsonSuccess(['campaigns' => $camps]);
    }

    // ─── Submit Deposit ────────────────────────────────────────────────────────

    public function submitDeposit(): void {
        $user     = requireAuth();
        $methodId = (int)($_POST['method_id'] ?? 0);
        $amount   = (float)($_POST['amount']    ?? 0);
        if (!$methodId) jsonError('طريقة الدفع مطلوبة');
        if ($amount < 10) jsonError('الحد الأدنى للشحن $10');

        $db   = getDB();
        $meth = $db->prepare('SELECT id FROM payment_methods WHERE id=? AND is_active=1 LIMIT 1');
        $meth->execute([$methodId]);
        if (!$meth->fetch()) jsonError('طريقة الدفع غير متاحة');

        $receiptPath = null;
        if (!empty($_FILES['receipt']['tmp_name'])) {
            $uploadDir = ROOT . '/uploads/receipts/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            $ext     = strtolower(pathinfo($_FILES['receipt']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg','jpeg','png','gif','webp','pdf'];
            if (!in_array($ext, $allowed)) jsonError('نوع الملف غير مسموح');
            if ($_FILES['receipt']['size'] > 5 * 1024 * 1024) jsonError('الحجم الأقصى 5MB');
            $fname = 'receipt_' . $user['id'] . '_' . time() . '.' . $ext;
            move_uploaded_file($_FILES['receipt']['tmp_name'], $uploadDir . $fname);
            $receiptPath = '/uploads/receipts/' . $fname;
        }

        $db->prepare(
            'INSERT INTO deposits (user_id, payment_method_id, amount, receipt_image, status) VALUES (?,?,?,?,\'pending\')'
        )->execute([$user['id'], $methodId, $amount, $receiptPath]);

        jsonSuccess([], 'تم إرسال طلب الشحن بنجاح');
    }

    // ─── Payment History ───────────────────────────────────────────────────────

    public function paymentHistory(): void {
        $user = requireAuth();
        $db   = getDB();

        $dStmt = $db->prepare(
            'SELECT d.*, pm.name as method_name FROM deposits d
             JOIN payment_methods pm ON pm.id = d.payment_method_id
             WHERE d.user_id = ? ORDER BY d.created_at DESC'
        );
        $dStmt->execute([$user['id']]);
        $deposits = $dStmt->fetchAll();

        $cStmt = $db->prepare(
            'SELECT * FROM campaigns WHERE user_id = ? ORDER BY created_at DESC'
        );
        $cStmt->execute([$user['id']]);
        $campaigns = $cStmt->fetchAll();
        foreach ($campaigns as &$c) $c['locations'] = json_decode($c['locations'], true);

        jsonSuccess(['deposits' => $deposits, 'campaigns' => $campaigns]);
    }

    // ─── Submit Link Request ───────────────────────────────────────────────────

    public function submitLinkRequest(): void {
        $user     = requireAuth();
        $data     = json_decode(file_get_contents('php://input'), true) ?? [];
        $pageUrl  = trim($data['page_url']  ?? '');
        $whatsapp = trim($data['whatsapp']  ?? '');
        $platform = $data['platform'] ?? 'facebook';
        if (!in_array($platform, ['facebook','instagram'])) $platform = 'facebook';
        if (!$pageUrl)  jsonError('رابط الصفحة مطلوب');
        if (!$whatsapp) jsonError('رقم الواتساب مطلوب');

        getDB()->prepare(
            'INSERT INTO page_link_requests (user_id, page_url, whatsapp, platform) VALUES (?,?,?,?)'
        )->execute([$user['id'], $pageUrl, $whatsapp, $platform]);

        jsonSuccess([], 'تم إرسال طلب الربط بنجاح. سيتم مراجعته قريباً.');
    }

    // ─── Site Settings (public) ────────────────────────────────────────────────

    public function getSiteSettings(): void {
        $db   = getDB();
        $rows = $db->query("SELECT `key`, value FROM site_settings")->fetchAll();
        $settings = [];
        foreach ($rows as $r) $settings[$r['key']] = $r['value'];
        jsonSuccess(['settings' => $settings]);
    }
}