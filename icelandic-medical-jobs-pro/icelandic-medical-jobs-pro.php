<?php
/*
Plugin Name: Icelandic Medical Jobs Translator Pro
Description: Translates Icelandic medical jobs with GPT-4, from external xml to local xml.
Version: 3.3
Author: Deepak Chhabra
*/
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Icelandic_Jobs_Translator_Pro {
    private $upload_dir;
    private $translated_file;
    private $log_file;
    private $settings;
	private $fields_not_to_translate = [
		'job_id',               
		'job_url',             
		'application_deadline_from',
		'application_deadline_to',  		
		'logo_url',  
		'jobPercentage'           
	];
    public function __construct() {
        
        $this->setup_paths();
		$this->init();
		
    }

    private function setup_paths() {
        $upload_dir = wp_upload_dir();
        $this->upload_dir = trailingslashit($upload_dir['basedir']) . 'icelandic-jobs/';
        $this->translated_file = $this->upload_dir . 'translated_jobs.xml';
        $this->log_file = $this->upload_dir . 'translation_log.txt';
    }

    private function init() {
        add_filter('cron_schedules', [$this, 'add_cron_schedules']);
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('ijt_daily_cron', [$this, 'process_source_feed']);
        add_action('ijt_translation_cron', [$this, 'process_translation_queue']);
        
        
        $this->settings = get_option('ijt_settings', array(
            'allowed_cities' => 'Akureyri',
            'api_key' => '',
            'source_url' => 'https://nytlaegejob.pythonanywhere.com/starfatorg.xml'
        ));
    }

    public function activate() {
        // Create directory first
		if (!$this->create_directory()) {
			$this->log('Failed to create directory during activation');
		}
        
        // Schedule cron jobs
        if (!wp_next_scheduled('ijt_daily_cron')) {
            wp_schedule_event(time(), 'daily', 'ijt_daily_cron');
        }
        
        if (!wp_next_scheduled('ijt_translation_cron')) {
            wp_schedule_event(time(), 'every_two_minutes', 'ijt_translation_cron');
        }
        
        $this->log('Plugin activated');
    }

    private function create_directory() {
        if (!file_exists($this->upload_dir) && !wp_mkdir_p($this->upload_dir)) {
            error_log('Icelandic Jobs Translator: Failed to create directory: ' . $this->upload_dir);
            return false;
        }
        return true;
    }

    public function deactivate() {
        wp_clear_scheduled_hook('ijt_daily_cron');
        wp_clear_scheduled_hook('ijt_translation_cron');
        delete_transient('ijt_translation_queue');
        $this->log('Plugin deactivated');
    }

    public function add_cron_schedules($schedules) {
        $schedules['every_two_minutes'] = [
            'interval' => 120,
            'display'  => __('Every Two Minutes')
        ];
        return $schedules;
    }

    public function admin_menu() {
        add_menu_page(
            'Icelandic Medical Jobs',
            'Medical Jobs',
            'manage_options',
            'icelandic-jobs',
            [$this, 'main_page'],
            'dashicons-translation'
        );
        
        add_submenu_page(
            'icelandic-jobs',
            'Settings',
            'Settings',
            'manage_options',
            'ijt-settings',
            [$this, 'settings_page']
        );
        
        add_submenu_page(
            'icelandic-jobs',
            'Error Log',
            'Error Log',
            'manage_options',
            'ijt-log',
            [$this, 'log_page']
        );
    }

    public function main_page() {
        $last_processed = get_option('ijt_last_processed', 0);
        $queue = get_transient('ijt_translation_queue');
        $queue_count = is_array($queue) ? count($queue) : 0;
        
        echo '<div class="wrap"><h1>Icelandic Medical Jobs Translator</h1>';
        echo '<div class="card"><h2>Current Status</h2>';
        echo '<p><strong>Translated file:</strong> <a href="' . esc_url($this->get_translated_file_url()) . '" target="_blank">' . esc_url($this->get_translated_file_url()) . '</a></p>';
        echo '<p><strong>Last processed:</strong> ' . ($last_processed ? date('Y-m-d H:i:s', $last_processed) : 'Never') . '</p>';
        echo '<p><strong>Jobs in queue:</strong> ' . $queue_count . '</p>';
        
        // Manual processing buttons
        echo '<form method="post" style="margin-top:20px;">';
        echo '<input type="submit" name="process_feed" value="Process Source Feed Now" class="button button-primary"> ';
        //echo '<input type="submit" name="process_queue" value="Process Translation Queue Now" class="button">';
        wp_nonce_field('ijt_manual_processing');
        echo '</form>';
        
        if (isset($_POST['process_feed'])) {
            if (check_admin_referer('ijt_manual_processing')) {
                $this->log('Manual source feed processing triggered');
                $this->process_source_feed();
                echo '<div class="updated"><p>Source feed processed successfully! ' . $queue_count . ' jobs added to queue.</p></div>';
            }
        }
        
        if (isset($_POST['process_queue'])) {
            if (check_admin_referer('ijt_manual_processing')) {
                $this->log('Manual queue processing triggered');
                $this->process_translation_queue();
                echo '<div class="updated"><p>Translation queue processed successfully!</p></div>';
            }
        }
        
        echo '</div></div>';
    }

    public function settings_page() {
        ?>
        <div class="wrap">
            <h1>Medical Jobs Settings</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('ijt_settings_group');
                do_settings_sections('ijt-settings');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    public function register_settings() {
        register_setting('ijt_settings_group', 'ijt_settings', [$this, 'validate_settings']);
        
        add_settings_section(
            'ijt_main_section',
            'Translation Settings',
            null,
            'ijt-settings'
        );
        
        add_settings_field(
            'allowed_cities',
            'Allowed Locations',
            [$this, 'text_field_callback'],
            'ijt-settings',
            'ijt_main_section',
            [
                'name' => 'allowed_cities',
                'description' => 'Enter city names separated by commas (e.g., Akureyri, Reykjavík)'
            ]
        );
        
        add_settings_field(
            'api_key',
            'OpenAI API Key',
            [$this, 'api_key_callback'],
            'ijt-settings',
            'ijt_main_section'
        );
        
        add_settings_field(
            'source_url',
            'Source XML URL',
            [$this, 'text_field_callback'],
            'ijt-settings',
            'ijt_main_section',
            [
                'name' => 'source_url',
                'description' => 'URL of the Icelandic jobs XML feed'
            ]
        );
    }
    
    public function validate_settings($input) {
        $valid = [];
        $valid['allowed_cities'] = sanitize_text_field($input['allowed_cities'] ?? 'Akureyri');
        $valid['api_key'] = sanitize_text_field($input['api_key'] ?? '');
        $valid['source_url'] = esc_url_raw($input['source_url'] ?? 'https://nytlaegejob.pythonanywhere.com/starfatorg.xml');
        
        return $valid;
    }

    public function text_field_callback($args) {
        $name = $args['name'];
        $value = $this->settings[$name] ?? '';
        $description = $args['description'] ?? '';
        
        echo "<input type='text' name='ijt_settings[$name]' value='" . esc_attr($value) . "' class='regular-text'>";
        if ($description) {
            echo "<p class='description'>$description</p>";
        }
    }

    public function api_key_callback() {
        $value = $this->settings['api_key'] ?? '';
        echo "<input type='password' name='ijt_settings[api_key]' value='" . esc_attr($value) . "' class='regular-text'>";
        echo '<p class="description">Required for translation functionality (GPT-4)</p>';
    }

    public function log_page() {
        echo '<div class="wrap"><h1>Translation Log</h1>';
        
        // Clear log button
        echo '<form method="post" style="margin-bottom:20px;">';
        echo '<input type="submit" name="clear_log" value="Clear Log" class="button">';
        wp_nonce_field('ijt_clear_log');
        echo '</form>';
        
        if (isset($_POST['clear_log'])) {
            if (check_admin_referer('ijt_clear_log')) {
                if (file_exists($this->log_file)) {
                    file_put_contents($this->log_file, '');
                    echo '<div class="updated"><p>Log cleared successfully!</p></div>';
                    $this->log('Log cleared manually');
                }
            }
        }
        
        if (file_exists($this->log_file)) {
            $log_content = file_get_contents($this->log_file);
            if (!empty($log_content)) {
                echo '<pre style="background:#fff;padding:20px;border:1px solid #ccc;max-height:500px;overflow:auto;">' . esc_html($log_content) . '</pre>';
            } else {
                echo '<p>No log entries found.</p>';
            }
        } else {
            echo '<p>Log file not created yet.</p>';
        }
        echo '</div>';
    }

    public function process_source_feed() {
        $this->log('===== STARTING DAILY PROCESSING =====');
        $this->log('Fetching source feed from: ' . $this->settings['source_url']);
        
        $source = wp_remote_get($this->settings['source_url'], ['timeout' => 30]);
        
        if (is_wp_error($source)) {
            $this->log('Error fetching source: ' . $source->get_error_message());
            return;
        }
        
        $response_code = wp_remote_retrieve_response_code($source);
        if ($response_code !== 200) {
            $this->log('Invalid response code: ' . $response_code);
            return;
        }
        
        $xml_content = wp_remote_retrieve_body($source);
        $xml = simplexml_load_string($xml_content);
        
        if (!$xml) {
            $this->log('Error parsing XML');
            return;
        }
        
        $this->log('Successfully parsed XML');
		$translated_ids = $this->get_translated_job_ids();
		//$this->log('Already translated job IDs: ' . count($translated_ids) . " == ". print_r($translated_ids, true));
		
        $allowed_cities = array_map('trim', explode(',', $this->settings['allowed_cities']));
        $queue = get_transient('ijt_translation_queue') ?: [];
        $queued_ids = array_column($queue, 'job_id');
		
		$jobs_processed = 0;
		$jobs_added = 0;
		$jobs_translated = 0;
        
        if (isset($xml->job)) {
            $this->log('Processing ' . count($xml->job) . ' jobs');
            foreach ($xml->job as $job) {
                $jobs_processed++;
                $city = (string)$job->company;
                $job_id = (string)$job->job_id;
                
                if ($this->is_city_allowed($city, $allowed_cities)) {
					
					if (in_array($job_id, $translated_ids)) {
						$jobs_translated++;
						$this->log("Skipping already translated job ID: $job_id");
						continue;
					}					
					
                    if (!in_array($job_id, $queued_ids)) {
                        $job_data = [];
                        foreach ($job->children() as $child) {
                            $job_data[$child->getName()] = (string)$child;
                        }
                        $queue[] = $job_data;
                        $jobs_added++;
                    } else {
                        $this->log("Skipping already queued job ID: $job_id");
                    }
                }
            }
        } else {
            $this->log('No <job> elements found in XML');
        }
        
        if ($jobs_added > 0) {
            set_transient('ijt_translation_queue', $queue, 12 * HOUR_IN_SECONDS);
            $this->log("Added $jobs_added new jobs to translation queue");
        } else {
            $this->log('No new jobs found matching criteria');
        }
        
        update_option('ijt_last_processed', time());
		$this->log("Already translated jobs skipped: $jobs_translated");
		$this->log("Total jobs processed: $jobs_processed");
		// cleanup translated xml file.
		$this->log('Running expired jobs cleanup...');
		$this->delete_expired_jobs();		
        $this->log('===== DAILY PROCESSING COMPLETE =====');
    }

    private function is_city_allowed($city, $allowed_cities) {
        foreach ($allowed_cities as $allowed) {
            if (stripos($city, $allowed) !== false) {
                return true;
            }
        }
        return false;
    }

    public function process_translation_queue() {
        $queue = get_transient('ijt_translation_queue');
        if (empty($queue)) {
            return;
        }
        
        $this->log('Processing translation queue (' . count($queue) . ' jobs remaining)');
        $job = array_shift($queue);
        $job_id = $job['job_id'] ?? 'unknown';
        
        $this->log("Translating job ID: $job_id");
        
        $translated = $this->translate_job($job);
        if ($translated) {
            $this->update_xml_job($translated);
            $this->log("Successfully translated job ID: $job_id");
        } else {
            array_unshift($queue, $job);
            $this->log("Translation failed for job ID: $job_id");
        }
        
        if (empty($queue)) {
            delete_transient('ijt_translation_queue');
            $this->log('Translation queue completed');
        } else {
            set_transient('ijt_translation_queue', $queue, 12 * HOUR_IN_SECONDS);
        }
    }

    private function translate_job($job) {
        $api_key = $this->settings['api_key'] ?? '';
        if (empty($api_key)) {
            $this->log('OpenAI API key missing');
            return false;
        }
        
        // Prepare job data for translation
		$translation_data = [];
		foreach ($job as $field => $value) {
			if (!in_array($field, $this->fields_not_to_translate) && !empty($value)) {
				// Preserve HTML tags as requested
				$translation_data[$field] = substr($value, 0, 3000);
			}
		}
        
        if (empty($translation_data)) {
            $this->log('No translatable fields found');
            return false;
        }
        
        // Create the translation prompt
        $prompt = "Translate the following Icelandic job fields to English. Preserve all HTML tags exactly as provided. ";
        $prompt .= "Additionally, determine if this is a doctor position based on **qualificationRequirements** field in job data and set is_doctor to 'yes', otherwise 'no':\n";
        //$prompt .= "1. Check if the title contains words related to 'doctor' or 'physician'\n";
        //$prompt .= "2. qualificationRequirements field if asking for Doctor then set is_doctor to 'yes', Otherwise 'no'. \n";
        //$prompt .= "3. If either condition is met, set is_doctor to 'yes', otherwise 'no'\n\n";
        $prompt .= "Job data to translate:\n";
        
        foreach ($translation_data as $field => $value) {
            $prompt .= "**$field**:\n$value\n\n";
        }
        
        $prompt .= "Respond ONLY with JSON in this format:\n";
        $prompt .= '{"translated": {"field1": "translation", "field2": "translation"}, "is_doctor": "yes|no"}';
        
        $this->log("Sending to OpenAI: " . substr($prompt, 0, 300) . "...");
        
        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $api_key
            ],
            'body' => json_encode([
                'model' => 'gpt-4-turbo',
                'messages' => [['role' => 'user', 'content' => $prompt]],
                'temperature' => 0.1,
                'max_tokens' => 4096,
                'response_format' => ['type' => 'json_object']
            ]),
            'timeout' => 90
        ]);
        
        if (is_wp_error($response)) {
            $this->log('API error: ' . $response->get_error_message());
            return false;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($status_code !== 200) {
            $this->log("API returned error: $status_code - " . print_r($body, true));
            return false;
        }
        
        $content = $body['choices'][0]['message']['content'] ?? '';
        
        if (empty($content)) {
            $this->log('Empty API response');
            return false;
        }
        
        $this->log("OpenAI response: " . substr($content, 0, 300) . "...");
        
        $result = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE || !isset($result['translated'])) {
            $this->log('Invalid JSON response: ' . $content);
            return false;
        }
        
        // Merge translations back into job data
        foreach ($result['translated'] as $field => $translation) {
            $job[$field] = $translation;
        }
        
        // Add doctor classification
        $job['is_doctor'] = strtolower($result['is_doctor']) === 'yes' ? 'yes' : 'no';
        
        return $job;
    }

    private function update_xml_job($job) {
        $job_id = $job['job_id'] ?? null;
        if (!$job_id) {
            $this->log('Job ID missing, cannot update XML');
            return;
        }
        
        // Ensure directory exists
        $this->create_directory();
        
        $file_path = $this->translated_file;
        
        if (!file_exists($file_path)) {
            $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><jobs></jobs>');
            $xml->asXML($file_path);
        }
        
        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;
        
        if ($dom->load($file_path)) {
            $xpath = new DOMXPath($dom);
            $nodes = $xpath->query("/jobs/job[id='$job_id']");
            
            // Remove existing job if found
            if ($nodes->length > 0) {
                $old_node = $nodes->item(0);
                $old_node->parentNode->removeChild($old_node);
                $this->log("Updated existing job in XML: $job_id");
            } else {
                $this->log("Added new job to XML: $job_id");
            }
        } else {
            $dom->loadXML('<?xml version="1.0" encoding="UTF-8"?><jobs></jobs>');
        }
        
        // Create new job node
        $job_node = $dom->createElement('job');
        
        // Add all job fields to XML
        foreach ($job as $key => $value) {
            // Skip empty values
            if (empty($value)) continue;
            
            $element = $dom->createElement($key);
            
            // Preserve HTML structure for specific fields
            if (in_array($key, ['description', 'intro', 'qualificationRequirements', 'tasksAndResponsibilities', 'salaryTerms', 'locations', 'contacts'])) {
                $cdata = $dom->createCDATASection($value);
                $element->appendChild($cdata);
            } else {
                $text = $dom->createTextNode($value);
                $element->appendChild($text);
            }
            
            $job_node->appendChild($element);
        }
        
        // Append to root and save
        $dom->documentElement->appendChild($job_node);
        $dom->save($file_path);
    }

	private function get_translated_job_ids() {
		$file_path = $this->translated_file;
		$job_ids = [];

		if (!file_exists($file_path)) {
			$this->log("Translated XML file not found at $file_path");
			return $job_ids;
		}

		$dom = new DOMDocument();
		if (!$dom->load($file_path)) {
			$this->log("Failed to load XML file: $file_path");
			return $job_ids;
		}

		$xpath = new DOMXPath($dom);
		$nodes = $xpath->query("/jobs/job/job_id");

		foreach ($nodes as $node) {
			$job_ids[] = trim($node->nodeValue);
		}

		return $job_ids;
	}

	private function delete_expired_jobs() {
		$file_path = $this->translated_file;
		
		if (!file_exists($file_path)) {
			$this->log("No translated XML file found to check for expired jobs");
			return;
		}

		$dom = new DOMDocument();
		if (!$dom->load($file_path)) {
			$this->log("Failed to load XML file for expiration check: $file_path");
			return;
		}

		$xpath = new DOMXPath($dom);
		$jobs = $xpath->query("/jobs/job");
		$current_date = new DateTime();
		$deleted_count = 0;

		foreach ($jobs as $job) {
			$deadline_node = $xpath->query("application_deadline_to", $job)->item(0);
			if (!$deadline_node) {
				continue;
			}

			$deadline_str = trim($deadline_node->nodeValue);
			if (empty($deadline_str)) {
				continue;
			}

			try {
				// Parse Icelandic date format (DD.MM.YYYY)
				$deadline_date = DateTime::createFromFormat('d.m.Y', $deadline_str);
				if (!$deadline_date) {
					$this->log("Invalid date format for job: " . $xpath->query("job_id", $job)->item(0)->nodeValue);
					continue;
				}

				if ($current_date > $deadline_date) {
					$job_id = $xpath->query("job_id", $job)->item(0)->nodeValue;
					$job->parentNode->removeChild($job);
					$deleted_count++;
					$this->log("Removed expired job ID: $job_id (deadline: $deadline_str)");
				}
			} catch (Exception $e) {
				$this->log("Error processing date for job: " . $e->getMessage());
				continue;
			}
		}

		if ($deleted_count > 0) {
			$dom->save($file_path);
			$this->log("Removed $deleted_count expired jobs from XML");
		} else {
			$this->log("No expired jobs found to remove");
		}
	}

    private function get_translated_file_url() {
        $upload_dir = wp_upload_dir();
        return trailingslashit($upload_dir['baseurl']) . 'icelandic-jobs/translated_jobs.xml';
    }

    private function log($message) {
        // Ensure directory exists
        $this->create_directory();
        
        if (!file_exists($this->upload_dir)) {
            error_log('Icelandic Jobs Translator: ' . $message);
            return;
        }
        
        $entry = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
        file_put_contents($this->log_file, $entry, FILE_APPEND);
    }
}

// Initialize the plugin
if ( class_exists( 'Icelandic_Jobs_Translator_Pro' ) ) {
    //new Icelandic_Jobs_Translator_Pro();
    $ijt_instance = new Icelandic_Jobs_Translator_Pro();
    register_activation_hook(__FILE__, [$ijt_instance, 'activate']);
    register_deactivation_hook(__FILE__, [$ijt_instance, 'deactivate']);	
}
