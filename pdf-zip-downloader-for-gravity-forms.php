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

if (!defined('ABSPATH')) exit; // Prevent direct access

// Add custom settings to the form
add_filter('gform_form_settings', 'gez_add_custom_settings_to_form', 10, 2);
function gez_add_custom_settings_to_form($settings, $form) {
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
add_filter('gform_pre_form_settings_save', 'gez_save_custom_form_settings');
function gez_save_custom_form_settings($form) {
    $form['custom_pdf_id'] = rgpost('custom_pdf_id');
    $form['zip_name_fields'] = rgpost('zip_name_fields') ?: [];
    return $form;
}

// Add download column to entry list
add_filter('gform_entry_list_columns', 'gez_add_download_column', 10, 2);
function gez_add_download_column($columns, $form_id) {
    $form = GFAPI::get_form($form_id);
    if (!empty($form['gfpdf_form_settings'])) {
        $columns['download_zip'] = esc_html__('Download ZIP', 'pdf-zip-downloader-for-gravity-forms');
    }
    return $columns;
}

// Generate download column content
add_filter('gform_entries_field_value', 'gez_render_download_link', 10, 4);
function gez_render_download_link($value, $form_id, $field_id, $entry) {
    if ($field_id === 'download_zip') {
        $form = GFAPI::get_form($form_id);
        if (!empty($form['gfpdf_form_settings'])) {
            $url = wp_nonce_url(
                admin_url("admin-post.php?action=gez_download_zip&entry_id={$entry['id']}&form_id={$form_id}"),
                'gez_download_zip'
            );
            return "<a href='" . esc_url($url) . "'>" . esc_html__('Download ZIP', 'pdf-zip-downloader-for-gravity-forms') . "</a>";
        }
    }
    return $value;
}

// Action to generate and download the ZIP
add_action('admin_post_gez_download_zip', 'gez_generate_entry_zip');
function gez_generate_entry_zip() {
    if (!isset($_GET['entry_id'], $_GET['form_id'], $_GET['_wpnonce'])) {
        wp_die(esc_html__('Invalid request.', 'pdf-zip-downloader-for-gravity-forms'));
    }

    $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
    if (!wp_verify_nonce($nonce, 'gez_download_zip')) {
        wp_die(esc_html__('Invalid nonce.', 'pdf-zip-downloader-for-gravity-forms'));
    }

    $entry_id = isset($_GET['entry_id']) ? intval($_GET['entry_id']) : 0;
    $form_id = isset($_GET['form_id']) ? intval($_GET['form_id']) : 0;

    if ($entry_id <= 0 || $form_id <= 0) {
        wp_die(esc_html__('Invalid entry or form ID.', 'pdf-zip-downloader-for-gravity-forms'));
    }

    $entry = GFAPI::get_entry($entry_id);
    if (is_wp_error($entry)) wp_die(esc_html__('Entry not found.', 'pdf-zip-downloader-for-gravity-forms'));

    $form = GFAPI::get_form($form_id);
    if (is_wp_error($form)) wp_die(esc_html__('Form not found.', 'pdf-zip-downloader-for-gravity-forms'));

    $temp_dir = gez_create_temp_dir($entry_id);
    if (!$temp_dir) {
        wp_die(esc_html__('Temporary directory creation failed.', 'pdf-zip-downloader-for-gravity-forms'));
    }

    $pdf_file = "$temp_dir/form-{$form['title']}-{$entry_id}.pdf";
    gez_generate_pdf($entry, $form, $pdf_file);

    gez_download_attachments($form, $entry, $temp_dir);

    $zip_file = gez_create_zip($form, $entry, $temp_dir);

    gez_send_zip_to_user($zip_file, $temp_dir);
}

// Create temporary directory
function gez_create_temp_dir($entry_id) {
    global $wp_filesystem;

    if (!function_exists('WP_Filesystem')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }

    if (!WP_Filesystem()) {
        return false;
    }

    $temp_dir = trailingslashit(sys_get_temp_dir()) . "gf-entry-{$entry_id}";

    if (!$wp_filesystem->is_dir($temp_dir)) {
        if (!$wp_filesystem->mkdir($temp_dir, FS_CHMOD_DIR)) {
            return false;
        }
    }

    return $temp_dir;
}

// Generate the PDF for the entry
function gez_generate_pdf($entry, $form, $output_file) {
    if (!class_exists('GPDFAPI')) {
        wp_die(esc_html__('Gravity PDF is not installed or activated.', 'pdf-zip-downloader-for-gravity-forms'));
    }

    $pdf_id = $form['custom_pdf_id'] ?? false;
    if (!$pdf_id) wp_die(esc_html__('No PDF is configured for this form.', 'pdf-zip-downloader-for-gravity-forms'));

    $pdf_path = GPDFAPI::create_pdf($entry['id'], $pdf_id);
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
function gez_download_attachments($form, $entry, $temp_dir) {
    foreach ($form['fields'] as $field) {
        if ($field->type === 'fileupload') {
            $file_urls = is_array($entry[$field->id]) ? $entry[$field->id] : json_decode($entry[$field->id], true);

            foreach ((array) $file_urls as $file_url) {
                $file_path = str_replace(WP_CONTENT_URL, WP_CONTENT_DIR, $file_url);
                if (file_exists($file_path)) {
                    copy($file_path, $temp_dir . '/' . basename($file_url));
                }
            }
        }
    }
}

// Create the ZIP file
function gez_create_zip($form, $entry, $temp_dir) {
    $zip_name_parts = array_map(function($field_id) use ($entry) {
        return $entry[$field_id] ?? '';
    }, $form['zip_name_fields'] ?? []);

    $zip_name = substr(implode('-', $zip_name_parts) ?: "entry-{$entry['id']}", 0, 246) . '.zip';
    $zip_file = sys_get_temp_dir() . "/{$zip_name}";

    $zip = new ZipArchive();
    if ($zip->open($zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temp_dir), RecursiveIteratorIterator::LEAVES_ONLY);
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
function gez_send_zip_to_user($zip_file, $temp_dir) {
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