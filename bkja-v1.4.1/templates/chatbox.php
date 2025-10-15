
<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<!-- TailwindCSS CDN -->
<link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">

<!-- Inline styles moved to assets/css/bkja-frontend.css - keep JS unchanged -->

<!-- Crisp-style Chat Launcher -->
<div id="bkja-chat-launcher">
  <button id="bkja-launcher-btn" aria-label="باز کردن چت">
    <svg width="32" height="32" viewBox="0 0 36 36" fill="none" xmlns="http://www.w3.org/2000/svg">
      <rect x="3" y="5" width="30" height="22" rx="11" fill="white" fill-opacity="0.2"/>
      <path d="M9 12.5C9 10.567 10.567 9 12.5 9H23.5C25.433 9 27 10.567 27 12.5V17.5C27 19.433 25.433 21 23.5 21H16L11 26V17.5C9.567 17.5 9 15.433 9 17.5V12.5Z" fill="white"/>
    </svg>
  </button>
  <div id="bkja-launcher-welcome">سلام 👋 من دستیار شغلی هستم. چطور می‌تونم کمکتون کنم؟</div>
</div>

<div id="bkja-chat-overlay"></div>

<!-- Chat Panel (hidden by default) -->
<div id="bkja-chatbox" class="bkja-container bkja-panel-hidden">
  <div class="bkja-header">
    <div class="bkja-header-info">
      <div class="bkja-header-avatar" aria-hidden="true">🤖</div>
      <div class="bkja-header-text">
        <span class="bkja-title"><?php echo esc_html( $atts['title'] ?? __( 'دستیار شغلی', 'bkja-assistant' ) ); ?></span>
        <span class="bkja-subtitle"><span class="bkja-status-dot" aria-hidden="true"></span> همیشه آماده پاسخ‌گویی</span>
      </div>
    </div>
    <div class="bkja-header-btns">
      <button id="bkja-menu-toggle" class="bkja-menu-toggle" aria-expanded="false" aria-controls="bkja-menu-panel" aria-label="باز کردن منو">☰</button>
      <button id="bkja-close-panel" class="bkja-close-panel" aria-label="بستن چت">✕</button>
    </div>
  </div>

  <div id="bkja-menu-panel" class="bkja-menu-panel">
    <button class="bkja-close-menu" aria-label="<?php esc_attr_e('Close menu','bkja-assistant'); ?>">✕</button>
    <div class="bkja-menu-content">
      <div class="bkja-menu-section bkja-profile-section">
        <h4 class="bkja-menu-title">پروفایل</h4>
        <p class="bkja-menu-text">👤 <?php echo is_user_logged_in() ? esc_html( wp_get_current_user()->display_name ) : 'کاربر مهمان'; ?></p>
      </div>
      <div class="bkja-menu-section bkja-categories-section">
        <h4 class="bkja-menu-title">دسته‌بندی‌ها</h4>
        <ul id="bkja-categories-list" class="bkja-menu-cats" role="list"></ul>
      </div>
      <div class="bkja-menu-section bkja-jobs-section" style="display:none;">
        <h4 class="bkja-menu-title">شغل‌ها</h4>
        <ul id="bkja-jobs-list" class="bkja-menu-cats" role="list"></ul>
      </div>
      <!-- پنل تاریخچه -->
      <!-- پنل تاریخچه توسط JS ساخته می‌شود -->
    </div>
  </div>

  <div class="bkja-messages" role="log" aria-live="polite">
    <!-- پیام‌های چت توسط JS اضافه می‌شوند -->
    <!-- نمونه استایل برای بابل کاربر و بات -->
    <div class="hidden">
      <div class="bkja-bubble user">نمونه پیام کاربر</div>
      <div class="bkja-bubble bot">نمونه پیام بات</div>
    </div>
  </div>

  <form id="bkja-chat-form" class="bkja-form" action="#" method="post">
    <div class="bkja-quick-list" aria-hidden="false"></div>
    <div class="bkja-input-row">
      <input id="bkja-user-message" name="message" type="text"
        placeholder="<?php esc_attr_e( 'پیام خود را بنویسید...', 'bkja-assistant' ); ?>" />
      <button id="bkja-send" type="submit"> <?php esc_html_e( 'ارسال', 'bkja-assistant' ); ?> </button>
    </div>
  </form>
</div>

