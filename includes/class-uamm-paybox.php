<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Connect UA MediaManager to the Freemius service managed by UA Project Manager.
 * No Freemius SDK or secret is shipped with this plugin.
 */
final class UAMM_PayBox {
    const SERVICE_SLUG = 'ua-mediamanager';

    public static function boot() {
        add_action('plugins_loaded', array(__CLASS__, 'register_service'), 30);
        add_action('uamm_settings_after', array(__CLASS__, 'render_settings'));
        add_action('admin_notices', array(__CLASS__, 'dependency_notice'));
    }

    public static function manager_available() {
        return class_exists('\\UAPM\\PayBox\\UPB_Services')
            && class_exists('\\UAPM\\PayBox\\UPB_Freemius');
    }

    public static function register_service() {
        if (!self::manager_available()) {
            return;
        }

        $service = \\UAPM\\PayBox\\UPB_Services::by_slug(self::SERVICE_SLUG);
        if (!$service && function_exists('\\UAPM\\PayBox\\upb_register_service')) {
            \\UAPM\\PayBox\\upb_register_service(
                self::SERVICE_SLUG,
                'UA MediaManager',
                array(
                    'service_type' => 'license',
                    'description' => 'PRO access for UA MediaManager WordPress plugin.',
                    'enabled' => 0,
                )
            );
        }
    }

    public static function service() {
        if (!self::manager_available()) {
            return null;
        }
        return \\UAPM\\PayBox\\UPB_Services::by_slug(self::SERVICE_SLUG);
    }

    public static function is_pro($user_id = 0) {
        if (!self::manager_available()) {
            return false;
        }
        $user_id = $user_id ? absint($user_id) : get_current_user_id();
        $user = $user_id ? get_userdata($user_id) : false;
        if (!$user || !is_email($user->user_email)) {
            return false;
        }
        $service = self::service();
        if (!$service || empty($service['id']) || empty($service['enabled'])) {
            return false;
        }
        return \\UAPM\\PayBox\\UPB_Freemius::is_email_active((int)$service['id'], $user->user_email);
    }

    private static function checkout_url($service, $email) {
        if (!$service || empty($service['enabled']) || !class_exists('\\UAPM\\PayBox\\UPB_Freemius')) {
            return '';
        }
        $url = \\UAPM\\PayBox\\UPB_Freemius::checkout_url($service);
        if (!$url) {
            return '';
        }
        if (is_email($email)) {
            $url = add_query_arg(array('user_email' => $email, 'readonly_user' => 'true'), $url);
        }
        return $url;
    }

    public static function render_settings() {
        if (!current_user_can('manage_options')) {
            return;
        }

        echo '<hr><h2>' . esc_html__('Freemius / UA Project Manager', 'ua-mediamanager') . '</h2>';

        if (!self::manager_available()) {
            echo '<p>' . esc_html__('UA Project Manager із вбудованим Pay Box не активний. Встановіть або активуйте його, щоб керувати оплатою та ліцензіями централізовано.', 'ua-mediamanager') . '</p>';
            return;
        }

        $service = self::service();
        if (!$service) {
            echo '<p>' . esc_html__('Не вдалося зареєструвати послугу UA MediaManager у Pay Box.', 'ua-mediamanager') . '</p>';
            return;
        }

        $edit_url = admin_url('admin.php?page=uapm-paybox-services&action=edit&id=' . absint($service['id']));
        $user = wp_get_current_user();
        $email = $user && is_email($user->user_email) ? $user->user_email : '';
        $configured = !empty($service['freemius_product_id']) && !empty($service['freemius_plan_id']) && !empty($service['freemius_secret_key']) && !empty($service['freemius_checkout_url']);
        $active = self::is_pro();

        echo '<p>' . esc_html__('Оплата та ключі Freemius налаштовуються тільки в UA Project Manager → Pay Box → Послуги. Секретні ключі не зберігаються в цьому плагіні.', 'ua-mediamanager') . '</p>';
        echo '<p><strong>' . esc_html__('Послуга:', 'ua-mediamanager') . '</strong> UA MediaManager (#' . absint($service['id']) . ')</p>';

        if (!$configured) {
            echo '<p class="notice notice-warning inline"><strong>' . esc_html__('Freemius ще не налаштовано.', 'ua-mediamanager') . '</strong> ' . esc_html__('Відкрийте налаштування послуги в менеджері та вкажіть Product ID, Plan ID, Checkout URL і Product Secret Key.', 'ua-mediamanager') . '</p>';
        } elseif ($active) {
            echo '<p><strong style="color:#16803c">' . esc_html__('PRO активний для поточного облікового запису.', 'ua-mediamanager') . '</strong></p>';
        } else {
            echo '<p>' . esc_html__('Для поточного облікового запису активної PRO-ліцензії не знайдено.', 'ua-mediamanager') . '</p>';
            $checkout = self::checkout_url($service, $email);
            if ($checkout) {
                echo '<p><a class="button button-primary" href="' . esc_url($checkout) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('Придбати PRO через Freemius', 'ua-mediamanager') . '</a></p>';
            }
        }

        echo '<p><a class="button" href="' . esc_url($edit_url) . '">' . esc_html__('Налаштувати оплату в UA Project Manager', 'ua-mediamanager') . '</a></p>';
        echo '<p class="description">' . esc_html__('Після оплати доступ активується тільки після перевірки підписаного Freemius webhook у Pay Box. Повернення з Checkout саме по собі не вмикає PRO.', 'ua-mediamanager') . '</p>';
    }

    public static function dependency_notice() {
        if (!is_admin() || !current_user_can('manage_options') || self::manager_available()) {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || strpos((string)$screen->id, 'ua-mediamanager') === false) {
            return;
        }
        echo '<div class="notice notice-info"><p><strong>UA MediaManager:</strong> ' . esc_html__('Для керування Freemius оплатою підключіть UA Project Manager. Основні функції медіаменеджера працюють і без нього.', 'ua-mediamanager') . '</p></div>';
    }
}
UAMM_PayBox::boot();
