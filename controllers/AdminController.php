<?php
class AdminController {

    public function saveToken(): void {
        requireAdmin();
        $data     = json_decode(file_get_contents('php://input'), true) ?? [];
        $token    = trim($data['token'] ?? '');
        $platform = $data['platform'] ?? 'facebook';
        if (!in_array($platform, ['facebook', 'instagram'])) $platform = 'facebook';
        if (!$token) jsonError('Token is required');

        $verify = fbGet('/me', $token, ['fields' => 'id,name']);
        if (isset($verify['error'])) jsonError('Invalid token: ' . ($verify['error']['message'] ?? ''));

        $encrypted = encryptToken($token);
        $db = getDB();
        $db->prepare(
            'INSERT INTO admin_tokens (platform, access_token) VALUES (?,?)
             ON DUPLICATE KEY UPDATE access_token=VALUES(access_token)'
        )->execute([$platform, $encrypted]);

        jsonSuccess(['fb_user' => $verify['name'] ?? '', 'platform' => $platform], 'Token saved successfully');
    }

    public function getTokenStatus(): void {
        requireAdmin();
        $db = getDB();
        $rows = $db->query("SELECT platform, updated_at FROM admin_tokens")->fetchAll();
        $status = ['facebook' => null, 'instagram' => null];
        foreach ($rows as $r) $status[$r['platform']] = $r['updated_at'];
        jsonSuccess(['tokens' => $status]);
    }

    public function fetchPages(): void {
        requireAdmin();
        $platform = $_GET['platform'] ?? 'all';
        $db = getDB();
        $saved = 0;

        // ─── Fetch Facebook pages ───────────────────────────────────────────
        if ($platform === 'all' || $platform === 'facebook') {
            $fbToken = $this->getAdminToken('facebook');
            if ($fbToken) {
                $result = fbGet('/me/accounts', $fbToken, ['fields' => 'id,name,access_token', 'limit' => 100]);
                if (!isset($result['error'])) {
                    $stmt = $db->prepare(
                        'INSERT INTO pages (page_id,page_name,access_token,platform) VALUES (?,?,?,?)
                         ON DUPLICATE KEY UPDATE page_name=VALUES(page_name), access_token=VALUES(access_token), platform=VALUES(platform)'
                    );
                    foreach ($result['data'] ?? [] as $p) {
                        if (empty($p['id']) || empty($p['access_token'])) continue;
                        $stmt->execute([$p['id'], $p['name'], encryptToken($p['access_token']), 'facebook']);
                        $saved++;
                    }
                }
            }
        }

        // ─── Fetch Instagram Business accounts ─────────────────────────────
        if ($platform === 'all' || $platform === 'instagram') {
            $igToken = $this->getAdminToken('instagram');
            if ($igToken) {
                // Get FB pages with Instagram accounts linked
                $result = fbGet('/me/accounts', $igToken, [
                    'fields' => 'id,name,access_token,instagram_business_account{id,name,username,profile_picture_url}',
                    'limit'  => 100
                ]);
                if (!isset($result['error'])) {
                    $stmt = $db->prepare(
                        'INSERT INTO pages (page_id,page_name,access_token,platform,instagram_id) VALUES (?,?,?,?,?)
                         ON DUPLICATE KEY UPDATE page_name=VALUES(page_name), access_token=VALUES(access_token), platform=VALUES(platform), instagram_id=VALUES(instagram_id)'
                    );
                    foreach ($result['data'] ?? [] as $p) {
                        if (empty($p['instagram_business_account'])) continue;
                        $ig = $p['instagram_business_account'];
                        $igPageId = 'ig_' . $ig['id'];
                        $igName   = $ig['username'] ?? ($ig['name'] ?? $p['name']);
                        $stmt->execute([$igPageId, $igName, encryptToken($p['access_token']), 'instagram', $ig['id']]);
                        $saved++;
                    }
                }
            }
        }

        jsonSuccess([
            'count' => $saved,
            'pages' => $this->getPagesList(),
        ], "$saved page(s) fetched");
    }

    public function listPages(): void {
        requireAdmin();
        $platform = $_GET['platform'] ?? '';
        jsonSuccess(['pages' => $this->getPagesList($platform)]);
    }

    public function listUsers(): void {
        requireAdmin();
        $db    = getDB();
        $users = $db->query("SELECT id,name,phone,role,balance,created_at FROM users ORDER BY id DESC")->fetchAll();
        jsonSuccess(['users' => $users]);
    }

    public function assignPage(): void {
        requireAdmin();
        $data   = json_decode(file_get_contents('php://input'), true) ?? [];
        $userId = (int)($data['user_id'] ?? 0);
        $pageId = (int)($data['page_id'] ?? 0);
        if (!$userId || !$pageId) jsonError('user_id and page_id required');

        $db  = getDB();
        $usr = $db->prepare('SELECT role FROM users WHERE id = ?');
        $usr->execute([$userId]);
        $u = $usr->fetch();
        if (!$u) jsonError('User not found');
        if ($u['role'] === 'admin') jsonError('Cannot assign to admin');

        $db->prepare('INSERT IGNORE INTO user_pages (user_id,page_id) VALUES (?,?)')->execute([$userId, $pageId]);
        jsonSuccess([], 'Assigned');
    }

    public function revokePage(): void {
        requireAdmin();
        $data   = json_decode(file_get_contents('php://input'), true) ?? [];
        $userId = (int)($data['user_id'] ?? 0);
        $pageId = (int)($data['page_id'] ?? 0);
        if (!$userId || !$pageId) jsonError('user_id and page_id required');
        getDB()->prepare('DELETE FROM user_pages WHERE user_id=? AND page_id=?')->execute([$userId, $pageId]);
        jsonSuccess([], 'Revoked');
    }

    public function userPages(): void {
        requireAdmin();
        $userId = (int)($_GET['user_id'] ?? 0);
        if (!$userId) jsonError('user_id required');
        $db   = getDB();
        $stmt = $db->prepare(
            'SELECT p.id,p.page_id,p.page_name,p.platform FROM pages p
             JOIN user_pages up ON up.page_id=p.id WHERE up.user_id=?'
        );
        $stmt->execute([$userId]);
        jsonSuccess(['pages' => $stmt->fetchAll()]);
    }

    // ─── Payment Methods ──────────────────────────────────────────────────────

    public function listPaymentMethods(): void {
        requireAdmin();
        $methods = getDB()->query('SELECT * FROM payment_methods ORDER BY id DESC')->fetchAll();
        jsonSuccess(['methods' => $methods]);
    }

    public function savePaymentMethod(): void {
        requireAdmin();
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        $name = trim($data['name'] ?? '');
        $addr = trim($data['address'] ?? '');
        if (!$name || !$addr) jsonError('Name and address required');

        $db = getDB();
        if (!empty($data['id'])) {
            $db->prepare('UPDATE payment_methods SET name=?,description=?,address=?,is_active=? WHERE id=?')
               ->execute([$name, $data['description'] ?? '', $addr, (int)($data['is_active'] ?? 1), (int)$data['id']]);
        } else {
            $db->prepare('INSERT INTO payment_methods (name,description,address) VALUES (?,?,?)')
               ->execute([$name, $data['description'] ?? '', $addr]);
        }
        jsonSuccess([], 'Saved');
    }

    public function deletePaymentMethod(): void {
        requireAdmin();
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        $id   = (int)($data['id'] ?? 0);
        if (!$id) jsonError('id required');
        getDB()->prepare('DELETE FROM payment_methods WHERE id=?')->execute([$id]);
        jsonSuccess([], 'Deleted');
    }

    // ─── Deposits ─────────────────────────────────────────────────────────────

    public function listDeposits(): void {
        requireAdmin();
        $status = $_GET['status'] ?? '';
        $db     = getDB();
        $sql    = 'SELECT d.*,u.name as user_name,u.phone,pm.name as method_name
                   FROM deposits d JOIN users u ON u.id=d.user_id
                   JOIN payment_methods pm ON pm.id=d.payment_method_id';
        if ($status) {
            $stmt = $db->prepare($sql . ' WHERE d.status=? ORDER BY d.created_at DESC');
            $stmt->execute([$status]);
        } else {
            $stmt = $db->query($sql . ' ORDER BY d.created_at DESC');
        }
        jsonSuccess(['deposits' => $stmt->fetchAll()]);
    }

    public function updateDeposit(): void {
        requireAdmin();
        $data   = json_decode(file_get_contents('php://input'), true) ?? [];
        $id     = (int)($data['id']     ?? 0);
        $status = $data['status'] ?? '';
        $note   = $data['note']   ?? '';

        if (!$id || !in_array($status, ['approved','rejected'])) jsonError('Invalid request');

        $db   = getDB();
        $dep  = $db->prepare('SELECT * FROM deposits WHERE id=? LIMIT 1');
        $dep->execute([$id]);
        $d = $dep->fetch();
        if (!$d) jsonError('Deposit not found');
        if ($d['status'] !== 'pending') jsonError('Already processed');

        $db->prepare('UPDATE deposits SET status=?,admin_note=? WHERE id=?')->execute([$status, $note, $id]);

        if ($status === 'approved') {
            $db->prepare('UPDATE users SET balance=balance+? WHERE id=?')->execute([$d['amount'], $d['user_id']]);
        }

        jsonSuccess([], 'Updated');
    }

    // ─── Campaigns ────────────────────────────────────────────────────────────

    public function listCampaigns(): void {
        requireAdmin();
        $status = $_GET['status'] ?? '';
        $db     = getDB();
        $sql    = 'SELECT c.*,u.name as user_name,u.phone FROM campaigns c JOIN users u ON u.id=c.user_id';
        if ($status) {
            $stmt = $db->prepare($sql . ' WHERE c.status=? ORDER BY c.created_at DESC');
            $stmt->execute([$status]);
        } else {
            $stmt = $db->query($sql . ' ORDER BY c.created_at DESC');
        }
        $camps = $stmt->fetchAll();
        foreach ($camps as &$c) $c['locations'] = json_decode($c['locations'], true);
        jsonSuccess(['campaigns' => $camps]);
    }

    public function updateCampaign(): void {
        requireAdmin();
        $data   = json_decode(file_get_contents('php://input'), true) ?? [];
        $id     = (int)($data['id']     ?? 0);
        $status = $data['status'] ?? '';
        $note   = $data['note']   ?? '';

        $allowed = ['approved','rejected','running','paused','completed'];
        if (!$id || !in_array($status, $allowed)) jsonError('طلب غير صالح');

        $db  = getDB();
        $cam = $db->prepare('SELECT * FROM campaigns WHERE id=? LIMIT 1');
        $cam->execute([$id]);
        $c = $cam->fetch();
        if (!$c) jsonError('الحملة غير موجودة');

        // Refund if rejected
        if ($status === 'rejected' && $c['status'] === 'pending') {
            $db->prepare('UPDATE users SET balance=balance+? WHERE id=?')->execute([$c['budget'], $c['user_id']]);
        }

        $db->prepare('UPDATE campaigns SET status=?,admin_note=? WHERE id=?')->execute([$status, $note, $id]);
        jsonSuccess([], 'تم تحديث الحملة');
    }

    // Admin: enter manual ad statistics for a campaign
    public function updateCampaignResults(): void {
        requireAdmin();
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        $id   = (int)($data['id'] ?? 0);
        if (!$id) jsonError('معرّف الحملة مطلوب');

        $impressions = max(0, (int)($data['impressions'] ?? 0));
        $clicks      = max(0, (int)($data['clicks']      ?? 0));
        $spend       = max(0, (float)($data['spend']     ?? 0));
        $note        = trim((string)($data['results_note'] ?? ''));
        $fbCamp      = trim((string)($data['fb_campaign_id'] ?? ''));

        getDB()->prepare(
            'UPDATE campaigns SET impressions=?, clicks=?, spend=?, results_note=?, fb_campaign_id=? WHERE id=?'
        )->execute([$impressions, $clicks, $spend, $note ?: null, $fbCamp ?: null, $id]);
        jsonSuccess([], 'تم تحديث نتائج الحملة');
    }

    // ─── Stats ────────────────────────────────────────────────────────────────

    public function stats(): void {
        requireAdmin();
        $db = getDB();
        $s  = [];
        $s['users']            = $db->query("SELECT COUNT(*) FROM users WHERE role='user'")->fetchColumn();
        $s['pages']            = $db->query("SELECT COUNT(*) FROM pages")->fetchColumn();
        $s['campaigns_total']  = $db->query("SELECT COUNT(*) FROM campaigns")->fetchColumn();
        $s['campaigns_pending']= $db->query("SELECT COUNT(*) FROM campaigns WHERE status='pending'")->fetchColumn();
        $s['deposits_pending'] = $db->query("SELECT COUNT(*) FROM deposits WHERE status='pending'")->fetchColumn();
        $s['total_revenue']    = $db->query("SELECT COALESCE(SUM(amount),0) FROM deposits WHERE status='approved'")->fetchColumn();
        jsonSuccess(['stats' => $s]);
    }

    public function updateUserBalance(): void {
        requireAdmin();
        $data   = json_decode(file_get_contents('php://input'), true) ?? [];
        $userId = (int)($data['user_id'] ?? 0);
        $amount = (float)($data['amount'] ?? 0);
        if (!$userId) jsonError('معرّف المستخدم مطلوب');
        getDB()->prepare('UPDATE users SET balance=? WHERE id=?')->execute([$amount, $userId]);
        jsonSuccess([], 'تم تحديث الرصيد');
    }

    // Admin: toggle page-restricted flag for a user
    public function setPageRestricted(): void {
        requireAdmin();
        $data       = json_decode(file_get_contents('php://input'), true) ?? [];
        $userId     = (int)($data['user_id'] ?? 0);
        $restricted = !empty($data['restricted']) ? 1 : 0;
        if (!$userId) jsonError('معرّف المستخدم مطلوب');
        getDB()->prepare('UPDATE users SET page_restricted=? WHERE id=?')->execute([$restricted, $userId]);
        jsonSuccess(['restricted' => $restricted], $restricted ? 'تم تقييد الصفحة' : 'تم رفع التقييد');
    }

    // Admin: add/subtract points for a user
    public function adjustPoints(): void {
        requireAdmin();
        $data    = json_decode(file_get_contents('php://input'), true) ?? [];
        $userId  = (int)($data['user_id'] ?? 0);
        $delta   = (int)($data['delta']   ?? 0);
        if (!$userId || $delta === 0) jsonError('بيانات غير صالحة');

        $db = getDB();
        if ($delta > 0) {
            $db->prepare('UPDATE users SET points = points + ? WHERE id=?')->execute([$delta, $userId]);
        } else {
            $db->prepare('UPDATE users SET points = GREATEST(0, points + ?) WHERE id=?')->execute([$delta, $userId]);
        }
        jsonSuccess([], 'تم تحديث النقاط');
    }

    // Admin: simple accounting overview
    public function accounting(): void {
        requireAdmin();
        $db = getDB();
        $a = [];
        $a['total_balances']    = (float)$db->query("SELECT COALESCE(SUM(balance),0) FROM users WHERE role='user'")->fetchColumn();
        $a['total_points']      = (int)  $db->query("SELECT COALESCE(SUM(points),0)  FROM users WHERE role='user'")->fetchColumn();
        $a['total_deposits']    = (float)$db->query("SELECT COALESCE(SUM(amount),0)  FROM deposits WHERE status='approved'")->fetchColumn();
        $a['total_campaigns']   = (float)$db->query("SELECT COALESCE(SUM(budget),0)  FROM campaigns WHERE status IN ('approved','running','completed')")->fetchColumn();
        $a['total_spend']       = (float)$db->query("SELECT COALESCE(SUM(spend),0)   FROM campaigns")->fetchColumn();
        $a['total_coupon_grant']= (float)$db->query("SELECT COALESCE(SUM(amount),0)  FROM coupon_redemptions")->fetchColumn();
        $a['profit']            = $a['total_deposits'] - $a['total_spend'];
        jsonSuccess(['accounting' => $a]);
    }

    // ─── Coupons ──────────────────────────────────────────────────────────────

    public function listCoupons(): void {
        requireAdmin();
        $rows = getDB()->query('SELECT * FROM coupons ORDER BY id DESC')->fetchAll();
        jsonSuccess(['coupons' => $rows]);
    }

    public function saveCoupon(): void {
        requireAdmin();
        $data     = json_decode(file_get_contents('php://input'), true) ?? [];
        $code     = strtoupper(trim($data['code'] ?? ''));
        $type     = $data['type'] ?? 'fixed';
        $value    = (float)($data['value'] ?? 0);
        $maxUses  = max(0, (int)($data['max_uses'] ?? 0));
        $isActive = !empty($data['is_active']) ? 1 : 0;

        if (!$code) jsonError('كود الكوبون مطلوب');
        if (!in_array($type, ['percent','fixed'], true)) jsonError('نوع الكوبون غير صالح');
        if ($value <= 0) jsonError('قيمة الكوبون يجب أن تكون أكبر من صفر');
        if ($type === 'percent' && $value > 100) jsonError('النسبة يجب ألا تتجاوز 100');

        $db = getDB();
        if (!empty($data['id'])) {
            $db->prepare('UPDATE coupons SET code=?, type=?, value=?, max_uses=?, is_active=? WHERE id=?')
               ->execute([$code, $type, $value, $maxUses, $isActive, (int)$data['id']]);
        } else {
            $db->prepare('INSERT INTO coupons (code,type,value,max_uses,is_active) VALUES (?,?,?,?,?)')
               ->execute([$code, $type, $value, $maxUses, $isActive]);
        }
        jsonSuccess([], 'تم حفظ الكوبون');
    }

    public function deleteCoupon(): void {
        requireAdmin();
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        $id   = (int)($data['id'] ?? 0);
        if (!$id) jsonError('معرّف الكوبون مطلوب');
        getDB()->prepare('DELETE FROM coupons WHERE id=?')->execute([$id]);
        jsonSuccess([], 'تم حذف الكوبون');
    }

    // Admin: save support links (whatsapp/telegram/form url)
    public function saveSupportLinks(): void {
        requireAdmin();
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        $keys = ['support_whatsapp', 'support_telegram', 'support_form_url',
                 'points_per_dollar', 'points_to_dollar', 'exchange_rate_usd_syp'];
        $db = getDB();
        $stmt = $db->prepare('INSERT INTO site_settings (`key`, value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=?');
        foreach ($keys as $k) {
            if (array_key_exists($k, $data)) {
                $val = trim((string)$data[$k]);
                $stmt->execute([$k, $val, $val]);
            }
        }
        jsonSuccess([], 'تم حفظ الإعدادات');
    }

    // ─── Site Settings ────────────────────────────────────────────────────────

    public function getSiteSettings(): void {
        requireAdmin();
        $db   = getDB();
        $rows = $db->query('SELECT `key`, value FROM site_settings')->fetchAll();
        $settings = [];
        foreach ($rows as $r) $settings[$r['key']] = $r['value'];
        jsonSuccess(['settings' => $settings]);
    }

    public function saveSiteSettings(): void {
        requireAdmin();
        $siteName = trim($_POST['site_name'] ?? '');
        if (!$siteName) jsonError('اسم الموقع مطلوب');

        $db = getDB();
        $stmt = $db->prepare('INSERT INTO site_settings (`key`, value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=?');
        $stmt->execute(['site_name', $siteName, $siteName]);

        // Handle logo upload
        $logoPath = null;
        if (!empty($_FILES['logo']['tmp_name'])) {
            $uploadDir = ROOT . '/uploads/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg','jpeg','png','gif','svg','webp'];
            if (!in_array($ext, $allowed)) jsonError('نوع الملف غير مسموح');
            if ($_FILES['logo']['size'] > 2 * 1024 * 1024) jsonError('الحجم الأقصى 2MB');
            $fname = 'logo_' . time() . '.' . $ext;
            move_uploaded_file($_FILES['logo']['tmp_name'], $uploadDir . $fname);
            $logoPath = '/uploads/' . $fname;
            $stmt->execute(['site_logo', $logoPath, $logoPath]);
        } elseif (!empty($_POST['clear_logo'])) {
            $stmt->execute(['site_logo', null, null]);
        }

        jsonSuccess(['site_name' => $siteName, 'site_logo' => $logoPath], 'تم حفظ الإعدادات');
    }

    // ─── Page Link Requests ───────────────────────────────────────────────────

    public function listLinkRequests(): void {
        requireAdmin();
        $status = $_GET['status'] ?? '';
        $db = getDB();
        $sql = 'SELECT r.*, u.name as user_name, u.phone FROM page_link_requests r JOIN users u ON u.id = r.user_id';
        if ($status) {
            $stmt = $db->prepare($sql . ' WHERE r.status=? ORDER BY r.created_at DESC');
            $stmt->execute([$status]);
        } else {
            $stmt = $db->query($sql . ' ORDER BY r.created_at DESC');
        }
        jsonSuccess(['requests' => $stmt->fetchAll()]);
    }

    public function updateLinkRequest(): void {
        requireAdmin();
        $data   = json_decode(file_get_contents('php://input'), true) ?? [];
        $id     = (int)($data['id'] ?? 0);
        $status = $data['status'] ?? '';
        $note   = $data['note'] ?? '';
        if (!$id || !in_array($status, ['approved','rejected'])) jsonError('Invalid request');
        getDB()->prepare('UPDATE page_link_requests SET status=?, admin_note=? WHERE id=?')->execute([$status, $note, $id]);
        jsonSuccess([], 'Updated');
    }

    public function pendingLinkRequests(): void {
        requireAdmin();
        $count = getDB()->query("SELECT COUNT(*) FROM page_link_requests WHERE status='pending'")->fetchColumn();
        jsonSuccess(['count' => (int)$count]);
    }

    // ─── Cache-busting ────────────────────────────────────────────────────────
    public function bustCache(): void {
        requireAdmin();
        $v = (string) time();
        getDB()->prepare(
            "INSERT INTO site_settings (`key`,value) VALUES ('asset_version',?)
             ON DUPLICATE KEY UPDATE value=VALUES(value)"
        )->execute([$v]);
        jsonSuccess(['asset_version' => $v], 'تم مسح الكاش لكل المستخدمين');
    }

    // ─── Facebook Insights (auto-fetch campaign results) ──────────────────────
    public function fetchInsights(): void {
        requireAdmin();
        $data = json_decode(file_get_contents('php://input'), true) ?? [];

        $localId    = (int) ($data['id'] ?? 0);
        $fbCampaign = trim((string) ($data['fb_campaign_id'] ?? ''));
        $platform   = (string) ($data['platform'] ?? 'all'); // facebook | instagram | all
        $persist    = !empty($data['persist']);

        if ($fbCampaign === '') jsonError('معرّف الحملة على فيسبوك (campaign_id) مطلوب');
        if (!in_array($platform, ['facebook','instagram','all'], true)) $platform = 'all';

        $token = $this->getAdminToken('facebook');
        if (!$token) jsonError('لم يتم ضبط Access Token الخاص بالأدمن', 400);

        $params = [
            'fields'     => 'impressions,clicks,spend,cpc,ctr',
            'breakdowns' => 'publisher_platform',
            'level'      => 'campaign',
        ];
        if ($platform !== 'all') {
            $params['filtering'] = json_encode([[
                'field'    => 'publisher_platform',
                'operator' => 'IN',
                'value'    => [$platform],
            ]]);
        }

        $resp = fbGet('/' . $fbCampaign . '/insights', $token, $params);

        if (isset($resp['error'])) {
            $err = $resp['error'];
            $msg = $err['message'] ?? 'خطأ من فيسبوك';
            $code = $err['code'] ?? 0;
            $sub  = $err['error_subcode'] ?? 0;
            if ($code === 190 || $sub === 463 || $sub === 467) {
                jsonError('التوكن منتهي أو غير صالح — يرجى تحديث Access Token', 401);
            }
            if ($code === 100) {
                jsonError('معرّف الحملة (campaign_id) غير صحيح', 404);
            }
            if ($code === 200 || $code === 10) {
                jsonError('صلاحيات غير كافية — يلزم توكن بصلاحية ads_read', 403);
            }
            jsonError('فيسبوك: ' . $msg, 500);
        }

        $rows = $resp['data'] ?? [];
        $byPlatform = [];
        $totals = ['impressions' => 0, 'clicks' => 0, 'spend' => 0.0];

        foreach ($rows as $r) {
            $plat = $r['publisher_platform'] ?? 'unknown';
            $imp  = (int)   ($r['impressions'] ?? 0);
            $clk  = (int)   ($r['clicks']      ?? 0);
            $spd  = (float) ($r['spend']       ?? 0);
            $cpc  = (float) ($r['cpc']         ?? 0);
            $ctr  = (float) ($r['ctr']         ?? 0);

            $byPlatform[] = [
                'platform'    => $plat,
                'impressions' => $imp,
                'clicks'      => $clk,
                'spend'       => $spd,
                'cpc'         => $cpc,
                'ctr'         => $ctr,
            ];
            $totals['impressions'] += $imp;
            $totals['clicks']      += $clk;
            $totals['spend']       += $spd;
        }

        $totals['ctr'] = $totals['impressions'] > 0
            ? round(($totals['clicks'] / $totals['impressions']) * 100, 2)
            : 0.0;
        $totals['cpc'] = $totals['clicks'] > 0
            ? round($totals['spend'] / $totals['clicks'], 2)
            : 0.0;

        if ($persist && $localId > 0) {
            getDB()->prepare(
                'UPDATE campaigns SET impressions=?, clicks=?, spend=?, fb_campaign_id=? WHERE id=?'
            )->execute([
                $totals['impressions'],
                $totals['clicks'],
                $totals['spend'],
                $fbCampaign,
                $localId,
            ]);
        }

        jsonSuccess([
            'totals'      => $totals,
            'by_platform' => $byPlatform,
            'platform'    => $platform,
        ], 'تم جلب النتائج من فيسبوك');
    }

    // ─── Private ──────────────────────────────────────────────────────────────

    private function getAdminToken(string $platform = 'facebook'): ?string {
        $row = getDB()->prepare('SELECT access_token FROM admin_tokens WHERE platform=? LIMIT 1');
        $row->execute([$platform]);
        $r = $row->fetch();
        if (!$r) return null;
        return decryptToken($r['access_token']);
    }

    private function getAdminTokenOrFail(string $platform = 'facebook'): string {
        $token = $this->getAdminToken($platform);
        if (!$token) jsonError("No $platform token configured", 400);
        return $token;
    }

    private function getPagesList(string $platform = ''): array {
        $db = getDB();
        if ($platform && in_array($platform, ['facebook', 'instagram'])) {
            $stmt = $db->prepare('SELECT id,page_id,page_name,platform,created_at FROM pages WHERE platform=? ORDER BY page_name');
            $stmt->execute([$platform]);
        } else {
            $stmt = $db->query('SELECT id,page_id,page_name,platform,created_at FROM pages ORDER BY platform,page_name');
        }
        return $stmt->fetchAll();
    }

    // ─── WhatsApp Broadcast ───────────────────────────────────────────────────

    private const WA_API_URL = 'http://35.184.247.251:5000/api/send';
    private const WA_API_KEY = '6385628956b38bcbf8791bf4317f61561eb932b12b4a0d0106266b451e98fc22';

    private function sendWhatsApp(string $phone, string $message): array {
        $ch = curl_init(self::WA_API_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_POSTFIELDS     => json_encode(compact('phone', 'message')),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-Api-Key: ' . self::WA_API_KEY,
            ],
        ]);
        $res = curl_exec($ch);
        curl_close($ch);
        return json_decode($res, true) ?? ['success' => false];
    }

    // POST /api/admin/whatsapp/send-one
    // Body: { user_id, message }
    public function waSendOne(): void {
        requireAdmin();
        $data    = json_decode(file_get_contents('php://input'), true) ?? [];
        $userId  = (int)($data['user_id'] ?? 0);
        $message = trim($data['message'] ?? '');
        if (!$userId || !$message) jsonError('user_id والرسالة مطلوبان');

        $db   = getDB();
        $stmt = $db->prepare('SELECT id, name, phone FROM users WHERE id = ? AND role = "user" LIMIT 1');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if (!$user) jsonError('المستخدم غير موجود');

        $result = $this->sendWhatsApp($user['phone'], $message);
        if (!empty($result['success'])) {
            // Log the message
            $db->prepare(
                'INSERT INTO wa_messages (target_type, target_id, message, status) VALUES ("user", ?, ?, "sent")'
            )->execute([$userId, $message]);
            jsonSuccess(['phone' => $user['phone']], 'تم إرسال الرسالة بنجاح');
        } else {
            jsonError('فشل إرسال الرسالة: ' . ($result['error'] ?? 'خطأ غير معروف'));
        }
    }

    // POST /api/admin/whatsapp/broadcast
    // Body: { message, delay_seconds? }  — sends to ALL users with timing delay
    public function waBroadcast(): void {
        requireAdmin();
        $data         = json_decode(file_get_contents('php://input'), true) ?? [];
        $message      = trim($data['message']        ?? '');
        $delaySeconds = max(3, min(60, (int)($data['delay_seconds'] ?? 5))); // 3-60 s between messages
        if (!$message) jsonError('الرسالة مطلوبة');

        $db    = getDB();
        $users = $db->query("SELECT id, name, phone FROM users WHERE role = 'user' ORDER BY id ASC")->fetchAll();
        if (empty($users)) jsonError('لا يوجد مستخدمون');

        // Save broadcast job
        $db->prepare(
            'INSERT INTO wa_messages (target_type, target_id, message, status, total_users) VALUES ("all", 0, ?, "queued", ?)'
        )->execute([$message, count($users)]);
        $jobId = (int)$db->lastInsertId();

        $sent   = 0;
        $failed = 0;
        foreach ($users as $i => $user) {
            if ($i > 0) sleep($delaySeconds);  // anti-ban delay
            $result = $this->sendWhatsApp($user['phone'], $message);
            if (!empty($result['success'])) {
                $sent++;
            } else {
                $failed++;
            }
        }

        $db->prepare('UPDATE wa_messages SET status="sent", sent_count=?, failed_count=? WHERE id=?')
           ->execute([$sent, $failed, $jobId]);

        jsonSuccess([
            'total'  => count($users),
            'sent'   => $sent,
            'failed' => $failed,
        ], "تم الإرسال: {$sent} نجح، {$failed} فشل");
    }

    // GET /api/admin/whatsapp/logs
    public function waLogs(): void {
        requireAdmin();
        $logs = getDB()->query(
            'SELECT m.*, u.name as user_name, u.phone as user_phone
             FROM wa_messages m
             LEFT JOIN users u ON u.id = m.target_id AND m.target_type = "user"
             ORDER BY m.created_at DESC LIMIT 100'
        )->fetchAll();
        jsonSuccess(['logs' => $logs]);
    }
}
