<?php
/*
Plugin Name: PDF Zip Downloader for Gravity Forms
Description: Download a Gravity Forms entry as a PDF with its attachments in a ZIP file.
Version: 1.0.0
Author: Martín Arufe
Text Domain: pdf-zip-downloader-for-gravity-forms
Domain Path: /languages
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
*/

namespace Martin695\PDFZipDownloader;


if (!defined('ABSPATH')) exit; 
define('PDFZDFGF_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('PDFZDFGF_PLUGIN_URL', plugin_dir_url(__FILE__));

// Add custom settings to the form
add_filter('gform_form_settings', __NAMESPACE__ . '\\pdfzdfgf_add_custom_settings_to_form', 10, 2);
function pdfzdfgf_add_custom_settings_to_form($settings, $form) {
    if (empty($form['gfpdf_form_settings'])) {
        return $settings;
    }

    // PDF options
    $pdf_options = '<option value="">' . esc_html__('Select a PDF', 'pdf-zip-downloader-for-gravity-forms') . '</option>';
    foreach ($form['gfpdf_form_settings'] as $pdf) {
        $selected = ($form['custom_pdf_id'] ?? '') === $pdf['id'] ? 'selected' : '';
        $pdf_options .= "<option value='{$pdf['id']}' {$selected}>{$pdf['name']}</option>";
    }

    $settings['Form Options']['custom_pdf_id'] = "
        <tr>
            <th>" . esc_html__('PDF for ZIP', 'pdf-zip-downloader-for-gravity-forms') . "</th>
            <td>
                <select name='custom_pdf_id'>
                    {$pdf_options}
                </select>
                <p class='description'>" . esc_html__('Select which PDF to include in the ZIP.', 'pdf-zip-downloader-for-gravity-forms') . "</p>
            </td>
        </tr>";

    // Options for ZIP file name fields
    $field_options = array_map(function($field) use ($form) {
        $checked = in_array($field->id, $form['zip_name_fields'] ?? []) ? 'checked' : '';
        return "<label><input type='checkbox' name='zip_name_fields[]' value='{$field->id}' {$checked}> {$field->label}</label><br>";
    }, $form['fields']);

    $settings['Form Options']['zip_name_fields'] = "
        <tr>
            <th>" . esc_html__('Fields for ZIP Name', 'pdf-zip-downloader-for-gravity-forms') . "</th>
            <td>
                " . implode('', $field_options) . "
                <p class='description'>" . esc_html__('Select the fields that will compose the ZIP file name.', 'pdf-zip-downloader-for-gravity-forms') . "</p>
            </td>
        </tr>";

    return $settings;
}

// Save custom settings
add_filter('gform_pre_form_settings_save', __NAMESPACE__ . '\\pdfzdfgf_save_custom_form_settings');
function pdfzdfgf_save_custom_form_settings($form) {
    $form['custom_pdf_id'] = rgpost('custom_pdf_id');
    $form['zip_name_fields'] = rgpost('zip_name_fields') ?: [];
    return $form;
}

// Add download column to entry list
add_filter('gform_entry_list_columns', __NAMESPACE__ . '\\pdfzdfgf_add_download_column', 10, 2);
function pdfzdfgf_add_download_column($columns, $form_id) {
    $form = \GFAPI::get_form($form_id);
    if (!empty($form['gfpdf_form_settings'])) {
        $columns['download_zip'] = esc_html__('Download ZIP', 'pdf-zip-downloader-for-gravity-forms');
    }
    return $columns;
}

// Generate download column content
add_filter('gform_entries_field_value', __NAMESPACE__ . '\\pdfzdfgf_render_download_link', 10, 4);
function pdfzdfgf_render_download_link($value, $form_id, $field_id, $entry) {
    if ($field_id === 'download_zip') {
        $form = \GFAPI::get_form($form_id);
        if (!empty($form['gfpdf_form_settings'])) {
            $url = wp_nonce_url(
                admin_url("admin-post.php?action=pdfzdfgf_download_zip&entry_id={$entry['id']}&form_id={$form_id}"),
                'pdfzdfgf_download_zip'
            );
            return "<a href='" . esc_url($url) . "'>" . esc_html__('Download ZIP', 'pdf-zip-downloader-for-gravity-forms') . "</a>";
        }
    }
    return $value;
}

// Action to generate and download the ZIP
add_action('admin_post_pdfzdfgf_download_zip', __NAMESPACE__ . '\\pdfzdfgf_generate_entry_zip');
function pdfzdfgf_generate_entry_zip() {
    if (!isset($_GET['entry_id'], $_GET['form_id'], $_GET['_wpnonce'])) {
        wp_die(esc_html__('Invalid request.', 'pdf-zip-downloader-for-gravity-forms'));
    }

    $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
    if (!wp_verify_nonce($nonce, 'pdfzdfgf_download_zip')) {
        wp_die(esc_html__('Invalid nonce.', 'pdf-zip-downloader-for-gravity-forms'));
    }

    $entry_id = isset($_GET['entry_id']) ? intval($_GET['entry_id']) : 0;
    $form_id = isset($_GET['form_id']) ? intval($_GET['form_id']) : 0;

    if ($entry_id <= 0 || $form_id <= 0) {
        wp_die(esc_html__('Invalid entry or form ID.', 'pdf-zip-downloader-for-gravity-forms'));
    }

    $entry = \GFAPI::get_entry($entry_id);
    if (is_wp_error($entry)) wp_die(esc_html__('Entry not found.', 'pdf-zip-downloader-for-gravity-forms'));

    $form = \GFAPI::get_form($form_id);
    if (is_wp_error($form)) wp_die(esc_html__('Form not found.', 'pdf-zip-downloader-for-gravity-forms'));

    $temp_dir = pdfzdfgf_create_temp_dir($entry_id);
    if (!$temp_dir) {
        wp_die(esc_html__('Temporary directory creation failed.', 'pdf-zip-downloader-for-gravity-forms'));
    }

    $pdf_file = "$temp_dir/form-{$form['title']}-{$entry_id}.pdf";
    pdfzdfgf_generate_pdf($entry, $form, $pdf_file);

    pdfzdfgf_download_attachments($form, $entry, $temp_dir);

    $zip_file = pdfzdfgf_create_zip($form, $entry, $temp_dir);

    pdfzdfgf_send_zip_to_user($zip_file, $temp_dir);
}

// Create temporary directory
function pdfzdfgf_create_temp_dir($entry_id) {
    global $wp_filesystem;

    if (!function_exists('WP_Filesystem')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }

    if (!WP_Filesystem()) {
        return false;
    }

    $uploads = wp_upload_dir();
    $temp_base = trailingslashit($uploads['basedir']) . 'pdfzdfgf-tmp';
    $temp_dir = trailingslashit($temp_base) . "gf-entry-{$entry_id}";

    // Create base directory if not exits already
    if (!$wp_filesystem->is_dir($temp_base)) {
        if (!$wp_filesystem->mkdir($temp_base, FS_CHMOD_DIR)) {
            return false;
        }
    }

    // Create temporal directory
    if (!$wp_filesystem->is_dir($temp_dir)) {
        if (!$wp_filesystem->mkdir($temp_dir, FS_CHMOD_DIR)) {
            return false;
        }
    }

    return $temp_dir;
}

// Generate the PDF for the entry
function pdfzdfgf_generate_pdf($entry, $form, $output_file) {
    if (!class_exists('GPDFAPI')) {
        wp_die(esc_html__('Gravity PDF is not installed or activated.', 'pdf-zip-downloader-for-gravity-forms'));
    }

    $pdf_id = $form['custom_pdf_id'] ?? false;
    if (!$pdf_id) wp_die(esc_html__('No PDF is configured for this form.', 'pdf-zip-downloader-for-gravity-forms'));

    $pdf_path = \GPDFAPI::create_pdf($entry['id'], $pdf_id);
    if (is_wp_error($pdf_path)) {
        wp_delete_file($pdf_path);
        wp_die(esc_html__('Error generating PDF: ', 'pdf-zip-downloader-for-gravity-forms') . esc_html($pdf_path->get_error_message()));
    }

    global $wp_filesystem;

    if (!function_exists('WP_Filesystem')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }

    if (WP_Filesystem()) {
        $content = $wp_filesystem->get_contents($pdf_path);
        if ($content === false) {
            wp_die(esc_html__('Error reading the PDF file.', 'pdf-zip-downloader-for-gravity-forms'));
        }

        if (!$wp_filesystem->put_contents($output_file, $content, FS_CHMOD_FILE)) {
            wp_die(esc_html__('Error writing the PDF file.', 'pdf-zip-downloader-for-gravity-forms'));
        }

        wp_delete_file($pdf_path);
    }
}

// Download attachments
function pdfzdfgf_download_attachments($form, $entry, $temp_dir) {
    $uploads = wp_upload_dir();
    
    foreach ($form['fields'] as $field) {
        if ($field->type === 'fileupload') {
            $file_urls = is_array($entry[$field->id]) ? $entry[$field->id] : json_decode($entry[$field->id], true);

            foreach ((array) $file_urls as $file_url) {
                // Convert URL to path using wp_upload_dir
                $file_path = str_replace(
                    $uploads['baseurl'],
                    $uploads['basedir'],
                    $file_url
                );
                
                if (file_exists($file_path)) {
                    copy($file_path, $temp_dir . '/' . basename($file_url));
                }
            }
        }
    }
}

// Create the ZIP file
function pdfzdfgf_create_zip($form, $entry, $temp_dir) {
    $zip_name_parts = array_map(function($field_id) use ($entry) {
        return $entry[$field_id] ?? '';
    }, $form['zip_name_fields'] ?? []);

    $zip_name = substr(implode('-', $zip_name_parts) ?: "entry-{$entry['id']}", 0, 246) . '.zip';
    
    $uploads = wp_upload_dir();
    $zip_file = trailingslashit($uploads['basedir']) . 'pdfzdfgf-tmp/' . $zip_name;

    $zip = new \ZipArchive();
    if ($zip->open($zip_file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === TRUE) {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($temp_dir), \RecursiveIteratorIterator::LEAVES_ONLY);
        foreach ($files as $file) {
            if (!$file->isDir()) {
                $zip->addFile($file->getRealPath(), substr($file->getRealPath(), strlen($temp_dir) + 1));
            }
        }
        $zip->close();
    } else {
        wp_die(esc_html__('Error creating ZIP file.', 'pdf-zip-downloader-for-gravity-forms'));
    }

    return $zip_file;
}

// Send the ZIP file to the user
function pdfzdfgf_send_zip_to_user($zip_file, $temp_dir) {
    global $wp_filesystem;

    if (!function_exists('WP_Filesystem')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }

    if (!WP_Filesystem()) {
        wp_die(esc_html__('Error initializing WP_Filesystem.', 'pdf-zip-downloader-for-gravity-forms'));
    }

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . basename($zip_file) . '"');
    header('Content-Length: ' . filesize($zip_file));

    // Use WP_Filesystem to read and send the file
    $content = $wp_filesystem->get_contents($zip_file);
    if ($content === false) {
        wp_die(esc_html__('Error reading the ZIP file.', 'pdf-zip-downloader-for-gravity-forms'));
    }

    echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

    // Delete files and temporary directory using WP_Filesystem
    $files = $wp_filesystem->dirlist($temp_dir);
    foreach ($files as $file) {
        $wp_filesystem->delete($temp_dir . '/' . $file['name']);
    }
    $wp_filesystem->rmdir($temp_dir);
    $wp_filesystem->delete($zip_file);

    exit;
}