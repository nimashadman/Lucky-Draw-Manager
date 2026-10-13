<?php
/**
 * Plugin Name: Lucky Draw Manager
 * Plugin URI: https://github.com/nimashadman/Lucky-Draw-Manager
 * Description: افزونه مدیریت قرعه‌کشی وردپرس با فرم واکنش‌گرا، کد اختصاصی، مدیریت شرکت‌کنندگان، خروجی CSV و انتخاب تصادفی برنده.
 * Version: 1.0.0
 * Author: نیما شادمان
 * Author URI: https://nimashadman.ir
 * Text Domain: lucky-draw-manager
 */

defined('ABSPATH') || exit;

final class Lucky_Draw_Manager {
    const VERSION = '1.0.0';
    const TABLE = 'lucky_draw_manager_entries';

    public static function init() {
        register_activation_hook(__FILE__, array(__CLASS__, 'activate'));
        add_shortcode('lucky_draw_form', array(__CLASS__, 'shortcode'));
        add_action('wp_enqueue_scripts', array(__CLASS__, 'register_assets'));
        add_action('wp_ajax_nopriv_lucky_draw_manager_submit', array(__CLASS__, 'submit'));
        add_action('wp_ajax_lucky_draw_manager_submit', array(__CLASS__, 'submit'));
        add_action('admin_menu', array(__CLASS__, 'admin_menu'));
        add_action('admin_post_lucky_draw_manager_export', array(__CLASS__, 'export_csv'));
        add_action('admin_post_lucky_draw_manager_delete', array(__CLASS__, 'delete_entry'));
        add_action('admin_post_lucky_draw_manager_draw', array(__CLASS__, 'draw_winner'));
        add_action('admin_notices', array(__CLASS__, 'admin_notice'));
    }

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    public static function activate() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $table = self::table();
        dbDelta("CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            mobile varchar(20) NOT NULL,
            instagram varchar(100) NOT NULL,
            lottery_code char(4) NOT NULL,
            created_at datetime NOT NULL,
            ip_hash char(64) NOT NULL DEFAULT '',
            PRIMARY KEY (id),
            UNIQUE KEY mobile (mobile),
            UNIQUE KEY lottery_code (lottery_code),
            KEY created_at (created_at)
        ) {$charset};");
    }

    public static function register_assets() {
        wp_register_style('lucky-draw-manager', plugins_url('assets/lottery.css', __FILE__), array(), self::VERSION);
        wp_register_script('lucky-draw-manager', plugins_url('assets/lottery.js', __FILE__), array(), self::VERSION, true);
    }

    public static function shortcode() {
        wp_enqueue_style('lucky-draw-manager');
        wp_enqueue_script('lucky-draw-manager');
        wp_localize_script('lucky-draw-manager', 'LuckyDrawManager', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('lucky_draw_manager_submit'),
        ));
        ob_start(); ?>
        <section class="ldm-wrap" dir="rtl">
            <div class="ldm-confetti" aria-hidden="true"></div>
            <div class="ldm-card">
                <div class="ldm-icon" aria-hidden="true">🎁</div>
                <p class="ldm-kicker">فرصت برنده شدن</p>
                <h2>در قرعه‌کشی ما شرکت کنید</h2>
                <p class="ldm-intro">اطلاعاتت را وارد کن و کد اختصاصی قرعه‌کشی‌ات را تحویل بگیر.</p>
                <form class="ldm-form" novalidate>
                    <label>شماره موبایل
                        <input type="tel" name="mobile" inputmode="numeric" autocomplete="tel" maxlength="11" placeholder="مثلاً ۰۹۱۲۱۲۳۴۵۶۷" required>
                    </label>
                    <label>آیدی اینستاگرام
                        <div class="ldm-instagram"><span>@</span><input type="text" name="instagram" autocomplete="off" maxlength="50" placeholder="your_id" required></div>
                    </label>
                    <div class="ldm-trap" aria-hidden="true"><label>وب‌سایت<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
                    <button type="submit" class="ldm-submit"><span>ثبت و دریافت کد</span><i aria-hidden="true">←</i></button>
                    <p class="ldm-status" role="alert" aria-live="polite"></p>
                </form>
                <div class="ldm-result" hidden>
                    <div class="ldm-success">✓</div>
                    <h3>شما در قرعه‌کشی شرکت داده شدید!</h3>
                    <p>این کد را تا روز قرعه‌کشی نزد خود نگه دارید.</p>
                    <div class="ldm-code" aria-label="کد قرعه‌کشی"><span></span></div>
                    <div class="ldm-actions">
                        <button type="button" class="ldm-copy">کپی کد</button>
                        <button type="button" class="ldm-save">ذخیره تصویر کد</button>
                    </div>
                    <small>در روز قرعه‌کشی برای دریافت جایزه به این کد نیاز دارید.</small>
                </div>
            </div>
        </section>
        <?php return ob_get_clean();
    }

    private static function normalize_mobile($mobile) {
        $map = array('۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9');
        $mobile = strtr(sanitize_text_field($mobile), $map);
        $mobile = preg_replace('/\D+/', '', $mobile);
        if (strpos($mobile, '98') === 0 && strlen($mobile) === 12) $mobile = '0' . substr($mobile, 2);
        return $mobile;
    }

    public static function submit() {
        check_ajax_referer('lucky_draw_manager_submit', 'nonce');
        if (!empty($_POST['website'])) wp_send_json_error(array('message' => 'درخواست نامعتبر است.'), 400);
        $mobile = self::normalize_mobile(isset($_POST['mobile']) ? wp_unslash($_POST['mobile']) : '');
        $instagram = sanitize_text_field(isset($_POST['instagram']) ? wp_unslash($_POST['instagram']) : '');
        $instagram = ltrim(trim($instagram), '@');
        if (!preg_match('/^09\d{9}$/', $mobile)) wp_send_json_error(array('message' => 'شماره موبایل معتبر وارد کنید.'), 422);
        if (!preg_match('/^[A-Za-z0-9._]{1,30}$/', $instagram)) wp_send_json_error(array('message' => 'آیدی اینستاگرام معتبر وارد کنید.'), 422);

        global $wpdb;
        $table = self::table();
        $existing = $wpdb->get_var($wpdb->prepare("SELECT lottery_code FROM {$table} WHERE mobile = %s", $mobile));
        if ($existing) wp_send_json_error(array('message' => 'این شماره قبلاً ثبت شده است.'), 409);

        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        $ip_hash = hash_hmac('sha256', $ip, wp_salt('auth'));
        $rate_key = 'ldm_rate_' . substr($ip_hash, 0, 24);
        if ((int) get_transient($rate_key) >= 5) wp_send_json_error(array('message' => 'تعداد درخواست‌ها زیاد است؛ کمی بعد دوباره تلاش کنید.'), 429);
        set_transient($rate_key, ((int) get_transient($rate_key)) + 1, HOUR_IN_SECONDS);

        $inserted = false;
        for ($i = 0; $i < 30; $i++) {
            $code = (string) random_int(1000, 9999);
            $inserted = $wpdb->insert($table, array('mobile'=>$mobile, 'instagram'=>$instagram, 'lottery_code'=>$code, 'created_at'=>current_time('mysql'), 'ip_hash'=>$ip_hash), array('%s','%s','%s','%s','%s'));
            if ($inserted) break;
            $duplicate_mobile = $wpdb->get_var($wpdb->prepare("SELECT lottery_code FROM {$table} WHERE mobile = %s", $mobile));
            if ($duplicate_mobile) wp_send_json_error(array('message'=>'این شماره قبلاً ثبت شده است.'), 409);
        }
        if (!$inserted) wp_send_json_error(array('message'=>'ظرفیت کدها تکمیل شده یا خطایی رخ داده است.'), 500);
        wp_send_json_success(array('code'=>$code, 'message'=>'ثبت‌نام با موفقیت انجام شد.'));
    }

    public static function admin_menu() {
        add_menu_page('قرعه‌کشی', 'قرعه‌کشی', 'manage_options', 'lucky-draw-manager', array(__CLASS__, 'admin_page'), 'dashicons-tickets-alt', 26);
    }

    public static function admin_page() {
        if (!current_user_can('manage_options')) return;
        global $wpdb;
        $table = self::table();
        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $page = max(1, isset($_GET['paged']) ? absint($_GET['paged']) : 1);
        $per_page = 25; $offset = ($page - 1) * $per_page;
        $where = ''; $args = array();
        if ($search !== '') { $like = '%' . $wpdb->esc_like($search) . '%'; $where = ' WHERE mobile LIKE %s OR instagram LIKE %s OR lottery_code LIKE %s'; $args = array($like,$like,$like); }
        $count_sql = "SELECT COUNT(*) FROM {$table}{$where}";
        $total = $args ? (int)$wpdb->get_var($wpdb->prepare($count_sql, $args)) : (int)$wpdb->get_var($count_sql);
        $rows_sql = "SELECT * FROM {$table}{$where} ORDER BY id DESC LIMIT %d OFFSET %d";
        $rows_args = array_merge($args, array($per_page,$offset));
        $rows = $wpdb->get_results($wpdb->prepare($rows_sql, $rows_args));
        $all = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        $today = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE created_at >= %s", current_time('Y-m-d 00:00:00')));
        ?>
        <div class="wrap ldm-admin" dir="rtl"><h1>مدیریت قرعه‌کشی</h1>
            <style>.ldm-admin{max-width:1200px}.ldm-stats{display:flex;gap:16px;margin:18px 0}.ldm-stat{background:#fff;border-right:4px solid #7c3aed;padding:18px 24px;min-width:170px;box-shadow:0 1px 3px #0001}.ldm-stat b{display:block;font-size:28px}.ldm-tools{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:16px 0}.ldm-admin table td,.ldm-admin table th{text-align:right}.ldm-code-admin{font:bold 17px monospace;color:#6d28d9}.ldm-winner{background:#f5f3ff;border:1px solid #c4b5fd;padding:16px;border-radius:8px;margin:16px 0}</style>
            <div class="ldm-stats"><div class="ldm-stat"><span>کل شرکت‌کنندگان</span><b><?php echo esc_html(number_format_i18n($all)); ?></b></div><div class="ldm-stat"><span>ثبت‌نام امروز</span><b><?php echo esc_html(number_format_i18n($today)); ?></b></div></div>
            <?php if (isset($_GET['winner'])): ?><div class="ldm-winner"><strong>برنده انتخاب‌شده:</strong> کد <span class="ldm-code-admin"><?php echo esc_html(sanitize_text_field(wp_unslash($_GET['winner']))); ?></span><?php if (isset($_GET['winner_mobile'])) echo ' — شماره ' . esc_html(sanitize_text_field(wp_unslash($_GET['winner_mobile']))); ?></div><?php endif; ?>
            <div class="ldm-tools">
                <form method="get"><input type="hidden" name="page" value="lucky-draw-manager"><input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="موبایل، آیدی یا کد"><button class="button">جست‌وجو</button></form>
                <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=lucky_draw_manager_export'), 'ldm_export')); ?>">خروجی CSV</a>
                <a class="button button-primary" onclick="return confirm('برنده به‌صورت تصادفی انتخاب شود؟')" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=lucky_draw_manager_draw'), 'ldm_draw')); ?>">انتخاب تصادفی برنده</a>
            </div>
            <table class="widefat striped"><thead><tr><th>ردیف</th><th>شماره موبایل</th><th>آیدی اینستاگرام</th><th>کد</th><th>تاریخ ثبت</th><th>عملیات</th></tr></thead><tbody>
            <?php if (!$rows): ?><tr><td colspan="6">موردی پیدا نشد.</td></tr><?php else: foreach ($rows as $row): ?>
                <tr><td><?php echo esc_html($row->id); ?></td><td dir="ltr"><?php echo esc_html($row->mobile); ?></td><td dir="ltr">@<?php echo esc_html($row->instagram); ?></td><td class="ldm-code-admin"><?php echo esc_html($row->lottery_code); ?></td><td><?php echo esc_html($row->created_at); ?></td><td><a style="color:#b32d2e" onclick="return confirm('این رکورد حذف شود؟')" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=lucky_draw_manager_delete&id=' . absint($row->id)), 'ldm_delete_' . absint($row->id))); ?>">حذف</a></td></tr>
            <?php endforeach; endif; ?></tbody></table>
            <?php echo wp_kses_post(paginate_links(array('base'=>add_query_arg('paged','%#%'),'format'=>'','current'=>$page,'total'=>max(1,ceil($total/$per_page)),'type'=>'list'))); ?>
            <p><strong>شورت‌کد فرم:</strong> <code>[lucky_draw_form]</code></p>
        </div><?php
    }

    public static function export_csv() {
        if (!current_user_can('manage_options')) wp_die('دسترسی غیرمجاز');
        check_admin_referer('ldm_export'); global $wpdb;
        $rows = $wpdb->get_results('SELECT id,mobile,instagram,lottery_code,created_at FROM ' . self::table() . ' ORDER BY id ASC', ARRAY_A);
        nocache_headers(); header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename=lottery-entries-' . gmdate('Y-m-d') . '.csv');
        $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF"); fputcsv($out, array('ID','Mobile','Instagram','Code','Created At'));
        foreach ($rows as $row) fputcsv($out, $row); fclose($out); exit;
    }

    public static function delete_entry() {
        if (!current_user_can('manage_options')) wp_die('دسترسی غیرمجاز');
        $id = isset($_GET['id']) ? absint($_GET['id']) : 0; check_admin_referer('ldm_delete_' . $id);
        global $wpdb; $wpdb->delete(self::table(), array('id'=>$id), array('%d'));
        wp_safe_redirect(admin_url('admin.php?page=lucky-draw-manager&ldm_notice=deleted')); exit;
    }

    public static function draw_winner() {
        if (!current_user_can('manage_options')) wp_die('دسترسی غیرمجاز');
        check_admin_referer('ldm_draw'); global $wpdb;
        $winner = $wpdb->get_row('SELECT mobile,lottery_code FROM ' . self::table() . ' ORDER BY RAND() LIMIT 1');
        $url = admin_url('admin.php?page=lucky-draw-manager');
        if ($winner) $url = add_query_arg(array('winner'=>$winner->lottery_code,'winner_mobile'=>$winner->mobile), $url);
        else $url = add_query_arg('ldm_notice','empty',$url);
        wp_safe_redirect($url); exit;
    }

    public static function admin_notice() {
        if (!isset($_GET['page']) || $_GET['page'] !== 'lucky-draw-manager' || empty($_GET['ldm_notice'])) return;
        $message = $_GET['ldm_notice'] === 'deleted' ? 'رکورد حذف شد.' : 'هنوز شرکت‌کننده‌ای ثبت نشده است.';
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($message) . '</p></div>';
    }
}

Lucky_Draw_Manager::init();
