<?php
// controllers/AuthController.php

class AuthController {

    private const WA_API_URL  = 'http://34.70.77.29:5000/api/send';
    private const WA_API_KEY  = 'no-key-set';
    private const SYRIA_CODE  = '963';
    private const OTP_EXPIRE  = 600;  // 10 دقائق
    private const OTP_COOLDOWN= 60;   // ثانية بين الطلبات
    private const OTP_TRIES   = 5;    // محاولات قبل الحجب

    // ─── تطبيع الرقم ─────────────────────────────────────────────────────────
    private function normalizePhone(string $raw): string {
        $raw = preg_replace('/\D/', '', $raw);
        if (substr($raw, 0, 3) === '963') $raw = substr($raw, 3);
        if (substr($raw, 0, 1) === '0')   $raw = substr($raw, 1);
        if (!preg_match('/^[985][0-9]{8}$/', $raw)) return '';
        return self::SYRIA_CODE . $raw;
    }

    // ─── إرسال واتساب ────────────────────────────────────────────────────────
    private function sendWhatsApp(string $phone, string $message): bool {
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
        $res  = curl_exec($ch);
        curl_close($ch);
        return !empty(json_decode($res, true)['success']);
    }

    private function generateOtp(): string {
        return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function cleanExpired(): void {
        try { getDB()->exec('DELETE FROM otp_codes WHERE expires_at < NOW()'); } catch (\Exception $e) {}
    }

    // ─── حفظ OTP في DB ───────────────────────────────────────────────────────
    private function saveOtp(string $phone, string $otp): void {
        $hash    = password_hash($otp, PASSWORD_BCRYPT);
        $expires = date('Y-m-d H:i:s', time() + self::OTP_EXPIRE);
        getDB()->prepare(
            'INSERT INTO otp_codes (phone, code_hash, expires_at, tries, verified)
             VALUES (?, ?, ?, 0, 0)
             ON DUPLICATE KEY UPDATE
               code_hash = VALUES(code_hash),
               expires_at= VALUES(expires_at),
               tries     = 0,
               verified  = 0,
               created_at= NOW()'
        )->execute([$phone, $hash, $expires]);
    }

    // ─── قراءة OTP من DB ─────────────────────────────────────────────────────
    private function fetchOtp(string $phone): ?array {
        $s = getDB()->prepare('SELECT * FROM otp_codes WHERE phone = ? LIMIT 1');
        $s->execute([$phone]);
        return $s->fetch() ?: null;
    }

    // ─── التحقق من OTP (مشترك) ───────────────────────────────────────────────
    // يرجع true عند النجاح، ويحذف السجل تلقائياً
    // يرمي jsonError عند الفشل
    private function checkOtp(string $phone, string $otp): void {
        $row = $this->fetchOtp($phone);
        if (!$row) jsonError('لم يتم طلب رمز تحقق لهذا الرقم');

        if (strtotime($row['expires_at']) < time()) {
            getDB()->prepare('DELETE FROM otp_codes WHERE phone=?')->execute([$phone]);
            jsonError('انتهت صلاحية رمز التحقق، يرجى طلب رمز جديد');
        }

        $tries = (int)$row['tries'] + 1;
        if ($tries > self::OTP_TRIES) {
            getDB()->prepare('DELETE FROM otp_codes WHERE phone=?')->execute([$phone]);
            jsonError('تجاوزت عدد المحاولات، يرجى طلب رمز جديد');
        }
        getDB()->prepare('UPDATE otp_codes SET tries=? WHERE phone=?')->execute([$tries, $phone]);

        if (!password_verify($otp, $row['code_hash'])) {
            $left = self::OTP_TRIES - $tries;
            jsonError('رمز التحقق غير صحيح' . ($left > 0 ? " ({$left} محاولات متبقية)" : ''));
        }

        // صحيح — احذفه فوراً (single-use)
        getDB()->prepare('DELETE FROM otp_codes WHERE phone=?')->execute([$phone]);
    }

    // =========================================================================
    // POST /api/auth/send-otp  { phone }
    // =========================================================================
    public function sendOtp(): void {
        $data  = json_decode(file_get_contents('php://input'), true) ?? [];
        $phone = $this->normalizePhone(trim($data['phone'] ?? ''));
        if (!$phone) jsonError('رقم الهاتف غير صحيح');

        $db = getDB();

        // Rate-limit
        $s = $db->prepare(
            'SELECT created_at FROM otp_codes WHERE phone=?
             AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND) LIMIT 1'
        );
        $s->execute([$phone, self::OTP_COOLDOWN]);
        if ($s->fetch()) jsonError('الرجاء الانتظار دقيقة قبل إعادة الإرسال');

        // هل مستخدم موجود؟ وهل أدمن؟
        $u = $db->prepare('SELECT role FROM users WHERE phone=? LIMIT 1');
        $u->execute([$phone]);
        $user      = $u->fetch();
        $isNewUser = !$user;
        $isAdmin   = $user && $user['role'] === 'admin';

        // أنشئ OTP وأرسله
        $otp = $this->generateOtp();
        $this->saveOtp($phone, $otp);

        $sent = $this->sendWhatsApp($phone,
            "🔐 رمز التحقق الخاص بك:\n\n*{$otp}*\n\nصالح لمدة 10 دقائق. لا تشاركه مع أحد."
        );

        if (!$sent) {
            $db->prepare('DELETE FROM otp_codes WHERE phone=?')->execute([$phone]);
            jsonError('تعذّر إرسال رمز التحقق، يرجى المحاولة لاحقاً');
        }

        if (random_int(1, 10) === 1) $this->cleanExpired();

        jsonSuccess([
            'phone'       => $phone,
            'is_new_user' => $isNewUser,
            'is_admin'    => $isAdmin,
        ], 'تم إرسال رمز التحقق إلى واتساب');
    }

    // =========================================================================
    // POST /api/auth/verify-otp  { phone, otp }
    // للمستخدم العادي فقط
    // =========================================================================
    public function verifyOtp(): void {
        $data  = json_decode(file_get_contents('php://input'), true) ?? [];
        $phone = $this->normalizePhone(trim($data['phone'] ?? ''));
        $otp   = trim($data['otp'] ?? '');
        if (!$phone) jsonError('رقم الهاتف غير صحيح');
        if (!preg_match('/^\d{6}$/', $otp)) jsonError('رمز التحقق يجب أن يكون 6 أرقام');

        $this->checkOtp($phone, $otp);

        $db = getDB();
        $s  = $db->prepare('SELECT * FROM users WHERE phone=? LIMIT 1');
        $s->execute([$phone]);
        $user = $s->fetch();

        if (!$user) {
            // مستخدم جديد — خزّن علامة التحقق
            $db->prepare(
                'INSERT INTO otp_codes (phone, code_hash, expires_at, tries, verified)
                 VALUES (?, "", DATE_ADD(NOW(), INTERVAL 10 MINUTE), 0, 1)
                 ON DUPLICATE KEY UPDATE verified=1, expires_at=DATE_ADD(NOW(), INTERVAL 10 MINUTE)'
            )->execute([$phone]);
            jsonSuccess(['needs_name' => true, 'phone' => $phone], 'أدخل اسمك');
        }

        // مستخدم موجود — منع الأدمن من هذا المسار
        if ($user['role'] === 'admin') {
            jsonError('يرجى استخدام مسار تسجيل دخول المدير');
        }

        sessionStart();
        session_regenerate_id(true);
        $_SESSION['user'] = [
            'id' => $user['id'], 'name' => $user['name'],
            'phone' => $user['phone'], 'role' => $user['role'],
        ];
        jsonSuccess(['user' => $_SESSION['user']], 'تم تسجيل الدخول');
    }

    // =========================================================================
    // POST /api/auth/verify-admin-otp  { phone, otp }
    // للأدمن فقط — يتحقق من OTP ثم يطلب كلمة المرور
    // =========================================================================
    public function verifyAdminOtp(): void {
        $data  = json_decode(file_get_contents('php://input'), true) ?? [];
        $phone = $this->normalizePhone(trim($data['phone'] ?? ''));
        $otp   = trim($data['otp'] ?? '');
        if (!$phone) jsonError('رقم الهاتف غير صحيح');
        if (!preg_match('/^\d{6}$/', $otp)) jsonError('رمز التحقق يجب أن يكون 6 أرقام');

        // تحقق أن الرقم أدمن
        $u = getDB()->prepare('SELECT role FROM users WHERE phone=? LIMIT 1');
        $u->execute([$phone]);
        $user = $u->fetch();
        if (!$user || $user['role'] !== 'admin') jsonError('غير مصرح');

        $this->checkOtp($phone, $otp);

        // خزّن علامة "OTP تم التحقق منه" في session لمسار كلمة المرور
        sessionStart();
        $_SESSION['admin_otp_verified'] = $phone;

        jsonSuccess([], 'رمز صحيح، أدخل كلمة المرور');
    }

    // =========================================================================
    // POST /api/auth/login  { phone, password }
    // للأدمن — بعد التحقق من OTP
    // =========================================================================
    public function login(): void {
        $data  = json_decode(file_get_contents('php://input'), true) ?? [];
        $phone = trim($data['phone']   ?? '');
        $pass  = $data['password']     ?? '';
        if (!$phone || !$pass) jsonError('البيانات مطلوبة');

        sessionStart();

        // يجب أن يكون OTP تم التحقق منه
        if (($_SESSION['admin_otp_verified'] ?? '') !== $phone) {
            jsonError('يجب التحقق من رمز واتساب أولاً');
        }

        $db = getDB();
        $s  = $db->prepare('SELECT * FROM users WHERE phone=? AND role="admin" LIMIT 1');
        $s->execute([$phone]);
        $user = $s->fetch();

        if (!$user || !password_verify($pass, $user['password'])) {
            jsonError('كلمة المرور غير صحيحة', 401);
        }

        unset($_SESSION['admin_otp_verified']);
        session_regenerate_id(true);
        $_SESSION['user'] = [
            'id' => $user['id'], 'name' => $user['name'],
            'phone' => $user['phone'], 'role' => $user['role'],
        ];
        jsonSuccess(['user' => $_SESSION['user']], 'تم تسجيل الدخول');
    }

    // =========================================================================
    // POST /api/auth/complete-name  { phone, name }
    // =========================================================================
    public function completeName(): void {
        $data  = json_decode(file_get_contents('php://input'), true) ?? [];
        $phone = $this->normalizePhone(trim($data['phone'] ?? ''));
        $name  = trim($data['name'] ?? '');
        if (!$phone) jsonError('رقم الهاتف غير صحيح');
        if (mb_strlen($name) < 2) jsonError('الاسم يجب أن يكون حرفين على الأقل');

        $db = getDB();
        $s  = $db->prepare(
            'SELECT id FROM otp_codes WHERE phone=? AND verified=1 AND expires_at>NOW() LIMIT 1'
        );
        $s->execute([$phone]);
        if (!$s->fetch()) jsonError('انتهت الجلسة، يرجى البدء من جديد');

        $db->prepare('DELETE FROM otp_codes WHERE phone=?')->execute([$phone]);

        // أنشئ المستخدم
        $e = $db->prepare('SELECT * FROM users WHERE phone=? LIMIT 1');
        $e->execute([$phone]);
        $user = $e->fetch();
        if (!$user) {
            $db->prepare('INSERT INTO users (name,phone,password,role) VALUES (?,?,?,"user")')
               ->execute([$name, $phone, password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT)]);
            $user = ['id' => (int)$db->lastInsertId(), 'name' => $name, 'phone' => $phone, 'role' => 'user'];
        }

        sessionStart();
        session_regenerate_id(true);
        $_SESSION['user'] = [
            'id' => $user['id'], 'name' => $user['name'],
            'phone' => $user['phone'], 'role' => $user['role'],
        ];
        jsonSuccess(['user' => $_SESSION['user']], 'تم إنشاء الحساب');
    }

    public function logout(): void {
        sessionStart();
        session_destroy();
        jsonSuccess([], 'تم تسجيل الخروج');
    }

    public function me(): void {
        $user = currentUser();
        if (!$user) jsonError('غير مصرح', 401);
        jsonSuccess(['user' => $user]);
    }
}
