<?php
/*
Plugin Name: BKJA Assistant
Version: 1.5.7
Description: ابزار دستیار شغلی حرفه‌ای برای وردپرس.
Author: Mahdi Mohammadi
*/
// Handle CSV import for jobs
add_action('admin_post_bkja_import_jobs', function() {
	if (!current_user_can('manage_options')) wp_die('دسترسی غیرمجاز');
	check_admin_referer('bkja_import_jobs');
	if (!isset($_FILES['bkja_jobs_csv']) || $_FILES['bkja_jobs_csv']['error'] !== UPLOAD_ERR_OK) {
		wp_redirect(add_query_arg('bkja_import_success', '0', admin_url('admin.php?page=bkja-assistant')));
		exit;
	}
	$file = $_FILES['bkja_jobs_csv']['tmp_name'];
	$handle = fopen($file, 'r');
	if ($handle) {
		$header = fgetcsv($handle);
		while (($row = fgetcsv($handle)) !== false) {
			$data = array_combine($header, $row);
			if ($data && !empty($data['title'])) {
				$fields = ['category_id','title','income','investment','city','gender','advantages','disadvantages','details'];
				$job = [];
				foreach ($fields as $f) {
					$job[$f] = isset($data[$f]) ? $data[$f] : '';
				}
				if (class_exists('BKJA_Database')) {
					BKJA_Database::insert_job($job);
				}
			}
		}
		fclose($handle);
	}
	wp_redirect(add_query_arg('bkja_import_success', '1', admin_url('admin.php?page=bkja-assistant')));
	exit;
});

// API for jobs - get job records (with advanced filters)
add_action('rest_api_init', function() {
	register_rest_route('bkja/v1', '/jobs/', [
		'methods' => 'GET',
		'callback' => 'bkja_get_jobs_api',
	]);
});

function bkja_get_jobs_api(WP_REST_Request $request) {
	global $wpdb;
	$table = $wpdb->prefix . 'bkja_jobs';
	$where = '1=1';
	$params = [];
	// فیلترهای مختلف
	if ($category = $request->get_param('category_id')) {
		$where .= ' AND category_id = %d';
		$params[] = $category;
	}
	if ($title = $request->get_param('title')) {
		$where .= ' AND title LIKE %s';
		$params[] = '%' . $wpdb->esc_like($title) . '%';
	}
	if ($city = $request->get_param('city')) {
		$where .= ' AND city LIKE %s';
		$params[] = '%' . $wpdb->esc_like($city) . '%';
	}
	if ($min_income = $request->get_param('min_income')) {
		$where .= ' AND income >= %f';
		$params[] = $min_income;
	}
	if ($max_income = $request->get_param('max_income')) {
		$where .= ' AND income <= %f';
		$params[] = $max_income;
	}

	// صفحه‌بندی
	$page = $request->get_param('page') ?: 1;
	$per_page = $request->get_param('per_page') ?: 10;
	$offset = ($page - 1) * $per_page;
	$sql = "SELECT * FROM {$table} WHERE $where LIMIT %d OFFSET %d";
	$params[] = $per_page;
	$params[] = $offset;

	$results = $wpdb->get_results($wpdb->prepare($sql, $params));
	return rest_ensure_response($results);
}
// AJAX: دریافت خلاصه شغل بر اساس عنوان
add_action('wp_ajax_bkja_get_job_summary','bkja_ajax_get_job_summary');
add_action('wp_ajax_nopriv_bkja_get_job_summary','bkja_ajax_get_job_summary');
function bkja_ajax_get_job_summary(){
	check_ajax_referer('bkja_nonce','nonce');
	$job_title = isset($_POST['job_title']) ? sanitize_text_field($_POST['job_title']) : '';
	if(!$job_title) wp_send_json_error(['error'=>'empty_title'],400);
	$summary = BKJA_Jobs::get_job_summary($job_title);
	$free_messages = get_option('bkja_free_messages_per_day', 5);
	if(!$summary) wp_send_json_error(['error'=>'not_found'],404);
	wp_send_json_success(['summary'=>$summary]);
}

// AJAX: دریافت رکوردهای شغل بر اساس عنوان (limit, offset)
add_action('wp_ajax_bkja_get_job_records','bkja_ajax_get_job_records');
add_action('wp_ajax_nopriv_bkja_get_job_records','bkja_ajax_get_job_records');
function bkja_ajax_get_job_records(){
	check_ajax_referer('bkja_nonce','nonce');
	$job_title = isset($_POST['job_title']) ? sanitize_text_field($_POST['job_title']) : '';
	$limit = isset($_POST['limit']) ? intval($_POST['limit']) : 5;
	$offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
	if(!$job_title) wp_send_json_error(['error'=>'empty_title'],400);
	$records = BKJA_Jobs::get_job_records($job_title, $limit, $offset);
	$free_messages = get_option('bkja_free_messages_per_day', 5);
	wp_send_json_success(['records'=>$records]);
}
if ( ! defined( 'ABSPATH' ) ) exit;
define( 'BKJA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BKJA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
require_once BKJA_PLUGIN_DIR . 'includes/class-bkja-database.php';
require_once BKJA_PLUGIN_DIR . 'includes/class-bkja-chat.php';
require_once BKJA_PLUGIN_DIR . 'includes/class-bkja-frontend.php';
require_once BKJA_PLUGIN_DIR . 'includes/class-bkja-user-profile.php';
require_once BKJA_PLUGIN_DIR . 'admin/settings.php';
register_activation_hook( __FILE__, array( 'BKJA_Database', 'activate' ) );
add_action( 'init', function(){ load_plugin_textdomain( 'bkja-assistant', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' ); if ( class_exists('BKJA_Frontend') ) BKJA_Frontend::init(); if ( class_exists('BKJA_User_Profile') ) BKJA_User_Profile::init(); });
/* BKJA builder v1.4.1 injections */
if ( file_exists(dirname(__FILE__) . '/includes/class-bkja-jobs.php') ) { require_once dirname(__FILE__) . '/includes/class-bkja-jobs.php'; }
if ( file_exists(dirname(__FILE__) . '/includes/class-bkja-chat.php') ) { require_once dirname(__FILE__) . '/includes/class-bkja-chat.php'; }

add_action('wp_ajax_bkja_get_categories','bkja_ajax_get_categories');
add_action('wp_ajax_nopriv_bkja_get_categories','bkja_ajax_get_categories');
function bkja_ajax_get_categories(){ check_ajax_referer('bkja_nonce','nonce'); $cats = BKJA_Jobs::get_categories(); wp_send_json_success(['categories'=>$cats]); }

add_action('wp_ajax_bkja_get_jobs','bkja_ajax_get_jobs');
add_action('wp_ajax_nopriv_bkja_get_jobs','bkja_ajax_get_jobs');
function bkja_ajax_get_jobs(){ check_ajax_referer('bkja_nonce','nonce'); $cat = isset($_POST['category_id'])? intval($_POST['category_id']):0; $jobs = BKJA_Jobs::get_jobs_by_category($cat); wp_send_json_success(['jobs'=>$jobs]); }

add_action('wp_ajax_bkja_get_job_detail','bkja_ajax_get_job_detail');
add_action('wp_ajax_nopriv_bkja_get_job_detail','bkja_ajax_get_job_detail');
function bkja_ajax_get_job_detail(){ check_ajax_referer('bkja_nonce','nonce'); $job_id = isset($_POST['job_id'])? intval($_POST['job_id']):0; $job = BKJA_Jobs::get_job_detail($job_id); if(!$job) wp_send_json_error(['error'=>'not_found'],404); global $wpdb; $table_chats = $wpdb->prefix.'bkja_chats'; if($wpdb->get_var("SHOW TABLES LIKE '{$table_chats}'") == $table_chats){ $wpdb->insert($table_chats,['user_id'=>get_current_user_id()?:null,'session_id'=>isset($_POST['session'])?sanitize_text_field($_POST['session']):null,'job_category'=>$job->category_id,'message'=>null,'response'=>wp_json_encode(['type'=>'job_card','data'=>$job]),'created_at'=>current_time('mysql')]); } wp_send_json_success(['job'=>$job]); }