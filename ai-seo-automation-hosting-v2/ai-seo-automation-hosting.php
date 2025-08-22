<?php
/*
Plugin Name: AI SEO Automation — Hosting Edition (v2)
Plugin URI: https://turnuphosting.com/
Description: Automated SEO content creation for hosting industry. Uses OpenAI, avoids duplicate content, includes competitor monitoring (RSS), human-friendly output, and SEO metadata support.
Version: 2.0.0
Author: TurnUpHosting
Author URI: https://turnuphosting.com/
License: GPL2
Text Domain: ai-seo-automation-hosting
*/

if (!defined('ABSPATH')) exit;

require_once plugin_dir_path(__FILE__) . 'includes/class-aisa-h-admin.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-aisa-h-openai.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-aisa-h-generator.php';

// init
add_action('plugins_loaded', function(){
    // instantiate admin to register settings page
    new AISA_H_Admin();
});
