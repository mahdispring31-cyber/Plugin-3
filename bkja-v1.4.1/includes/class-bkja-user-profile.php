<?php if ( ! defined( 'ABSPATH' ) ) exit;
class BKJA_User_Profile {
    public static function init(){ add_action('show_user_profile',array(__CLASS__,'render_profile_fields')); add_action('edit_user_profile',array(__CLASS__,'render_profile_fields')); add_action('personal_options_update',array(__CLASS__,'save_profile_fields')); add_action('edit_user_profile_update',array(__CLASS__,'save_profile_fields')); }
    public static function render_profile_fields($user){ ?>
        <h2><?php esc_html_e('اطلاعات دستیار شغلی (BKJA)','bkja-assistant'); ?></h2>
        <table class="form-table">
            <tr><th><label for="bkja_job_title"><?php esc_html_e('عنوان شغلی','bkja-assistant'); ?></label></th><td><input type="text" name="bkja_job_title" id="bkja_job_title" value="<?php echo esc_attr(get_user_meta($user->ID,'bkja_job_title',true)); ?>" class="regular-text" /></td></tr>
            <tr><th><label for="bkja_skills"><?php esc_html_e('مهارت‌ها','bkja-assistant'); ?></label></th><td><textarea name="bkja_skills" id="bkja_skills" rows="4"><?php echo esc_textarea(get_user_meta($user->ID,'bkja_skills',true)); ?></textarea></td></tr>
            <tr><th><label for="bkja_experience"><?php esc_html_e('تجربه کاری','bkja-assistant'); ?></label></th><td><textarea name="bkja_experience" id="bkja_experience" rows="3"><?php echo esc_textarea(get_user_meta($user->ID,'bkja_experience',true)); ?></textarea></td></tr>
        </table><?php }
    public static function save_profile_fields($user_id){ if(!current_user_can('edit_user',$user_id)) return false; if(isset($_POST['bkja_job_title'])) update_user_meta($user_id,'bkja_job_title',sanitize_text_field(wp_unslash($_POST['bkja_job_title']))); if(isset($_POST['bkja_skills'])) update_user_meta($user_id,'bkja_skills',sanitize_textarea_field(wp_unslash($_POST['bkja_skills']))); if(isset($_POST['bkja_experience'])) update_user_meta($user_id,'bkja_experience',sanitize_textarea_field(wp_unslash($_POST['bkja_experience']))); }
}