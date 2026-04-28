<?php
/**
 * lang/en.php — English strings
 */
return [
    // ── Login ────────────────────────────────────────────────
    'page_title'           => 'Sign in — Local App',
    'sign_in'              => 'Sign in',
    'sign_in_subtitle'     => 'Enter your username and password to continue',
    'username_label'       => 'Username',
    'username_placeholder' => 'username or email',
    'password_label'       => 'Password',
    'show_password'        => 'Show password',
    'hide_password'        => 'Hide password',
    'btn_sign_in'          => 'Sign in',
    'forgot_password'      => 'Forgot password?',
    'language_label'       => 'Language',

    // ── Login errors ─────────────────────────────────────────
    'error_empty'          => 'Please enter your username and password.',
    'error_invalid'        => 'Incorrect username or password. Try again.',
    'error_inactive'       => 'Your account is inactive. Contact the administrator.',
    'error_locked'         => 'Account locked due to failed attempts. Try again in %s minute(s).',
    'error_timeout'        => 'Your session was closed due to inactivity (30 minutes). Please sign in again.',
    'error_deactivated'    => 'Your account has been deactivated. Contact the administrator.',

    // ── Forgot password ───────────────────────────────────────
    'forgot_page_title'    => 'Request password — Local App',
    'forgot_title'         => 'Request password',
    'forgot_subtitle'      => 'Fill out the form and the system administrator will send you your password.',
    'company_label'        => 'Company name',
    'company_placeholder'  => 'My Company Inc.',
    'email_label'          => 'Email address',
    'email_placeholder'    => 'email@company.com',
    'opt_user_label'       => 'Username (if you remember it)',
    'opt_user_placeholder' => 'username',
    'notes_label'          => 'Additional information (optional)',
    'btn_request'          => 'Request password',
    'btn_back'             => 'Back',
    'forgot_success'       => 'Your request has been sent to the administrator. You will receive a response shortly.',
    'forgot_error_empty'   => 'Company name and email address are required.',
    'forgot_error_email'   => 'Please enter a valid email address.',

    // ── Shared dashboard ─────────────────────────────────────
    'sign_out'             => 'Sign out',
    'session_active'       => 'Active session',
    'signed_in_at'         => 'Signed in',
    'user_id_label'        => 'User ID',
    'role_label'           => 'Role',
    'role_admin'           => 'Administrator',
    'role_supplier'        => 'Supplier',
    'role_owner'           => 'Owner',
    'role_user'            => 'User',
    'welcome'              => 'Welcome',

    // ── Owner panel ───────────────────────────────────────────
    'owner_page_title'     => 'Owner Panel — Local App',
    'owner_title'          => 'Business Administration',
    'btn_set_role'         => 'Set role',
    'feedback_activated'   => 'User activated.',
    'feedback_deactivated' => 'User deactivated.',
    'feedback_unlocked'    => 'Account unlocked.',
    'feedback_role_changed'   => 'Role updated.',
    'feedback_request_resolved' => 'Request marked as resolved.',

    // ── User dashboard ────────────────────────────────────────
    'user_page_title'     => 'User Dashboard — Local App',
    'user_title'          => 'My account',
    'user_welcome'        => 'Welcome, %s',
    'user_session_info'   => 'You have signed in successfully.',
    'user_idle_notice'    => 'Your session will close automatically after %d minutes of inactivity.',

    // ── Tab navigation ────────────────────────────────────────
    'tab_nav_label'  => 'Sections',
    'tab_coming_soon'=> 'Coming soon',
    // Supplier tabs
    'tab_profile'    => 'My Profile',
    'tab_summary'    => 'Summary',
    'tab_documents'  => 'Documents',
    'tab_orders'     => 'Orders',
    // Admin / Owner tabs
    'tab_users'      => 'Users',
    'tab_reports'    => 'Reports',
    'tab_settings'   => 'Settings',
    // User tabs
    'tab_dashboard'  => 'My account',
    'tab_history'    => 'History',

    // ── Admin ─────────────────────────────────────────────────
    'admin_page_title'     => 'Administration — Local App',
    'admin_title'          => 'System Administration',
    'admin_subtitle'       => 'Main administration panel.',
    'user_management'      => 'User management',
    'col_id'               => 'ID',
    'col_username'         => 'Username',
    'col_email'            => 'Email',
    'col_role'             => 'Role',
    'col_status'           => 'Status',
    'col_first_login'      => 'First login',
    'col_attempts'         => 'Failed attempts',
    'col_locked_until'     => 'Locked until',
    'col_actions'          => 'Actions',
    'status_active'        => 'Active',
    'status_inactive'      => 'Inactive',
    'first_login_yes'      => 'Pending',
    'first_login_no'       => 'Completed',
    'btn_activate'         => 'Activate',
    'btn_deactivate'       => 'Deactivate',
    'btn_unlock'           => 'Unlock',
    'col_requests'         => 'Password requests',
    'req_company'          => 'Company',
    'req_email'            => 'Email',
    'req_user'             => 'Username',
    'req_notes'            => 'Notes',
    'req_date'             => 'Date',
    'req_status'           => 'Status',
    'req_pending'          => 'Pending',
    'req_resolved'         => 'Resolved',
    'btn_resolve'          => 'Mark resolved',
    'no_requests'          => 'No pending requests.',
    'no_users'             => 'No registered users.',

    // ── Supplier profile ──────────────────────────────────────
    'profile_page_title'   => 'Supplier Profile — Local App',
    'profile_title'        => 'Supplier Profile',
    'profile_subtitle'     => 'Complete your profile to continue.',
    'btn_save'             => 'Save profile',
    'profile_success'      => 'Profile saved successfully.',
    'profile_error_fields' => 'Please correct the fields highlighted in red.',

    // ── Section: General Information ─────────────────────────
    'section_general'           => 'General Information',
    'full_name_label'           => 'Primary contact name',
    'full_name_placeholder'     => 'John Smith',
    'company_name_label'        => 'Legal name / Company name',
    'company_name_ph'           => 'My Company Inc.',
    'company_name_help'         => 'As it appears in the company\'s legal registration.',

    // ── Section: Legal Information ────────────────────────────
    'section_legal'             => 'Legal Information',
    'tax_id_label'              => 'Legal ID (EIN / VAT / Tax ID)',
    'tax_id_placeholder'        => '12-3456789',
    'legal_rep_name_label'      => 'Legal representative name',
    'legal_rep_name_placeholder'=> 'Mary Johnson',
    'legal_rep_id_label'        => 'Legal representative ID',
    'legal_rep_id_placeholder'  => '000-00-0000',
    'company_phone_label'       => 'Company phone',
    'legal_rep_phone_label'     => 'Representative phone (optional)',
    'phone_code_placeholder'    => 'Code',
    'phone_number_placeholder'  => 'Number',

    // ── Section: Main Office Address ─────────────────────────
    'section_addr_company'      => 'Main Office Address',
    'addr_street_label'         => 'Street / Avenue / Number',
    'addr_street_placeholder'   => '100 Main St, Suite 300',
    'addr_city_label'           => 'City',
    'addr_city_placeholder'     => 'New York',
    'addr_state_label'          => 'State / Province / Department',
    'addr_state_placeholder'    => 'New York',
    'addr_zip_label'            => 'ZIP / Postal code',
    'addr_zip_placeholder'      => '10001',
    'addr_country_label'        => 'Country',
    'addr_country_default'      => '-- Select a country --',

    // ── Section: Factory Address ──────────────────────────────
    'section_addr_factory'      => 'Factory Address (optional)',
    'factory_street_label'      => 'Street / Avenue / Number',
    'factory_street_placeholder'=> 'Industrial Zone, Block 5',
    'factory_city_label'        => 'City',
    'factory_city_placeholder'  => 'Detroit',
    'factory_state_label'       => 'State / Province / Department',
    'factory_state_placeholder' => 'Michigan',
    'factory_zip_label'         => 'ZIP / Postal code',
    'factory_zip_placeholder'   => '48201',
    'factory_country_label'     => 'Country',
    'factory_country_default'   => '-- Select a country --',

    // ── Section: Contacts ─────────────────────────────────────
    'section_contacts'          => 'Contact List',
    'contacts_subtitle'         => 'Add the contact persons for your organization.',
    'col_contact_name'          => 'Name',
    'col_contact_role'          => 'Role / Department',
    'col_contact_email'         => 'Email',
    'col_contact_phone'         => 'Phone',
    'col_contact_primary'       => 'Primary',
    'col_contact_actions'       => 'Actions',
    'contact_yes'               => 'Yes',
    'contact_no'                => '—',
    'btn_add_contact'           => 'Add contact',
    'btn_delete'                => 'Delete',
    'contact_name_label'        => 'Name *',
    'contact_name_placeholder'  => 'Ana Martinez',
    'contact_role_label'        => 'Role / Department',
    'contact_role_placeholder'  => 'Purchasing Manager',
    'contact_email_label'       => 'Email address',
    'contact_email_placeholder' => 'ana@company.com',
    'contact_phone_label'       => 'Phone',
    'contact_primary_label'     => 'Mark as primary contact',
    'no_contacts'               => 'No contacts registered yet.',
    'contact_error_name'        => 'Contact name is required.',
    'contact_added'             => 'Contact added successfully.',
    'contact_deleted'           => 'Contact deleted.',

    // ── Back button ───────────────────────────────────────────
    'btn_back'                  => 'Back',
    'phone_label'               => 'Phone',
    'phone_placeholder'         => '+1 555-0000',

    // ── Supplier summary ──────────────────────────────────────
    'summary_page_title'   => 'Supplier Summary — Local App',
    'summary_title'        => 'Supplier Summary',
    'summary_subtitle'     => 'Welcome to your supplier panel.',
    'your_profile'         => 'Your profile',
    'edit_profile'         => 'Edit profile',
    'field_full_name'      => 'Name',
    'field_company'        => 'Company',
    'field_phone'          => 'Phone',
    'field_email'          => 'Email',
    'not_provided'         => 'Not provided',    // ── Tab: Add product ─────────────────────────────────────────────────
    'tab_add_product'           => 'Add product',

    // ── Product upload ───────────────────────────────────────────────────
    'add_product_page_title'    => 'Add product — Local App',
    'add_product_title'         => 'Product upload',
    'add_product_subtitle'      => 'Complete the form to register a new product.',

    // Section labels
    'section_product_info'      => 'Product information',
    'section_product_pricing'   => 'Pricing (optional)',

    // Fields
    'field_supplier_code'       => 'Supplier product code',
    'field_supplier_code_ph'    => 'e.g. PROD-001',
    'field_supplier_code_help'  => 'Must be unique within your product catalog.',
    'field_admin_code'          => 'Admin product code',
    'field_admin_code_ph'       => 'e.g. SKU-0001',
    'field_admin_code_help'     => 'Globally unique code assigned by the administrator.',
    'field_product_name'        => 'Product name',
    'field_product_name_ph'     => 'Descriptive product name',
    'field_tech_desc'           => 'Description / Technical Sheet',
    'field_tech_desc_ph'        => 'Enter description, features and technical details...',
    'field_tech_desc_help'      => 'Plain text, bullets and section titles allowed. External links are not permitted.',
    'field_price_fob'           => 'Unit Price FOB',
    'field_price_fob_ph'        => '0.00',
    'field_price_cif'           => 'Unit Price CIF',
    'field_price_cif_ph'        => '0.00',

    // Buttons
    'btn_save_product'          => 'Save product',
    'btn_cancel_product'        => 'Cancel',

    // Validation messages
    'err_supplier_code_required'  => 'Product code is required.',
    'err_product_name_required'   => 'Product name is required.',
    'err_supplier_code_duplicate' => 'A product with this code already exists for this supplier.',
    'err_admin_code_duplicate'    => 'A product with this admin code already exists.',
    'err_price_fob_numeric'       => 'FOB price must be a valid number.',
    'err_price_cif_numeric'       => 'CIF price must be a valid number.',
    'err_desc_no_links'           => 'Description cannot contain external links.',

    // Success message
    'product_saved'               => 'Product saved successfully.',

    // Product images
    'section_product_images'      => 'Product images',
    'img_aerial_label'            => 'Aerial view',
    'img_lateral_front_label'     => 'Front view',
    'img_lateral_back_label'      => 'Rear view',
    'img_lateral_left_label'      => 'Left side view',
    'img_lateral_right_label'     => 'Right side view',
    'img_click_to_upload'         => 'Click to select image',
    'img_allowed_types'           => 'JPG, PNG, WEBP, GIF, BMP, AVIF · max 5 MB',
    'img_remove'                  => 'Remove',
    'err_img_required'            => 'You must upload at least the aerial view image.',
    'err_img_type'                => 'Only JPG, PNG or WEBP images are allowed.',
    'err_img_size'                => 'File size must not exceed 5 MB.',
    'err_img_upload_failed'       => 'Error uploading image. Please try again.',
    'err_product_save_failed'     => 'Error saving product. Please try again.',

    // My products (list)
    'tab_my_products'             => 'My products',
    'my_products_page_title'      => 'My products — Local App',
    'my_products_title'           => 'My products',
    'my_products_subtitle'        => 'Registered product catalog.',
    'no_products'                 => 'No products registered yet.',
    'btn_view_product'            => 'View details',
    'btn_add_new_product'         => 'Add new product',
    'col_product_code'            => 'Code',
    'col_product_name'            => 'Product name',
    'col_product_price_fob'       => 'FOB Price',
    'col_product_price_cif'       => 'CIF Price',
    'col_product_date'            => 'Date',
    'col_product_images'          => 'Images',
    'col_actions'                 => 'Actions',

    // Product detail
    'product_view_page_title'     => 'Product detail — Local App',
    'product_view_title'          => 'Product detail',
    'product_not_found'           => 'Product not found.',
    'section_product_gallery'     => 'Image gallery',
    'no_images'                   => 'No images registered.',
    'img_slot_aerial'             => 'Aerial view',
    'img_slot_lateral_front'      => 'Front view',
    'img_slot_lateral_back'       => 'Rear view',
    'img_slot_lateral_left'       => 'Left side view',
    'img_slot_lateral_right'      => 'Right side view',
    'btn_back_to_products'        => 'Back to my products',
    'product_view_required'       => '* Required',
    'product_view_optional'       => 'Optional',
    // Product view tabs
    'tab_product_detail'          => 'Detail',
    'tab_product_edit'            => 'Edit',
    // Edit product
    'product_updated'             => 'Product updated successfully.',
    'err_product_update_failed'   => 'Error updating product. Please try again.',
    'edit_img_delete'             => 'Remove current image',
    'edit_img_or_replace'         => 'Or upload a replacement:',
    'edit_img_click_zoom'         => 'Click to enlarge',
    // ── Multi-org / Org picker ─────────────────────────────────────
    'error_no_org'         => 'Your account has no access to any organization. Contact the administrator.',
    'error_org_invalid'    => 'Invalid organization selection. Please try again.',
    'org_picker_title'     => 'Select organization — Local App',
    'org_picker_heading'   => 'Which organization do you want to enter?',
    'org_picker_subtitle'  => 'Your account “%s” has access to more than one organization.',
    'org_picker_cancel'    => 'Cancel and sign in again',
    'org_label'            => 'Organization',

    // ── Contact email / Email verification ────────────────────────────────
    'contact_email_field_label'   => 'Contact email address',
    'contact_email_field_ph'      => 'email@company.com',
    'contact_email_field_help'    => 'This email will replace your login email after verification. You will receive a 6-digit code.',
    'email_invalid'               => 'Please enter a valid email address.',

    // Verification banner in profile.php
    'email_verify_banner_title'   => 'Email verification pending',
    'email_verify_banner_desc'    => 'A 6-digit code was sent to %s. Enter it below to confirm the change.',
    'email_verify_expires_in'     => 'Expires in: %s',
    'email_verify_code_label'     => 'Verification code (6 digits)',
    'email_verify_btn'            => 'Verify email',
    'email_verify_resend'         => 'Resend code',

    // Flash / POST→GET messages
    'email_verify_sent'           => 'Verification code sent. Check your inbox and enter it below.',
    'email_verify_resent'         => 'Verification code resent.',
    'email_verify_already_pending'=> 'A verification code is already active for that email. Check your inbox.',
    'email_verified_success'      => 'Email updated successfully! Your new login email has been verified.',

    // Code errors
    'email_verify_no_pending'     => 'No email is pending verification.',
    'email_verify_no_code'        => 'No active verification code found.',
    'email_verify_expired'        => 'Code expired. Resend the code and try again.',
    'email_verify_wrong_code'     => 'Incorrect code. Please check and try again.',

    // Expired banner
    'email_verify_expired_title'  => 'Verification code expired',
    'email_verify_expired_desc'   => 'The code sent to %s expired without being verified. Your profile is saved but the email was not updated.',

    // Badges
    'email_badge_pending'         => 'Pending verification',
    'email_badge_expired'         => 'Unverified',
    'btn_copy_code'               => 'Copy',

    // Draft section in summary.php
    'draft_section_title'         => 'Draft',
    'draft_section_subtitle'      => 'Saved items that require action to be completed.',
    'draft_email_change_title'    => 'Incomplete email change',
    'draft_email_change_desc'     => 'An attempt was made to change the email to %s but the verification code expired.',
    'draft_email_change_hint'     => 'Click "Resend code" to receive a new code and complete the change.',
];
