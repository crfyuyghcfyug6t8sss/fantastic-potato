<?php
class UserController {

    // ─── Pages ─────────────────────────────────────────

    public function myPages(): void {
        $user = requireAuth();
        $db = getDB();

        if ($user['role'] === 'admin') {
            $pages = $db->query('SELECT id, page_id, page_name, platform FROM pages ORDER BY platform, page_name')->fetchAll();
            $restricted = 0;
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

            $rStmt = $db->prepare('SELECT page_restricted FROM users WHERE id=? LIMIT 1');
            $rStmt->execute([$user['id']]);
            $restricted = (int)($rStmt->fetch()['page_restricted'] ?? 0);
        }

        jsonSuccess(['pages' => $pages, 'restricted' => $restricted]);
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

        $stmt = $db->prepare('SELECT balance, points FROM users WHERE id = ?');
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

        $rate            = (float)($this->getSetting('exchange_rate_usd_syp') ?? 0);
        $pointsToDollar  = (int)($this->getSetting('points_to_dollar')  ?? 100);

        jsonSuccess([
            'balance'           => (float)($row['balance'] ?? 0),
            'points'            => (int)($row['points'] ?? 0),
            'methods'           => $methods,
            'deposits'          => $deposits,
            'exchange_rate'     => $rate,
            'points_to_dollar'  => $pointsToDollar,
        ]);
    }

    // ─── Campaigns ─────────────────────────────────────

    public function createCampaign(): void {
        $user = requireAuth();
        $data = json_decode(file_get_contents('php://input'), true) ?? [];

        $required = ['page_id','post_id','campaign_name','objective','gender','age_min','age_max','locations','budget'];

        foreach ($required as $f) {
            if (empty($data[$f])) jsonError("الحقل '$f' مطلوب");
        }

        $allowedObjectives = ['followers','messages','engagement','visits','sales','video_views'];
        if (!in_array($data['objective'], $allowedObjectives, true)) {
            jsonError('الهدف من الحملة غير صالح');
        }

        $budget       = (float)$data['budget'];
        $durationDays = max(1, (int)($data['duration_days'] ?? 1));
        $totalBudget  = $budget * $durationDays;

        if ($budget < 2) {
            jsonError('الحد الأدنى للميزانية اليومية 2 دولار');
        }
        if ($totalBudget < 7) {
            jsonError('الحد الأدنى لإجمالي الميزانية 7 دولار');
        }
        if ($totalBudget > 5000) {
            jsonError('الحد الأقصى لإجمالي الميزانية 5000 دولار');
        }

        $db = getDB();

        // Optional percent-coupon application
        $applyCoupon = !empty($data['apply_coupon']);
        $couponRow   = null;
        $discount    = 0.0;
        $finalCost   = $totalBudget;

        if ($applyCoupon) {
            $cstmt = $db->prepare(
                "SELECT r.id AS redemption_id, c.id AS coupon_id, c.code, c.value AS percent
                   FROM coupon_redemptions r
                   JOIN coupons c ON c.id = r.coupon_id
                  WHERE r.user_id = ?
                    AND r.consumed_at IS NULL
                    AND c.type = 'percent'
                    AND c.is_active = 1
                  ORDER BY r.id DESC
                  LIMIT 1"
            );
            $cstmt->execute([$user['id']]);
            $couponRow = $cstmt->fetch();
            if (!$couponRow) jsonError('لا يوجد كوبون متاح لتطبيقه');

            $discount  = round($totalBudget * ((float)$couponRow['percent'] / 100), 2);
            $finalCost = max(0.0, round($totalBudget - $discount, 2));
        }

        $stmt = $db->prepare('SELECT balance FROM users WHERE id = ?');
        $stmt->execute([$user['id']]);
        $balRow = $stmt->fetch();

        if (!$balRow || (float)$balRow['balance'] < $finalCost) {
            jsonError('رصيدك غير كافٍ لإطلاق هذه الحملة');
        }

        $stmt = $db->prepare('SELECT page_name FROM pages WHERE page_id = ? LIMIT 1');
        $stmt->execute([$data['page_id']]);
        $pageRow = $stmt->fetch();

        $locations = is_array($data['locations'])
            ? json_encode($data['locations'], JSON_UNESCAPED_UNICODE)
            : $data['locations'];

        $keywords = trim((string)($data['keywords'] ?? ''));
        $postUrl  = trim((string)($data['post_url'] ?? ''));
        if ($postUrl === '' && !empty($data['post_id'])) {
            $postUrl = 'https://www.facebook.com/' . $data['post_id'];
        }

        $db->beginTransaction();
        try {
            $stmt = $db->prepare(
                'INSERT INTO campaigns
                 (user_id,page_id,page_name,post_id,post_message,post_picture,post_url,campaign_name,
                  objective,gender,age_min,age_max,locations,keywords,budget,duration_days,status)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,\'pending\')'
            );

            $stmt->execute([
                $user['id'],
                $data['page_id'],
                $pageRow['page_name'] ?? '',
                $data['post_id'],
                $data['post_message'] ?? '',
                $data['post_picture'] ?? '',
                $postUrl ?: null,
                $data['campaign_name'],
                $data['objective'],
                $data['gender'],
                (int)$data['age_min'],
                (int)$data['age_max'],
                $locations,
                $keywords ?: null,
                $totalBudget,
                $durationDays,
            ]);

            $campaignId = (int)$db->lastInsertId();

            $db->prepare('UPDATE users SET balance = balance - ? WHERE id = ?')
               ->execute([$finalCost, $user['id']]);

            if ($couponRow) {
                $db->prepare(
                    'UPDATE coupon_redemptions
                        SET amount = ?, consumed_at = NOW(), consumed_in_campaign_id = ?
                      WHERE id = ?'
                )->execute([$discount, $campaignId, $couponRow['redemption_id']]);
                $db->prepare('UPDATE coupons SET used_count = used_count + 1 WHERE id = ?')
                   ->execute([$couponRow['coupon_id']]);
            }

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            jsonError('تعذّر إنشاء الحملة، حاول لاحقاً');
        }

        // نقاط: نقطة واحدة لكل دولار يُصرف افتراضياً (قابل للتعديل من إعدادات الموقع)
        $rate = (int)($this->getSetting('points_per_dollar') ?? 1);
        if ($rate > 0) {
            $earned = (int)floor($finalCost * $rate);
            if ($earned > 0) {
                $db->prepare('UPDATE users SET points = points + ? WHERE id = ?')
                   ->execute([$earned, $user['id']]);
            }
        }

        jsonSuccess([
            'id'       => $campaignId,
            'discount' => $discount,
            'final'    => $finalCost,
        ], 'تم إرسال الحملة للمراجعة');
    }

    private function getSetting(string $key): ?string {
        $stmt = getDB()->prepare('SELECT value FROM site_settings WHERE `key` = ? LIMIT 1');
        $stmt->execute([$key]);
        $r = $stmt->fetch();
        return $r['value'] ?? null;
    }

    // ─── Campaign details (single) ─────────────────────────────────────────────
    public function campaignDetails(): void {
        $user = requireAuth();
        $id   = (int)($_GET['id'] ?? 0);
        if (!$id) jsonError('معرّف الحملة مطلوب');

        $db = getDB();
        $stmt = $db->prepare('SELECT * FROM campaigns WHERE id=? AND user_id=? LIMIT 1');
        $stmt->execute([$id, $user['id']]);
        $c = $stmt->fetch();
        if (!$c) jsonError('الحملة غير موجودة', 404);

        // Auto-refresh insights from Facebook (cache 5 minutes per campaign,
        // bypass cache when ?force=1)
        $byPlatform = [];
        $force = !empty($_GET['force']);
        if (!empty($c['fb_campaign_id'])) {
            $stale = $force
                || empty($c['last_insights_at'])
                || (time() - strtotime($c['last_insights_at'])) > 300;
            if ($stale) {
                $r = fbFetchAndStoreInsights((int)$c['id'], (string)$c['fb_campaign_id']);
                if ($r['ok']) {
                    $c['impressions']      = $r['totals']['impressions'];
                    $c['clicks']           = $r['totals']['clicks'];
                    $c['spend']            = $r['totals']['spend'];
                    $c['last_insights_at'] = date('Y-m-d H:i:s');
                    $byPlatform            = $r['by_platform'];
                }
            }
        }

        $c['locations'] = json_decode($c['locations'], true);

        $hasResults = ((int)$c['impressions'] + (int)$c['clicks'] + (float)$c['spend']) > 0
                       || !empty($c['results_note']);

        // Apply profit margin to displayed spend (real cost stays in DB)
        $c['real_spend'] = (float)$c['spend'];
        $c['spend']      = applyMarginToSpend((float)$c['spend']);
        foreach ($byPlatform as &$p) {
            $p['real_spend'] = $p['spend'];
            $p['spend']      = applyMarginToSpend((float)$p['spend']);
        }
        unset($p);

        jsonSuccess([
            'campaign'    => $c,
            'has_results' => $hasResults,
            'by_platform' => $byPlatform,
        ]);
    }

    // ─── Coupons (user) ────────────────────────────────────────────────────────
    public function redeemCoupon(): void {
        $user = requireAuth();
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        $code = strtoupper(trim($data['code'] ?? ''));
        if (!$code) jsonError('الرجاء إدخال كود الكوبون');

        $db = getDB();
        $stmt = $db->prepare('SELECT * FROM coupons WHERE code=? AND is_active=1 LIMIT 1');
        $stmt->execute([$code]);
        $cp = $stmt->fetch();
        if (!$cp) jsonError('كود الكوبون غير صالح أو منتهي');

        if ($cp['max_uses'] > 0 && (int)$cp['used_count'] >= (int)$cp['max_uses']) {
            jsonError('انتهت عدد مرات استخدام هذا الكوبون');
        }

        $alreadyStmt = $db->prepare('SELECT id FROM coupon_redemptions WHERE coupon_id=? AND user_id=? LIMIT 1');
        $alreadyStmt->execute([$cp['id'], $user['id']]);
        if ($alreadyStmt->fetch()) jsonError('لقد استخدمت هذا الكوبون من قبل');

        // Fixed: credit wallet immediately, mark consumed, increment used_count.
        // Percent: just register availability; discount is applied at campaign creation.
        if ($cp['type'] === 'fixed') {
            $bonus = (float)$cp['value'];
            if ($bonus <= 0) jsonError('قيمة الكوبون غير صالحة');

            $db->beginTransaction();
            try {
                $db->prepare('INSERT INTO coupon_redemptions (coupon_id,user_id,amount,consumed_at) VALUES (?,?,?,NOW())')
                   ->execute([$cp['id'], $user['id'], $bonus]);
                $db->prepare('UPDATE coupons SET used_count = used_count + 1 WHERE id=?')->execute([$cp['id']]);
                $db->prepare('UPDATE users SET balance = balance + ? WHERE id=?')->execute([$bonus, $user['id']]);
                $db->commit();
            } catch (\Throwable $e) {
                $db->rollBack();
                jsonError('تعذّر تطبيق الكوبون، حاول لاحقاً');
            }

            jsonSuccess(['amount' => $bonus, 'type' => 'fixed'], "تم إضافة \${$bonus} إلى رصيدك");
        }

        // type === 'percent'
        $percent = (float)$cp['value'];
        if ($percent <= 0) jsonError('قيمة الكوبون غير صالحة');

        try {
            $db->prepare('INSERT INTO coupon_redemptions (coupon_id,user_id,amount,consumed_at) VALUES (?,?,?,NULL)')
               ->execute([$cp['id'], $user['id'], 0]);
        } catch (\Throwable $e) {
            jsonError('تعذّر حفظ الكوبون، حاول لاحقاً');
        }

        jsonSuccess(
            ['type' => 'percent', 'percent' => $percent, 'code' => $cp['code']],
            "تم تفعيل كوبون خصم {$percent}% — يمكنك استخدامه عند إنشاء حملة"
        );
    }

    // Returns the user's available (unconsumed) percent coupon, if any.
    public function activeCoupon(): void {
        $user = requireAuth();
        $stmt = getDB()->prepare(
            "SELECT c.id, c.code, c.value AS percent
               FROM coupon_redemptions r
               JOIN coupons c ON c.id = r.coupon_id
              WHERE r.user_id = ?
                AND r.consumed_at IS NULL
                AND c.type = 'percent'
                AND c.is_active = 1
              ORDER BY r.id DESC
              LIMIT 1"
        );
        $stmt->execute([$user['id']]);
        $row = $stmt->fetch();
        jsonSuccess(['coupon' => $row ?: null]);
    }

    // ─── Points (user) ─────────────────────────────────────────────────────────
    public function convertPoints(): void {
        $user = requireAuth();
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        $points = (int)($data['points'] ?? 0);
        if ($points <= 0) jsonError('الرجاء إدخال عدد النقاط');

        $rate = max(1, (int)($this->getSetting('points_to_dollar') ?? 100));
        if ($points < $rate) jsonError("الحد الأدنى للتحويل {$rate} نقطة");

        $dollars = floor($points / $rate);
        $usePts  = $dollars * $rate;

        $db = getDB();
        $stmt = $db->prepare('SELECT points FROM users WHERE id=? LIMIT 1');
        $stmt->execute([$user['id']]);
        $row = $stmt->fetch();
        if (!$row || (int)$row['points'] < $usePts) jsonError('نقاطك غير كافية');

        $db->beginTransaction();
        try {
            $db->prepare('UPDATE users SET points = points - ?, balance = balance + ? WHERE id=?')
               ->execute([$usePts, $dollars, $user['id']]);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            jsonError('تعذّر تحويل النقاط، حاول لاحقاً');
        }
        jsonSuccess(['amount' => $dollars, 'points_used' => $usePts], "تم تحويل {$usePts} نقطة إلى \${$dollars}");
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
        foreach ($camps as &$c) {
            $c['locations']  = json_decode($c['locations'], true);
            $c['real_spend'] = (float)$c['spend'];
            $c['spend']      = applyMarginToSpend((float)$c['spend']);
        }
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
        $db     = getDB();
        $public = ['site_name','site_logo','support_whatsapp','support_telegram',
                   'support_form_url','points_per_dollar','points_to_dollar',
                   'exchange_rate_usd_syp'];
        $in     = implode(',', array_fill(0, count($public), '?'));
        $stmt   = $db->prepare("SELECT `key`, value FROM site_settings WHERE `key` IN ($in)");
        $stmt->execute($public);
        $settings = [];
        foreach ($stmt->fetchAll() as $r) $settings[$r['key']] = $r['value'];
        jsonSuccess(['settings' => $settings]);
    }
}